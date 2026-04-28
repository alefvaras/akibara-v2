import { test, expect } from '@playwright/test';
test.use({ storageState: 'tests/e2e/admin/.auth/admin.json' });
test('ML Auth page renders with content', async ({ page }) => {
  const r = await page.goto('/wp-admin/admin.php?page=akibara-ml-auth');
  expect(r?.status()).toBe(200);
  await expect(page.locator('h1.akb-page-header__title')).toContainText(/MercadoLibre/);
  await expect(page.locator('.akb-stats')).toBeVisible();
  const html = await page.content();
  expect(html).not.toMatch(/Fatal error/i);
});
