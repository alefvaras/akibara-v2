---
agent: wordpress-master
round: 1
date: 2026-04-26
scope: Patterns WordPress + WooCommerce idioms en código custom — REST endpoints, AJAX, nonces, capabilities, hooks priorities, preventa flow, security headers
files_examined: 251
findings_count: { P0: 4, P1: 9, P2: 7, P3: 4 }
---

## Resumen ejecutivo

1. **REST endpoint cart sin nonce en `/cart/add`** (`themes/akibara/inc/rest-cart.php:73-168`) — `permission_callback => '__return_true'` y comentario justifica "WC pattern" — pero el endpoint mira el carrito completo del usuario sin rate limit. Cualquier bot puede inflar carritos masivamente. **P0**.
2. **BlueX webhook fallback inseguro** (`themes/akibara/inc/bluex-webhook.php:31-41`) — Si la option `akb_bluex_webhook_secret` está vacía `return true` — webhook acepta cualquier POST. **P0**.
3. **DUPLICATE `/akibara/v1/health` endpoints** entre theme (`inc/health.php`) y plugin (`modules/health-check/module.php`). Theme guard depende del orden de carga. **P1**.
4. **Test products homepage**: `inc/performance.php:284-336` (`akibara_get_homepage_section`) NO filtra por SKU TEST-AKB-* ni excluye productos en categoría Uncategorized. Confirma F-PRE-011, F-PRE-012, F-PRE-014. **P1**.
5. **No security headers** (HSTS / X-Frame / X-Content / CSP / Referrer-Policy global) en código custom. Para tienda con auth + payments es base estándar 2026. **P1**.

## Findings P0

### F-22-001: REST `/cart/add` público sin nonce ni rate limit
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** `themes/akibara/inc/rest-cart.php:16-20, 73-168`
- **Descripción:** Endpoint POST `/wp-json/akibara/v1/cart/add` con `permission_callback => '__return_true'`. Sin rate limit, sin validar origen, mantiene WC()->session. Bots pueden crear sesiones masivas, inflar carritos, llenar tabla wp_woocommerce_sessions. Combinado con `/cart/get` también `__return_true`, permite enumerar productos de cualquier sesión.
- **Propuesta:** Agregar rate limit por IP usando `akb_rate_limit()` de mu-plugins (10 req/min/IP). Mantener `__return_true` permission. Para `/cart/remove` y `/cart/update-qty` agregar nonce check.
- **Esfuerzo:** S (1-2h)
- **Sprint:** S1

### F-22-002: BlueX webhook abierto sin secret configurado
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** `themes/akibara/inc/bluex-webhook.php:31-41, 154-158`
- **Descripción:** `akb_bluex_webhook_auth()` retorna `true` cuando secret vacío. Auto-generation solo en admin_init. Si nunca un admin entró post-install, webhook acepta cualquier POST → marca orders como completed/on-hold sin auth.
- **Propuesta:** Cambiar `return true` → `return false` cuando secret vacío. Auto-generar en register_activation_hook. Validar header X-BlueX-Secret presente Y matches.
- **Esfuerzo:** S (15 min)
- **Sprint:** S1

### F-22-003: REST `/mkt/open` y `/mkt/click` sin auth ni rate limit
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** `plugins/akibara/modules/marketing-campaigns/tracking.php:23-46, 49-90`
- **Descripción:** Open pixel y click tracker `__return_true` (esperado para email tracking). Sin rate limit. Cada hit hace `update_option(AKB_MKT_TRACKING_OPTION, $tracking, false)` → atacante puede inflar `wp_options` con miles de hits falsos.
- **Propuesta:** Rate limit por IP `akb_rate_limit('mkt_track:'.$ip, 60, 60)`. Validar campaign_id existe. Cap array opens por campaign a 5000.
- **Esfuerzo:** S (1h)
- **Sprint:** S2

