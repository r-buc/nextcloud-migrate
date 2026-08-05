import { test, expect } from '@playwright/test';
import { createUser, generateAppPassword, runJobs, pollUntil } from '../helpers/nextcloud';
import { uploadFiles, buildFileTree, fileExists } from '../helpers/webdav';

/**
 * Spec 02 – Happy-path migration
 *
 * Full lifecycle:
 *   1. Seed source user with a folder tree
 *   2. Create a target instance (via API)
 *   3. Test the connection
 *   4. Create a migration run
 *   5. Trigger dry-run, wait for dry_run_ready
 *   6. Verify dry-run report shows correct file count
 *   7. Approve run, drain background jobs, poll until completed
 *   8. Verify all files exist on target via WebDAV
 */

const SOURCE_URL = process.env.SOURCE_URL ?? 'http://localhost:8081';
const TARGET_URL = process.env.TARGET_URL ?? 'http://localhost:8082';

const SOURCE_USER = 'e2e-happy-src';
const TARGET_USER = 'e2e-happy-tgt';
const USER_PASS = 'TestPass123!';
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'OCS-APIREQUEST': 'true',
      Authorization: 'Basic ' + Buffer.from(`admin:${token}`).toString('base64'),
    },
    body: JSON.stringify(body),
  });
  return res;
}

async function apiGet(url: string, token: string) {
  return fetch(url, {
    headers: {
      Authorization: 'Basic ' + Buffer.from(`admin:${token}`).toString('base64'),
    },
  });
}

test.describe('Happy-path migration', () => {
  let adminToken: string;
  let instanceId: number;
  let runId: number;

  test.beforeAll(async () => {
    // Create source and target users
    createUser('nc-source', SOURCE_USER, USER_PASS);
    createUser('nc-target', TARGET_USER, USER_PASS);

    // Generate an app-password for admin API calls
    adminToken = generateAppPassword('nc-source', 'admin');

    // Seed source user with 12 files in a nested folder structure
    const files = buildFileTree('migration-test', 12);
    await uploadFiles(SOURCE_URL, SOURCE_USER, USER_PASS, files);
  });

  test('01 – create target instance', async () => {
    const targetAdminToken = generateAppPassword('nc-target', 'admin');
    const res = await apiPost(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances`,
      {
        label: 'E2E Target',
        url: TARGET_URL,
        targetUserId: TARGET_USER,
        appPassword: targetAdminToken,
        allowSelfSigned: false,
      },
      adminToken,
    );
    expect(res.status).toBe(201);
    const body = await res.json();
    instanceId = body.id;
    expect(instanceId).toBeGreaterThan(0);
  });

  test('02 – test connection', async () => {
    const res = await apiPost(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances/${instanceId}/test`,
      {},
      adminToken,
    );
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
  });

  test('03 – create migration run', async () => {
    const res = await apiPost(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs`,
      {
        instanceId,
        collisionStrategy: 'rename',
        userMappings: { [SOURCE_USER]: TARGET_USER },
      },
      adminToken,
    );
    expect(res.status).toBe(201);
    const body = await res.json();
    runId = body.id;
    expect(runId).toBeGreaterThan(0);
  });

  test('04 – dry run reaches dry_run_ready', async () => {
    // Trigger dry-run
    const triggerRes = await apiPost(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/dry-run`,
      {},
      adminToken,
    );
    expect(triggerRes.status).toBe(200);

    // Drain background jobs (DiscoveryJob)
    runJobs('nc-source');

    // Poll until state is dry_run_ready
    const run = await pollUntil(async () => {
      const r = await apiGet(
        `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}`,
        adminToken,
      );
      const body = await r.json();
      if (body.state === 'dry_run_ready') return body;
      if (body.state === 'validation_failed' || body.state === 'failed') {
        throw new Error(`Run reached terminal error state: ${body.state}`);
      }
      return null;
    }, 120_000, 3_000);

    expect(run.state).toBe('dry_run_ready');
  });

  test('05 – dry-run report contains expected file count', async () => {
    const res = await apiGet(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/report`,
      adminToken,
    );
    const body = await res.json();
    // The dry-run report should list the discovered files
    expect(body.dryRunReport).not.toBeNull();
    // At least 12 files discovered (may include implicit folder entries)
    const totalDiscovered = body.dryRunReport?.totalFiles ?? 0;
    expect(totalDiscovered).toBeGreaterThanOrEqual(12);
  });

  test('06 – approve run and wait for completed', async () => {
    const approveRes = await apiPost(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/approve`,
      {},
      adminToken,
    );
    expect(approveRes.status).toBe(200);

    // Drain jobs repeatedly: EnqueueTransfersJob, TransferWorkerJob, VerifyWorkerJob, FinalizeJob
    for (let i = 0; i < 10; i++) {
      runJobs('nc-source', 5);
    }

    const run = await pollUntil(async () => {
      const r = await apiGet(
        `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}`,
        adminToken,
      );
      const body = await r.json();
      if (body.state === 'completed' || body.state === 'completed_with_errors') return body;
      if (body.state === 'failed') throw new Error('Run failed');
      // Keep draining jobs while waiting
      runJobs('nc-source', 3);
      return null;
    }, 300_000, 5_000);

    expect(['completed', 'completed_with_errors']).toContain(run.state);
  });

  test('07 – all migrated files exist on target', async () => {
    const files = buildFileTree('migration-test', 12);
    for (const f of files) {
      const exists = await fileExists(TARGET_URL, TARGET_USER, USER_PASS, f.path);
      expect(exists, `Expected ${f.path} to exist on target`).toBe(true);
    }
  });
});
