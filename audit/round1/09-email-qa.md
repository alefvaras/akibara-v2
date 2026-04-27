---
agent: mesa-09-email-qa
round: 1
date: 2026-04-26
scope: Brevo SMTP integration, email-testing guard, 14 email-emitting modules + theme inc, WC email templates, marketing/growth features ready-to-enable, Free tier cap risk
files_examined: 28
findings_count: { P0: 3, P1: 7, P2: 6, P3: 5 }
---

## Resumen ejecutivo

1. **CLEAN-003 INCORRECTO — `akibara-brevo-smtp.php` NO se puede borrar.** El mu-plugin no es legacy: registra `pre_wp_mail` que enruta TODO `wp_mail()` por Brevo (sin él, Hostinger bloquea email = 0 delivery). Define `akb_brevo_get_api_key()` consumido por 7 archivos (newsletter, encargos, health-check, brevo module, src/Infra/Brevo.php). **F-09-001 P0**.
2. **Cap 300/día Free tier es riesgo P0 inminente** — con welcome-series + cart-abandoned + back-in-stock + next-volume + series-notify + review-request + review-incentive + marketing campaigns activas, una campaña masiva 100+ contactos puede saturar cap, **bloqueando órdenes confirmation**. Recomendación: upgrade Standard $9-18/mes ANTES de habilitar growth modules. **F-09-002 P0**.
3. **Bypass del email guard en theme/inc/woocommerce.php** — flujo back-in-stock duplicado en `themes/akibara/inc/woocommerce.php:867-904` usa meta `_akibara_notify_emails` + `wp_mail` con headers que el guard solo cubre si email es `*@akibara.cl`. Emails REALES de cliente NO se redirigen. **F-09-003 P0** (también DEAD-CODE duplicado).
4. **API key Brevo en wp-config.php plain text** (línea 90). Mismo problema que F-PRE-001 BlueX. **F-09-004 P1**.
5. **Brevo upstream tracker 0 traffic confirmado (F-PRE-002 expandido)** — plugin oficial activo pero ningún módulo custom valida que el tracker JS haya cargado. CLEAN-002 NO se puede ejecutar hasta verificar Brevo upstream firing. **F-09-005 P1**.

## Findings P0

### F-09-001: CLEAN-003 incorrecto — mu-plugin akibara-brevo-smtp.php es crítico
- **Severidad:** P0
- **Categoría:** SETUP (corrección de seed)
- **Archivo(s):** `mu-plugins/akibara-brevo-smtp.php` + 7 consumidores externos
- **Descripción:** Seed CLEAN-003 dice "Legacy nunca usado en producción" — INCORRECTO. Mu-plugin registra `pre_wp_mail` que intercepta y enruta TODO `wp_mail()` a Brevo Transactional API (Hostinger bloquea PHP mail). Eliminarlo rompe:
  - Recovery passwords (wp_mail nativo)
  - WC order confirmation, processing, completed, refunded
  - Theme magic-link login (themes/akibara/inc/magic-link.php:117)
  - Theme encargos (themes/akibara/inc/encargos.php:47)
  - Theme reservas admin alerts (plugins/akibara-reservas/includes/class-reserva-cron.php:107)
  - Welcome-discount fallback (welcome-discount/class-wd-email.php:113)
  - WC contact (themes/akibara/page-contacto.php:79)
  - Welcome-discount anti-fraude alerts (welcome-discount/class-wd-validator.php:314)
  - Theme back-in-stock duplicado (ver F-09-003)
- **Evidencia:** Línea 4 del header: "Intercepta TODO wp_mail() y lo rutea por Brevo Transactional API". Línea 11-13: "Hostinger bloqueó la cuenta por intentos repetidos de PHP mail() desde contextos donde el theme no se carga (cron, CLI)". Línea 67: `add_filter( 'pre_wp_mail', 'akibara_brevo_intercept_mail', 10, 2 );`. Función `akb_brevo_get_api_key()` consumida por 7 archivos.
- **Propuesta:** **Sacar CLEAN-003 del backlog inmediatamente.** Reclasificar mu-plugin como infraestructura crítica. Si seed insiste, lead-arquitecto valida con dueño en R2. Documentar en `docs/adr/` que mu-plugin es load-bearing (TODO wp_mail) + complementa email-testing-guard.php (que solo redirige recipient @akibara.cl ficticio, NO redirige a Brevo).
- **Esfuerzo:** S (decisión documentada)
- **Sprint:** S1 (corrección de seed)

