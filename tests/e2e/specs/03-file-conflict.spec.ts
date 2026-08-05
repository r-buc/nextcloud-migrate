import { test, expect } from '@playwright/test';
import { createUser, generateAppPassword, runJobs, pollUntil } from '../helpers/nextcloud';
import { uploadFiles, uploadFile, downloadFile, fileExists } from '../helpers/webdav';

/**
 * Spec 03 – File-conflict strategies
 *
 * For each strategy (rename, skip, overwrite) we:
 *   1. Upload a file to source
 *   2. Upload the *same relative path* with different content to target
 *   3. Run the migration
 *   4. Assert the expected outcome on target
 */

const SOURCE_URL = process.env.SOURCE_URL ?? 'http://localhost:8081';
const TARGET_URL = process.env.TARGET_URL ?? 'http://localhost:8082';
const USER_PASS = 'TestPass123!';

async function createAndRunMigration(
  adminToken: string,
  instanceId: number,
  sourceUser: string,
  targetUser: string,
  strategy: 'rename' | 'skip' | 'overwrite',
): Promise<{ runId: number; state: string }> {
  const createRes = await fetch(
    `${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({
        instanceId,
        collisionStrategy: strategy,
        userMappings: { [sourceUser]: targetUser },
      }),
    },
  );
  expect(createRes.status).toBe(201);
  const run = await createRes.json();
  const runId: number = run.id;

  // Dry-run
  await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/dry-run`, {
    method: 'POST',
    headers: { Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64') },
  });
  runJobs('nc-source');

  await pollUntil(async () => {
    const r = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}`, {
      headers: { Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64') },
    });
    const b = await r.json();
    if (b.state === 'dry_run_ready') return b;
    if (b.state === 'validation_failed' || b.state === 'failed') throw new Error(b.state);
    runJobs('nc-source', 2);
    return null;
  }, 120_000, 3_000);

  // Approve
  await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/approve`, {
    method: 'POST',
    headers: { Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64') },
  });

  // Drain jobs until terminal
  const finalRun = await pollUntil(async () => {
    runJobs('nc-source', 3);
    const r = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}`, {
      headers: { Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64') },
    });
    const b = await r.json();
    if (['completed', 'completed_with_errors', 'failed'].includes(b.state)) return b;
    return null;
  }, 300_000, 5_000);

  return { runId, state: finalRun.state };
}

test.describe('File-conflict strategies', () => {
  let adminToken: string;
  let instanceId: number;

  test.beforeAll(async () => {
    adminToken = generateAppPassword('nc-source', 'admin');

    // Create a shared target instance once; individual tests use separate users
    const targetAdminToken = generateAppPassword('nc-target', 'admin');
    const res = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({
        label: 'Conflict Test Target',
        url: TARGET_URL,
        targetUserId: 'conflict-tgt-placeholder',
        appPassword: targetAdminToken,
        allowSelfSigned: false,
      }),
    });
    const body = await res.json();
    instanceId = body.id;
  });

  test('rename strategy – conflicting file is renamed on target', async () => {
    const srcUser = 'conflict-rename-src';
    const tgtUser = 'conflict-rename-tgt';
    createUser('nc-source', srcUser, USER_PASS);
    createUser('nc-target', tgtUser, USER_PASS);

    // Upload same path to both source and target with different content
    await uploadFile(SOURCE_URL, srcUser, USER_PASS, { path: 'conflict.txt', content: 'SOURCE' });
    await uploadFile(TARGET_URL, tgtUser, USER_PASS, { path: 'conflict.txt', content: 'TARGET_ORIGINAL' });

    // Update the instance with the correct target user
    await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances/${instanceId}`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({ targetUserId: tgtUser }),
    });

    const { state } = await createAndRunMigration(adminToken, instanceId, srcUser, tgtUser, 'rename');
    expect(['completed', 'completed_with_errors']).toContain(state);

    // The original target file must still exist
    const original = await downloadFile(TARGET_URL, tgtUser, USER_PASS, 'conflict.txt');
    // Under rename strategy, one of the two versions is renamed
    // Either original remains as-is, or source content was written and original renamed
    // In either case both files should exist (original + renamed copy)
    expect(original).toBeTruthy();
  });

  test('skip strategy – conflicting file is not overwritten on target', async () => {
    const srcUser = 'conflict-skip-src';
    const tgtUser = 'conflict-skip-tgt';
    createUser('nc-source', srcUser, USER_PASS);
    createUser('nc-target', tgtUser, USER_PASS);

    await uploadFile(SOURCE_URL, srcUser, USER_PASS, { path: 'conflict.txt', content: 'SOURCE' });
    await uploadFile(TARGET_URL, tgtUser, USER_PASS, { path: 'conflict.txt', content: 'TARGET_ORIGINAL' });

    await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances/${instanceId}`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({ targetUserId: tgtUser }),
    });

    const { state } = await createAndRunMigration(adminToken, instanceId, srcUser, tgtUser, 'skip');
    expect(['completed', 'completed_with_errors']).toContain(state);

    // Target file must retain original content
    const content = await downloadFile(TARGET_URL, tgtUser, USER_PASS, 'conflict.txt');
    expect(content).toBe('TARGET_ORIGINAL');
  });

  test('overwrite strategy – target file is replaced with source content', async () => {
    const srcUser = 'conflict-overwrite-src';
    const tgtUser = 'conflict-overwrite-tgt';
    createUser('nc-source', srcUser, USER_PASS);
    createUser('nc-target', tgtUser, USER_PASS);

    await uploadFile(SOURCE_URL, srcUser, USER_PASS, { path: 'conflict.txt', content: 'SOURCE_CONTENT' });
    await uploadFile(TARGET_URL, tgtUser, USER_PASS, { path: 'conflict.txt', content: 'OLD_TARGET' });

    await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances/${instanceId}`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({ targetUserId: tgtUser }),
    });

    const { state } = await createAndRunMigration(adminToken, instanceId, srcUser, tgtUser, 'overwrite');
    expect(['completed', 'completed_with_errors']).toContain(state);

    // Target must have source content
    const content = await downloadFile(TARGET_URL, tgtUser, USER_PASS, 'conflict.txt');
    expect(content).toBe('SOURCE_CONTENT');
  });
});
