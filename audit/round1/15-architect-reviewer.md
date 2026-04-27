---
agent: architect-reviewer
round: 1
date: 2026-04-26
scope: Architectural review of plugin-loader/module-registry, akibara-reservas (preventa flow), theme inc/, marketing/discount module overlap, mu-plugins boundaries
files_examined: 47
findings_count: { P0: 0, P1: 5, P2: 12, P3: 5 }
---

## Resumen ejecutivo

- **Preventa flow es el módulo MÁS robusto del repo** (akibara-reservas): state machine clara, idempotencia emails, atomic stock check con mutex transient (con bug $waited int/float), async queue con retries, fallback síncrono. Mantenerlo como base de crecimiento. Solo 2 issues: cron registrado 2 veces (race) y daily_digest envía email con wp_mail directo (bypass del queue robusto).
- **Overlap real de descuentos/cupones (F-PRE-005 confirmado):** 3 sistemas paralelos `descuentos` (~218 LOC + engine), `welcome-discount` (~606 LOC + 8 clases), `marketing-campaigns` (~1.473 LOC). Suma ~3.700 LOC. Consolidar a marketing-campaigns como umbrella en S2-S3.
- **Module Registry buena infra pero integración inconsistente:** solo 5 de 27 módulos usan guard `akb_is_module_enabled()` dentro del module.php; el resto confía solo en el toggle del registry.
- **Theme inc/ tiene 48 archivos PHP con 2 duplicaciones reales y 1 anti-pattern:** `seo.php` + `seo/` subdir OK (loader pattern), pero `google-business-schema.php` y `seo/schema-organization.php` ambos hookean rank_math/json_ld con prio 99 — orden de ejecución no determinístico.
- **mu-plugins pattern es sólido** (11 archivos, 2.074 LOC). Cada uno resuelve un problema concreto documentado. NO consolidar — la separación es ventaja. Solo 2 candidatos a CLEAN ya identificados (sentry-customizations CONFIRMED LOAD-BEARING por mesa-10, brevo-smtp CONFIRMED LOAD-BEARING por mesa-09/02/10).

## Findings P1

### F-15-001: Cron akb_reservas_check_dates y daily_digest registrados en 2 lugares (race)
- **Severidad:** P2
- **Categoría:** FRAGILE
- **Archivo(s):** `plugins/akibara-reservas/akibara-reservas.php:84-94` + `includes/class-reserva-cron.php:25-32`
- **Descripción:** Plugin registra cron en register_activation_hook Y en `Akibara_Reserva_Cron::ensure_scheduled()` (init priority 20). Defensivo pero ambiguo. register_activation_hook usa time() base — si activación corre antes que filter cron_schedules registre 'akb_fifteen_minutes', WP cae a hourly.
- **Propuesta:** Eliminar wp_schedule_event del register_activation_hook. Solo ensure_scheduled() en init. Centralizar add_filter cron_schedules a init priority 1.
- **Sprint:** S1

