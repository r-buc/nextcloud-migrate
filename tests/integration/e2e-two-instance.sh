#!/usr/bin/env bash
#
# End-to-end integration test for the nextcloud_migrate app: spins up two
# fresh, throwaway Nextcloud containers (source + target) on a private
# podman network, installs the app on the source, drives a full migration
# run through the REST API + background jobs exactly like the admin UI
# would, and verifies the migrated files land on the target with matching
# SHA-256 checksums and an identical `ls -lR` structure.
#
# Exercises BOTH default "auto" mapping modes in one run:
#   - alice: does NOT exist on the target yet -> exercises auto-CREATE.
#   - bob:   already exists on the target      -> exercises auto-RESET.
#
# Test data deliberately includes both a duplicate folder name (Documents/)
# AND a duplicate file name (Documents/shared.txt, different content per
# user) shared across alice and bob, plus a uniquely-named file per user
# (alice.txt/bob.txt) - this combination is what originally caught a real
# unique-constraint bug where two users' identically-named paths collided
# in the same run (fixed by scoping migrate_files' uniqueness per user_map,
# not just per run).
#
# alice additionally gets 1,000 extra files (Documents/bulk/) so discovery
# (Service\DiscoveryService, see BATCH_SIZE) is forced across multiple
# Files-API search() pages instead of ever fitting in a single page - this
# is what originally caught a real off-by-one where the folder's own row
# silently consumed one of the first page's slots (see DiscoveryService's
# walk() docblock), causing every file beyond roughly the 499th to be
# dropped for any user with >= 500 files.
#
# Requirements: `podman` available directly on PATH (run this on a real
# host, not through any sandbox-specific escape hatch). Nothing here is
# specific to any particular development sandbox.
#
# Usage:
#   tests/integration/e2e-two-instance.sh
#
# Safe to re-run any time: always tears down and recreates ncm-src/ncm-tgt
# from scratch first (per the project's "no schema migration during v1
# development - always use fresh instances" policy).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STAGING_DIR="/tmp/ncm-custom-apps-e2e"
NETWORK="ncm-e2e-net"
SRC=ncm-e2e-src
TGT=ncm-e2e-tgt
IMAGE="docker.io/library/nextcloud:28-apache"
ADMIN_USER=admin
ADMIN_PASS=adminpass
TARGET_ADMIN_PASS=adminpass

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*" >&2; cleanup_note; exit 1; }
step() { echo; echo "=== $* ==="; }

cleanup_note() {
	echo "(containers '$SRC'/'$TGT' left running for inspection; re-run this script to reset)"
}

run_occ() {
	local container="$1"
	shift
	podman exec -u www-data "$container" php /var/www/html/occ "$@"
}

wait_for_nextcloud() {
	local container="$1"
	local i
	for i in $(seq 1 60); do
		if podman exec "$container" sh -c 'curl -s -f http://localhost/status.php' >/dev/null 2>&1; then
			return 0
		fi
		sleep 2
	done
	fail "$container did not become ready in time"
}

