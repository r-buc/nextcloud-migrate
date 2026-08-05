import { execSync } from 'child_process';

/**
 * Nextcloud helpers that wrap `docker exec` calls to `occ` on the source or
 * target container. These are used in global setup and test fixtures to
 * provision users, generate app-passwords, and drive background jobs.
 */

export type Container = 'nc-source' | 'nc-target';

/**
 * Run an occ command inside the given container and return trimmed stdout.
 * Commands are run as the www-data user so file ownership is correct.
 */
export function occ(container: Container, ...args: string[]): string {
  const cmd = [
    'docker', 'exec', '--user', 'www-data', container,
    'php', 'occ', '--no-ansi', '--no-interaction',
    ...args,
  ].join(' ');
  return execSync(cmd, { encoding: 'utf8' }).trim();
}

/**
 * Create a Nextcloud user with a known password.
 * Idempotent – silently returns if the user already exists.
 */
export function createUser(container: Container, userId: string, password: string): void {
  try {
    execSync(
      `docker exec --user www-data -e OC_PASS=${password} ${container} php occ --no-ansi --no-interaction user:add --password-from-env --display-name ${userId} ${userId}`,
      { encoding: 'utf8' },
    );
  } catch {
    // user already exists – ignore
  }
  // Always set/reset the password so tests are deterministic
  execSync(
    `docker exec --user www-data -e OC_PASS=${password} ${container} php occ --no-ansi --no-interaction user:resetpassword --password-from-env ${userId}`,
    { encoding: 'utf8' },
  );
}

/**
 * Generate an app-password for a user and return the token string.
 * Uses `occ user:auth-tokens:add` (NC 26+).
 */
export function generateAppPassword(container: Container, userId: string): string {
  const out = occ(container, 'user:auth-tokens:add', '--password-auth', userId);
  // Output format: "New app-password token created:\n<token>"
  const match = out.match(/([A-Za-z0-9\-_]{72,})/);
  if (!match) throw new Error(`Could not parse app-password from occ output: ${out}`);
  return match[1];
}

/**
 * Enable the nextcloud_migrate app on the source container.
 */
export function enableApp(container: Container = 'nc-source'): void {
  occ(container, 'app:enable', 'nextcloud_migrate');
}

/**
 * Trigger background jobs synchronously (drain the cron queue) so tests
 * don't have to wait for real cron intervals.
 *
 * Runs `occ background:cron` which processes all due jobs in the queue.
 */
export function runJobs(container: Container = 'nc-source', maxRounds = 20): void {
  for (let i = 0; i < maxRounds; i++) {
    occ(container, 'background:cron');
  }
}

/** Build the API v1 base URL for the source Nextcloud. */
export function apiUrl(base: string, path: string): string {
  return `${base}/apps/nextcloud_migrate/api/v1${path}`;
}

/** Return headers for an admin API request (using Nextcloud's basic-auth token mechanism). */
export function adminHeaders(adminToken: string): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    Authorization: `Basic ${Buffer.from(`admin:${adminToken}`).toString('base64')}`,
    // CSRF is not required for non-browser fetch; Nextcloud skips it for Basic auth
  };
}

/**
 * Poll `fn` every `intervalMs` ms until it returns a truthy value or
 * `timeoutMs` is exceeded, then return the value.
 * Throws if the timeout is reached.
 */
export async function pollUntil<T>(
  fn: () => Promise<T | null | undefined | false>,
  timeoutMs = 120_000,
  intervalMs = 3_000,
): Promise<T> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const result = await fn();
    if (result) return result as T;
    await new Promise(r => setTimeout(r, intervalMs));
  }
  throw new Error(`pollUntil timed out after ${timeoutMs}ms`);
}
