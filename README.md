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
  Redis/external queue). Self-perpetuating worker pools
  (`TransferWorkerJob`, `VerifyWorkerJob`) keep a constant number of jobs
  in flight, claiming one file at a time via DB-row locking
  (`lock_owner`/`lock_expires_at`), with `CleanupLocksJob` reclaiming rows
  left behind by a crashed worker.
- **Run lifecycle**: `CREATED -> VALIDATING -> DISCOVERING -> DRY_RUN_READY
  -> APPROVED -> TRANSFERRING -> VERIFYING -> FINALIZING -> COMPLETED |
  COMPLETED_WITH_ERRORS`, with `PAUSED`/`CANCELLED`/`VALIDATION_FAILED`
  branches. See `RunOrchestrator` for the full transition graph.
- **Collision handling**: resolved inline per-file during transfer
  (`MappingService`), not as a separate bulk pre-pass, to avoid an extra
  PROPFIND round trip per file. Strategies: `rename` (default), `skip`,
  `overwrite`.

## Key classes

| Concern | Class |
|---|---|
| Run state machine | `Service\RunOrchestrator` |
| Local tree walk | `Service\DiscoveryService` |
| Collision resolution | `Service\MappingService` |
| Upload (simple + chunked) | `Service\TransferService` |
| Checksum verification | `Service\VerificationService` |
| Target WebDAV calls | `Service\WebDavClient` |
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

## Unit tests

`tests/Unit` contains PHPUnit tests for pure logic (collision/path mapping,
run state machine transitions) using minimal local stubs for the handful of
OCP base classes/interfaces needed to load the real production classes
(see `tests/stubs/OCP` and `tests/bootstrap.php`). Run with:

```bash
composer install
composer test
```