# Drains self-perpetuating NextcloudMigrate background jobs (transfer/verify
# workers re-queue themselves each run) until either the run reaches one of
# the given terminal states, or we give up after $2 rounds.
drain_jobs_until() {
	local run_id="$1"
	local max_rounds="$2"
	shift 2
	local terminal_states=("$@")
	local round state job_ids id

	for round in $(seq 1 "$max_rounds"); do
		state="$(api_get "/runs/$run_id" | grep -oP '"state":"\K[^"]+')"
		for want in "${terminal_states[@]}"; do
			if [[ "$state" == "$want" ]]; then
				return 0
			fi
		done

		job_ids="$(run_occ "$SRC" background-job:list 2>/dev/null | grep 'NextcloudMigrate' | grep -v 'null' | awk -F'|' '{print $2}' | tr -d ' ')"
		if [[ -z "$job_ids" ]]; then
			sleep 1
			continue
		fi
		for id in $job_ids; do
			run_occ "$SRC" background-job:execute --force-execute "$id" >/dev/null 2>&1 || true
		done
	done

	state="$(api_get "/runs/$run_id" | grep -oP '"state":"\K[^"]+')"
	fail "run $run_id did not reach [${terminal_states[*]}] within $max_rounds rounds (stuck at '$state')"
}

# Like drain_jobs_until(), but waits for a specific event_type to appear in
# the run's event log instead of a run-state transition - needed for
# SharesWorkerJob, which only starts syncing a user's shares once that
# user's files have settled (see RunOrchestrator::isUserFilesSettled()),
# which can happen at essentially the same moment the run itself reaches
# a terminal state. Without this separate wait, the outer drain loop could
# stop (run already "completed") one round before SharesWorkerJob's
# now-unblocked queued execution actually runs.
drain_jobs_until_event() {
	local run_id="$1"
	local max_rounds="$2"
	local event_type="$3"
	local round job_ids id

	for round in $(seq 1 "$max_rounds"); do
		if api_get "/runs/$run_id/events" | grep -q "$event_type"; then
			return 0
		fi

		job_ids="$(run_occ "$SRC" background-job:list 2>/dev/null | grep 'NextcloudMigrate' | grep -v 'null' | awk -F'|' '{print $2}' | tr -d ' ')"
		if [[ -z "$job_ids" ]]; then
			sleep 1
			continue
		fi
		for id in $job_ids; do
			run_occ "$SRC" background-job:execute --force-execute "$id" >/dev/null 2>&1 || true
		done
	done

	fail "event '$event_type' did not appear for run $run_id within $max_rounds rounds"
}

# --- REST API helpers (browser-session simulation, same flow admin.js uses) ---

login_admin() {
	local token
	podman exec "$SRC" sh -c "curl -s -c /tmp/e2e_cookies -b /tmp/e2e_cookies http://localhost/login -o /tmp/e2e_login.html"
	token="$(podman exec "$SRC" sh -c 'grep -oP "data-requesttoken=\"\K[^\"]+" /tmp/e2e_login.html | head -1')"
	podman exec "$SRC" sh -c "curl -s -c /tmp/e2e_cookies -b /tmp/e2e_cookies -X POST http://localhost/login \
		--data-urlencode 'user=$ADMIN_USER' --data-urlencode 'password=$ADMIN_PASS' \
		--data-urlencode 'requesttoken=$token' --data-urlencode 'timezone-offset=0' --data-urlencode 'timezone=UTC' \
		-o /dev/null -w '%{http_code}'" | grep -q '^303$' || fail "admin login POST did not redirect (303)"
	podman exec "$SRC" sh -c "curl -s -c /tmp/e2e_cookies -b /tmp/e2e_cookies http://localhost/apps/dashboard/ -o /tmp/e2e_dash.html"
	API_TOKEN="$(podman exec "$SRC" sh -c 'grep -oP "data-requesttoken=\"\K[^\"]+" /tmp/e2e_dash.html | head -1')"
	[[ -n "$API_TOKEN" ]] || fail "could not extract post-login CSRF token"
}

api_get() {
	podman exec "$SRC" sh -c "curl -s -b /tmp/e2e_cookies http://localhost/apps/nextcloud_migrate/api/v1$1 -H 'requesttoken: $API_TOKEN'"
}

api_call() {
	local method="$1" path="$2" body="${3:-}"
	podman exec "$SRC" sh -c "curl -s -b /tmp/e2e_cookies -X $method http://localhost/apps/nextcloud_migrate/api/v1$path \
		-H 'requesttoken: $API_TOKEN' -H 'Content-Type: application/json' ${body:+-d '$body'}"
}

api_status() {
	local method="$1" path="$2" body="${3:-}"
	podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -b /tmp/e2e_cookies -X $method http://localhost/apps/nextcloud_migrate/api/v1$path \
		-H 'requesttoken: $API_TOKEN' -H 'Content-Type: application/json' ${body:+-d '$body'}"
}

# --- Setup ---

step "Resetting containers and network"
podman rm -f -v "$SRC" "$TGT" >/dev/null 2>&1 || true
podman network rm "$NETWORK" >/dev/null 2>&1 || true
podman network create "$NETWORK" >/dev/null

step "Staging app code (excluding .git/vendor/composer.lock)"
rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR/nextcloud_migrate"
cp -r "$REPO_ROOT/." "$STAGING_DIR/nextcloud_migrate/"
rm -rf "$STAGING_DIR/nextcloud_migrate/.git" "$STAGING_DIR/nextcloud_migrate/vendor" "$STAGING_DIR/nextcloud_migrate/composer.lock"

step "Starting source and target containers"
podman run -d --name "$SRC" --network "$NETWORK" \
	--user www-data --userns="keep-id:uid=33,gid=33" --cap-add=NET_BIND_SERVICE \
	-v "$STAGING_DIR:/var/www/html/custom_apps:Z" \
	-e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER="$ADMIN_USER" -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
	"$IMAGE" >/dev/null
podman run -d --name "$TGT" --network "$NETWORK" \
	--user www-data --userns="keep-id:uid=33,gid=33" --cap-add=NET_BIND_SERVICE \
	-e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER="$ADMIN_USER" -e NEXTCLOUD_ADMIN_PASSWORD="$TARGET_ADMIN_PASS" \
	"$IMAGE" >/dev/null

wait_for_nextcloud "$SRC"
wait_for_nextcloud "$TGT"
# The target must trust the hostname the source's outbound WebDAV/OCS calls
# use in the request URL (its own container name), or it rejects them as an
# "untrusted domain". The source doesn't need this: we only ever reach it
# as localhost (via podman exec curl) in this script.
run_occ "$TGT" config:system:set trusted_domains 1 --value="$TGT" >/dev/null
# Disable Nextcloud's default demo-content skeleton for new accounts on the
# target, so every new/reset user's home folder starts truly empty - keeps
# the later ls -lR structural comparison meaningful (otherwise it would be
# full of unrelated "Welcome to Nextcloud" sample files).
run_occ "$TGT" config:system:set skeletondirectory --value="" >/dev/null

step "Enabling nextcloud_migrate on the source instance"
run_occ "$SRC" app:enable nextcloud_migrate || fail "app:enable failed"
run_occ "$SRC" app:list | grep -q nextcloud_migrate || fail "app not listed as enabled"
pass "app enabled, schema created fresh"

step "Creating local (source) test users + sample files"
podman exec -u www-data -e OC_PASS=AliceTestPassphrase2026xyz "$SRC" php /var/www/html/occ user:add --password-from-env --display-name="Alice Wonderland" alice
podman exec -u www-data -e OC_PASS=BobTestPassphrase2026xyz "$SRC" php /var/www/html/occ user:add --password-from-env --display-name="Bob Builder" bob
# Documents/ and Documents/shared.txt intentionally exist for BOTH users
# with the SAME relative path but DIFFERENT content, to exercise
# per-(run,user)-scoped uniqueness (a real bug once collided across users
# sharing a folder/file name - see migrate_file_unique_idx). alice.txt/
# bob.txt are uniquely named per user, to confirm content never crosses
# between users during migration.
podman exec "$SRC" sh -c "mkdir -p /var/www/html/data/alice/files/Documents
 echo 'hello from alice' > /var/www/html/data/alice/files/Documents/alice.txt
echo 'shared-name file, alice content' > /var/www/html/data/alice/files/Documents/shared.txt"
podman exec "$SRC" sh -c "mkdir -p /var/www/html/data/bob/files/Documents
 echo 'hello from bob' > /var/www/html/data/bob/files/Documents/bob.txt
echo 'shared-name file, bob content' > /var/www/html/data/bob/files/Documents/shared.txt"

step "Creating 1,000 additional files for alice (exercises Search-API discovery pagination beyond a single 500-row page)"
podman exec "$SRC" sh -c '
	mkdir -p /var/www/html/data/alice/files/Documents/bulk
	for i in $(seq 1 1000); do
		n=$(printf %04d "$i")
		echo "bulk file number $i" > "/var/www/html/data/alice/files/Documents/bulk/file_$n.txt"
	done
'

run_occ "$SRC" files:scan alice >/dev/null
run_occ "$SRC" files:scan bob >/dev/null

step "Setting source user profile fields (displayname/email/language/groups) for user info migration"
run_occ "$SRC" user:setting alice settings email alice@example.com >/dev/null
run_occ "$SRC" user:setting alice files quota "5 GB" >/dev/null
run_occ "$SRC" user:setting alice core lang de >/dev/null
run_occ "$SRC" group:add editors >/dev/null 2>&1 || true
run_occ "$SRC" group:adduser editors alice >/dev/null
run_occ "$SRC" user:setting bob settings email bob@example.com >/dev/null
run_occ "$SRC" user:setting bob files quota "2 GB" >/dev/null
run_occ "$SRC" user:setting bob core lang fr >/dev/null
pass "source profile fields set for alice and bob"

step "Seeding a CardDAV addressbook + contacts for alice (for contacts migration test)"
run_occ "$SRC" dav:create-addressbook alice friends >/dev/null
podman exec "$SRC" sh -c "cat > /tmp/card1.vcf << 'EOF'
BEGIN:VCARD
VERSION:3.0
UID:friend-one-uid
FN:Friend One
EMAIL:friend.one@example.com
END:VCARD
EOF"
podman exec "$SRC" sh -c "cat > /tmp/card2.vcf << 'EOF'
BEGIN:VCARD
VERSION:3.0
UID:friend-two-uid
FN:Friend Two
EMAIL:friend.two@example.com
END:VCARD
EOF"
podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -u alice:AliceTestPassphrase2026xyz -X PUT -H 'Content-Type: text/vcard' --data-binary @/tmp/card1.vcf http://localhost/remote.php/dav/addressbooks/users/alice/friends/card1.vcf" | grep -qE '^20[0-9]$' || fail "failed to seed card1.vcf via CardDAV"
podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -u alice:AliceTestPassphrase2026xyz -X PUT -H 'Content-Type: text/vcard' --data-binary @/tmp/card2.vcf http://localhost/remote.php/dav/addressbooks/users/alice/friends/card2.vcf" | grep -qE '^20[0-9]$' || fail "failed to seed card2.vcf via CardDAV"
pass "seeded 2 contacts in alice's 'friends' addressbook"

step "Seeding a CalDAV calendar + event for alice (for calendar migration test)"
run_occ "$SRC" dav:create-calendar alice work >/dev/null
podman exec "$SRC" sh -c "cat > /tmp/event1.ics << 'EOF'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//e2e-test//EN
BEGIN:VEVENT
UID:event-one-uid
DTSTAMP:20260101T000000Z
DTSTART:20260101T100000Z
DTEND:20260101T110000Z
SUMMARY:Team meeting
END:VEVENT
END:VCALENDAR
EOF"
podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -u alice:AliceTestPassphrase2026xyz -X PUT -H 'Content-Type: text/calendar' --data-binary @/tmp/event1.ics http://localhost/remote.php/dav/calendars/alice/work/event1.ics" | grep -qE '^20[0-9]$' || fail "failed to seed event1.ics via CalDAV"
pass "seeded 1 event in alice's 'work' calendar"

step "Seeding shares for alice (for shares migration test)"
# 'charlie' exists on source but is deliberately NOT included in the
# migration run's user mappings below, to exercise the unmappable-
# recipient skip-with-warning path.
podman exec -u www-data -e OC_PASS=CharlieTestPassphrase2026xyz "$SRC" php /var/www/html/occ user:add --password-from-env charlie >/dev/null
SHARE_LINK_STATUS="$(podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -u alice:AliceTestPassphrase2026xyz -X POST http://localhost/ocs/v1.php/apps/files_sharing/api/v1/shares -H 'OCS-APIRequest: true' -d 'path=/Documents/alice.txt' -d 'shareType=3' -d 'permissions=1'")"
[[ "$SHARE_LINK_STATUS" =~ ^20[0-9]$ ]] || fail "failed to create link share (status=$SHARE_LINK_STATUS)"
SHARE_BOB_STATUS="$(podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -u alice:AliceTestPassphrase2026xyz -X POST http://localhost/ocs/v1.php/apps/files_sharing/api/v1/shares -H 'OCS-APIRequest: true' -d 'path=/Documents/alice.txt' -d 'shareType=0' -d 'shareWith=bob' -d 'permissions=1'")"
[[ "$SHARE_BOB_STATUS" =~ ^20[0-9]$ ]] || fail "failed to create user share to bob (status=$SHARE_BOB_STATUS)"
SHARE_CHARLIE_STATUS="$(podman exec "$SRC" sh -c "curl -s -o /dev/null -w '%{http_code}' -u alice:AliceTestPassphrase2026xyz -X POST http://localhost/ocs/v1.php/apps/files_sharing/api/v1/shares -H 'OCS-APIRequest: true' -d 'path=/Documents/alice.txt' -d 'shareType=0' -d 'shareWith=charlie' -d 'permissions=1'")"
[[ "$SHARE_CHARLIE_STATUS" =~ ^20[0-9]$ ]] || fail "failed to create user share to charlie (status=$SHARE_CHARLIE_STATUS)"
pass "seeded 3 shares for alice's Documents/alice.txt (link, user->bob, user->charlie)"

