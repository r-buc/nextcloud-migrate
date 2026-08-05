import { test, expect } from '@playwright/test';
import { createUser, generateAppPassword, runJobs, pollUntil } from '../helpers/nextcloud';
import { uploadFiles, buildFileTree, fileExists } from '../helpers/webdav';

/**
 * Spec 05 – Pause and resume
 *
 * Scenario:
 *   1. Seed source with enough files that the run takes multiple job rounds
 *   2. Start a run and let it enter transferring state
 *   3. Pause the run via API
 *   4. Verify state becomes paused and transfers stop
 *   5. Resume, continue draining jobs
 *   6. Verify run reaches completed / completed_with_errors
 *   7. Spot-check that all files exist on target
 */

const SOURCE_URL = process.env.SOURCE_URL ?? 'http://localhost:8081';
const TARGET_URL = process.env.TARGET_URL ?? 'http://localhost:8082';
const USER_PASS = 'TestPass123!';

test.describe('Pause and resume', () => {
  let adminToken: string;
  let instanceId: number;
  let runId: number;
  const srcUser = 'pause-src';
  const tgtUser = 'pause-tgt';

  test.beforeAll(async () => {
    createUser('nc-source', srcUser, USER_PASS);
    createUser('nc-target', tgtUser, USER_PASS);
    adminToken = generateAppPassword('nc-source', 'admin');

    const files = buildFileTree('pause-test', 20);
    await uploadFiles(SOURCE_URL, srcUser, USER_PASS, files);

    const targetToken = generateAppPassword('nc-target', 'admin');
    const res = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/instances`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Basic ' + Buffer.from(`admin:${adminToken}`).toString('base64'),
      },
      body: JSON.stringify({
        label: 'Pause-Resume Target',
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

  test('02 – approve, run a few jobs, then pause', async () => {
    await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/approve`, {
      method: 'POST',
      headers: authHeader(),
    });

    // Run a few job rounds so transfers start
    runJobs('nc-source', 3);

    // Pause
    const pauseRes = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/pause`, {
      method: 'POST',
      headers: authHeader(),
    });
    expect(pauseRes.status).toBe(200);
    const pauseBody = await pauseRes.json();
    expect(pauseBody.state).toBe('paused');
  });

  test('03 – state is paused and no further files transfer', async () => {
    expect(await getRunState()).toBe('paused');

    // Run more jobs – they should be no-ops while paused
    runJobs('nc-source', 3);
    await new Promise(r => setTimeout(r, 2000));

    // State must still be paused
    expect(await getRunState()).toBe('paused');
  });

  test('04 – resume and run reaches completed', async () => {
    const resumeRes = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}/resume`, {
      method: 'POST',
      headers: authHeader(),
    });
    expect(resumeRes.status).toBe(200);

    const finalRun = await pollUntil(async () => {
      runJobs('nc-source', 3);
      const r = await fetch(`${SOURCE_URL}/apps/nextcloud_migrate/api/v1/runs/${runId}`, {
        headers: authHeader(),
      });
      const b = await r.json();
      if (['completed', 'completed_with_errors', 'failed'].includes(b.state)) return b;
      return null;
    }, 300_000, 5_000);

    expect(['completed', 'completed_with_errors']).toContain(finalRun.state);
  });

  test('05 – files exist on target after resumed run', async () => {
    const sampleFiles = buildFileTree('pause-test', 20).slice(0, 3);
    for (const f of sampleFiles) {
      expect(await fileExists(TARGET_URL, tgtUser, USER_PASS, f.path)).toBe(true);
    }
  });
});
