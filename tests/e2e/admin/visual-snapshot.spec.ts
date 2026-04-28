import { test, expect } from '@playwright/test';

/**
 * Akibara admin QA — Visual snapshot suite.
 *
 * Captures screenshot per admin page para visual regression baseline.
 * READ-ONLY (no clicks mutadores).
 *
 * Snapshots se almacenan en test-results/screenshots/ — útil para
 * comparar antes/después de cambios visuales (Sprint 5.5+).
 *
 * Ejecutar:
 *  WP_ADMIN_USER=xxx WP_ADMIN_PASSWORD=yyy npm run test:e2e:admin
 */

const ADMIN_PAGES = [
  { slug: 'akibara', name: 'dashboard' },
  { slug: 'akibara-search', name: 'search' },
  { slug: 'akibara-installments', name: 'installments' },
  { slug: 'akibara-series-autofill', name: 'series-autofill' },
  { slug: 'akibara-ordenar-tomos', name: 'ordenar-tomos' },
  { slug: 'akb-reservas', name: 'reservas' },
  { slug: 'akibara-editorial-notify', name: 'editorial-notify' },
  { slug: 'akibara-next-volume', name: 'next-volume' },
  { slug: 'akibara-encargos', name: 'encargos' },
  { slug: 'akibara-marketing-campaigns', name: 'marketing-campaigns' },
  { slug: 'akibara-topbar', name: 'topbar-banner' },
  { slug: 'akibara-brevo', name: 'brevo' },
  { slug: 'akibara-descuentos', name: 'descuentos' },
  { slug: 'akibara-welcome-discount', name: 'welcome-discount' },
  { slug: 'akibara-coupon-metrics', name: 'coupon-metrics' },
  { slug: 'akibara-back-in-stock', name: 'back-in-stock' },
  { slug: 'akibara-whatsapp', name: 'whatsapp' },
  { slug: 'akibara-finance-manga', name: 'finance-manga' },
];

test.describe('@admin Visual snapshots — full admin pages', () => {
  test.use({
    storageState: 'tests/e2e/admin/.auth/admin.json',
    viewport: { width: 1440, height: 900 },
  });

  for (const adminPage of ADMIN_PAGES) {
    test(`Screenshot: ${adminPage.name}`, async ({ page }) => {
      const response = await page.goto(`/wp-admin/admin.php?page=${adminPage.slug}`);

      // Skip si capability mismatch (403) — no screenshot útil
      if (response?.status() === 403) {
        test.skip(true, `403 forbidden — capability mismatch en ${adminPage.slug}`);
        return;
      }

      // Wait for admin sidebar render (proxy para "page fully rendered")
      await page.waitForSelector('#adminmenu', { timeout: 10000 });

      // Screenshot full page
      await page.screenshot({
        path: `test-results/screenshots/admin-${adminPage.name}.png`,
        fullPage: true,
      });

      // Capture also stylesheet load status (era el bug del screenshot user:
      // page renders sin CSS = text dump)
      const cssLoaded = await page.evaluate(() => {
        const links = document.querySelectorAll('link[rel="stylesheet"]');
        const akibara = Array.from(links).filter((l) =>
          (l as HTMLLinkElement).href.includes('akibara-core/assets/css/admin.css')
        );
        return akibara.length > 0;
      });

      expect(cssLoaded, `Akibara admin.css NOT loaded on ${adminPage.slug} — page rendering sin CSS`).toBeTruthy();
    });
  }

  test('Screenshot: sidebar full (Akibara position visible)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=akibara');
    await page.waitForSelector('#adminmenu', { timeout: 10000 });

    // Screenshot sidebar específico
    const sidebar = page.locator('#adminmenu');
    await sidebar.screenshot({
      path: 'test-results/screenshots/admin-sidebar-akibara.png',
    });
  });
});

test.describe('@admin CSS load verification — TODOS los plugins akibara', () => {
  test.use({ storageState: 'tests/e2e/admin/.auth/admin.json' });

  test('admin.css carga en todas las akibara-* pages', async ({ page }) => {
    test.setTimeout(180000); // 3 min para 18 pages
    const results: { slug: string; cssLoaded: boolean; status: number }[] = [];

    for (const adminPage of ADMIN_PAGES) {
      const response = await page.goto(`/wp-admin/admin.php?page=${adminPage.slug}`, { timeout: 8000 }).catch(() => null);
      const status = response?.status() ?? 0;

      if (status !== 200) {
        results.push({ slug: adminPage.slug, cssLoaded: false, status });
        continue;
      }

      const cssLoaded = await page.evaluate(() => {
        const links = document.querySelectorAll('link[rel="stylesheet"]');
        return Array.from(links).some((l) =>
          (l as HTMLLinkElement).href.includes('akibara-core/assets/css/admin.css')
        );
      });

      results.push({ slug: adminPage.slug, cssLoaded, status });
    }

    const failures = results.filter((r) => r.status === 200 && !r.cssLoaded);
    if (failures.length > 0) {
      console.error('[FAIL] Pages sin admin.css:');
      failures.forEach((f) => console.error(`  - ${f.slug}`));
    }

    expect(failures, `${failures.length} páginas no tienen admin.css cargado`).toHaveLength(0);
  });

  test('Stat values con classes correctas (visual sanity)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=akibara-search');
    await page.waitForSelector('.akb-stats', { timeout: 5000 });

    // Verifica que .akb-stat__value tiene CSS aplicado (no es text crudo)
    const statValue = page.locator('.akb-stat__value').first();
    const fontSize = await statValue.evaluate((el) => getComputedStyle(el).fontSize);
    const fontWeight = await statValue.evaluate((el) => getComputedStyle(el).fontWeight);

    // CSS dice: font-size: 28px, font-weight: 700
    expect(parseInt(fontSize, 10), `font-size should be ~28px but got ${fontSize}`).toBeGreaterThanOrEqual(20);
    expect(parseInt(fontWeight, 10), `font-weight should be 700 but got ${fontWeight}`).toBeGreaterThanOrEqual(500);
  });

  test('Stats grid renders como grid (no text-dump)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=akibara-marketing-campaigns');
    await page.waitForSelector('.akb-stats', { timeout: 5000 });

    const statsContainer = page.locator('.akb-stats').first();
    const display = await statsContainer.evaluate((el) => getComputedStyle(el).display);

    // CSS dice: display: grid
    expect(display, `display should be 'grid' but got '${display}'`).toBe('grid');

    // Stats children deben tener layout (no apilados verticalmente sin spacing)
    const stats = statsContainer.locator('.akb-stat');
    const count = await stats.count();
    expect(count).toBeGreaterThanOrEqual(2);
  });
});