step "Pre-creating 'bob' on the target (to exercise auto-RESET), leaving 'alice' absent (to exercise auto-CREATE)"
podman exec -u www-data -e OC_PASS=BobExistingTargetPassphrase2026 "$TGT" php /var/www/html/occ user:add --password-from-env bob

step "Logging in as admin on the source and driving the REST API"
login_admin
pass "authenticated session + CSRF token acquired"

CREATE_STATUS="$(api_status POST /instances "{\"url\":\"http://$TGT\",\"adminUserId\":\"$ADMIN_USER\",\"adminAppPassword\":\"$TARGET_ADMIN_PASS\",\"allowSelfSigned\":true}")"
[[ "$CREATE_STATUS" == "200" || "$CREATE_STATUS" == "201" ]] || fail "createInstance returned $CREATE_STATUS"
INSTANCE_ID="$(api_get /instances | grep -oP '"id":\K[0-9]+' | head -1)"
pass "target instance configured (id=$INSTANCE_ID)"

TEST_BODY="$(api_call POST "/instances/$INSTANCE_ID/test")"
echo "$TEST_BODY" | grep -q '"success":true' || fail "instance test failed: $TEST_BODY"
pass "target reachable, admin provisioning credentials valid"

LOCAL_USERS="$(api_get /local-users)"
echo "$LOCAL_USERS" | grep -q '"id":"alice"' || fail "alice missing from /local-users"
echo "$LOCAL_USERS" | grep -q '"id":"bob"' || fail "bob missing from /local-users"
pass "local user list includes alice and bob"

