# Cleanup Plan Akibara — Mesa Técnica 2026-04-26

**Fecha:** 2026-04-26
**Líder síntesis:** mesa-01 lead arquitecto + ratificación final mesa-23 PM
**Status seeds:** 3 cancelados explícitos (CLEAN-001/003/004) + 1 condicional (CLEAN-002) + 10 nuevos identificados en R1
**Total cleanup items:** 14

> **Cómo se usa:** este archivo es la única fuente de verdad para deletions/cleanup. Cada item tiene LOC removidos, razón, findings respaldo, pre-cleanup backup, post-cleanup smoke test, rollback <30 min, sprint asignado y owner skill. Ratifica decisiones del CLEANUP-PLAN R2 con razones técnicas documentadas para cada cancelación.

---

## Reglas duras universales

- DOBLE OK explícito de Alejandro para items destructivos en server.
- Backup pre-cleanup obligatorio en `.private/snapshots/` o `.private/backups/`.
- Rollback documentado <30 min en cada item.
- Smoke test post-cleanup obligatorio antes de marcar DONE.
- B-S1-SETUP-00 RUNBOOK-DESTRUCTIVO.md DEBE estar creado antes de cualquier item destructivo.
- B-S1-SEC-01 WP core verify-checksums DEBE pasar antes de cleanup destructivos.

---

## CLEAN seeds — status final ratificado

### CLEAN-001 — `mu-plugins/akibara-sentry-customizations.php` — CANCELADO

- **Status:** ❌ RECHAZADO con razón técnica documentada
- **LOC que NO se eliminan:** 273
- **Razón cancelación:** Mu-plugin es **infraestructura load-bearing** del Sentry stack, NO customizations decorativas:
  1. Define `WP_SENTRY_PHP_DSN` constante (líneas 49-58) que el plugin upstream `wp-sentry-integration v8.x` REQUIERE para inicializar. Sin esta constante, Sentry NO arranca → cero error tracking en prod.
  2. Scrub PII chileno (líneas 67-151) — filtro `wp_sentry_before_send` redacta RUT, teléfono +56, email antes de enviar events a Sentry US. Sin este filtro, datos personales de clientes chilenos van plain text a servers Sentry en USA → **violación Ley 19.628 + Ley 21.719 + transferencia internacional sin base legal**.
  3. Override `sample_rate=1.0` y `send_default_pii=false` (líneas 262-273) — defaults seguros que el plugin upstream no establece.
- **Findings respaldo:** F-10-002 P0, F-15-011 (validación pattern mu-plugins).
- **Statement original del usuario "solo ocupamos el plugin no el custom" interpretado como confusión** — el plugin upstream EXISTE pero el mu-plugin custom es lo que lo HACE FUNCIONAR.
- **Acción correctiva:** Mantener mu-plugin permanentemente como infraestructura. Documentar como ADR `docs/adr/sentry-stack-architecture.md` para futuros mantenedores (B-S1-CLEAN-04 del BACKLOG).
- **Sprint asociado:** S1 (solo doc ADR, NO eliminar código)
- **Owner skill:** `[SECURITY]` + `[ARCHITECTURE]`
- **Mejora alternativa futura (S2-S3):** Si se quiere reducir custom code:
  1. Mover `define('WP_SENTRY_PHP_DSN', SENTRY_DSN)` a `wp-config.php` principal
  2. Configurar PII scrubbing del lado Sentry dashboard (Inbound Filters + Custom Regex)
  3. Aceptar pérdida breadcrumbs WC custom (order.created, payment.failed, email.failed, cron.missed)
  4. Smoke test 24h post-deploy verificando Sentry sigue recibiendo events

---

### CLEAN-002 — `plugins/akibara/modules/cart-abandoned/` — CONDICIONAL

- **Status:** ⚠️ MANTENIDO CONDICIONAL con secuencia obligatoria
- **LOC potencialmente removidos:** ~539 + cron + tabla `akibara_abandoned_carts` + transients
- **Razón:** Brevo upstream "Carrito abandonado" workflow ACTIVO desde 08-04-2026 con 0 traffic confirmado (F-PRE-002 + F-09-005). Si borramos local antes de validar upstream firing → revenue silent loss (clientes que abandonan carrito no reciben email).
- **Findings respaldo:** F-09-005 P1, F-PRE-002 P1, F-23-001 P2.
- **Pre-cleanup obligatorio (S1 setup — B-S1-EMAIL-01):**
  1. Validar sender domain `akibara.cl` en Brevo dashboard (UI "Senders & IP" → ✓ verified)
  2. Configurar SPF en Cloudflare DNS (TXT record incluyendo Brevo) — propagation 1-24h
  3. Configurar DKIM en Cloudflare DNS (TXT `brevo._domainkey.akibara.cl`)
  4. Configurar DMARC en Cloudflare DNS (TXT `_dmarc.akibara.cl`)
  5. Verificar plugin oficial Brevo tracker activo en wp-admin
  6. Smoke test: producto test 24261 + login `alejandro.fvaras@gmail.com` → cart + abandonment 1h → verify Brevo Logs muestra evento + email llega
  7. **Monitoring 24-48h confirmando workflow upstream recibe eventos**
- **Cleanup action (S2 condicional — B-S2-EMAIL-01):**
  ```bash
  # Backup pre-cleanup
  bin/mysql-prod akibara_db wp_akb_abandoned_carts > .private/backups/2026-04-NN-akibara_abandoned_carts.sql
  tar -czf .private/snapshots/2026-04-NN-cart-abandoned-module.tar.gz wp-content/plugins/akibara/modules/cart-abandoned/

  # DOBLE OK Alejandro

  # Remove module + table + cron
  rm -rf wp-content/plugins/akibara/modules/cart-abandoned/
  bin/wp-ssh cron event delete akb_cart_abandoned_check
  bin/mysql-prod -e "DROP TABLE akibara_abandoned_carts"
  bin/wp-ssh transient delete --all
  ```