### F-22-004: vendor/ y coverage/ en producción dentro del plugin akibara
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** `plugins/akibara/vendor/` + `coverage/html/`
- **Descripción:** Plugin lleva 74MB dev tools en prod. coverage/html/ expone snippets código fuente con líneas no-cubiertas resaltadas → browsing del código permitido si server permite.
- **Propuesta:** Excluir vendor/, coverage/, tests/, .phpunit.cache/, composer.* del deploy. .htaccess deny coverage/. composer dump-autoload --no-dev pre-deploy.
- **Esfuerzo:** S (30 min)
- **Sprint:** S1

## Findings P1

### F-22-005: Capability check DESPUÉS de nonce check en wp_ajax_akb_inv_csv
- **Severidad:** P1
- **Archivo(s):** `plugins/akibara/modules/inventory/module.php:487-494`
- **Descripción:** Handler llama check_ajax_referer ANTES de current_user_can. Orden correcto: capability primero (usuario sin perms NO debería consumir nonce ni recibir señal de validación).
- **Propuesta:** Invertir orden — current_user_can() primero, luego check_ajax_referer().
- **Sprint:** S1

### F-22-006: Public order tracking endpoint sin rate limit (IDOR risk)
- **Severidad:** P1
- **Archivo(s):** `themes/akibara/inc/tracking.php:11-32`
- **Descripción:** akibara_ajax_track_order acepta nopriv. Sin rate limit. Atacante puede enumerar orders (auto-increment IDs adivinables) probando emails. Response incluye items, totals, shipping.
- **Propuesta:** Rate limit `akb_rate_limit('track_order:'.$ip, 10, 60)`.
- **Sprint:** S1

### F-22-007: Test products visibles en home
- **Severidad:** P1 → P3 (intencional según usuario)
- **Categoría:** UX
- **Archivo(s):** `themes/akibara/inc/performance.php:284-336`
- **Descripción:** akibara_get_homepage_section solo filtra `product_visibility: exclude-from-catalog NOT IN`. Productos test 24261/62/63 NO tienen ese tag. Aparecen en latest + preorders.
- **Propuesta:** Marcar productos test con `product_visibility = exclude-from-catalog` (1 click admin). Re-clasificado como P3 task pre-launch porque usuario confirmó es intencional.
- **Sprint:** S1 (5 min admin) o pre-launch
- **Requiere mockup:** NO

### F-22-008: Producto agotado puede ser preventa simultáneamente
- **Severidad:** P1
- **Categoría:** UX
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-product.php:30-35`, `class-reserva-frontend.php:156-178`
- **Descripción:** El plugin permite producto outofstock + _akb_reserva=yes mostrarse "Reservar ahora" — by design para preventas con stock 0. Bug visible: card UI muestra "Preventa" + "Disponible" simultáneo.
- **Propuesta:** Mutually exclusive UX: si `_akb_reserva=yes` Y stock>0 → "Disponible para reservar". Si stock=0 → "Reserva — fecha por confirmar". Card lee `Akibara_Reserva_Product::get_estado_proveedor()`.
- **Esfuerzo:** S (1h)
- **Sprint:** S2

### F-22-009: Conflicto priority filters precio (998 vs 999) — fragile coupling
- **Severidad:** P1
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-frontend.php:29-34` + `plugins/akibara/modules/descuentos/engine.php:177-185`
- **Descripción:** Akibara-reservas priority 998. Descuentos priority 999. Acoplamiento explícito en comentarios. Si tercer filter se agrega entre 998-999, lógica "best-for-client wins" se rompe.
- **Propuesta:** Definir constantes en mu-plugin core helpers: `AKB_FILTER_PRIORITY_RESERVAS_PRICE=998`, `AKB_FILTER_PRIORITY_DESCUENTOS_PRICE=999`. Documentar contrato.
- **Sprint:** S2