step "Creating migration run (both users in default 'auto' mode)"
RUN_BODY="$(api_call POST /runs '{"collisionStrategy":"rename","userMappings":[{"sourceUserId":"alice","targetUserId":"alice","mode":"auto"},{"sourceUserId":"bob","targetUserId":"bob","mode":"auto"}],"migrateUserInfo":true,"migrateContacts":true,"migrateCalendars":true,"migrateShares":true}')"
RUN_ID="$(echo "$RUN_BODY" | grep -oP '"id":\K[0-9]+' | head -1)"
[[ -n "$RUN_ID" ]] || fail "createRun failed: $RUN_BODY"
pass "run created (id=$RUN_ID) - alice account should now exist on target, bob's password should be reset"

step "Dry run (validate target user credentials + discover files)"
api_call POST "/runs/$RUN_ID/dry-run" >/dev/null
# Higher than the ~handful-of-files case needs: discovering alice's 1,000+
# bulk files spans several Search-API pages/DiscoveryJob ticks.
drain_jobs_until "$RUN_ID" 90 dry_run_ready validation_failed
STATE="$(api_get "/runs/$RUN_ID" | grep -oP '"state":"\K[^"]+')"
[[ "$STATE" == "dry_run_ready" ]] || fail "run did not reach dry_run_ready (state=$STATE) - check per-user credential validation"
pass "dry run succeeded: both auto-created/reset credentials validated over WebDAV"

step "Approving and running the migration to completion"
api_call POST "/runs/$RUN_ID/approve" >/dev/null
# Higher than the ~handful-of-files case needs: transferring/verifying
# alice's 1,000+ bulk files takes more worker-job ticks even though each
# tick processes many files in a loop (see TransferWorkerJob::run()).
drain_jobs_until "$RUN_ID" 120 completed completed_with_errors failed
STATE="$(api_get "/runs/$RUN_ID" | grep -oP '"state":"\K[^"]+')"
[[ "$STATE" == "completed" ]] || fail "run finished in state '$STATE', expected 'completed'"
pass "run completed"

step "Waiting for shares sync to finish (gated on file transfer, may lag the run's own 'completed' state by a round)"
drain_jobs_until_event "$RUN_ID" 20 "shares_sync_completed"
pass "shares_sync_completed event recorded"

step "Verifying migrated files on the target instance"
run_occ "$TGT" files:scan alice >/dev/null
run_occ "$TGT" files:scan bob >/dev/null

ALICE_SRC_SUM="$(podman exec "$SRC" sha256sum /var/www/html/data/alice/files/Documents/alice.txt | awk '{print $1}')"
ALICE_TGT_SUM="$(podman exec "$TGT" sha256sum /var/www/html/data/alice/files/Documents/alice.txt 2>/dev/null | awk '{print $1}')" \
	|| fail "alice.txt not found on target (auto-CREATE path failed)"
[[ "$ALICE_SRC_SUM" == "$ALICE_TGT_SUM" ]] || fail "alice.txt checksum mismatch (src=$ALICE_SRC_SUM tgt=$ALICE_TGT_SUM)"
pass "alice.txt landed on target with matching checksum (auto-CREATE path verified)"

BOB_SRC_SUM="$(podman exec "$SRC" sha256sum /var/www/html/data/bob/files/Documents/bob.txt | awk '{print $1}')"
BOB_TGT_SUM="$(podman exec "$TGT" sha256sum /var/www/html/data/bob/files/Documents/bob.txt 2>/dev/null | awk '{print $1}')" \
	|| fail "bob.txt not found on target (auto-RESET path failed)"
[[ "$BOB_SRC_SUM" == "$BOB_TGT_SUM" ]] || fail "bob.txt checksum mismatch (src=$BOB_SRC_SUM tgt=$BOB_TGT_SUM)"
pass "bob.txt landed on target with matching checksum (auto-RESET path verified)"

# shared.txt exists at the SAME relative path for both alice and bob but with
# DIFFERENT content - confirms the per-(run,user_map) uniqueness fix actually
# keeps both users' rows/content distinct instead of colliding or one
# overwriting the other's transfer.
ALICE_SHARED_SRC_SUM="$(podman exec "$SRC" sha256sum /var/www/html/data/alice/files/Documents/shared.txt | awk '{print $1}')"
ALICE_SHARED_TGT_SUM="$(podman exec "$TGT" sha256sum /var/www/html/data/alice/files/Documents/shared.txt 2>/dev/null | awk '{print $1}')" \
	|| fail "alice's Documents/shared.txt not found on target"
[[ "$ALICE_SHARED_SRC_SUM" == "$ALICE_SHARED_TGT_SUM" ]] || fail "alice's shared.txt checksum mismatch"
BOB_SHARED_SRC_SUM="$(podman exec "$SRC" sha256sum /var/www/html/data/bob/files/Documents/shared.txt | awk '{print $1}')"
BOB_SHARED_TGT_SUM="$(podman exec "$TGT" sha256sum /var/www/html/data/bob/files/Documents/shared.txt 2>/dev/null | awk '{print $1}')" \
	|| fail "bob's Documents/shared.txt not found on target"
[[ "$BOB_SHARED_SRC_SUM" == "$BOB_SHARED_TGT_SUM" ]] || fail "bob's shared.txt checksum mismatch"
[[ "$ALICE_SHARED_SRC_SUM" != "$BOB_SHARED_SRC_SUM" ]] || fail "test data bug: alice/bob shared.txt should differ in content"
pass "duplicate-named Documents/shared.txt migrated correctly and distinctly for both users"

step "Verifying alice's 1,000 bulk files all migrated (Search-API discovery pagination)"
ALICE_BULK_SRC_COUNT="$(podman exec "$SRC" sh -c 'ls /var/www/html/data/alice/files/Documents/bulk | wc -l')"
ALICE_BULK_TGT_COUNT="$(podman exec "$TGT" sh -c 'ls /var/www/html/data/alice/files/Documents/bulk 2>/dev/null | wc -l')"
[[ "$ALICE_BULK_SRC_COUNT" == "1000" ]] || fail "test setup bug: expected 1000 source bulk files, found $ALICE_BULK_SRC_COUNT"
[[ "$ALICE_BULK_TGT_COUNT" == "$ALICE_BULK_SRC_COUNT" ]] || fail "bulk file count mismatch: source=$ALICE_BULK_SRC_COUNT target=$ALICE_BULK_TGT_COUNT (pagination likely dropped files beyond the first Search-API page - see DiscoveryService::walk())"
ALICE_BULK_SRC_SUMS="$(podman exec "$SRC" sh -c 'sha256sum /var/www/html/data/alice/files/Documents/bulk/*.txt' | awk '{print $1}' | sort)"
ALICE_BULK_TGT_SUMS="$(podman exec "$TGT" sh -c 'sha256sum /var/www/html/data/alice/files/Documents/bulk/*.txt' | awk '{print $1}' | sort)"
[[ "$ALICE_BULK_SRC_SUMS" == "$ALICE_BULK_TGT_SUMS" ]] || fail "bulk file checksum set mismatch between source and target"
pass "all 1,000 bulk files migrated with matching checksums (multi-page discovery verified)"

step "Structural comparison: ls -lR of each user's Documents/ on source vs target"
# Strip "total N" block-count summary lines (filesystem-dependent noise, not
# meaningful for correctness) and directory entries' own listing lines
# (a folder's own mtime/size, as listed in its parent's output, is
# filesystem housekeeping metadata that legitimately updates every time a
# child is added - it will genuinely differ from the source once a folder
# has many children arriving one-by-one over time via WebDAV instead of
# all at once, even though every file inside it is correct) before
# diffing; everything else (file names, sizes, perms, and mtimes -
# preserved via X-OC-MTime - is expected to match exactly.
for user in alice bob; do
	SRC_LISTING="$(podman exec "$SRC" sh -c "ls -lR /var/www/html/data/$user/files/Documents" | grep -v '^total ' | grep -v '^d')"
	TGT_LISTING="$(podman exec "$TGT" sh -c "ls -lR /var/www/html/data/$user/files/Documents" | grep -v '^total ' | grep -v '^d')"
	if [[ "$SRC_LISTING" != "$TGT_LISTING" ]]; then
		echo "--- source ($user) ---"
		echo "$SRC_LISTING"
		echo "--- target ($user) ---"
		echo "$TGT_LISTING"
		fail "ls -lR mismatch for $user's Documents/ between source and target"
	fi
done
pass "ls -lR of Documents/ matches exactly between source and target for both users"

step "Verifying user info migration (displayname/email/language/groups)"
TGT_ALICE_OCS="$(podman exec "$TGT" sh -c "curl -s -u $ADMIN_USER:$TARGET_ADMIN_PASS -H 'OCS-APIRequest: true' 'http://localhost/ocs/v1.php/cloud/users/alice?format=json'")"
echo "$TGT_ALICE_OCS" | grep -q '"displayname":"Alice Wonderland"' || fail "alice displayname not migrated: $TGT_ALICE_OCS"
echo "$TGT_ALICE_OCS" | grep -q '"email":"alice@example.com"' || fail "alice email not migrated: $TGT_ALICE_OCS"
echo "$TGT_ALICE_OCS" | grep -q '"language":"de"' || fail "alice language not migrated: $TGT_ALICE_OCS"
echo "$TGT_ALICE_OCS" | grep -q '"editors"' || fail "alice group membership not migrated: $TGT_ALICE_OCS"
pass "alice's user info (displayname/email/language/groups) migrated to target"

TGT_BOB_OCS="$(podman exec "$TGT" sh -c "curl -s -u $ADMIN_USER:$TARGET_ADMIN_PASS -H 'OCS-APIRequest: true' 'http://localhost/ocs/v1.php/cloud/users/bob?format=json'")"
echo "$TGT_BOB_OCS" | grep -q '"displayname":"Bob Builder"' || fail "bob displayname not migrated: $TGT_BOB_OCS"
echo "$TGT_BOB_OCS" | grep -q '"email":"bob@example.com"' || fail "bob email not migrated: $TGT_BOB_OCS"
echo "$TGT_BOB_OCS" | grep -q '"language":"fr"' || fail "bob language not migrated: $TGT_BOB_OCS"
pass "bob's user info (displayname/email/language) migrated to target"

step "Verifying user info sync events were recorded"
EVENTS_BODY="$(api_get "/runs/$RUN_ID/events")"
echo "$EVENTS_BODY" | grep -q 'user_info_sync_completed' || fail "user_info_sync_completed event not found: $EVENTS_BODY"
pass "user_info_sync_completed event recorded"

step "Verifying contacts migration (CardDAV)"
echo "$EVENTS_BODY" | grep -q 'contacts_sync_completed' || fail "contacts_sync_completed event not found: $EVENTS_BODY"
pass "contacts_sync_completed event recorded"
# alice's target account uses an auto-generated app password unknown to this
# script, and there is no admin bypass over CardDAV (see WebDavClient's
# docblock), so the migrated vCards can't be fetched via a normal CardDAV
# request here. Query the target's SQLite database directly instead (via
# PHP's bundled pdo_sqlite, no Nextcloud bootstrap needed) - equivalent to
# what CardDavBackend itself reads from.
TGT_CARDS_COUNT="$(podman exec "$TGT" php -r '
$pdo = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$stmt = $pdo->query("SELECT COUNT(*) FROM oc_cards WHERE uid IN (\"friend-one-uid\", \"friend-two-uid\")");
echo $stmt->fetchColumn();
')"
[[ "$TGT_CARDS_COUNT" == "2" ]] || fail "expected 2 migrated contacts on target, found '$TGT_CARDS_COUNT'"
pass "both contacts (friend-one-uid, friend-two-uid) landed in alice's addressbook on target"

step "Verifying calendar migration (CalDAV)"
echo "$EVENTS_BODY" | grep -q 'calendars_sync_completed' || fail "calendars_sync_completed event not found: $EVENTS_BODY"
pass "calendars_sync_completed event recorded"
TGT_EVENTS_COUNT="$(podman exec "$TGT" php -r '
$pdo = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$stmt = $pdo->query("SELECT COUNT(*) FROM oc_calendarobjects WHERE uid = \"event-one-uid\"");
echo $stmt->fetchColumn();
')"
[[ "$TGT_EVENTS_COUNT" == "1" ]] || fail "expected 1 migrated calendar event on target, found '$TGT_EVENTS_COUNT'"
pass "event-one-uid landed in alice's 'work' calendar on target"

step "Verifying shares migration (OCS Share API)"
echo "$EVENTS_BODY" | grep -q 'shares_sync_completed' || fail "shares_sync_completed event not found: $EVENTS_BODY"
pass "shares_sync_completed event recorded"
TGT_LINK_SHARES="$(podman exec "$TGT" php -r '
$pdo = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$stmt = $pdo->query("SELECT COUNT(*) FROM oc_share s JOIN oc_filecache fc ON s.file_source = fc.fileid WHERE s.uid_owner = \"alice\" AND s.share_type = 3 AND fc.path LIKE \"%alice.txt\"");
echo $stmt->fetchColumn();
')"
[[ "$TGT_LINK_SHARES" == "1" ]] || fail "expected 1 migrated link share on target, found '$TGT_LINK_SHARES'"
TGT_BOB_SHARES="$(podman exec "$TGT" php -r '
$pdo = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$stmt = $pdo->query("SELECT COUNT(*) FROM oc_share s JOIN oc_filecache fc ON s.file_source = fc.fileid WHERE s.uid_owner = \"alice\" AND s.share_type = 0 AND s.share_with = \"bob\" AND fc.path LIKE \"%alice.txt\"");
echo $stmt->fetchColumn();
')"
[[ "$TGT_BOB_SHARES" == "1" ]] || fail "expected 1 migrated user share to bob on target, found '$TGT_BOB_SHARES'"
pass "link share and user share (alice -> bob) both migrated to target"
TGT_CHARLIE_SHARES="$(podman exec "$TGT" php -r '
$pdo = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$stmt = $pdo->query("SELECT COUNT(*) FROM oc_share WHERE share_with = \"charlie\"");
echo $stmt->fetchColumn();
')"
[[ "$TGT_CHARLIE_SHARES" == "0" ]] || fail "share to unmapped user 'charlie' should NOT have been migrated, found $TGT_CHARLIE_SHARES"
echo "$EVENTS_BODY" | grep -q 'share_recipient_unmapped' || fail "share_recipient_unmapped warning event not found: $EVENTS_BODY"
pass "share to unmapped user 'charlie' correctly skipped with a warning event"

step "Cleaning up"
podman rm -f -v "$SRC" "$TGT" >/dev/null 2>&1 || true
podman network rm "$NETWORK" >/dev/null 2>&1 || true
rm -rf "$STAGING_DIR"

echo
echo "=================================================="
echo " ALL CHECKS PASSED"
echo "=================================================="