- **Post-cleanup smoke test:**
  - `bin/wp-ssh cron event list | grep -i abandon` → expect 0 results
  - `bin/mysql-prod -e "SHOW TABLES LIKE '%abandoned%'"` → expect 0 results
  - Producto test 24261, abandonar cart 1h, verify email Brevo upstream llega
- **Rollback (<30 min):** restore tar.gz módulo + restore SQL dump tabla + reactivar cron registration
- **Sprint asignado:** S1 setup (B-S1-EMAIL-01) + S2 cleanup condicional (B-S2-EMAIL-01)
- **Owner skill:** `[EMAIL]` + `[SETUP]` + `[CLEANUP]`
- **Esfuerzo:** L (1-2 días total: 4h activas + 24-48h espera DNS + 24-48h monitoring)
- **Si tracker NO firing post-setup:** mantener cart-abandoned local + investigar conflict + escalar.

---

### CLEAN-003 — `mu-plugins/akibara-brevo-smtp.php` — CANCELADO

- **Status:** ❌ RECHAZADO con razón técnica documentada
- **LOC que NO se eliminan:** 270
- **Razón cancelación:** Mu-plugin es **infraestructura crítica load-bearing**:
  1. Registra `pre_wp_mail` filter (línea 67) que intercepta TODO `wp_mail()` y lo enruta a Brevo Transactional API.
  2. **Hostinger BLOQUEA PHP mail() directo** (incidente documentado en mu-plugin header líneas 11-13: "Hostinger bloqueó la cuenta por intentos repetidos de PHP mail() desde contextos donde el theme no se carga (cron, CLI)").
  3. **7 archivos dependen de su función `akb_brevo_get_api_key()`:**
     - `plugins/akibara/src/Infra/Brevo.php:30-31`
     - `plugins/akibara/modules/health-check/module.php:149`
     - `plugins/akibara/modules/brevo/module.php:354`
     - `themes/akibara/inc/newsletter.php:90-91`
     - `themes/akibara/inc/health.php:110-111`
     - `themes/akibara/inc/encargos.php:72-73`
     - `plugins/akibara-reservas/includes/class-reserva-cron.php:107`
  4. **Si se ejecuta CLEAN-003 → ROTURA TOTAL email delivery + Hostinger vuelve a bloquear cuenta.** Confirmado 72 emails ya enviados via este path (`akibara_brevo_mail_sent_count`).
- **Findings respaldo:** F-09-001 P0, F-10-003 P0, F-02-002 P0, F-15-011 (validación pattern).
- **Statement original "no es necesario, nunca fue de iteración pasada" interpretado como confusión** con OTRO componente (posiblemente módulo `akibara/modules/brevo/` o setup form admin nunca completado). El mu-plugin SÍ se usa en runtime crítico.
- **Acción correctiva:** Mantener permanentemente. Documentar como ADR `docs/adr/brevo-smtp-architecture.md` (B-S1-CLEAN-04 del BACKLOG).
- **Sprint asociado:** S1 (solo doc ADR, NO eliminar código)
- **Owner skill:** `[EMAIL]` + `[ARCHITECTURE]`
- **Mejora relacionada (S2 — F-15-018):** Migrar callsite `themes/akibara/inc/encargos.php` a usar `AkibaraBrevo::sync_contact()` (shim plugin akibara) para reducir acoplamiento theme → mu-plugin. NO elimina mu-plugin, solo mejora consistency. Cubierto en B-S2B-BACK-03.

---

### CLEAN-004 — Constante `AKB_BREVO_API_KEY` en `wp-config.php` — CANCELADO

- **Status:** ❌ RECHAZADO — alimenta CLEAN-003 que se mantiene
- **Razón cancelación:** La constante NO es legacy — alimenta `akb_brevo_get_api_key()` del mu-plugin (CLEAN-003 confirmed critical). Sin la constante, **email delivery falla**.
- **Findings respaldo:** F-09-001 P0, F-10-003 P0, F-02-002 P0.
- **Issue real (que sí requiere acción separada — NO delete):**
  - La key actual returna `"API Key is not enabled"` en API call → setup Brevo INCOMPLETO (sender domain no validated, key restrictions aplicadas).
  - **Acción correctiva (NO delete):**
    1. **Mesa-09 F-09-005 P1:** completar setup Brevo (validar sender domain `akibara.cl` en Brevo dashboard, agregar SPF/DKIM/DMARC en Cloudflare DNS) — ejecutado en B-S1-EMAIL-01.
    2. **Generar nueva API key con permisos completos** en Brevo dashboard (NO rotar — generar new + retire old per memoria `project_no_key_rotation_policy`).
    3. Update `AKB_BREVO_API_KEY` en `wp-config-private.php` con nueva key (B-S1-EMAIL-03).
    4. Smoke test: order processing → verify email llega via Brevo.
- **Acción adicional (F-09-004 P1 — B-S1-EMAIL-03):** mover keys (Brevo, GA4 secret, Maps, Sentry) a `wp-config-private.php` (chmod 600, no served por Apache) en lugar de plain text en wp-config.php principal.
- **Sprint asociado:** S1 (parte de pre-cleanup CLEAN-002 + B-S1-EMAIL-03)
- **Owner skill:** `[EMAIL]` + `[SECURITY]`

---

## CLEAN nuevos identificados en R1

### CLEAN-005 — `vendor/` + `coverage/` + dev tooling del plugin akibara

- **LOC removidos:** ~74 MB (vendor 55 MB + coverage 19 MB) + composer artifacts (composer.json/.lock + phpcs.xml + phpstan.neon + baselines + phpunit.xml + tests/)
- **Razón:** Dev tooling deployado a prod sin protección. Coverage HTML expone source code mapeado por línea (recon completo). composer.json declara TODO bajo `require-dev` → vendor/ existe SOLO para CI/dev tooling, NO runtime.
- **Findings respaldo:** F-02-001 P0, F-02-025 P3, F-09-015 P2, F-09-016 P2, F-10-006 P0, F-10-007 P0, F-15-008 P1, F-22-004 P0.
- **Pre-cleanup:**
  1. Verificar autoload runtime está en `includes/autoload.php` (NO depende de `vendor/autoload.php`)
  2. Snapshot completo plugin: `tar -czf .private/snapshots/2026-04-NN-akibara-plugin-pre-cleanup.tar.gz wp-content/plugins/akibara/`
  3. Verificar mismo patrón en `plugins/akibara-reservas/` (tiene `bin/`, `tests/`)