### F-09-002: Cap 300 emails/día Free tier — risk de bloqueo de transaccionales
- **Severidad:** P0
- **Categoría:** FRAGILE
- **Archivo(s):** Gap arquitectural global
- **Descripción:** Brevo Free tier 2026 = **300 emails/día compartido** entre marketing + transactional. Akibara tiene 7 sistemas que dispatchan emails. Worst case un día con 1 campaña 300 contactos + restock spike: cap consumido a las 10am, todas las **órdenes posteriores no reciben confirmation** = cliente compra y queda confundido = ticket support → desconfianza brand.

  **Hoy NO hay** circuit breaker por cap, NO hay priorización (transaccional > marketing), NO hay alerta cuando cap se acerca.
- **Evidencia:** mu-plugin akibara-brevo-smtp.php:232 cuenta `mail_sent_count` pero no enforza cap. `Akibara\Infra\Brevo::send_transactional()` no valida cap antes de POST. `marketing-campaigns/module.php` despacha batches de 50 sin check cap.
- **Propuesta:**
  1. **Recomendación principal:** Upgrade a Brevo Starter $9/mes (10K emails/mes ≈ 333/día) o Business $18/mes ANTES de habilitar marketing-campaigns/welcome-series a base >30 clientes activos
  2. **Defensiva en código (S2 paralelo):** `akb_brevo_daily_cap_check()` consultando `https://api.brevo.com/v3/account` para `creditsType=sendLimit`. Si quedan <30 créditos: bloquear marketing-campaigns dispatch, permitir solo TX P0 (order confirmation, password reset), notificar admin via Sentry
  3. Priorizar emails: tag `priority=high` para órdenes/passwords; batch marketing en hora valle (3am)
- **Esfuerzo:** S (upgrade) + M (cap check + priorization)
- **Sprint:** S1 (upgrade) + S2 (cap check)

### F-09-003: Theme inc/woocommerce.php duplica back-in-stock + bypass parcial guard
- **Severidad:** P0 (potential bypass) / P2 (duplicación)
- **Categoría:** DEAD-CODE / FRAGILE
- **Archivo(s):** `themes/akibara/inc/woocommerce.php:834-904`
- **Descripción:** Theme tiene sistema **completo de back-in-stock** (AJAX endpoint `akibara_notify_stock`, meta `_akibara_notify_emails`, hook `woocommerce_product_set_stock_status`, async batch send via cron `akibara_send_restock_batch`) que envía vía `wp_mail()` con header `From: Akibara <no-reply@akibara.cl>`. Colisiona con módulo correcto `plugins/akibara/modules/back-in-stock/module.php` que usa Brevo API directo + tabla `wp_akb_bis_subs` + token unsubscribe.

  Problemas:
  - Dos sistemas activos al mismo tiempo dispatchando al hook → cliente recibe 2 emails
  - Email theme NO tiene unsubscribe link (incumple Ley 19.628 + Brevo policy)
  - Email theme usa `From: no-reply@akibara.cl` que NO está validado en Brevo
  - **Bypass parcial del email-testing-guard:** guard solo redirige `*@akibara.cl` ficticio. Emails REALES (`@gmail.com`) NO los redirige
- **Evidencia:** `themes/akibara/inc/woocommerce.php:867` add_action vs `plugins/akibara/modules/back-in-stock/module.php:287` add_action — ambos en mismo hook
- **Propuesta:**
  1. Verificar producción si meta `_akibara_notify_emails` tiene datos reales hoy
  2. Si hay subscriptores legacy, migrar a `wp_akb_bis_subs`
  3. Eliminar sección `themes/akibara/inc/woocommerce.php:834-904`
- **Esfuerzo:** S (50-100 LOC delete después de migración)
- **Sprint:** S2

## Findings P1

### F-09-004: API key Brevo en wp-config.php plain text
- **Severidad:** P1
- **Archivo(s):** `wp-config.php:90`
- **Descripción:** `define( 'AKB_BREVO_API_KEY', 'xkeysib-...' );` plain text. Mismo issue F-PRE-001 BlueX.
- **Propuesta:** Asegurar wp-config.php NO en repo público. Mover keys a `wp-config-private.php` (chmod 600, no-served). Considerar key rotation post-audit.
- **Sprint:** S1

### F-09-005: Brevo upstream "Carrito abandonado" 0 traffic — setup pendiente bloquea CLEAN-002
- **Severidad:** P1
- **Categoría:** SETUP
- **Descripción:** Workflow Brevo upstream "Carrito abandonado" activo desde 08-04-2026 con 0 Iniciado / 0 Terminado. Causa probable:
  - Sender domain `akibara.cl` no validado en Brevo
  - Plugin oficial tracker JS no firing en checkout
  - Conflicto entre `cart-abandoned/module.php` local (limpia transient en thankyou) y tracker upstream