### F-15-002: daily_digest envía email con wp_mail() bypass del queue robusto
- **Severidad:** P2
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-cron.php:107`
- **Descripción:** Akibara_Reserva_Cron::daily_digest() usa wp_mail() síncrono sin retry. Mismo plugin tiene Akibara_Reserva_Email_Queue (Action Scheduler + 3 retries + backoff). El cron diario no usa esa infra → si Brevo falla, digest se pierde silencioso.
- **Propuesta:** Refactor daily_digest a registrar email type WC nativo y dispatchar via Akibara_Reserva_Orders::dispatch_email(). Alternativa simple: wrap wp_mail con check + log.
- **Sprint:** S2

### F-15-003: 3 sistemas paralelos descuentos/cupones overlap
- **Severidad:** P1
- **Categoría:** REFACTOR
- **Archivo(s):** descuentos/module.php (~218 + engine + cart + admin + presets), welcome-discount/module.php (~606 + 8 clases), marketing-campaigns/module.php (~1.473 + tracking + welcome-series)
- **Descripción:** 3 módulos resuelven facetas del mismo problema:
  - descuentos: motor interno filtros precio + cupones VIRTUALES en cart
  - welcome-discount: cupón único 1-uso por email (PRIMERACOMPRA10) con anti-abuso (RUT, blacklist, captcha, double opt-in) — WC_Coupon real
  - marketing-campaigns: campañas programadas + cupones con WC_Coupon real, tracking UTM, segmentos
  
  Resultado: dueño tiene 3 admin pages distintas. Para "10% off Black Friday solo Manga Ivrea por 48h" debe combinar descuentos + marketing-campaigns. Sin single source of truth.
- **Propuesta (S2-S3):** Diseñar arquitectura unificada con marketing-campaigns como umbrella:
  1. Mantener descuentos como motor pricing por taxonomía (renombrar pricing-rules)
  2. Migrar welcome-discount a "campaña tipo welcome trigger" dentro marketing-campaigns. Anti-abuso (RUT, blacklist, captcha) vuelve policy module reutilizable
  3. Consolidar admin a "Campañas y Descuentos" con sub-tabs
- **Esfuerzo:** L (3-4 sprints)
- **Sprint:** S3
- **Requiere mockup:** SÍ

### F-15-004: akibara-reservas no usa Module Registry — plugin separado
- **Severidad:** P2
- **Archivo(s):** `plugins/akibara-reservas/akibara-reservas.php`
- **Descripción:** Plugin 100% custom Akibara, depende de akb_ajax_endpoint() (de plugin akibara), comparte AkibaraEmailTemplate y conceptos. Plugin separado y NO en Module Registry. Dueño activa/desactiva 2 plugins distintos.
- **Propuesta:** **Camino A (recomendado):** Mantener separado pero registrarlo en Module Registry como `'type' => 'external_plugin'` para que admin tab unificado lo muestre. Camino B: Mover a akibara/modules/reservas/ — más drástico.
- **Esfuerzo:** S (A) | L (B)
- **Sprint:** S2 (camino A)

### F-15-005: Module Registry guard inconsistente — 22 de 27 módulos sin guard interno
- **Severidad:** P2
- **Descripción:** Registry filtra por `$mod['enabled']` antes de require_once. Solo 5 módulos defienden con `if (!akb_is_module_enabled('xxx')) return;` interno: descuentos, welcome-discount, popup, back-in-stock, cart-abandoned. Otros 22 confían solo en registry. Si toggle se setea pero módulo se carga por otro path (require_once directo, tests, manual include), módulo se ejecuta ignorando toggle.
- **Propuesta:** Single source of truth en boot del registry. Eliminar guard manual de los 5 (DRY).
- **Sprint:** S2

### F-15-006: google-business-schema.php y seo/schema-organization.php hookean json_ld prio 99
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/google-business-schema.php:12` y `inc/seo/schema-organization.php:36`
- **Descripción:** Ambos `add_filter('rank_math/json_ld', ..., 99, 2)`. Misma prioridad. Orden ejecución depende orden require_once en functions.php. Si reordena, JSON-LD final cambia (str_replace stale match).
- **Propuesta:** google-business-schema → prio 95, schema-organization → prio 99. Documentar inline. Como bonus consolidar lógica en seo/schema-organization.php y eliminar google-business-schema.php separado.
- **Sprint:** S2

### F-15-007: inc/enqueue.php.bak-2026-04-25-pre-fix (12K) en filesystem
- **Severidad:** P3
- **Categoría:** DEAD-CODE
- **Propuesta:** Borrar (commit del fix está en main confirmar)
- **Sprint:** S1

