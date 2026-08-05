import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the nextcloud_migrate E2E suite.
 *
 * Environment variables (set by the CI workflow or locally):
 *   SOURCE_URL  – base URL of the source Nextcloud (default: http://localhost:8081)
 *   TARGET_URL  – base URL of the target Nextcloud (default: http://localhost:8082)
 */
export default defineConfig({
  testDir: './specs',
  // Each spec file is independent; run them serially so they don't fight over
  // shared Nextcloud state. Parallelism within a file is controlled per-spec.
  fullyParallel: false,
  workers: 1,
  timeout: 5 * 60 * 1000,   // 5 min per test (background jobs need time)
  expect: { timeout: 30_000 },
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.SOURCE_URL ?? 'http://localhost:8081',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // Output folder for screenshots / videos
  outputDir: 'test-results',
});