- **Propuesta:** Orden estricto secuencial:
  1. **S1 setup pre-cleanup:** Validar sender domain Brevo, agregar SPF/DKIM/DMARC Cloudflare, verificar plugin tracker activo. Smoke test producto test 24261 + alejandro.fvaras@gmail.com → checkout sin pagar → esperar 60min → verificar Brevo Logs
  2. Si tracker firing OK → CLEAN-002 (delete módulo local)
  3. Si NO firing → mantener cart-abandoned local + investigar conflict
- **Sprint:** S1 (setup), S2 (cleanup si OK)

### F-09-006: marketing-campaigns "cumpleanos" + "customer_anniversary" sin opt-in
- **Severidad:** P1
- **Categoría:** GROWTH-READY (parcial)
- **Archivo(s):** `marketing-campaigns/module.php:1117-1129, 1183-1202`
- **Descripción:** Segmentos `birthday_today` y `customer_anniversary_today` implementados completos con templates editoriales PERO el campo `_akb_birthday_mmdd` se busca en user_meta o order meta y **nadie lo escribe**. Cumpleaños segment siempre vacío → email cumpleaños nunca se envía.
- **Propuesta:** Marcar `[GROWTH-DEFERRED]`:
  1. Campo opt-in checkbox + date picker MM-DD en checkout
  2. Save hook en woocommerce_checkout_update_order_meta
  3. Opción en mi-cuenta para editar/quitar
- **Sprint:** S3
- **Requiere mockup:** SÍ

### F-09-007: welcome-discount module deshabilitado por default — feature growth-ready
- **Severidad:** P1
- **Archivo(s):** `welcome-discount/module.php:62-65`
- **Descripción:** Módulo (popup lightbox + double opt-in + 10% OFF cupón con anti-abuso ~40 dominios temporales + RUT validation + captcha matemático + audit log + 11 templates email) está completo y testeado pero OFF por default. El popup `popup` (más simple) está activo en cambio. Coexistencia conflictiva (suprimido parcial con filter pero admin no lo sabe).
- **Propuesta:** Decidir: **A)** Activar welcome-discount + deshabilitar popup simple. **B)** Dejar popup simple + eliminar welcome-discount (~1.7K LOC).
- **Sprint:** S2-S3 (decisión + deploy + monitor)
- **Requiere mockup:** SÍ

### F-09-008: back-in-stock módulo activo pero sin tráfico documentado
- **Severidad:** P1
- **Categoría:** GROWTH-READY
- **Archivo(s):** `back-in-stock/module.php`
- **Descripción:** Módulo completo: form en PDP de productos agotados, AJAX subscribe, schedule notify a 5min post-restock, email branded, conversion tracking, admin panel con stats. Tabla `wp_akb_bis_subs` probablemente vacía (3 clientes hoy). Hook `woocommerce_after_single_product` prio 8 — verificar no colisiona con theme override que omite `woocommerce_single_product_summary`.
- **Propuesta:** Marcar `[GROWTH-DEFERRED]` activado. Pre-launch check (S2): producto agotado real (24263) + flow E2E con email alejandro.fvaras@gmail.com → simular restock manual via wp-cli → verificar email llega. Una vez confirmado → eliminar duplicado theme (F-09-003).
- **Sprint:** S2

### F-09-009: next-volume + series-notify crons sin documentar wp-cron config
- **Severidad:** P1
- **Categoría:** SETUP
- **Archivo(s):** `next-volume/module.php:31-38`, `series-notify/module.php:206-212`
- **Descripción:** Ambos schedulean crons daily via `wp_schedule_event(time(), 'daily', 'hook_name')`. Pero `wp-config.php:103` define `DISABLE_WP_CRON = true`. Si Hostinger crontab no bien configurado → crons no firing → next-volume y series-notify silenciosamente no envían (memoria F-PRE-004 confirma "no funcionando").
- **Propuesta:**
  1. Validar Hostinger crontab `wget -q -O - https://akibara.cl/wp-cron.php > /dev/null 2>&1` cada 5min
  2. Verify wp-cli: `bin/wp-ssh cron event list` → ver akibara_next_volume_check + akb_series_notify_cron
  3. Si crons no scheduled → wp cron event run hook_name manual + monitor
- **Sprint:** S1