### F-15-008: vendor/ (55MB) y coverage/ (19MB) versionados en plugin akibara
- **Severidad:** P1
- **Categoría:** OVER-ENGINEERED + dead bloat
- **Descripción:** Plugin lleva en producción 74 MB de dependencias DEV (PHPUnit, PHPStan, PHPCS, Symfony, sebastian/*, etc.) más 19 MB coverage HTML. Ninguno runtime. composer.json declara solo require-dev — cero deps producción. Plugin runtime real ~1.3 MB en modules/, vendor/ ocupa 55 MB de noise.
- **Propuesta:** Excluir vendor/, coverage/, .phpunit.cache/, .phpcs-baseline, .pcp-baseline, composer.lock, phpcs.xml, phpstan.neon, phpstan-baseline.neon, phpunit.xml.dist, tests/ del deploy a producción (.distignore o regla deploy.sh). Mantener todo en repo git — solo no desplegar.
- **Sprint:** S1

### F-15-009: "preventa" vs "encargo" vs "agotado" — 3 flujos paralelos sin guía clara
- **Severidad:** P1
- **Categoría:** UX + REFACTOR
- **Archivo(s):** `plugins/akibara-reservas/`, `themes/akibara/inc/encargos.php`, `plugins/akibara/modules/back-in-stock/`
- **Descripción:** Cliente puede caer en 3 flujos para "no inmediatamente disponible":
  - **Preventa** (akibara-reservas): existe en catálogo `_akb_reserva=yes`. Cliente PAGA full + state machine + emails async
  - **Encargo** (theme/inc/encargos.php): formulario para PEDIR producto NO en catálogo. Solo email admin + Brevo. NO cobro, NO tracking
  - **Agotado + Avísame** (back-in-stock): producto outofstock. Suscripción sin pago. Email cuando vuelve instock
  
  Comparten "intención cliente" pero paradigma distinto. UX confusion crítica. F-PRE-011 confirma productos test 24262/24263 muestran badge "Preventa" + estado mezcla.
- **Propuesta:** Documentar matriz decisión "cuándo es preventa, encargo, agotado" en ADR. Reglas:
  - Producto en catálogo + `_akb_reserva=yes` → PREVENTA (Reservar ahora)
  - Producto en catálogo sin reserva + outofstock → AVÍSAME (back-in-stock)
  - Producto NO en catálogo → ENCARGO (formulario)
  
  Mutually exclusive UI. Test E2E. Auto-convert back-in-stock → preventa (`class-reserva-stock.php`) debe respetar suscriptores activos.
- **Esfuerzo:** M
- **Sprint:** S2
- **Requiere mockup:** SÍ

### F-15-010: Acoplamiento implícito tema → akibara-reservas vía class_exists() dispersos
- **Severidad:** P2
- **Archivo(s):** product-card.php:117, single-product/info.php:92, woocommerce.php:379, seo/meta.php:471, serie-landing.php:328
- **Descripción:** Tema tiene 5+ checks `class_exists('Akibara_Reserva_Product')` o `_akb_reserva` directos. Boundary leak: tema asume conocimiento del schema interno del plugin.
- **Propuesta:** 1-2 helpers públicos en `akibara-reservas/includes/functions.php`:
  - `akb_reserva_esta_activa($product)` (existe pero no se usa donde debería)
  - `akb_reserva_render_card_status($product): string`
- **Esfuerzo:** M
- **Sprint:** S3

### F-15-011: 11 mu-plugins — pattern sólido (validación)
- **Severidad:** P3 (info positivo)
- **Descripción:** Revisión confirma pattern sólido. Cada mu-plugin resuelve problema concreto, documentado en header. NO consolidar — separación es ventaja. **CLEAN-001 (sentry-customizations) y CLEAN-003 (brevo-smtp) CONFIRMADOS LOAD-BEARING por mesa-10/09/02 — NO eliminar.**

### F-15-012: class-akibara-brevo.php (shim) marcado para deletion en 6 meses (ADR-008)
- **Severidad:** P3
- **Descripción:** Shim 22 LOC con class_alias. Header dice "Eliminar después 6 meses". 5 callers usan AkibaraBrevo:: legacy.
- **Propuesta:** Recordatorio S4+: completar migración + eliminar shim. NO urgente.
- **Sprint:** S4+

### F-15-013: atomic_stock_check mutex es defensa de aspecto
- **Severidad:** P2
- **Descripción:** Bug `$waited = 0` (int), `$waited += 0.1` lo convierte a 1 después de 1 incremento (PHP coerce). Loop sale antes de tiempo o dura más. Lock effectively bypass.
- **Propuesta:** Bug fix: `$waited = 0.0;` (float). Atomicidad correcta: callback wrapper o GET_LOCK MySQL. Para 3 clientes race teórico. Fix mínimo S1, refactor robusto S3.
- **Sprint:** S1 (bug) + S3 (refactor)

### F-15-014: Akibara_Reserva_Frontend::filter_price flag estática global compartida
- **Severidad:** P2
- **Descripción:** `$filtering_price = false` flag estática a nivel clase. PHP single-threaded WP request OK hoy. Si se introduce async (Action Scheduler procesando precios background), flag puede dar falsos positives.
- **Propuesta:** NO refactor proactivo (3 clientes). Comentario inline explicando + tripwire para futuros devs cuando aparezca async.
- **Sprint:** S2 (solo doc)

### F-15-015: popup module hardcoded a 1 use case (welcome) — confirma F-PRE-003
- **Severidad:** P3
- **Descripción:** 604 LOC para 1 popup. NO over-engineering hoy (1 caso). Candidate FUTURO si aparece 2do caso.
- **Propuesta:** Marcar `[OVER-ENGINEERED?]` con condición trigger. NO sprint actual.
- **Sprint:** S4+ condicional

### F-15-016: popup y welcome-discount supresión cruzada manual frágil
- **Severidad:** P2
- **Archivo(s):** popup/module.php:84 y welcome-discount/module.php:69, 268-274
- **Descripción:** Sistema "winner takes all" implementado por dependencias circulares entre módulos. Si renombran cookie, el otro no se entera.
- **Propuesta:** Como parte del refactor F-15-003, unificar captura email a "subscribe-popup" único con tipos. Mientras tanto: documentar acoplamiento con comentario.
- **Sprint:** S2 (doc), S3 (refactor con F-15-003)

### F-15-017: Cron wp_schedule_event en 5+ módulos sin garantía ejecución (depende tráfico web)
- **Severidad:** P1
- **Categoría:** FRAGILE (infra)
- **Archivo(s):** next-volume, series-notify, akibara-reservas, cart-abandoned, brevo, etc. — al menos 6 crons
- **Descripción:** Confirma F-PRE-004. Todos usan wp_schedule_event (WP-cron interno) que dispara con tráfico web. 3 clientes con horas sin tráfico (madrugada cron diario) = crons no firing.
- **Propuesta:** S1 setup Hostinger crontab `*/5 * * * * wget -q -O - https://akibara.cl/wp-cron.php > /dev/null 2>&1`. Verificar wp-config.php tiene `DISABLE_WP_CRON=true`.
- **Esfuerzo:** S (15 min ops)
- **Sprint:** S1

### F-15-018: theme/inc/encargos.php usa akb_brevo_get_api_key() del mu-plugin
- **Severidad:** P1
- **Categoría:** FRAGILE (dependency)
- **Archivo(s):** `themes/akibara/inc/encargos.php:72-74`
- **Descripción:** Function `akb_brevo_get_api_key()` definida en mu-plugin akibara-brevo-smtp.php (load-bearing confirmed por mesa-10). Si CLEAN-003 se ejecutara mal, encargos cae a fallback que retorna vacío → form deja de suscribir Brevo lista 2.
- **Propuesta:** Pre-cualquier-cleanup-mu-plugin, migrar callsite encargos a usar AkibaraBrevo::get_api_key() (shim plugin akibara). Más relevante ahora que CLEAN-003 está cancelado.
- **Sprint:** S2 (refactor consistency)

### F-15-019: akibara-reservas shortcode [akb_preventas] duplica lógica de /preventas/ archive
- **Severidad:** P3
- **Descripción:** Shortcode renderiza grid productos preventa. URL /preventas/ existe (probable archive WC con product_cat). Dos formas mismo render.
- **Propuesta:** Validar uso real shortcode (`grep -r "akb_preventas"`). Si NO se usa → eliminar (~30 LOC). Si se usa → documentar diferencia
- **Sprint:** S2

### F-15-020: 39 unidades activas para ~3 clientes
- **Severidad:** P3 (estratégico)
- **Categoría:** TECH-DEBT (gestión)
- **Descripción:** Inventario:
  - 27 modules akibara/modules (~16.787 LOC)
  - 11 mu-plugins (~2.074 LOC)
  - akibara-reservas (~3.071 LOC)
  - akibara-whatsapp
  - 48 archivos theme/inc/* (~12.235 LOC)
  - **Total ~35.000 LOC custom para 3 clientes** (~10.000 LOC/cliente)
  
  Mucha superficie. Causa: cada feature como módulo separado. Disciplina extrema en boundaries y deprecation.
- **Propuesta:** Cross-cutting recommendation mesa-23 PM:
  - Regla "no nuevo módulo sin justificar por qué los existentes no encajan"
  - Telemetry simple: hits a admin pages por módulo últimos 30d. Módulos sin uso → CLEAN candidates
  - ADRs como markdown formal en docs/adr/ (mencionados ADR-002/005/008 pero no veo archivos)
- **Sprint:** S2+ (proceso continuo)

### F-15-021: Akibara_Reserva_Stock::on_stock_change pisa back-in-stock cuando producto sale stock
- **Severidad:** P1
- **Categoría:** FRAGILE (cross-module collision)
- **Archivo(s):** `class-reserva-stock.php:18-32` + `back-in-stock/module.php`
- **Descripción:** Dos módulos en `woocommerce_product_set_stock_status`:
  - Reserva-Stock priority 10: si outofstock + auto_oos_enabled, convierte producto en preventa
  - back-in-stock: cuando outofstock → instock, dispara emails a suscriptores
  
  Race: producto outofstock con suscriptores back-in-stock. Re-stock momentáneo. instock fired → notifica 100 subs. 1 cliente compra rápido. outofstock fired → reservas auto-convierte preventa. Los otros 99 suscriptores recibieron "stock disponible" → llegan al PDP y ven "Reservar ahora" — they were notified incorrectly.
- **Propuesta:** En `Akibara_Reserva_Stock::maybe_enable_auto`, check: si hay suscriptores back-in-stock pendientes → notificarles ANTES de auto-convert + delay 1h. `do_action('akb_bis_will_convert_to_preventa', ...)` + wp_schedule_single_event(+HOUR, ...).
- **Esfuerzo:** M
- **Sprint:** S2

### F-15-022: PSR-4 autoloader correcto pero src/ tiene solo 2 clases
- **Severidad:** P3
- **Descripción:** Plugin tiene PSR-4 autoload `Akibara\\` → `src/`. Solo 2 clases (Brevo, EmailTemplate). Resto procedural en modules/*/module.php con `Akibara_*`. Migración PSR-4 estancada. NO problema para 3 clientes — estado intermedio aceptable.
- **Propuesta:** Mantener. Documentar en ADR: "Nuevas clases compartidas (Akibara\Infra\*, Akibara\Domain\*) van a src/. Módulos siguen procedurales mientras eso funcione." NO refactor proactivo.

## Cross-cutting flags

1. F-PRE-001 confirmado mesa-10
2. F-PRE-008 4 backdoor admins SEC-P0-001 + audit user 6 posts/cron/options
3. Hostinger crontab no configurado (F-PRE-004 expandido) — mesa-22
4. AkibaraEmailTemplate util cohesion (mesa-08 confirma tokens)
5. Brevo workflow upstream "Carrito abandonado" (F-PRE-002) — pre-cleanup CLEAN-002 validar upstream
6. akibara-whatsapp NO auditado profundo — mesa-22

## Áreas que NO cubrí

- akibara-whatsapp deep
- Plugin akibara admin/ folder
- tests/ folder akibara plugin
- assets/ CSS/JS (mesa-07/08)
- WC template overrides theme/woocommerce/ (mesa-22)
- Performance LCP/TBT
- mercadolibre integration deep
- shipping module (735 LOC, posible duplicación BlueX en theme/inc/checkout-accordion.php)
- finance-dashboard (F-PRE-006 backlog)
- referrals (1.659 LOC, módulo más grande, no leído)
- google-auth.php, magic-link.php auth flows custom no audit fondo
