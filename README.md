# Nextcloud Migrate

An admin-driven Nextcloud app that pushes a user's files 1:1 from this
(source) instance to a remote (target) Nextcloud instance over WebDAV.

## v1 scope

Migrated: file content, folder structure, modification time.

Not migrated in v1 (explicit decision, see "Out of scope" below): shares,
tags/comments/favorites, file versions, encrypted files, federated shares,
ACLs.

## Architecture

- **Push mode**: this app is installed on the SOURCE instance. Discovery
  reads the local file tree directly via the Nextcloud Files API
  (`OCP\Files\IRootFolder`) - fast, no HTTP. Only the TARGET instance is
  reached over the network, via WebDAV.
- **Credentials (important)**: Nextcloud's WebDAV auth backend rewrites the
  DAV principal to whichever user actually authenticates - there is no
  admin-bypass for writing into a different user's files. So the single
  target instance's admin credential (`RemoteInstance`) is used ONLY for
  the OCS Provisioning API (listing remote users, and the default "auto"
  mapping mode's create-or-reset-password flow); every actual file transfer
  authenticates as that specific mapped user's own app password
  (`UserMap.targetAppPasswordEncrypted`). Two mapping modes, chosen per
  user in the admin UI:
  - **auto** (default): the target account is created (if it doesn't exist
    yet) or has its password reset (if it does) via the admin credential,
    so no manual per-user password is needed. Intended for migrations where
    target accounts are freshly provisioned/initial.
  - **manual** ("expert mode"): the admin supplies an app password they
    already obtained from that specific target user, without touching the
    target account via the admin API at all.
- **Large-file transfer** uses the NG Chunking v2 protocol - the same wire
  protocol the official Nextcloud desktop/mobile clients use - giving true
  chunk-level resume instead of restarting a whole large file after a crash.
  Small files use a single PUT.
- **Live source changes**: before/after reading a file, its mtime+size are
  compared (mirroring the desktop client's torn-read guard). A mismatch
  aborts and retries that file rather than uploading inconsistent bytes.
  This is a point-in-time migration, not continuous sync: edits made only
  *after* a file has already been verified are not detected or re-migrated.
  Avoid heavy write activity on the source during a run.
- **Orchestration**: entirely native Nextcloud background jobs (no
  Redis/external queue). One self-perpetuating `TransferWorkerJob` (then
  one `VerifyWorkerJob`) lineage per mapped user, each processing many
  files in a batched loop (`RunOrchestrator::getBatchSeconds()`, default
  240s) before re-enqueueing itself, and claiming files via DB-row locking
  (`lock_owner`/`lock_expires_at`) as a crash-safety net rather than for
  cross-worker contention - since each lineage only ever works its own
  user's files, there's no risk of two lineages racing for the same row.
  Scoping one lineage per user also means each one only ever authenticates
  as that single target user for its whole lifetime, so `WebDavClient`'s
  reused/keep-alive connection never has to be torn down and reopened
  mid-job. `CleanupLocksJob` reclaims rows left behind by a crashed worker.
  The run only advances past TRANSFERRING/VERIFYING once every user's
  lineage has drained (`RunOrchestrator::onUserTransferComplete()`/
  `onUserVerificationComplete()`). Every job that queues follow-up work for
  itself or another job class (self-re-enqueues, `EnqueueTransfersJob`
  spawning workers, phase-transition handoffs to `FinalizeJob`/
  `VerifyWorkerJob`, etc.) backdates that job's `IJobList::add()`
  `$firstCheck` to the epoch (`Util\JobScheduling::IMMEDIATE_FIRST_CHECK`).
  Without this, a newly-queued job's `last_checked` column is "now" - tied
  with (or later than) periodic jobs like `CleanupLocksJob` that cron.php
  already re-touched to "now" earlier in the very same pass - and
  `getNext()`'s `ORDER BY last_checked ASC` can hand cron.php that already-
  executed job a second time, tripping its `$executedJobs` dedup guard and
  aborting the *entire* cron.php invocation early, stranding the real new
  work until the next scheduled tick (confirmed via real cron logs: a
  spawned `TransferWorkerJob` sat idle for a full 5-minute system-cron
  interval before its first execution). Backdating guarantees the new job
  always sorts first and gets picked up within the same pass that queued
  it.
- **Run lifecycle**: `CREATED -> VALIDATING -> DISCOVERING -> DRY_RUN_READY
  -> APPROVED -> TRANSFERRING -> VERIFYING -> FINALIZING -> COMPLETED |
  COMPLETED_WITH_ERRORS`, with `PAUSED`/`CANCELLED`/`VALIDATION_FAILED`
  branches. See `RunOrchestrator` for the full transition graph.
- **Collision handling**: resolved inline per-file during transfer
  (`MappingService`), not as a separate bulk pre-pass, to avoid an extra
  PROPFIND round trip per file. Strategies: `rename` (default), `skip`,
  `overwrite`.
- **Post-transfer verification (optional)**: every upload already includes
  an `OC-Checksum` header (see `TransferService`/`WebDavClient`), which
  Nextcloud's DAV server validates against the received bytes and rejects
  on mismatch - so content integrity is already checked at transfer time.
  The separate `VERIFYING` phase (`VerifyWorkerJob`) additionally
  re-downloads every file from the target afterwards to compare checksums
  again, which also catches rarer issues upload-time validation can't,
  such as storage corruption on the target *after* a successful write.
  Set `skipVerification: true` when creating a run (exposed as a checkbox
  in the admin UI) to skip this second pass and go straight from
  `TRANSFERRING` to `FINALIZING` once transfer completes - roughly halves
  total network/IO cost for the run at the expense of that extra safety
  net. Verification is on by default.
- **Retry exhaustion vs. transient failure**: a file in `transfer_failed`/
  `verification_failed` is only actually retried while its attempt count is
  below `MigrationFile::MAX_TRANSFER_ATTEMPTS`/`MAX_VERIFY_ATTEMPTS` - once
  exhausted it's permanently stuck in that state (`findTransferable()`/
  `findVerifiable()` will never select it again). Phase-advancement checks
  (`RunOrchestrator::anyUserStillTransferring()`/`anyUserStillVerifying()`/
  `resumeRun()`) and `StatusController`'s progress-percent calculation both
  distinguish the two via `MigrationFileMapper::countRetryableFailures()`:
  only still-retryable failures count as "remaining work" (so a run isn't
  stuck in TRANSFERRING/VERIFYING forever once every mapped user's lineage
  has genuinely finished, even with some permanent failures left over), and
  exhausted failures count as fully settled (weight 1.0) for progress
  reporting rather than the partial "still in progress" weight.
- **v1 simplification**: one target instance and one current run per admin
  (no multi-instance/multi-run list UI) - re-saving the instance form
  updates the same row, and the admin settings page shows only the
  latest/current run. A run can still cover many mapped users at once.
- **Admin UI**: a Vue 3 app (`src/`, built with `@nextcloud/vue` components -
  `NcButton`, `NcTextField`, `NcCheckboxRadioSwitch`, `NcNoteCard`,
  `NcProgressBar` - the same library and components Nextcloud's own Settings
  app uses, e.g. for the Users page's quota bar) replacing the old
  hand-written HTML/vanilla-JS admin page. `templates/settings/admin.php`
  is now just a mount point (`<div id="nextcloud-migrate-admin-app">`);
  `src/main.js` mounts `App.vue`, which composes `InstanceSettings.vue`
  (target instance form) and `MigrationPanel.vue` (create-run form plus
  live progress view, both in one component with an "Advanced
  options"/"Advanced controls" disclosure instead of a separate duplicate
  panel). The frontend drives the run hands-off by default - it
  automatically calls the dry-run endpoint once a run is `CREATED` and the
  approve endpoint once it reaches `DRY_RUN_READY` (see `maybeAutoAdvance()`
  in `MigrationPanel.vue`), polling `GET .../status` every few seconds for
  a live `NcProgressBar` and a per-user files/bytes table (each row's
  Volume column is itself an `NcProgressBar`, mirroring the Users page's
  quota column). The advanced disclosure exposes expert mode, skip
  verification, manual dry-run/approve/pause/resume/refresh buttons, and a
  raw status JSON dump for the same hands-on testing/debugging the old
  panel offered - this is purely a frontend concern, the backend
  endpoints/state machine are unchanged. Per-user transferred/total byte
  counts are computed live in `StatusController`
  (`MigrationFileMapper::statsByUser()`), not from `UserMap`'s own
  `totalFiles`/`transferredFiles` columns, which are only ever set once at
  discovery time.

## Key classes

| Concern | Class |
|---|---|
| Run state machine | `Service\RunOrchestrator` |
| Local tree walk | `Service\DiscoveryService` |
| Collision resolution | `Service\MappingService` |
| Upload (simple + chunked) | `Service\TransferService` |
| Checksum verification | `Service\VerificationService` |
| Target WebDAV calls (per-user file transfer) | `Service\WebDavClient` |
| Target OCS Provisioning API (admin-only: list/create/reset users) | `Service\ProvisioningClient` |
| Credential encryption | `Service\CredentialService` (uses `OCP\Security\ICrypto`) |
| Audit log | `Service\EventLogger` |
| Reports (dry-run/final) | `Service\ReportService` |

## REST API

All endpoints are admin-only and scoped to the calling admin's own instances
and runs (`created_by` ownership check). See `appinfo/routes.php`.

## Out of scope for v1 (and why)

- **Shares/permissions**: requires OCS share API round trips per file and
  user-mapping resolution; deferred to keep v1 focused on file fidelity.
- **Versions**: tied to source file IDs; not portable 1:1 across instances.
- **Encrypted files**: server-side encryption keys are instance-specific.
- **Federated shares/ACLs**: require cross-instance trust setup out of scope
  for a one-shot migration tool.

## Local validation

No PHP/Composer is required to review this code's syntax - if you have
Docker or Podman available:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli \
  sh -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Full runtime testing requires a Nextcloud dev instance (server + this app
installed under `apps/nextcloud_migrate`) since it depends on Nextcloud's
`OCP` APIs, DI container, and database migrations.

## End-to-end integration test

`tests/integration/e2e-two-instance.sh` spins up two fresh, throwaway
Nextcloud containers (source + target) on a private podman network,
installs this app on the source, and drives a full migration run through
the REST API + background jobs exactly like the admin UI would - covering
both the default "auto" mapping mode's create-a-new-target-user and
reset-an-existing-target-user's-password paths, and verifying the migrated
files land on the target with a matching SHA-256 checksum.

Requires `podman` on PATH. Safe to re-run any time - it always tears down
and recreates its containers from scratch (schema/data migration between
versions is a v2 concern, not handled during v1 development):

```bash
tests/integration/e2e-two-instance.sh
```

## Unit tests

`tests/Unit` contains PHPUnit tests for pure logic (collision/path mapping,
run state machine transitions) using minimal local stubs for the handful of
OCP base classes/interfaces needed to load the real production classes
(see `tests/stubs/OCP` and `tests/bootstrap.php`). Run with:

```bash
composer install
composer test
```

## Frontend build

The admin settings page is a Vue 3 app (`src/`) built with webpack via
`@nextcloud/webpack-vue-config`. Vue 3 (not NC28-core's own Vue 2) was
chosen deliberately: each app bundles its own isolated Vue instance (no
runtime shared with core), and the installed `@nextcloud/vue@9.9.0`'s
components (`NcTextField`, `NcButton`, etc.) are themselves written with
Vue-3-only Composition API features (`<script setup>`, `defineModel`) - so
despite `@nextcloud/vue`'s peer dependency nominally allowing Vue 2.7,
Vue 3 is the only version this specific component version is actually
built against. `vendor/` and the built `js/*.js` bundle
are both gitignored (not committed) - like `vendor/`, they must be
generated locally before the app will actually load in a real Nextcloud
instance, and regenerated after any `src/` change:

```bash
npm install
npm run build   # emits js/nextcloud_migrate-main.js (+ .map/.LICENSE.txt)
```

`lib/Settings/AdminSettings.php` loads it via
`Util::addScript('nextcloud_migrate', 'nextcloud_migrate-main')`. The
second argument must be the built file's full basename (including the
`{appId}-` prefix `@nextcloud/webpack-vue-config` always bakes into
`output.filename`), not just the webpack entry name (`main`) - passing
`'main'` looks for a nonexistent `js/main.js` and silently fails to load
any script at all (logged to `nextcloud.log` as `app: jsresourceloader,
"Could not find resource ... /js/main.js"`, no visible error otherwise).
See `JSResourceLocator::doFind()` in Nextcloud core for the exact fallback
file-lookup order this depends on. Component styles are plain
Vue SFC `<style scoped>` blocks bundled inline via `style-loader` (injected
at runtime), so there is no separate CSS file/`addStyle()` call to keep in
sync.

## Release automation

`.github/workflows/release.yml` automates the documented Nextcloud app
release flow when a GitHub release is published:

- validates that the release tag version matches `appinfo/info.xml` and
 `package.json`
- installs dependencies, runs the existing PHP checks, and builds the
 frontend bundle
- assembles a production tarball with the correct `nextcloud_migrate/`
 top-level directory
- signs the packaged app with `occ integrity:sign-app`
- uploads the `.tar.gz` archive to the GitHub release
- publishes the same archive to the Nextcloud App Store

Configure these GitHub Actions secrets before publishing a release:

- `APP_PRIVATE_KEY`: the app private key issued for `nextcloud_migrate`
- `APP_PUBLIC_CRT`: the matching Nextcloud signing certificate
- `APPSTORE_TOKEN`: a Nextcloud App Store API token

The workflow is configured to use a protected `release` environment for
those secrets and approvals, matching Nextcloud's recommended release setup.
