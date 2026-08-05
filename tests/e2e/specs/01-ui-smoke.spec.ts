import { test, expect } from '@playwright/test';

/**
 * Spec 01 – UI Smoke
 *
 * Verifies that the admin settings page for nextcloud_migrate loads correctly,
 * that the key UI sections are visible, and that the instance-creation form
 * and migration-runs table are present.
 *
 * Prerequisites (set up by the CI workflow / global setup):
 *   - nc-source has the app enabled
 *   - The admin user exists with password "adminpass"
 */

const SOURCE_URL = process.env.SOURCE_URL ?? 'http://localhost:8081';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'adminpass';

test.describe('UI Smoke', () => {
  test.beforeEach(async ({ page }) => {
    // Log in as admin
    await page.goto(`${SOURCE_URL}/login`);
    await page.fill('input[name="user"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('input[type="submit"], button[type="submit"]');
    await page.waitForURL(/\/(dashboard|index\.php\/dashboard|apps\/)/);
  });

  test('admin settings page loads', async ({ page }) => {
    await page.goto(`${SOURCE_URL}/settings/admin/nextcloud_migrate`);
    await expect(page.locator('h2')).toContainText('Migrate to another instance');
  });

  test('target instances section is visible', async ({ page }) => {
    await page.goto(`${SOURCE_URL}/settings/admin/nextcloud_migrate`);
    await expect(page.locator('#ncm-instances')).toBeVisible();
    await expect(page.locator('#ncm-instances-table')).toBeVisible();
  });

  test('add target instance form is visible', async ({ page }) => {
    await page.goto(`${SOURCE_URL}/settings/admin/nextcloud_migrate`);
    await expect(page.locator('#ncm-create-instance-form')).toBeVisible();
    await expect(page.locator('input[name="label"]')).toBeVisible();
    await expect(page.locator('input[name="url"]')).toBeVisible();
    await expect(page.locator('input[name="appPassword"]')).toBeVisible();
  });

  test('migration runs section is visible', async ({ page }) => {
    await page.goto(`${SOURCE_URL}/settings/admin/nextcloud_migrate`);
    await expect(page.locator('#ncm-runs')).toBeVisible();
    await expect(page.locator('#ncm-runs-table')).toBeVisible();
  });

  test('create run form has collision strategy options', async ({ page }) => {
    await page.goto(`${SOURCE_URL}/settings/admin/nextcloud_migrate`);
    const select = page.locator('select[name="collisionStrategy"]');
    await expect(select).toBeVisible();
    await expect(select.locator('option[value="rename"]')).toHaveCount(1);
    await expect(select.locator('option[value="skip"]')).toHaveCount(1);
    await expect(select.locator('option[value="overwrite"]')).toHaveCount(1);
  });
});
