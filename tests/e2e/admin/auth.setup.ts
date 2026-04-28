import { test as setup, expect } from '@playwright/test';
import path from 'path';

/**
 * Akibara admin QA — Auth setup.
 *
 * Login via wp-login.php form POST + saveState para reusar en specs.
 *
 * READ-ONLY policy (mesa-23 + user "no clicklea"):
 *  - SOLO login (necesario para acceso admin).
 *  - Cero clicks que muten data downstream.
 *  - storageState reutilizado por todos los admin specs.
 *
 * Env vars requeridas:
 *  - WP_ADMIN_USER: username admin
 *  - WP_ADMIN_PASSWORD: password admin
 *  - PLAYWRIGHT_BASE_URL: opcional, default https://akibara.cl
 */

const WP_USER = process.env.WP_ADMIN_USER;
const WP_PASS = process.env.WP_ADMIN_PASSWORD;

const authFile = path.join(__dirname, '.auth/admin.json');

setup('authenticate as admin', async ({ page }) => {
  if (!WP_USER || !WP_PASS) {
    setup.skip(true, 'WP_ADMIN_USER / WP_ADMIN_PASSWORD env vars no provistos');
    return;
  }

  await page.goto('/wp-login.php');
  await expect(page).toHaveURL(/wp-login\.php/);

  // Fill login form (read-only del POV del DB — esto NO muta data, solo crea sesión).
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass', WP_PASS);
  await page.click('#wp-submit');

  // Wait for admin redirect.
  await page.waitForURL(/wp-admin/, { timeout: 10000 });

  // Verify admin sidebar present (post-login state).
  await expect(page.locator('#adminmenu')).toBeVisible();

  // Save storage state for downstream specs.
  await page.context().storageState({ path: authFile });
});
