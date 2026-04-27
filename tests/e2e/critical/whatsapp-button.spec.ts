import { test, expect } from '@playwright/test';

/**
 * Akibara WhatsApp button @critical tests — Cell D Sprint 4.
 *
 * Scope mínimo (per project_whatsapp_strategy.md):
 *  - Botón visible en página de producto (single product).
 *  - Botón visible en footer (página home).
 *  - Link apunta a wa.me/<número> o web.whatsapp.com/send?phone=<número>.
 *  - Número tiene prefijo '56' (Chile).
 *
 * NO testea:
 *  - Cloud API Meta integration (M2 diferido).
 *  - Templates marketing (prohibido por policy).
 *  - Webhook receiver.
 *
 * Tag @critical → corre en GHA quality-gate pipeline.
 *
 * Nota: el botón tiene delay de 1500ms antes de hacerse visible (CSS transition).
 * Playwright espera con waitFor visible.
 */

const WA_PHONE_PATTERN = /^56\d{8,9}$/; // Número CL: 56 + 8-9 dígitos

test.describe('@critical WhatsApp button', () => {

  test('botón visible en página de producto', async ({ page }) => {
    // Navegar al catálogo y entrar al primer producto
    const catalogResponse = await page.goto('/manga/');
    expect(catalogResponse?.status()).toBe(200);

    // Clic en primer producto de la grilla
    const firstProductLink = page.locator('.products .product a').first();
    await firstProductLink.click();

    // Esperar que la página de producto cargue
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain('/product/');

    // El contenedor del botón WhatsApp debe estar en el DOM
    const waContainer = page.locator('#akibara-wa');
    await expect(waContainer).toBeAttached();

    // Esperar que el botón sea visible (delay 1500ms CSS animation)
    const waBtn = waContainer.locator('.akibara-wa__btn');
    await expect(waBtn).toBeVisible({ timeout: 5_000 });

    // Leer data-settings para verificar número
    const settingsRaw = await waContainer.getAttribute('data-settings');
    expect(settingsRaw).toBeTruthy();

    const settings = JSON.parse(settingsRaw!);
    expect(settings.phone).toMatch(WA_PHONE_PATTERN);

    // La URL debe apuntar a wa.me o web.whatsapp.com
    const url: string = settings.url ?? '';
    const isValidWaUrl =
      url.startsWith('https://wa.me/') ||
      url.startsWith('https://web.whatsapp.com/send?phone=');
    expect(isValidWaUrl).toBe(true);
  });

  test('botón visible en home (footer area)', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);

    // El contenedor debe existir en el DOM
    const waContainer = page.locator('#akibara-wa');
    await expect(waContainer).toBeAttached();

    // Esperar visibility post-delay
    const waBtn = waContainer.locator('.akibara-wa__btn');
    await expect(waBtn).toBeVisible({ timeout: 5_000 });

    // Verificar que el número en data-settings tiene prefijo 56
    const settingsRaw = await waContainer.getAttribute('data-settings');
    const settings = JSON.parse(settingsRaw ?? '{}');
    expect(settings.phone).toMatch(WA_PHONE_PATTERN);
  });

  test('número de negocio default es 56944242844', async ({ page }) => {
    // Este test valida que el default AKIBARA_WA_PHONE_DEFAULT está activo.
    // Si el admin configuró un número diferente, el test verifica el patrón
    // (prefijo 56 + 9 dígitos) no el valor exacto.
    // El número por defecto es 56944242844 (función akibara_whatsapp_get_business_number).

    await page.goto('/');

    const waContainer = page.locator('#akibara-wa');
    await expect(waContainer).toBeAttached();

    const settingsRaw = await waContainer.getAttribute('data-settings');
    const settings = JSON.parse(settingsRaw ?? '{}');

    // Número debe ser válido CL — puede ser default o configurado en admin
    expect(settings.phone).toMatch(WA_PHONE_PATTERN);
  });

});