### F-09-010: referrals 4 endpoints Brevo direct sin retry / circuit breaker
- **Severidad:** P1
- **Archivo(s):** `referrals/module.php:673, 727, 1451, 1575`
- **Descripción:** 4 callsites de `wp_remote_post('https://api.brevo.com/v3/smtp/email')` directo sin retry, sin circuit breaker, sin `test_recipient()` guard. Si Brevo está caído, falla silenciosa.
- **Propuesta:** Refactor a `AkibaraBrevo::send_transactional()` (que ya tiene `test_recipient()` + `akb_circuit_guarded_request`). 4 callsites → 4 cambios pequeños, ~30-50 LOC delete.
- **Sprint:** S2

## Findings P2-P3

### F-09-011: review-incentive y next-volume direct Brevo calls (idem F-09-010)
- **Severidad:** P2
- **Archivo(s):** review-incentive/module.php:278, next-volume/module.php:335, series-notify/module.php:396, popup/module.php:431+460+491, review-request/module.php:716
- **Descripción:** Mismo patrón F-09-010 — múltiples módulos hacen `wp_remote_post` directo a Brevo en vez de usar clase compartida. 11 sitios duplicados.
- **Propuesta:** Refactor consistente a `AkibaraBrevo::send_transactional()` (smtp) y `AkibaraBrevo::sync_contact()` (contacts). Borra ~200 LOC duplicado.
- **Sprint:** S3

### F-09-012: marketing-campaigns sin gating manual / preview confirm
- **Severidad:** P2
- **Archivo(s):** `marketing-campaigns/module.php:1217-1297`
- **Descripción:** Cuando admin programa campaña con send_at futuro, Action Scheduler dispatches sin confirmation/preview. Si admin se equivocó en segmento, batch ejecuta y consume cap.
- **Propuesta:** (1) Confirmation gate si recipients_count > 100 requiere "I confirm I will send to N recipients". (2) Pre-execute hook 30min antes con sentry/email admin + cancel link
- **Sprint:** S3

### F-09-013: welcome-series template hardcoded a 3 emails — falta opt-out parcial
- **Severidad:** P2
- **Archivo(s):** `marketing-campaigns/welcome-series.php:175-186`
- **Descripción:** Welcome series envía 3 emails sin opción "solo cupón sí, FOMO no". Único opt-out es Brevo blacklist global = pierde TODO marketing.
- **Propuesta:** wp_option `akb_ws_optout_<email>` con flags por step + footer link "Solo quiero el cupón, no más recordatorios". O usar Brevo lista "Welcome Series" separada con unsubscribe nativo.
- **Sprint:** S3

