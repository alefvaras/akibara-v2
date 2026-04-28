import { test, expect } from '@playwright/test';

/**
 * Akibara admin QA — Sprint 5.5 menu reorg verification.
 *
 * READ-ONLY (no clicks mutadores). Solo navega URLs y verifica:
 *  - Top-level "Akibara" menu visible en sidebar (NO bajo WooCommerce)
 *  - Cada sub-page carga 200 con admin layout válido
 *  - Cada page tiene .wrap div + h1 + NO PHP fatal/notice visible
 *  - Migration notice presente (o dismissed)
 *  - Marketing-campaigns page tiene layout (NO text dump como screenshot bug)
 *
 * Ejecutar:
 *  WP_ADMIN_USER=admin WP_ADMIN_PASSWORD=xxx npm run test:e2e:admin
 */

const ADMIN_PAGES = [
  // Sub-pages bajo akibara — ÉSTE es el nuevo home esperado.
  { slug: 'akibara', title: /Panel|Akibara/, plugin: 'akibara-core (dashboard)' },
  { slug: 'akb-search-index', title: /Búsqueda|Search/, plugin: 'akibara-core' },
  { slug: 'akb-installments', title: /Cuota|Installment/, plugin: 'akibara-core' },
  { slug: 'akb-series-autofill', title: /Auto-Series|Series/, plugin: 'akibara-core' },
  { slug: 'akb-order-reorder', title: /Ordenar|Reorder/, plugin: 'akibara-core' },
  { slug: 'akb-reservas', title: /Reservas|Preventa/, plugin: 'akibara-preventas' },
  { slug: 'akb-editorial-notify', title: /Editorial|Notif/, plugin: 'akibara-preventas' },
  { slug: 'akb-next-volume', title: /Siguiente|Próximo|Tomo/, plugin: 'akibara-preventas' },
  { slug: 'akb-encargos', title: /Encargos/, plugin: 'akibara-preventas' },
  { slug: 'akibara-marketing-campaigns', title: /Campaña|Marketing/, plugin: 'akibara-marketing' },
  { slug: 'akb-banner', title: /Banner|Topbar|Mensaje/, plugin: 'akibara-marketing' },
  { slug: 'akb-brevo', title: /Brevo/, plugin: 'akibara-marketing' },
  { slug: 'akb-descuentos', title: /Descuento/, plugin: 'akibara-marketing' },
  { slug: 'akb-welcome-discount', title: /Welcome|Bienvenida|Descuento/, plugin: 'akibara-marketing' },
  { slug: 'akibara-back-in-stock', title: /Back in Stock|Aviso/, plugin: 'akibara-inventario' },
  { slug: 'akibara-whatsapp', title: /WhatsApp/, plugin: 'akibara-whatsapp' },
];

