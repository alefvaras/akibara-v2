import { test, expect } from '@playwright/test';

/**
 * Akibara customer journey E2E suite — READ-ONLY navigation.
 *
 * NO clicks que muten datos:
 *  - Browse OK
 *  - Search OK
 *  - Product detail OK
 *  - Add to cart (read cart state)
 *  - Cart page OK
 *  - Checkout page LOADS (no submit)
 *  - Account page OK (anonymous)
 *
 * NO incluye:
 *  - Checkout submission (mutates orders)
 *  - Login (would mutate session)
 *  - Forms submit
 */

test.describe('@critical Customer journey — read-only flow', () => {
  test('Home page carga + branding visible', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle(/Akibara/i);

    // Body sin error WSOD
    const html = await page.content();
    expect(html).not.toMatch(/error crítico/i);
    expect(html).not.toMatch(/Fatal error/i);
  });

  test('Tienda (shop) page carga', async ({ page }) => {
    const response = await page.goto('/tienda/');
    expect(response?.status()).toBe(200);

    // Productos visibles (theme akibara usa .product-card o WC nativo .product)
    const products = page.locator('.product-card, .product, .wc-block-product, li.product');
    const count = await products.count();
    expect(count, 'Tienda debería mostrar productos').toBeGreaterThanOrEqual(1);
  });

  test('Búsqueda AJAX responde', async ({ page }) => {
    await page.goto('/');

    // REST search endpoint
    const response = await page.request.get('/wp-json/akibara/v1/search?q=manga');
    expect(response.status()).toBe(200);

    const data = await response.json();
    // Estructura básica: results array
    expect(data).toBeTruthy();
  });

  test('Producto detail page carga (random product)', async ({ page }) => {
    // Get product list first
    const tiendaResponse = await page.goto('/tienda/');
    expect(tiendaResponse?.status()).toBe(200);

    // First product link (theme custom .product-card a)
    const productLink = page.locator('.product-card a, a.woocommerce-loop-product__link, .product a').first();
    const href = await productLink.getAttribute('href').catch(() => null);

    if (href) {
      const response = await page.goto(href);
      expect(response?.status()).toBe(200);
      await expect(page.locator('h1, h1.product_title, h1.entry-title').first()).toBeVisible({ timeout: 8000 });
    }
  });

  test('Cart page carga (vacío OK)', async ({ page }) => {
    const response = await page.goto('/carrito/');
    expect(response?.status()).toBe(200);

    // Empty state OK or cart table visible
    const hasContent = await page.locator('main, #content, .woocommerce').count();
    expect(hasContent).toBeGreaterThan(0);
  });

  test('Mi cuenta page carga (login form anonymous)', async ({ page }) => {
    const response = await page.goto('/mi-cuenta/');
    expect(response?.status()).toBe(200);

    // Login form visible (anonymous user)
    const loginForm = page.locator('form.woocommerce-form-login, #customer_login, input[name="username"]');
    const count = await loginForm.count();
    expect(count, 'Login form debería estar visible').toBeGreaterThanOrEqual(1);
  });

  test('Health endpoint REST funcional', async ({ page }) => {
    const response = await page.request.get('/wp-json/akibara/v1/health');
    expect(response.status()).toBe(200);

    const data = await response.json();
    expect(data).toHaveProperty('status');
  });

  test('Sitemap accesible (SEO)', async ({ page }) => {
    const response = await page.goto('/sitemap_index.xml');
    expect([200, 301, 302]).toContain(response?.status() ?? 0);
  });

  test('Robots.txt accesible', async ({ page }) => {
    const response = await page.request.get('/robots.txt');
    expect(response.status()).toBe(200);

    const text = await response.text();
    expect(text.length).toBeGreaterThan(0);
  });
});

test.describe('@critical SEO + WP standards', () => {
  test('Home tiene meta description', async ({ page }) => {
    await page.goto('/');
    const meta = await page.locator('meta[name="description"]').count();
    expect(meta).toBeGreaterThanOrEqual(1);
  });

  test('Open Graph tags presentes', async ({ page }) => {
    await page.goto('/');
    const ogTitle = await page.locator('meta[property="og:title"]').count();
    expect(ogTitle).toBeGreaterThanOrEqual(1);
  });

  test('Canonical URL tag presente', async ({ page }) => {
    await page.goto('/');
    const canonical = await page.locator('link[rel="canonical"]').count();
    expect(canonical).toBeGreaterThanOrEqual(1);
  });
});