### F-09-014: theme/inc/encargos.php Brevo direct sin handle de error
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/encargos.php:76-93`
- **Descripción:** AJAX encargos sincroniza contact a Brevo lista 2 con timeout 5s, sin verificar response code, sin retry. Si Brevo down → contact no sincronizado pero usuario ve "Encargo enviado".
- **Propuesta:** Refactor a `AkibaraBrevo::sync_contact()` que ya maneja errors + retry + circuit breaker
- **Sprint:** S2

### F-09-015: Coverage HTML files (4340 archivos) en plugin/coverage/
- **Severidad:** P2
- **Categoría:** DEAD-CODE
- **Archivo(s):** `plugins/akibara/coverage/html/**`
- **Descripción:** Carpeta coverage/html/ con artifacts phpunit code coverage report en repo. Inflando plugin size + slowing scans + accidentalmente served if .htaccess gap.
- **Propuesta:** `coverage/` a `.gitignore` + `git rm -r --cached coverage/`. Si CI necesita, regenerar en pipeline-temp.
- **Sprint:** S1

### F-09-016: vendor/ con 4340 archivos en plugin
- **Severidad:** P2
- **Categoría:** DEAD-CODE
- **Descripción:** Composer dependencies committed. Auto-load gestionado via vendor/autoload.php (OK), pero dev dependencies inflando.
- **Propuesta:** Mesa-02/15. Agregar vendor/ a .gitignore, run composer install --no-dev en deploy
- **Sprint:** S1

### F-09-017: WC theme emails preheader filter sin listeners
- **Severidad:** P3
- **Categoría:** OVER-ENGINEERED
- **Archivo(s):** `themes/akibara/woocommerce/emails/email-header.php:75`
- **Descripción:** Header WC tiene `apply_filters('akibara_email_preheader', '')` pero ningún plugin/theme registra ese filter. Comment dice "Cada template lo registra" pero grep confirma 0 listeners.
- **Propuesta:** A) Borrar apply_filters block (líneas 75-79). B) Documentar uso esperado y dejar (low value para 3 clientes).
- **Sprint:** S3

### F-09-018: WC theme emails — logo URL hardcoded fallback con scaled-eNNNN suffix
- **Severidad:** P3
- **Archivo(s):** `themes/akibara/woocommerce/emails/email-header.php:34-35`, `src/Infra/EmailTemplate.php:67`
- **Descripción:** Fallback final logo: `https://akibara.cl/wp-content/uploads/2022/02/1000000826-2-scaled-e1758692190673.png`. Comentario: "WordPress renombra el archivo cuando admin hace crop". Si admin crop nuevamente → URL cae a 404 y emails sin logo.
- **Propuesta:** Subir logo PNG limpio a path estable `wp-content/uploads/akibara/logo-email.png`. Test/monitoring que verifique HEAD 200 OK semanalmente.
- **Sprint:** S2

### F-09-019: Brevo segmentación demográfica (shonen→shonen) FACTIBLE
- **Severidad:** P3
- **Categoría:** GROWTH-READY (info)
- **Archivo(s):** `plugins/akibara/modules/brevo/module.php:50-62`
- **Descripción:** Usuario quiere "Brevo segmentación demográfica". Módulo brevo ya define mapping `AKIBARA_BREVO_CAT_MAP` (shonen=5, seinen=6, shojo=7, etc). Brevo Free tier soporta segmentation por listas + custom attributes (FAVORITE_GENRE, FAVORITE_SERIE, FAVORITE_EDITORIAL).
- **Propuesta:** **Confirmar feasible NOW** sin custom code. Cuando dueño quiera activar: (1) Validar listas Brevo creadas; (2) Backfill (botón "Sincronizar ahora"); (3) Crear primera campaña test segmento `manga_buyers` con cupón `SHONEN10`
- **Sprint:** S3 cuando dueño quiera growth marketing

### F-09-020: cart-abandoned race condition — flock no funciona en NFS
- **Severidad:** P3
- **Archivo(s):** `cart-abandoned/module.php:165-194`
- **Descripción:** `akibara_ca_update_index()` usa `flock()` sobre `sys_get_temp_dir() . '/akb_ca_index.lock'`. En NFS comportamiento indefinido. Para 3 clientes no-issue.
- **Propuesta:** Si crece >50 simultáneos, migrar a transient lock atomic. Para hoy, dejar.
- **Sprint:** S4+

### F-09-021: marketing-campaigns segments hardcoded a categorías ES — riesgo si admin renombra
- **Severidad:** P3
- **Archivo(s):** `marketing-campaigns/module.php:86-98, 1158-1166`
- **Descripción:** Segments `manga_buyers` filtra por `in_array('manga', $c['cats'], true)` (slug exacto). Si admin renombra slug, segment queda vacío silenciosamente.
- **Propuesta:** Documentar slugs reservados. Test salud: si segment retorna 0 cuando hay buyers de manga → admin notice.
- **Sprint:** S4

## Cross-cutting flags

- **mesa-10 security:** F-09-004 API key Brevo plain text. F-09-003 bypass parcial guard para emails @gmail.com en flujo theme back-in-stock duplicado.
- **mesa-02 tech-debt:** F-09-015 coverage 4340 archivos. F-09-016 vendor 4340 archivos. F-09-017 filter preheader sin listeners.
- **mesa-15 architect:** F-09-007 welcome-discount vs popup decisión arquitectónica. F-09-011 11 callsites duplicados refactor a clase compartida.
- **mesa-22 wp-master / mesa-pm:** F-09-002 upgrade Brevo Starter $9 pre-launch growth. F-09-005 setup DNS + sender domain bloquea CLEAN-002. F-09-009 validar Hostinger crontab. F-09-019 Brevo demographic segmentation factible sin custom.
- **mesa-23 PM sprint planner:** **CRITICAL F-09-001** sacar CLEAN-003 del backlog. F-09-006/007/008 tres growth features con código listo pero no activas — ordenar prioritization.

## Áreas que NO cubrí

- DNS / Cloudflare config para SPF/DKIM/DMARC (mesa-23 valida con dueño)
- Brevo dashboard inspección directa (requiere login Brevo)
- Brevo plugin third-party config en wp-admin
- Sentry custom mu-plugin emails (CLEAN-001 — flag: validar que delete no rompe alertas Sentry)
- akibara-whatsapp plugin emails (no email dispatch encontrado)
- Tests E2E rendering en clientes reales (Outlook, Gmail, Apple Mail)
- Brevo upstream rate-limit / API quotas
- GDPR / Ley 19.628 compliance audit completo templates (mesa-19 cubre)