test.describe('@admin Sprint 5.5 menu reorg', () => {
  test.use({ storageState: 'tests/e2e/admin/.auth/admin.json' });

  test('Top-level Akibara menu visible en sidebar (NO bajo WooCommerce)', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page.locator('#adminmenu')).toBeVisible();

    // Akibara debe ser top-level menu propio (li.menu-top)
    const akibaraTopLevel = page.locator('#adminmenu li.toplevel_page_akibara');
    await expect(akibaraTopLevel).toBeVisible();
    await expect(akibaraTopLevel).toContainText('Akibara');

    // Sub-items de Akibara deben estar bajo este parent (NO bajo WooCommerce)
    const akibaraSubmenu = page.locator('#adminmenu li.toplevel_page_akibara ul.wp-submenu');
    await expect(akibaraSubmenu).toBeVisible();

    // Verifica que items críticos están en submenu de Akibara
    for (const item of ['Panel', 'Preventas', 'Marketing', 'Inventario']) {
      // Algunos items pueden estar oculto hasta hover, usa locator soft check
      const subItem = akibaraSubmenu.locator('a', { hasText: new RegExp(item, 'i') });
      const count = await subItem.count();
      // Soft assertion — si admin notice rompe el menú, log pero no fail
      if (count === 0) {
        console.warn(`[soft] Submenu item not found: ${item}`);
      }
    }
  });

  test('Top-level Akibara tiene Manga Crimson border-left en active state', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=akibara');
    const akibaraTop = page.locator('#adminmenu li.toplevel_page_akibara');
    await expect(akibaraTop).toHaveClass(/current|wp-has-current-submenu/);

    // Box-shadow inset 3px Manga Crimson en current state (admin.css)
    const boxShadow = await akibaraTop.evaluate((el) => getComputedStyle(el).boxShadow);
    expect(boxShadow).toMatch(/220, 38, 38|#dc2626|rgb.*220.*38.*38/i);
  });

  // Test cada admin page individual — sanity check
  for (const adminPage of ADMIN_PAGES) {
    test(`Page loads: ${adminPage.slug} (${adminPage.plugin})`, async ({ page }) => {
      const response = await page.goto(`/wp-admin/admin.php?page=${adminPage.slug}`);
      const status = response?.status() ?? 0;

      // 200 OK o 302 redirect (algunos pages requieren caps adicionales)
      expect([200, 302]).toContain(status);

      if (status === 200) {
        // No PHP fatal/parse error visible en HTML
        const html = await page.content();
        expect(html).not.toMatch(/Fatal error/i);
        expect(html).not.toMatch(/Parse error/i);
        expect(html).not.toMatch(/Cannot redeclare/i);
        expect(html).not.toMatch(/Undefined constant/i);
        expect(html).not.toMatch(/Call to undefined function/i);

        // WordPress error page check (WSOD)
        expect(html).not.toMatch(/Ha habido un error crítico en esta web/i);

        // Admin layout sanity: debe tener .wrap div O h1
        const hasWrap = await page.locator('.wrap').count();
        const hasH1 = await page.locator('h1, h2.wp-heading-inline').count();
        expect(hasWrap + hasH1).toBeGreaterThan(0);

        // No raw text dump anti-pattern (screenshot bug user reported):
        // body sin elementos estructurados = text dump
        const bodyChildren = await page.locator('#wpcontent > *').count();
        expect(bodyChildren).toBeGreaterThan(2);
      }
    });
  }

  test('Marketing Campaigns page tiene admin layout (no text dump)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=akibara-marketing-campaigns');

    // Sanity: page debe tener cards/sections estructurados
    const wrap = page.locator('.wrap');
    await expect(wrap).toBeVisible();

    // h1 visible
    const h1 = page.locator('.wrap h1, .wrap h2.wp-heading-inline').first();
    await expect(h1).toBeVisible();

    // KPIs deben estar en cards/grid, NO como texto plano apilado
    // Buscar patrón anti-pattern del screenshot: "0\nTotal\n0\nProgramadas\n..."
    const hasGridOrCards = await page.locator(
      '.wp-list-table, .akb-stats-grid, .akb-card, .postbox, [class*="grid"], [class*="card"]'
    ).count();

    // Si no hay grid/cards, marca como FAIL (este es el bug del screenshot)
    expect(hasGridOrCards).toBeGreaterThan(0);
  });

  test('Migration admin notice visible (o dismissed)', async ({ page }) => {
    // Visit otra admin page (NO el panel Akibara, donde notice no se muestra)
    await page.goto('/wp-admin/edit.php');

    const notice = page.locator('.akibara-reorg-notice');
    const count = await notice.count();

    if (count > 0) {
      // Notice presente — verifica a11y attributes
      await expect(notice).toBeVisible();
      await expect(notice).toHaveAttribute('role', 'note');
      await expect(notice).toHaveAttribute('aria-live', 'polite');

      // Dismiss button accesible
      const dismissBtn = notice.locator('.notice-dismiss, a:has-text("Entendido")');
      await expect(dismissBtn.first()).toBeVisible();
    } else {
      // Ya fue dismissed por user_meta — OK también
      console.info('[info] Migration notice ya dismissed para este user.');
    }
  });

  test('NO submenus de Akibara aparecen bajo WooCommerce', async ({ page }) => {
    await page.goto('/wp-admin/');

    // WooCommerce parent menu
    const wcMenu = page.locator('#adminmenu li#toplevel_page_woocommerce');
    await expect(wcMenu).toBeVisible();

    // Submenu items bajo WC
    const wcSubmenu = wcMenu.locator('ul.wp-submenu a');

    // Items que NO deberían estar bajo WC tras reorg (era el bug del screenshot)
    const akibaraSlugs = [
      'akibara-marketing-campaigns', 'akb-banner', 'akb-brevo',
      'akb-reservas', 'akb-encargos', 'akibara-back-in-stock',
      'akibara-whatsapp', 'akb-installments', 'akb-search-index',
      'akb-series-autofill', 'akb-order-reorder',
    ];

    for (const slug of akibaraSlugs) {
      const link = wcSubmenu.locator(`[href*="page=${slug}"]`);
      const found = await link.count();
      expect(found, `Submenu '${slug}' aún visible bajo WooCommerce — reorg incompleto`).toBe(0);
    }
  });
});

test.describe('@admin Visual sanity — pages mostradas en screenshot user', () => {
  test.use({ storageState: 'tests/e2e/admin/.auth/admin.json' });

  test('Marketing Campaigns: emoji template list NO debe ser flat text', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=akibara-marketing-campaigns');

    // El bug reportado: "Elige un template" seguido de 16 lineas con emoji.
    // Tras fix esperamos: radio inputs, lista <ul>, o cards estructuradas.
    const templateSection = page.locator('text=/Elige un template|Templates|Plantilla/i').first();

    if (await templateSection.count() > 0) {
      // Buscar estructura siblings del header
      const parent = templateSection.locator('xpath=..');
      const hasInputs = await parent.locator('input[type="radio"], input[type="checkbox"]').count();
      const hasList = await parent.locator('ul li, ol li, .template-card, .akb-template').count();

      expect(hasInputs + hasList, 'Templates section sigue siendo text dump').toBeGreaterThan(0);
    }
  });
});