- **Cleanup action (acción doble):**
  1. Crear `bin/deploy.sh` (B-S1-SETUP-04) o `.distignore` excluyendo: `vendor/`, `coverage/`, `tests/`, `.phpunit.cache/`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `phpcs.xml`, `phpstan.neon`, `.phpcs-baseline`, `.pcp-baseline`, `phpstan-baseline.neon`
  2. Defense in depth: agregar `wp-content/plugins/akibara/.htaccess`:
     ```apache
     <FilesMatch "(composer\.(json|lock)|phpunit\.xml.*|phpcs\.xml.*|phpstan(\..+)?(\.neon)?|.*-baseline)$">
       Require all denied
     </FilesMatch>
     <DirectoryMatch ".*(vendor|coverage|tests|\.phpunit\.cache).*">
       Require all denied
     </DirectoryMatch>
     ```
  3. Replicar `.htaccess` defensivo en `plugins/akibara-reservas/` y `themes/akibara/`
- **Post-cleanup smoke test:**
  - `curl -o /dev/null -w "%{http_code}\n" https://akibara.cl/wp-content/plugins/akibara/coverage/html/index.html` → expect 403/404
  - `curl -o /dev/null -w "%{http_code}\n" https://akibara.cl/wp-content/plugins/akibara/composer.json` → expect 403
  - Plugin functionality smoke: load home + admin + checkout test producto 24261
- **Rollback (<10 min):** restore tar.gz + remove .htaccess
- **Sprint asignado:** S1 (B-S1-SEC-03)
- **Owner skill:** `[SECURITY]` + `[SETUP]` + `[CLEANUP]`
- **Esfuerzo:** M (2-3h: deploy script + .htaccess + smoke test + replicar reservas)

---

### CLEAN-006 — `themes/akibara/inc/enqueue.php.bak-2026-04-25-pre-fix`

- **LOC removidos:** 12.4 KB (~340 líneas)
- **Razón:** Backup file leftover del fix CSS preload incident. WP NO carga `.bak-*` files via require, pero queda como ruido + accesible via URL si regex fuera laxo. Commit del fix está en main (verificar).
- **Findings respaldo:** F-02-005 P3, F-07-005 P3, F-15-007 P3, F-22-018 P2.
- **Pre-cleanup:**
  1. Verificar commit del fix está en main: `git log --all --oneline themes/akibara/inc/enqueue.php | head -5`
  2. Snapshot: `tar -czf .private/snapshots/2026-04-NN-enqueue-bak.tar.gz themes/akibara/inc/enqueue.php.bak-2026-04-25-pre-fix`
- **Cleanup action:**
  ```bash
  rm wp-content/themes/akibara/inc/enqueue.php.bak-2026-04-25-pre-fix
  ```
- **Post-cleanup smoke test:**
  - Home load → expect HTTP 200 + CSS correcto cargado
  - `find themes/akibara/inc/ -name "*.bak*"` → expect 0
- **Rollback (<2 min):** restore tar.gz
- **Sprint asignado:** S1 (B-S1-CLEAN-01)
- **Owner skill:** `[CLEANUP]`
- **Esfuerzo:** S (5 min)

---

### CLEAN-007 — `themes/akibara/hero-section.css` (root duplicado)

- **LOC removidos:** 306 líneas (NO enqueued, OBSOLETO)
- **Razón:** Hay 2 archivos `hero-section.css`:
  (a) `themes/akibara/hero-section.css` (root, 306 líneas, NO enqueued — verificado en `inc/enqueue.php`)
  (b) `themes/akibara/assets/css/hero-section.css` (243 líneas, ENQUEUED)
  Diff confirma diferencias significativas. El de root es legacy.
- **Findings respaldo:** F-08-008 P3.
- **Pre-cleanup:**
  1. Confirmar `inc/enqueue.php` NO referencia root path: `grep -n "hero-section.css" themes/akibara/inc/enqueue.php` → expect solo `assets/css/hero-section.css`
  2. Snapshot: `tar -czf .private/snapshots/2026-04-NN-hero-root.tar.gz themes/akibara/hero-section.css`
- **Cleanup action:**
  ```bash
  rm wp-content/themes/akibara/hero-section.css
  ```
- **Post-cleanup smoke test:**
  - Home load → expect hero renders correctamente con CSS de assets/css/
  - DevTools Network → verify cargó `assets/css/hero-section.css`, NO el root
- **Rollback (<2 min):** restore tar.gz
- **Sprint asignado:** S1 (B-S1-CLEAN-02)
- **Owner skill:** `[CLEANUP]` + `[FRONTEND]`
- **Esfuerzo:** S (10 min)

---

### CLEAN-008 — `themes/akibara/setup.php` (root vs inc/setup.php duplicado byte-idéntico)

- **LOC removidos:** ~150 líneas (un archivo, no se sabe cuál hasta verificar carga)
- **Razón:** `diff setup.php inc/setup.php` produce salida vacía. Si ambos cargan, PHP fatal error (function redeclaration). Si solo uno, el otro es dead code.
- **Findings respaldo:** F-07-004 P2.
- **Pre-cleanup:**
  1. Verificar cuál carga: `grep -n "require.*setup" themes/akibara/functions.php`
  2. Si carga `inc/setup.php` → root es dead. Si carga `setup.php` (root) → `inc/setup.php` es dead.
  3. Snapshot ambos: `tar -czf .private/snapshots/2026-04-NN-setup-files.tar.gz themes/akibara/setup.php themes/akibara/inc/setup.php`
- **Cleanup action:**
  - Borrar el redundante (el que NO se carga via require).
- **Post-cleanup smoke test:**
  - Home load → expect HTTP 200 (no PHP fatal)
  - Admin load → expect HTTP 200
  - Si `setup.php` define `add_theme_support` u otros, validar features WP siguen activos
