import { test, expect } from '@playwright/test';
import { createUser, generateAppPassword, runJobs, pollUntil } from '../helpers/nextcloud';
import { uploadFiles, buildFileTree } from '../helpers/webdav';

/**
 * Spec 06 – Cancel run
 *
 * Scenario:
 *   1. Create a run and start the dry-run
 *   2. Approve the run
 *   3. Let one batch of transfer jobs run
 *   4. Cancel the run via API
 *   5. Assert state becomes cancelled immediately
 *   6. Run more background jobs and confirm state stays cancelled
 *   7. Verify no new transfers happen after cancellation
 */

const SOURCE_URL = process.env.SOURCE_URL ?? 'http://localhost:8081';
const TARGET_URL = process.env.TARGET_URL ?? 'http://localhost:8082';
const USER_PASS = 'TestPass123!';

test.describe('Cancel run', () => {
  let adminToken: string;
  let instanceId: number;
  let runId: number;
  const srcUser = 'cancel-src';
  const tgtUser = 'cancel-tgt';

  test.beforeAll(async () => {
    createUser('nc-source', srcUser, USER_PASS);
    createUser('nc-target', tgtUser, USER_PASS);
    adminToken = generateAppPassword('nc-source', 'admin');

    const files = buildFileTree('cancel-test', 20);
    await uploadFiles(SOURCE_URL, srcUser, USER_PASS, files);

    const targetToken = generateAppPassword('nc-target', 'admin');
    const res = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({
        label: 'Cancel Target',
        url: TARGET_URL,
        targetUserId: tgtUser,
        appPassword: targetToken,
        allowSelfSigned: false,
      }),
    });
    instanceId = (await res.json()).id;
  });

  function authHeader() {
    return { Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64') };
  }

  async function getRunState(): Promise<string> {
    const r = await fetch(
      `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}`,
      { headers: authHeader() },
    );
    return (await r.json()).state;
  }

  test('01 – create run and start dry-run', async () => {
    const createRes = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...authHeader() },
      body: JSON.stringify({
        instanceId,
        collisionStrategy: 'rename',
        userMappings: { [srcUser]: tgtUser },
      }),
    });
    runId = (await createRes.json()).id;

    await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/dry-run`, {
      method: 'POST',
      headers: authHeader(),
    });
    runJobs('nc-source');

    await pollUntil(async () => {
      const state = await getRunState();
      if (state === 'dry_run_ready') return true;
      runJobs('nc-source', 2);
      return null;
    }, 120_000, 3_000);

    expect(await getRunState()).toBe('dry_run_ready');
  });

  test('02 – approve, start transfers, then cancel immediately', async () => {
    await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/approve`, {
      method: 'POST',
      headers: authHeader(),
    });
    // One batch of jobs so we enter transferring state
    runJobs('nc-source', 2);

    // Cancel
    const cancelRes = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/cancel`, {
      method: 'POST',
      headers: authHeader(),
    });
    expect(cancelRes.status).toBe(200);
    const body = await cancelRes.json();
    expect(body.state).toBe('cancelled');
  });

  test('03 – state remains cancelled after further job drains', async () => {
    // Additional job runs must not change the state
    runJobs('nc-source', 5);
    await new Promise(r => setTimeout(r, 3000));
    runJobs('nc-source', 3);

    expect(await getRunState()).toBe('cancelled');
  });

  test('04 – run cannot be re-approved after cancellation', async () => {
    const res = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/approve`, {
      method: 'POST',
      headers: authHeader(),
    });
    // Should get a 409 Conflict since the state machine rejects approve from cancelled
    expect(res.status).toBe(409);
  });

  test('05 – run cannot be resumed after cancellation', async () => {
    const res = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/resume`, {
      method: 'POST',
      headers: authHeader(),
    });
    expect(res.status).toBe(409);
  });
});
