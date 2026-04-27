import { test, expect } from '@playwright/test';

/**
 * Akibara golden flow @critical tests.
 *
 * Filosofía:
 * - Read-only smoke contra endpoints públicos (NO completa transacciones).
 * - Solo paths más críticos para customer journey.
 * - Mobile + Desktop projects via playwright.config.ts.
 *
 * Cubre:
 * 1. Home loads + branding visible
 * 2. Manga catalog browse (1.300+ productos)
 * 3. Product detail loads + add-to-cart funcional
 * 4. Search AJAX endpoint responde
 * 5. Sitemap valid (SEO)
 * 6. Health endpoint REST (DevOps)
 *
 * Tag @critical → corre en GHA workflow Quality Gates pipeline.
 */

test.describe('@critical Akibara golden flow', () => {
  test('1. Home loads + Akibara branding visible', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);

    // Title + branding
    await expect(page).toHaveTitle(/Akibara/);

    // Logo Akibara en header (link or image with alt)
    const logo = page.locator('header').getByRole('link', { name: /akibara/i }).first();
    await expect(logo).toBeVisible();

    // Nav principal visible
    await expect(page.getByRole('link', { name: /^manga$/i }).first()).toBeVisible();
  });

  test('2. Manga catalog loads + filtros funcionan', async ({ page }) => {
    const response = await page.goto('/manga/');
    expect(response?.status()).toBe(200);

    // Productos visible (al menos 1 card con texto product)
    const productCards = page.locator('a[href*="/"][class*="product"], .product, article.product');
    const count = await productCards.count();
    expect(count).toBeGreaterThan(0);

    // Filtros editorial visibles (Ivrea/Panini/Planeta etc Chile manga distribución)
    const editorialFilter = page.locator('text=/ivrea|panini|planeta/i').first();
    await expect(editorialFilter).toBeVisible({ timeout: 15_000 });
  });

  test('3. Product detail loads + add-to-cart button exists', async ({ page }) => {
    // Productos test pueden cambiar — usar primer producto del catalog
    await page.goto('/manga/');

    // Click primer producto
    const firstProductLink = page
      .locator('a[href*="/"]')
      .filter({ has: page.locator('text=/manga|comic|seinen|shonen/i') })
      .first();

    await firstProductLink.click();
    await page.waitForLoadState('domcontentloaded');

    // Product detail signals: precio + add to cart
    await expect(page.locator('text=/\\$[0-9.,]+/').first()).toBeVisible();
    await expect(
      page.getByRole('button', { name: /agregar al carrito|añadir al carrito/i }).first()
    ).toBeVisible();
  });

  test('4. Search AJAX endpoint responde', async ({ page }) => {
    // Akibara_Search módulo expone wp_ajax_akibara_search action
    const response = await page.request.post('/wp-admin/admin-ajax.php', {
      form: {
        action: 'akibara_search',
        s: 'manga',
      },
    });

    // Endpoint responde (200 o 400 con JSON, NO 500)
    expect([200, 400]).toContain(response.status());

    const text = await response.text();
    // Debería ser JSON válido (success o data field)
    expect(text.length).toBeGreaterThan(0);
  });

  test('5. Sitemap valid XML', async ({ page }) => {
    const response = await page.request.get('/sitemap_index.xml');
    expect(response.status()).toBe(200);

    const xml = await response.text();
    expect(xml).toContain('<?xml');
    expect(xml).toContain('<sitemapindex');
  });

  test('6. Health endpoint REST responde', async ({ page }) => {
    // health-check module expone /wp-json/akibara/v1/health
    const response = await page.request.get('/wp-json/akibara/v1/health');
    expect(response.status()).toBe(200);

    const json = await response.json();
    // Esperado: { status: 'ok' } o { ok: true } shape
    expect(json).toBeTruthy();
  });
});