- **Rollback (<2 min):** restore tar.gz
- **Sprint asignado:** S1 (B-S1-CLEAN-03)
- **Owner skill:** `[CLEANUP]` + `[FRONTEND]`
- **Esfuerzo:** S (15 min: verify cuál carga + delete redundante + smoke test)

---

### CLEAN-009 — `themes/akibara/inc/woocommerce.php:834-904` (back-in-stock duplicado)

- **LOC removidos:** ~70 líneas
- **Razón:** Theme tiene sistema completo back-in-stock que duplica módulo `plugins/akibara/modules/back-in-stock/`. Bug: ambos hookeados a `woocommerce_product_set_stock_status` → cliente recibe 2 emails. Theme version usa `wp_mail` con `From: no-reply@akibara.cl` (NO validado en Brevo) y NO tiene unsubscribe link (incumple Ley 19.628 + Brevo policy). Bypass parcial del email-testing-guard.
- **Findings respaldo:** F-09-003 P0, F-09-008 P1, F-PRE-019.
- **Pre-cleanup:**
  1. Verificar producción si meta `_akibara_notify_emails` tiene datos reales hoy:
     ```sql
     SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key='_akibara_notify_emails' AND meta_value != '' LIMIT 100
     ```
  2. Si hay subscriptores legacy → migrar a tabla `wp_akb_bis_subs` del módulo plugin (script migración separado)
  3. Snapshot: `tar -czf .private/snapshots/2026-04-NN-theme-bis-duplicate.tar.gz themes/akibara/inc/woocommerce.php`
- **Cleanup action:**
  - Eliminar líneas 834-904 de `themes/akibara/inc/woocommerce.php`
  - Eliminar AJAX endpoint `akibara_notify_stock` registration
  - Eliminar cron `akibara_send_restock_batch` registration
- **Post-cleanup smoke test:**
  - Producto agotado real (24263) + flow E2E con email `alejandro.fvaras@gmail.com` → simular restock manual via wp-cli
  - Verify módulo plugin envía 1 solo email (no 2)
  - Verify email tiene unsubscribe link
- **Rollback (<10 min):** restore tar.gz
- **Sprint asignado:** S2 (B-S2-EMAIL-03) — después de validar Brevo upstream firing en CLEAN-002 sequence
- **Owner skill:** `[EMAIL]` + `[BACKEND]` + `[CLEANUP]`
- **Esfuerzo:** S (50-100 LOC delete + migración data si aplica)

---

### CLEAN-010 — `wp_bluex_logs` table — TRUNCATE (~65k rows con BlueX API key plain text)

- **Rows removidos:** ~65,640 (entre 2026-04-10 y 2026-04-12)
- **Razón:** Plugin third-party BlueX loggea cada request HTTP con header completo `x-api-key: QUoO07ZRZ12tzkkF8yJM9am7uhxUJCbR7f6kU5Dz` en `log_body`. Cualquier export SQL → llave expuesta. NO modificar plugin third-party (solo cleanup datos + monitoring).
- **Findings respaldo:** F-10-001 P0, F-PRE-001 P0.
- **Pre-cleanup:**
  1. Backup mysqldump tabla: `bin/mysql-prod akibara_db wp_bluex_logs > .private/backups/2026-04-NN-wp_bluex_logs.sql`
  2. Verificar: `bin/mysql-prod -e "SELECT COUNT(*) FROM wp_bluex_logs WHERE log_body LIKE '%QUoO07Z%'"` (debe coincidir cantidad esperada)
  3. **DOBLE OK explícito Alejandro** antes de TRUNCATE
- **Cleanup action:**
  ```sql
  TRUNCATE TABLE wp_bluex_logs;
  ```
- **Post-cleanup actions complementarias (NO rotation per memoria `project_no_key_rotation_policy`):**
  1. **Reportar a soporte BlueX** (responsible disclosure): plugin loggea API key en plaintext.
  2. **NO rotar BlueX API key**. En su lugar:
     - Mover `AKB_BLUEX_API_KEY` a `wp-config-private.php` (chmod 600)
     - Verify Apache/LiteSpeed NO sirve `wp-config-private.php` (curl → 403/404)
  3. **Cron mensual** DELETE rows > 30 días: crear `mu-plugins/akibara-bluex-logs-purge.php` (custom mu-plugin)
  4. Verificar `.gitignore` cubre `.private/` y `wp-config-private.php`
- **Smoke test:**
  - `bin/mysql-prod -e "SELECT COUNT(*) FROM wp_bluex_logs"` → expect 0
  - Trigger BlueX API call (cualquier order que dispare integración) → verify log entry nuevo se crea (plugin sigue funcional, solo limpiamos history)
  - Verificar nueva key NO leak: `bin/mysql-prod -e "SELECT COUNT(*) FROM wp_bluex_logs WHERE log_body LIKE '%QUoO07Z%'"` → 0 (post-population con monitoring)
- **Rollback (<30 min):** restore SQL dump (recupera logs históricos pero key sigue expuesta — solo recover si TRUNCATE rompió algo crítico, improbable).
- **Sprint asignado:** S1 (B-S1-SEC-04)
- **Owner skill:** `[SECURITY]` + `[BACKEND]`
- **Esfuerzo:** M (3h: backup + TRUNCATE + key migration + cron mensual)

---

### CLEAN-011 — Leftover plugin folders en `wp-content/uploads/`

- **Folders removidos:**
  - `revslider/` (8.4 MB jpg/mp4)
  - `elementor/thumbs/` (140K webp)
  - `wpseo-redirects/` (8K)
  - `mailpoet/` (4K)
  - `mailchimp-for-wp/` (4K)
  - `pum/` (84K)
  - `annasta-filters/` (8K)
  - `smush/` (184K incluyendo 4 .php files: index.php, integrations-log.php, resize-log.php, smush-log.php)
  - `cache/wpml/twig/` (6 PHP twig templates compilados)