### F-22-010: atomic_stock_check no es atómico (lock se libera antes de retornar)
- **Severidad:** P1
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-cart.php:18-49`
- **Descripción:** Método llamado "atomic" libera lock ANTES de retornar resultado. Race condition real para hot preventas. Bug `$waited = 0` (int) + `$waited += 0.1` rompe el loop por coerción PHP.
- **Propuesta:** Bug fix `$waited = 0.0;` (float). Atomicidad correcta: callback pattern `atomic_stock_check_and_act($product_id, $qty, callable $on_pass)` o MySQL GET_LOCK().
- **Esfuerzo:** S (bug fix) | M (refactor)
- **Sprint:** S1 (bug) + S3 (refactor)

### F-22-011: pre_get_posts filter en track_search_query corre en TODOS queries main
- **Severidad:** P1
- **Categoría:** FRAGILE / OVER-ENGINEERED
- **Archivo(s):** `themes/akibara/inc/smart-features.php:117-134`
- **Descripción:** Callback corre en CADA pre_get_posts. Para 3 clientes nulo. Para crecimiento, set_transient/get_transient por hit puede saturar wp_options.
- **Propuesta:** Mantener (YAGNI 3 clientes). Documentar en comentario umbral optimización si tráfico >100 searches/día
- **Sprint:** Backlog

### F-22-012: REST /health endpoint duplicado theme + plugin
- **Severidad:** P1
- **Archivo(s):** `themes/akibara/inc/health.php:19-26` + `plugins/akibara/modules/health-check/module.php:23-36`
- **Descripción:** Ambos registran register_rest_route('akibara/v1', '/health', ...). Theme guard `defined('AKIBARA_HEALTH_CHECK_LOADED')` depende orden carga. Si plugin desactivado, theme version queda sin misma capability check.
- **Propuesta:** Eliminar `themes/akibara/inc/health.php`. Plugin ya provee /health. UptimeRobot puede pegar a HEAD del homepage.
- **Sprint:** S2

### F-22-013: NO security headers globales (HSTS, X-Frame, X-Content, CSP, Referrer-Policy)
- **Severidad:** P1
- **Categoría:** SECURITY / SETUP
- **Descripción:** Búsqueda exhaustiva NO retorna NADA. Solo Referrer-Policy ad-hoc en magic-link.php. Para tienda con auth + payments + SSO + magic links baseline 2026 obligatorio.
- **Propuesta:** Crear mu-plugin akibara-security-headers.php con send_headers action: HSTS max-age=31536000, X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy camera/microphone/geolocation deny. NO CSP estricta inicial (rompe scripts WC + Sentry + GA4 + Brevo). Verificar Cloudflare/Hostinger no agreguen ya estos.
- **Esfuerzo:** S (1h + verify curl)
- **Sprint:** S1

### F-22-014: REST API user enumeration — /wp-json/wp/v2/users no protegido
- **Severidad:** P1
- **Descripción:** Endpoint default WP abierto para usuarios con posts publicados. Combinado con backdoor admin 6 (47 posts) → expone usernames admin = vector brute-force wp-login.
- **Propuesta:** En akibara-security-headers mu-plugin (mismo F-22-013):
  ```php
  add_filter('rest_endpoints', function($endpoints) {
      unset($endpoints['/wp/v2/users']);
      unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
      return $endpoints;
  });
  ```
- **Sprint:** S1 (junto con cleanup admin backdoors)

## Findings P2

### F-22-015: wp_set_auth_cookie con $remember=true hardcoded en magic-link y google-auth
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/magic-link.php:295` + `inc/google-auth.php:158`
- **Descripción:** Ambos llaman wp_set_auth_cookie($user->ID, true). El true extiende cookie a 14 días vs 2 default. Para magic link "passwordless" usuario espera sesión efímera.
- **Propuesta:** Magic-link: `false` (2h-2d). Google OAuth: dejar true. Trade-off seguridad vs comodidad.
- **Sprint:** S2

### F-22-016: Admin AJAX wp_ajax_akibara_test_email solo verifica nonce
- **Severidad:** P2
- **Archivo(s):** `plugins/akibara/admin/class-akibara-admin.php:208-210`
- **Descripción:** Handler check_ajax_referer pero NO current_user_can. Cualquier usuario logueado que obtenga el nonce puede disparar test emails.
- **Propuesta:** Agregar capability check ANTES del nonce o migrar a akb_ajax_endpoint() helper
- **Sprint:** S2