- **Razón:** Plugins removidos de prod dejaron sus uploads. Más grave: `cache/wpml/twig/*.php` son archivos PHP compilados ejecutables. Si WPML namespace queda registrado por algún include leftover, podrían ejecutar.
- **Findings respaldo:** F-02-006 P2, F-09-015/016 P2, F-PRE-009 P3.
- **Pre-cleanup:**
  1. **Mesa-10 audit obligatorio:** confirmar 0 archivos PHP sospechosos (no backdoors). Search patterns `eval`, `base64_decode`, `gzinflate`, `str_rot13`:
     ```bash
     find wp-content/uploads/ -name "*.php" -exec grep -l "eval\|base64_decode\|gzinflate\|str_rot13" {} \;
     ```
  2. Verificar `.htaccess` raíz de uploads NO permite ejecutar PHP — si lo permite, BLOQUEAR PRIMERO:
     ```apache
     <Files *.php>
       Deny from all
     </Files>
     ```
  3. Snapshot folders:
     ```bash
     tar -czf .private/snapshots/2026-04-NN-uploads-leftover.tar.gz \
       wp-content/uploads/{revslider,elementor,wpseo-redirects,mailpoet,mailchimp-for-wp,pum,annasta-filters,smush,cache/wpml}
     ```
- **Cleanup action (después de mesa-10 OK):**
  ```bash
  rm -rf wp-content/uploads/revslider/
  rm -rf wp-content/uploads/elementor/
  rm -rf wp-content/uploads/wpseo-redirects/
  rm -rf wp-content/uploads/mailpoet/
  rm -rf wp-content/uploads/mailchimp-for-wp/
  rm -rf wp-content/uploads/pum/
  rm -rf wp-content/uploads/annasta-filters/
  rm -rf wp-content/uploads/smush/
  rm -rf wp-content/uploads/cache/wpml/
  ```
- **Post-cleanup smoke test:**
  - Home load → expect HTTP 200
  - Admin load → expect HTTP 200
  - `find wp-content/uploads/ -name "*.php"` → expect 0 (o solo `index.php` security)
- **Rollback (<10 min):** restore tar.gz
- **Sprint asignado:** S2 (B-S2-CLEAN-02)
- **Owner skill:** `[SECURITY]` + `[CLEANUP]`
- **Esfuerzo:** S (1h: mesa-10 audit + delete + smoke test)

---

### CLEAN-012 — 4 admin backdoor accounts (SEC-P0-001 expanded)

- **Rows removidos:** 4 wp_users + posts asociados user 6 (~47 reasignados) + Application Passwords + opcional `wp_akb_referrals` row 4
- **Razón:** Backdoors confirmados (typosquat `@akibara.cl.com`, mismo TLD, creados en 4 minutos automatizado). Persistence vector activo.

| ID | Username | Email (typosquat .cl.com) | Created | Posts |
|---|---|---|---|---|
| 5 | `admin_a06d0185` | `admin_fc13558a@akibara.cl.com` | 2025-11-02 16:07:55 | 0 |
| 6 | `admin_3b4206ec` | `admin_32d980f4@akibara.cl.com` | 2025-11-02 16:08:13 | ~47 (verificar legitimidad) |
| 7 | `admin_eae090ac` | `admin_5a64f9c5@akibara.cl.com` | 2025-11-02 16:11:03 | 0 |
| 8 | `admin_55b96b0c` | `admin_e3d7bbed@akibara.cl.com` | 2025-11-02 16:11:16 | 0 |

- **Findings respaldo:** SEC-P0-001, F-10-004 P0, F-10-021 P0, F-02-003 P1, F-PRE-010, F-10-024.
- **Pre-cleanup workflow (Decisión #3 expansion):**
  1. Mesa-10 audit user 6 ~47 posts:
     ```bash
     bin/wp-ssh post list --author=6 --post_status=any --format=csv > .private/audit/user6-posts.csv
     # grep patterns maliciosos en cada post content
     ```
  2. Mesa-10 audit user 18 (`nightmarekinggrimm26`):
     ```bash
     bin/wp-ssh user get 18 --format=json
     # Si customer real con orders → keep
     # Si admin/sospechoso sin justificación → agregar al delete
     ```
  3. Mesa-10 audit Application Passwords:
     ```bash
     bin/wp-ssh user meta get 5 _application_passwords --format=json
     bin/wp-ssh user meta get 6 _application_passwords --format=json
     bin/wp-ssh user meta get 7 _application_passwords --format=json
     bin/wp-ssh user meta get 8 _application_passwords --format=json
     ```
  4. Mesa-10 audit cron events no estándar:
     ```bash
     bin/wp-ssh cron event list --format=csv
     # Search patterns sospechosos
     ```
  5. Backup DB completo: `bin/mysql-prod akibara_db > .private/backups/2026-04-NN-pre-sec-p0-001-FULL.sql`
  6. **DOBLE OK explícito al usuario** antes de delete
- **Cleanup action:**
  ```bash
  # Si user 6 tiene posts legítimos (productos importados):
  bin/wp-ssh user delete 6 --reassign=1 --yes

  # Plain delete:
  bin/wp-ssh user delete 5 --yes
  bin/wp-ssh user delete 7 --yes
  bin/wp-ssh user delete 8 --yes

  # Application Passwords cleanup (si hay):
  bin/wp-ssh user meta delete 5 _application_passwords
  bin/wp-ssh user meta delete 6 _application_passwords
  bin/wp-ssh user meta delete 7 _application_passwords
  bin/wp-ssh user meta delete 8 _application_passwords

  # Si user 18 confirmado malicioso, cleanup wp_akb_referrals:
  bin/mysql-prod -e "DELETE FROM wp_akb_referrals WHERE id=4"
  ```
- **Post-cleanup smoke test:**
  - `bin/wp-ssh user list --role=administrator --format=count` → expect 1 (solo Alejandro)
  - `bin/wp-ssh user list --role=shop_manager` → expect Akibara_shipit
  - Login como Alejandro a wp-admin → verify funciona
  - Visit `/wp-admin/users.php` → verify listado correcto sin huérfanos
  - `bin/wp-ssh user list --search="@akibara.cl.com"` → expect 0
- **Rollback (<15 min):** restore SQL dump completo
- **Sprint asignado:** S1 PRIMER ITEM (B-S1-SEC-02)
- **Owner skill:** `[SECURITY]`
- **Esfuerzo:** M (2-4h: audit + delete + smoke test)
- **Bloqueada por:** B-S1-SEC-01 (verify-checksums clean)

---

### CLEAN-013 — `wp_akb_unify_backup_202604` table (post-30d retention)

- **Rows removidos:** 5
- **Razón:** Tabla creada como backup pre-migración del unify ENCARGO→PREVENTA. Tiene 5 rows. No hay código que la lea ni la limpie. Dead schema. Si UNIFY_FLAG tiene >30 días sin issues, se puede DROP.
- **Findings respaldo:** F-02-010 P3.
- **Pre-cleanup:**
  1. Verificar `bin/mysql-prod -e "SELECT * FROM wp_akb_unify_backup_202604"`
  2. Verificar `bin/wp-ssh option get akibara_unify_flag` → debe ser >=2 con timestamp >30 días
  3. Backup CSV: `bin/mysql-prod akibara_db wp_akb_unify_backup_202604 > .private/backups/unify-backup-202604.csv`
- **Cleanup action:**
  ```sql
  DROP TABLE wp_akb_unify_backup_202604;
  ```
- **Smoke test:**
  - Verify producto preventa flow E2E sigue funcional
  - `bin/mysql-prod -e "SHOW TABLES LIKE '%unify%'"` → expect 0
- **Rollback:** restore CSV via `mysqlimport` (la tabla schema está en migration class).
- **Sprint asignado:** S3 (B-S3-CLEAN-01)
- **Owner skill:** `[BACKEND]` + `[CLEANUP]`
- **Esfuerzo:** S (15 min)

---

### CLEAN-014 — `class-reserva-migration.php` función `run()` legacy YITH

- **LOC removidos:** ~110 líneas (líneas 75-186)
- **Razón:** Función `Akibara_Reserva_Migration::run()` migra productos+orders desde YITH Pre-Order. Query base retorna 0 rows en prod (verificado: `SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '_ywpo%'` → 0). YITH ya migrado, no quedan datos. Función `maybe_unify_types()` SÍ es útil (líneas 17-69) — mantener.
- **Findings respaldo:** F-02-004 P2.
- **Pre-cleanup:**
  1. Confirmar 0 referencias activas: `grep -rn "Akibara_Reserva_Migration::run" plugins/ themes/ mu-plugins/`
  2. Snapshot: `tar -czf .private/snapshots/2026-04-NN-reserva-migration.tar.gz plugins/akibara-reservas/includes/class-reserva-migration.php`
- **Cleanup action:**
  - Editar `class-reserva-migration.php` removiendo líneas 75-186 (`run()` method completo)
  - Mantener `maybe_unify_types()` y resto de la clase
  - Si hay admin button "Run YITH migration", removerlo también
- **Post-cleanup smoke test:**
  - Plugin akibara-reservas carga sin error
  - Cron `maybe_unify_types` corre sin error (si UNIFY_FLAG <2)
- **Rollback:** restore tar.gz
- **Sprint asignado:** S2 (B-S2-CLEAN-01)
- **Owner skill:** `[BACKEND]` + `[CLEANUP]`
- **Esfuerzo:** S (30 min)

---

## Cleanup que NO se ejecuta (referenciado pero NO procede)

### `mu-plugins/akibara-bootstrap-legal-pages.php` — NO eliminar

- **Razón:** Aunque mesa-02 F-02-022 marca como candidato cleanup post-bootstrap, va a ser EXTENDIDO en Decisión #9 con `/cookies/`, secciones preventa Sernac, política privacidad re-write Ley 21.719. Es load-bearing en próximo sprint, NO removible.
- **Acción:** Mantener + extend per Decisión #9 (B-S1-COMP-02 + B-S2B-COMP-01).

### `plugins/akibara/modules/welcome-discount/` (~2,090 LOC) — NO eliminar

- **Razón:** Aunque OFF por default y solo 1 sub real, mesa-15 F-15-003 propone consolidar a marketing-campaigns en S3+ (Decisión #14). Hasta entonces, mantener código + tablas vacías. NO eliminar proactivamente.
- **Acción:** Mantener hasta consolidación S3+ trigger (>25 customers/mo).

### `plugins/akibara/modules/referrals/` (~1,659 LOC) — NO eliminar

- **Razón:** mesa-02 F-02-007 marca como growth-ready 0 completed. Decisión PM (Mesa-23): mantener para growth-deferred trigger, NO cleanup proactivo. Se evalúa Milestone 3 (>50 customers/mo) si activar o deprecar.
- **Acción:** Growth-deferred. Documentar en BACKLOG sin sprint asignado.

### `plugins/akibara/modules/back-in-stock/` + `series-notify/` + `next-volume/` (~1,800 LOC) — NO eliminar

- **Razón:** Confirmados FUNCIONANDO en frontend (F-PRE-019: widgets visibles, CTA "Avísame cuando vuelva"). Solo emails no firing por crontab no configurado (Decisión #6 lo arregla). NO cleanup, son growth-ready activatable Milestone 1-2.
- **Acción:** Mantener. Activar progresivamente growth-deferred trigger.

### `plugins/akibara/modules/mercadolibre/` (~4,250 LOC) — DECISIÓN PM RESUELTA 2026-04-26

- **Status:** ✅ MIGRADO a addon `akibara-mercadolibre` Sprint 5 secuencial.
- **Razón:** Decisión usuario 2026-04-26 — addon separado, NO eliminar. 1 listing real activo (One Punch Man 3 Ivrea España $15,465 MLC).
- **Acción:** Cell E migration Sprint 5 (~15-20h). Plugin separado depending de akibara-core.

### `plugins/akibara/modules/popup/` y `welcome-discount/` supresión cruzada — NO refactor

- **Razón:** F-15-016 + F-02-023 marcan supresión cruzada frágil pero ambos popups capturan email + cupón. Refactor parte de Decisión #14 (consolidación descuentos) en S3+. NO acción S1.
- **Acción:** Mantener con doc inline. Refactor con Decisión #14.

---

### CLEAN-015 — ga4 module DISABLE + archivar (2026-04-26 NEW)

- **Status:** ✅ NUEVO
- **LOC archivados (NO eliminados):** ~1,200 LOC en `plugins/akibara/modules/ga4/`
- **Razón:** Decisión usuario 2026-04-26 — disable módulo custom. Plugin oficial "Google Analytics for WooCommerce" sigue activo cubriendo eventos estándar e-commerce. Custom events (`view_serie`, `reserva_created`, `add_to_wishlist`, `referral_signup`, `pack add_to_cart`) + server-side Measurement Protocol descontinuado:
  1. **3 customers reales** = datos GA4 estadísticamente irrelevantes (n=3, sin significancia)
  2. **F-19-001 P0 + F-19-005 P0 compliance** — sin banner consent, MP envía email/IP sin gating, viola Ley 21.719 (vigencia plena Dec 2026)
  3. Plugin oficial WC GA4 ya cubre eventos estándar (page_view, add_to_cart, begin_checkout, purchase, view_item)
- **Acción:**
  ```bash
  # Backup
  tar -czf .private/snapshots/2026-04-NN-ga4-module.tar.gz wp-content/plugins/akibara/modules/ga4/

  # Disable flag en registry
  bin/wp-ssh option update akibara_modules_disabled --add ga4

  # Archive
  mkdir -p wp-content/plugins/akibara/modules/_archived/
  mv wp-content/plugins/akibara/modules/ga4/ wp-content/plugins/akibara/modules/_archived/ga4/

  # Remover constante wp-config (no se usa más)
  # Editar wp-config.php → eliminar línea: define('AKB_GA4_API_SECRET', '...');

  # Smoke test
  curl -I https://akibara.cl/ # HTTP 200 sin errores GA4
  # Sentry 24h check: 0 nuevos errors
  ```
- **Findings respaldo:** F-19-001 P0, F-19-005 P0, F-10-016 (AKB_GA4_API_SECRET wp-config sensibilidad)
- **Re-evaluar:** Milestone 3 (50 customers/mo durante 3 meses) → considerar re-activate si datos GA4 ganan relevancia estadística
- **Sprint asignado:** S1 (parte de B-S1-COMP-* + B-S2 cleanup)
- **Owner skill:** `[COMPLIANCE]` + `[CLEANUP]`
- **Esfuerzo:** S (1h archive + 30 min config + smoke test)
- **DOBLE OK requerido:** SÍ (afecta tracking analytics — confirmar plugin oficial WC GA4 sigue capturando eventos estándar)
- **Rollback:** restore tar.gz + remove from `akibara_modules_disabled` option + restore wp-config constant

---

### CLEAN-016 — finance-dashboard DISABLE + archivar para rebuild manga-specific (2026-04-26 NEW)

- **Status:** ✅ NUEVO
- **LOC archivados (NO eliminados):** ~1,453 LOC en `plugins/akibara/modules/finance-dashboard/`
- **Razón:** Decisión usuario 2026-04-26 — finance-dashboard actual está over-engineered (F-02-014 P2: cache transient "para 10K+ órdenes" cuando hay 3 órdenes). NO migrar a addon como está. Rebuild manga-specific en `akibara-marketing` Sprint 3 Cell B con 5 widgets prioritarios:
  1. Top series por volumen vendido (consume `_akibara_serie` de core)
  2. Top editoriales (consume `akibara_brevo_editorial_lists` 8 listas: Ivrea AR, Panini AR, Planeta ES, Milky Way, Ovni Press, Ivrea ES, Panini ES, Arechi)
  3. Encargos pendientes (consume `akibara_encargos_log` — 2 activos: Jujutsu kaisen 24/26)
  4. Trending searches (consume `akibara_trending_searches` — One Piece 196k, Jujutsu 34, Berserk 9, etc.)
  5. Stock crítico <3 unidades (queries wc_orders + postmeta `_stock`)
- **Acción:**
  ```bash
  # Backup
  tar -czf .private/snapshots/2026-04-NN-finance-dashboard-module.tar.gz wp-content/plugins/akibara/modules/finance-dashboard/

  # Disable flag en registry
  bin/wp-ssh option update akibara_modules_disabled --add finance-dashboard

  # Archive
  mv wp-content/plugins/akibara/modules/finance-dashboard/ wp-content/plugins/akibara/modules/_archived/finance-dashboard/

  # Smoke test
  curl -I https://akibara.cl/wp-admin/admin.php?page=akibara&tab=finanzas # HTTP 404 esperado (tab desaparece)
  ```
- **Findings respaldo:** F-02-014 P2 (over-engineering), F-PRE-006 (refactor manga-specific con mockup), B-S3-PAY-05/B-S3-FRONT-05 backlog
- **Sprint asignado:** S2 (disable) + S3 Cell B (rebuild)
- **Owner skill:** `[BACKEND]` + `[CLEANUP]` + `[FRONTEND]` (rebuild)
- **Esfuerzo:** S (30 min disable) + L Cell B Sprint 3 (~12-15h rebuild)
- **DOBLE OK requerido:** NO (admin internal tool, no customer-facing)
- **Rollback:** restore tar.gz + remove flag

---

### CLEAN-017 — series-autofill migration class → legacy CLI (2026-04-26 NEW)

- **Status:** ✅ NUEVO
- **LOC movidos (NO eliminados):** 241 LOC en `plugins/akibara/modules/series-autofill/class-migration.php`
- **Razón:** F-02-021 P3 mesa-02 — migration histórica via Action Scheduler ya completada (1,371 productos procesados según `akibara_series_migration_status`). La capa 2 (Action Scheduler migration backlog) ya no es necesaria en cada `plugins_loaded`. Mover a `legacy/migration-cli.php` cargado solo via WP-CLI para futuros backfills ad-hoc.
- **IMPORTANTE:** series-autofill module **NO se elimina** — capas 1 (auto-fill on save) + 1.5 (consistency guard) + 3 (admin UI) son SEO foundation crítico (Schema BookSeries `isPartOf` reconocido por Google). Solo la capa 2 migration histórica se mueve.
- **Pre-cleanup verificación:**
  ```bash
  bin/wp-ssh option get akibara_series_migration_status
  # Expected: status=completed, total=1371, chunks_done=28
  ```
- **Acción:**
  ```bash
  # Backup
  tar -czf .private/snapshots/2026-04-NN-series-autofill-class-migration.tar.gz wp-content/plugins/akibara/modules/series-autofill/class-migration.php

  # Move to legacy
  mkdir -p wp-content/plugins/akibara/modules/series-autofill/legacy/
  mv wp-content/plugins/akibara/modules/series-autofill/class-migration.php wp-content/plugins/akibara/modules/series-autofill/legacy/migration-cli.php

  # Update module.php para cargar legacy/migration-cli.php solo via WP-CLI:
  # Cambiar: require_once __DIR__ . '/class-migration.php';
  # A:       if (defined('WP_CLI') && WP_CLI) { require_once __DIR__ . '/legacy/migration-cli.php'; }

  # Update Migration::register_hooks() call:
  # Mover dentro del condicional WP_CLI

  # Smoke test
  bin/wp-ssh akibara series-autofill stats
  # Expected: stats funciona (capas 1 + 3 intactas)
  bin/wp-ssh akibara series-autofill migrate --dry-run
  # Expected: WP-CLI command sigue disponible para futuros backfills
  ```
- **Findings respaldo:** F-02-021 P3
- **Sprint asignado:** S3 (cuando series-autofill se mueva a akibara-core durante extracción)
- **Owner skill:** `[BACKEND]` + `[CLEANUP]` + `[SEO]`
- **Esfuerzo:** S (1h)
- **DOBLE OK requerido:** NO (refactor interno, no customer-facing)
- **Rollback:** restore tar.gz + revert module.php

---

## Resumen ejecutivo CLEAN

| ID | Item | Status | Sprint | LOC/Rows removidos | Owner skill |
|---|---|---|---|---|---|
| CLEAN-001 | sentry-customizations.php | ❌ CANCELADO | S1 (solo ADR) | (273 NO removidos) | `[SECURITY]` + `[ARCHITECTURE]` |
| CLEAN-002 | cart-abandoned local | ⚠️ CONDICIONAL | S1 setup + S2 cleanup | 539 + tabla + cron | `[EMAIL]` + `[SETUP]` + `[CLEANUP]` |
| CLEAN-003 | brevo-smtp.php | ❌ CANCELADO | S1 (solo ADR) | (270 NO removidos) | `[EMAIL]` + `[ARCHITECTURE]` |
| CLEAN-004 | AKB_BREVO_API_KEY | ❌ CANCELADO | S1 (alternativa wp-config-private) | (1 const NO removida) | `[EMAIL]` + `[SECURITY]` |
| CLEAN-005 | vendor/coverage/dev tooling | ✅ NUEVO | S1 | 74 MB | `[SECURITY]` + `[SETUP]` + `[CLEANUP]` |
| CLEAN-006 | enqueue.php.bak leftover | ✅ NUEVO | S1 | 12.4 KB | `[CLEANUP]` |
| CLEAN-007 | hero-section.css root duplicado | ✅ NUEVO | S1 | 306 LOC | `[CLEANUP]` + `[FRONTEND]` |
| CLEAN-008 | setup.php duplicado byte-idéntico | ✅ NUEVO | S1 | ~150 LOC | `[CLEANUP]` + `[FRONTEND]` |
| CLEAN-009 | theme back-in-stock duplicado | ✅ NUEVO | S2 | 70 LOC | `[EMAIL]` + `[BACKEND]` + `[CLEANUP]` |
| CLEAN-010 | wp_bluex_logs TRUNCATE | ✅ NUEVO | S1 | 65,640 rows | `[SECURITY]` + `[BACKEND]` |
| CLEAN-011 | leftover uploads/ folders | ✅ NUEVO | S2 | ~9 MB + 11 PHP files | `[SECURITY]` + `[CLEANUP]` |
| CLEAN-012 | 4 admin backdoors | ✅ NUEVO | S1 | 4 users + meta | `[SECURITY]` |
| CLEAN-013 | wp_akb_unify_backup table | ✅ NUEVO | S3 | 5 rows | `[BACKEND]` + `[CLEANUP]` |
| CLEAN-014 | class-reserva-migration::run() | ✅ NUEVO | S2 | ~110 LOC | `[BACKEND]` + `[CLEANUP]` |
| **CLEAN-015** | **ga4 module DISABLE + archivar** | ✅ **NUEVO 2026-04-26** | **S1+S2** | **~1,200 LOC archivados** | `[COMPLIANCE]` + `[CLEANUP]` |
| **CLEAN-016** | **finance-dashboard DISABLE + rebuild manga-specific S3** | ✅ **NUEVO 2026-04-26** | **S2 disable + S3 Cell B rebuild** | **~1,453 LOC archivados** | `[BACKEND]` + `[FRONTEND]` |
| **CLEAN-017** | **series-autofill migration class → legacy CLI** | ✅ **NUEVO 2026-04-26** | **S3 (durante core extraction)** | **241 LOC moved (no removed)** | `[BACKEND]` + `[SEO]` |

**Total cleanup items:** 17 (3 cancelados + 1 condicional + 13 nuevos)

**Total LOC custom code removido/archivado:** ~75 MB de tooling + ~1.300 LOC custom + 65k rows logs + 5 backup rows + 1 tabla schema + ~2,653 LOC archivados (ga4 + finance-dashboard)

**Items que requieren DOBLE OK explícito de Alejandro (destructivos):**
- CLEAN-002 (S2 condicional)
- CLEAN-005 (re-deploy plugin)
- CLEAN-009 (theme code delete)
- CLEAN-010 (TRUNCATE 65k rows)
- CLEAN-011 (rm -rf folders)
- CLEAN-012 (delete users + reasign posts)
- CLEAN-013 (DROP TABLE)
- CLEAN-014 (delete ~110 LOC)
- **CLEAN-015 (ga4 disable + remove wp-config constant + archive)**

**FIN DEL CLEANUP-PLAN.**