### F-22-017: Theme register_activation_hook pattern (preventivo)
- **Severidad:** P2
- **Descripción:** Theme NO tiene activation hook. Si futuro se agrega lógica install, debe usarse after_switch_theme NO register_activation_hook.
- **Propuesta:** Documentar en comentario functions.php
- **Sprint:** Backlog

### F-22-018: enqueue.php.bak-2026-04-25-pre-fix queda en producción
- **Severidad:** P2
- **Descripción:** Backup file 12K bytes accesible vía URL → exposición código fuente
- **Propuesta:** Eliminar archivo. Deploy script exclude *.bak/.backup/.old. .htaccess deny pattern.
- **Sprint:** S1

### F-22-019: $_GET params usados con sanitize_key + map hardcoded — pattern correcto
- **Severidad:** P2 (positivo)
- **Descripción:** `before_customer_login_form` y otros usan sanitize_key + lookup map hardcoded → previene XSS via param injection. Documentar como pattern canónico.
- **Sprint:** Backlog

### F-22-020: BlueX webhook search por meta sin LIMIT explícito
- **Severidad:** P3
- **Descripción:** wc_get_orders([...'limit' => 1, 'meta_key' => ..., 'meta_value' => ...]) — bien tiene limit. Pero meta lookup unindexed. Para 3 customers OK. >1000 orders revisar.
- **Propuesta:** Documentar TODO performance umbral
- **Sprint:** Backlog

### F-22-021: wp_ajax_clear_shipping_cache priority 1 noop
- **Severidad:** P3
- **Descripción:** Pattern correcto pero comment justificando priority 1 implícito
- **Propuesta:** Agregar comentario inline
- **Sprint:** Backlog

### F-22-022: WC AJAX endpoints (wc_ajax_akibara_*) heredan handler
- **Severidad:** P3
- **Descripción:** Mismo handler en wp_ajax_* y wc_ajax_*. Comment menciona "bypasses CDN block on admin-ajax.php"
- **Propuesta:** Documentar contract en comentario block
- **Sprint:** Backlog

### F-22-023: Theme inc/encargos.php — $_POST sin wp_unslash en algunos campos
- **Severidad:** P3
- **Archivo(s):** `themes/akibara/inc/encargos.php:14-22`
- **Descripción:** Inconsistencia: línea 17 wp_unslash con paréntesis mal puestos `wp_unslash($_POST['nombre']) ?? ''`. Línea 22 SIN wp_unslash.
- **Propuesta:** Refactor uniforme `sanitize_text_field( wp_unslash( $_POST['nombre'] ?? '' ) )`
- **Sprint:** S2

### F-22-024: NO hay register_post_type custom — pattern OK
- **Severidad:** P3 (info positivo)
- **Descripción:** Akibara usa WC `product` CPT + taxonomías standard. NO custom CPTs innecesarios. Alineado con anti-over-engineering.
- **Sprint:** N/A

## Hipótesis Iter 2

1. WC HPOS migration verification incompleta — $order->update_meta_data() vs update_post_meta($order_id)
2. Cart abandonment Brevo upstream 0 traffic vs custom local — si ambos activos cliente recibe 2 emails
3. pre_get_posts filter 11 + WC filters orden carga
4. Newsletter signup AJAX akibara_newsletter_subscribe sin rate limit
5. wp_set_auth_cookie post wc_create_new_customer con password 32-char random — wp_login.php sigue activo

## Cross-cutting flags

- F-PRE-008 mesa-10: WP core hashes verify
- F-22-004 cleanup mesa-02 + mesa-10
- akb_ajax_endpoint() helper pattern correcto — mesa-15 architect recomienda migrar legacy AJAX

## Áreas que NO cubrí

- vendor/ deps CVEs (mesa-10 expand)
- Custom statuses WC orders preventa (mesa-15)
- Performance/N+1 homepage (mesa-04 perf)
- i18n compliance
- Block editor (no FSE/blocks usados)
- WP-CLI commands custom (no registrados)
- Multisite (single-site)
- Cron real Hostinger (mesa-09 + mesa-15)
