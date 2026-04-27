---
agent: mesa-10-security
round: 1
date: 2026-04-26
scope: Compromise assessment + custom plugin/theme code security review (akibara, akibara-reservas, akibara-whatsapp, mu-plugins, theme inc/) + secrets/IOC sweep en SQL dump + ataque-surface analysis
files_examined: 41
findings_count: { P0: 9, P1: 6, P2: 7, P3: 3 }
---

## Resumen ejecutivo

- **F-10-001 (P0):** Confirmado en SQL dump: BlueX `x-api-key: QUoO07ZRZ12tzkkF8yJM9am7uhxUJCbR7f6kU5Dz` está plain-text en tabla `wp_bluex_logs` (~65,640 filas). Cada request de pricing/comunas la loguea. Origen: plugin third-party `bluex-for-woocommerce`. Cualquier dump SQL exporta esto.
- **F-10-002 (P0 — CONTRADICCIÓN CON CLEAN SEEDS):** CLEAN-001 propone borrar `mu-plugins/akibara-sentry-customizations.php`. Pero ese mu-plugin define `WP_SENTRY_PHP_DSN` (constante que el plugin upstream `wp-sentry-integration` REQUIERE) a partir de `SENTRY_DSN` que sí existe en `wp-config.php`. Si se borra, Sentry no inicializa. Además se pierde PII scrubbing chileno (RUT, +56 phone, email).
- **F-10-003 (P0 — CONTRADICCIÓN CON CLEAN SEEDS):** CLEAN-003 propone borrar `mu-plugins/akibara-brevo-smtp.php` describiéndolo como "legacy nunca usado". Falso: el archivo es el interceptor activo (`add_filter('pre_wp_mail', ..., 10, 2)` línea 67) que rutea TODO `wp_mail()` por Brevo Transactional API. Hostinger bloquea PHP `mail()`.
- **F-10-004 (P0):** 4 admin backdoor accounts (IDs 5/6/7/8, dominio typosquat `@akibara.cl.com`) confirmados. User #6 con ~47 posts requiere análisis 1×1 antes de delete.
- **F-10-005 (P0):** 10 archivos WP core modificados <90d requieren `wp core verify-checksums` antes de cualquier acción.

## Findings

### F-10-001: BlueX API key plain-text en tabla `wp_bluex_logs`
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** Tabla `wp_bluex_logs`; ejemplos en `.private/akb-prod-dump.sql:11536+`
- **Descripción:** Plugin third-party BlueX loggea cada request HTTP con header completo `x-api-key:QUoO...` en `log_body`. Tabla creció a ~65,641 rows entre 2026-04-10 y 2026-04-12. Cualquier export SQL → llave expuesta.
- **Propuesta:** (1) TRUNCATE TABLE wp_bluex_logs después de DOBLE OK; (2) reportar a soporte BlueX (responsible disclosure); (3) rotar BlueX API key; (4) cron mensual DELETE rows > 30 días; (5) verificar `.gitignore` cubre `.private/`
- **Esfuerzo:** S (15 min cleanup + 30 min rotation)
- **Sprint:** S1 inmediato

### F-10-002: CLEAN-001 silenciosamente rompe Sentry y PII scrubbing
- **Severidad:** P0
- **Categoría:** SECURITY (regresión potencial)
- **Archivo(s):** `mu-plugins/akibara-sentry-customizations.php` (273 LOC); `wp-config.php:96`
- **Descripción:** El mu-plugin (a) mapea SENTRY_DSN → WP_SENTRY_PHP_DSN que el plugin upstream REQUIERE para inicializar; (b) registra filtro `wp_sentry_before_send` que scrubea RUT/+56/email; (c) override sample_rate y send_default_pii=false. Si se borra: Sentry no recibe events Y eventos con PII de clientes chilenos viajan plain a Sentry US.
- **Propuesta:** NO ejecutar CLEAN-001 tal cual. Migración correcta: (1) mover define WP_SENTRY_PHP_DSN a wp-config.php; (2) configurar PII scrubbing del lado Sentry (Inbound Filters); (3) confirmar pérdida breadcrumbs custom OK.
- **Esfuerzo:** M (2h)
- **Sprint:** S1 (bloquea CLEAN-001)

### F-10-003: CLEAN-003 borraría el interceptor activo de email
- **Severidad:** P0
- **Categoría:** SECURITY (regresión bloqueante)
- **Archivo(s):** `mu-plugins/akibara-brevo-smtp.php` (270 LOC)
- **Descripción:** add_filter pre_wp_mail línea 67 reemplaza envío default WP. Hostinger bloquea PHP mail(). Si se borra: order confirmations, password reset, contact form, magic-link no funcionan. `akb_brevo_get_api_key()` usado por 7 archivos.
- **Propuesta:** NO ejecutar CLEAN-003 tal cual. Aclarar con usuario qué quiso decir. Smoke test obligatorio antes de cualquier delete.
- **Esfuerzo:** M (1h aclaración + smoke test)
- **Sprint:** S1 (bloquea CLEAN-003)

### F-10-004: 4 admin backdoors — User 6 requiere análisis manual antes de delete
- **Severidad:** P0
- **Categoría:** SECURITY (compromise IOC)
- **Archivo(s):** Database wp_users IDs 5/6/7/8
- **Descripción:** SEC-P0-001 ya identificado. Mi expansión: User 6 tiene ~47 posts. Antes de `wp user delete 6 --reassign=1`, hay que verificar si posts son legítimos o injection (PHP code, redirects, SEO spam). Ejecutar --reassign ciegamente PROPAGA payload.
- **Propuesta:** Workflow obligatorio:
  1. `bin/wp-ssh post list --author=6 --post_status=any --format=csv > user6-posts.csv`
  2. Para cada post, dump content y revisar
  3. Buscar patterns `eval\|base64_decode\|<script\|window.location` en posts
  4. Listar cron events no estándar
  5. Application Passwords cleanup
  6. Triple OK antes de delete final
- **Esfuerzo:** M (2-4h)
- **Sprint:** S1 inmediato

### F-10-005: WP core 10 archivos modificados <90d — verify-checksums prerequisito
- **Severidad:** P0
- **Categoría:** SECURITY (IOC potencial)
- **Archivo(s):** F-PRE-008 lista 10 archivos
- **Descripción:** Sin verify-checksums no se sabe si modificaciones son: WP update legítimo, backdoor injection con código adicional, backdoor sigiloso modificando funciones existentes, o reemplazo total con shell.
- **Propuesta:** Ejecutar inmediato: `bin/wp-ssh core verify-checksums --version=$(bin/wp-ssh core version)`. Si mismatches → diff vs upstream + buscar patterns malware. Si confirmed → IR completo.
- **Esfuerzo:** S (30 min checksum) + 2h forensic si mismatches
- **Sprint:** S1 inmediato — antes de cualquier cleanup

### F-10-006: vendor/ con dev tools deployado a prod
- **Severidad:** P0
- **Categoría:** SECURITY (attack surface)
- **Archivo(s):** `plugins/akibara/vendor/` (76MB), `composer.json`
- **Descripción:** Plugin akibara deploya vendor/ con PHPUnit 11, PHPStan 1.12, PHPCS, WPCS. Recon: composer.json HTTP fetchable. Tamaño 76MB. .htaccess bloquea *.bak|sql|log pero no *.json.
- **Propuesta:** (1) `composer install --no-dev --optimize-autoloader` en deploy; (2) extender .htaccess: bloquear vendor/, tests/, coverage/, composer.json, phpunit.xml*, phpcs.xml*, phpstan.neon*, package*.json
- **Esfuerzo:** M (2h)
- **Sprint:** S1

### F-10-007: coverage/ directory verificar contenido
- **Severidad:** P0 (potencial)
- **Categoría:** SECURITY
- **Descripción:** Coverage reports PHPUnit típicamente contienen HTML con código fuente embebido + source-map de toda la base. Si exposed = recon completo.
- **Propuesta:** Verificar contenido + borrar si confirmado web-fetchable
- **Esfuerzo:** S
- **Sprint:** S1

### F-10-008: Magic Link IP rate-limit confía en HTTP_CF_CONNECTING_IP sin validar
- **Severidad:** P0
- **Categoría:** SECURITY (auth bypass)
- **Archivo(s):** `themes/akibara/inc/magic-link.php:48`
- **Descripción:** Función lee headers en orden: CF-Connecting-IP > X-Forwarded-For > REMOTE_ADDR. Sin validar que request realmente viene de Cloudflare. Attacker puede enviar header arbitrario y bypassear rate limit (10 magic links/hour/IP). Combined con email enumeration vector + Brevo cap exhaustion.
- **Propuesta:** Validar IP source contra lista CF whitelisted, o eliminar lectura CF si no aplica. Mientras tanto: respuesta uniforme antiendoenumeración.
- **Esfuerzo:** M (3h)
- **Sprint:** S1

### F-10-009: Endpoint público `akibara_encargo_submit` sin rate limit
- **Severidad:** P0
- **Categoría:** SECURITY (resource exhaustion + Brevo cap)
- **Archivo(s):** `themes/akibara/inc/encargos.php:14-90`
- **Descripción:** Sin rate limit per IP/email. Attacker con nonce válido puede: (1) spamear 200+ requests vaciando encargos legítimos; (2) generar 200+ emails admin saturando Brevo Free 300/día; (3) suscribir fake emails a lista Brevo "Encargos".
- **Propuesta:** Agregar rate limit transient per-IP (3/h) + per-email (2/día). Migrar `akibara_encargos_log` de wp_options a tabla custom.
- **Esfuerzo:** S (30 min)
- **Sprint:** S1

### F-10-010: Welcome Discount captcha endpoint sin rate limit
- **Severidad:** P1
- **Categoría:** SECURITY (resource exhaustion conditional)
- **Archivo(s):** `welcome-discount/module.php:195-197`
- **Descripción:** Endpoint público `wp_ajax_nopriv_akb_wd_captcha` crea transient sin rate limit. 1000 requests/min × 5 min = 5000 entries simultáneas. Mitigado parcialmente: módulo OFF default.
- **Propuesta:** Rate limit per-IP 30/min en ajax_captcha
- **Esfuerzo:** S
- **Sprint:** S2

### F-10-011: 12horas webhook abierto si OPT_WEBHOOK_TOKEN vacío
- **Severidad:** P1
- **Categoría:** SECURITY
- **Archivo(s):** `plugins/akibara/modules/shipping/class-12horas.php:526-547`
- **Descripción:** verify_webhook_auth() retorna true si token vacío ("grace mode"). Webhook abierto a Internet permite spoofear status orders matching tracking_code/external_id. Mitigado parcial por hash_equals identity check, pero attacker que conoce trackingCode (de logs BlueX, emails) puede explotar.
- **Propuesta:** (1) Verificar token seteado en prod; (2) si vacío, generar y setear via DOBLE OK; (3) actualizar URL en panel 12 Horas; (4) deprecar grace mode → hard-fail; (5) escalar logging a critical
- **Esfuerzo:** S
- **Sprint:** S1

### F-10-012: SHORTINIT search.php endpoint con write-side-effect
- **Severidad:** P1
- **Categoría:** SECURITY (write amplification)
- **Archivo(s):** `plugins/akibara/search.php:413-453`
- **Descripción:** Endpoint público escribe `akb_failed_searches` option por cada query <3 chars sin match. Rate limit per-IP 120/60s pero sin global. 100 IPs (botnet) = 12000 writes/min sostenibles → DB write thrashing + autoload bloat.
- **Propuesta:** (1) Mover `akb_failed_searches` a tabla custom; (2) global rate limit; (3) heurística human-like queries
- **Esfuerzo:** M (2-3h)
- **Sprint:** S2

### F-10-013: Endpoint search.php contrato no documentado read-only
- **Severidad:** P2
- **Descripción:** No declara explicitly que es read-only. Futuro maintainer podría agregar writes asumiendo OK.
- **Propuesta:** Agregar header comment + considerar wrap log con wp_schedule_single_event
- **Sprint:** S3

### F-10-014: WP_DEBUG_LOG no definido explícito
- **Severidad:** P1
- **Archivo(s):** `wp-config.php:84-86`
- **Descripción:** WP_DEBUG=false pero WP_DEBUG_LOG no definido. Si en emergencia se setea WP_DEBUG=true, escribe debug.log al webroot (block en .htaccess pero defense en depth).
- **Propuesta:** Agregar define WP_DEBUG_LOG=false + WP_DEBUG_DISPLAY=false + SCRIPT_DEBUG=false
- **Esfuerzo:** S (15 min)
- **Sprint:** S2

### F-10-015: akibara_encargos_log retiene PII visitor anónimos sin TTL
- **Severidad:** P2
- **Categoría:** SECURITY (privacy)
- **Archivo(s):** `themes/akibara/inc/encargos.php:50-69`
- **Descripción:** Option retiene últimos 200 encargos con nombre/email/notas indefinitely. Bajo Ley 19.628 retention indefinida sin propósito específico es riesgo.
- **Propuesta:** Cron diario purga > 90 días + consent checkbox + policy update
- **Esfuerzo:** M (2h)
- **Sprint:** S2

### F-10-016: AKB_GA4_API_SECRET en wp-config (alta sensibilidad)
- **Severidad:** P2
- **Archivo(s):** `wp-config.php:92`
- **Descripción:** API secret GA4 Measurement Protocol. Si filtra: attacker puede inyectar events fake. NO permite read access. Maps API key debe tener HTTP referrer restriction `*.akibara.cl`.
- **Propuesta:** Verificar uso activo + IP restriction + rotation trimestral schedule + Maps referrer restriction
- **Esfuerzo:** S
- **Sprint:** S2

### F-10-017: marketing-campaigns test handler no valida response shape Brevo
- **Severidad:** P2
- **Archivo(s):** `marketing-campaigns/module.php:639-668`
- **Propuesta:** Hacer send_transactional retornar array con error real
- **Sprint:** S3

### F-10-018: akb_rl_* transients no auto-cleanup si DISABLE_WP_CRON
- **Severidad:** P3 (preocupación menor)
- **Descripción:** No problema a 50 visitas/día. A escalar (50/min con bots) podría ser. Ya hay rate limit per-IP en muchos endpoints.
- **Propuesta:** No-op por ahora. Cuando aplique: `wp transient delete --expired`
- **Sprint:** S4+

### F-10-019: Falta Strict-Transport-Security (HSTS) header
- **Severidad:** P2
- **Archivo(s):** `.htaccess:60-67`
- **Descripción:** Bloque "Akibara Security Headers" agrega X-Frame, X-Content-Type, Referrer-Policy, Permissions-Policy pero NO HSTS. Sin HSTS browser permite downgrade HTTP por accidente/MITM.
- **Propuesta:** Después de confirmar HTTPS funcional, agregar HSTS (start max-age=300 24h luego escalar a 1 año)
- **Esfuerzo:** S + 24h validación
- **Sprint:** S2

### F-10-020: Falta Content-Security-Policy (CSP)
- **Severidad:** P3
- **Descripción:** CSP en sitio con WC + Elementor + GA + Brevo + CF complejo. Empezar con CSP-Report-Only.
- **Propuesta:** S4+ porque requiere análisis específico. NO actuar hasta tener tráfico real (YAGNI)
- **Sprint:** S4+

### F-10-021: wp_users 5/6/7/8 podrían tener Application Passwords activas
- **Severidad:** P0 (potencial)
- **Categoría:** SECURITY (compromise IOC adicional)
- **Descripción:** WP NO revoca automáticamente Application Passwords al `wp user delete`. Si attackers crearon AppPasswords como persistence, siguen funcionando como orphan tokens permitiendo REST API access permanente con admin permission.
- **Propuesta:** Como parte de F-10-004 workflow:
  ```bash
  bin/wp-ssh user meta get 5 _application_passwords --format=json
  bin/wp-ssh user meta get 6 _application_passwords --format=json
  bin/wp-ssh user meta get 7 _application_passwords --format=json
  bin/wp-ssh user meta get 8 _application_passwords --format=json
  # Si HAY: bin/wp-ssh user meta delete 5 _application_passwords
  ```
  Considerar disable global App Passwords si no usadas.
- **Esfuerzo:** S (15 min)
- **Sprint:** S1 (parte de F-10-004 workflow)

### F-10-022: xmlrpc.php no bloqueado server-side
- **Severidad:** P1
- **Archivo(s):** `.htaccess` (no contiene block); `robots.txt:8` (solo Disallow)
- **Descripción:** xmlrpc.php accesible. Vector histórico para: brute-force con system.multicall, DDoS pingback amplification, info disclosure. Akibara probablemente no usa XML-RPC.
- **Propuesta:** Agregar a .htaccess: `<Files xmlrpc.php> Require all denied </Files>`. Validar primero que ningún plugin/integration legítimo usa XML-RPC.
- **Esfuerzo:** S (10 min)
- **Sprint:** S1

### F-10-023: wp-login.php sin rate limit nativo + sin 2FA
- **Severidad:** P2
- **Descripción:** WP login no tiene rate limit nativo. Sin 2FA en cuentas admin. Política "NO third-party plugins" complica WP Two-Factor plugin.
- **Propuesta:** (1) S1: cambiar password admin (user 1) post-cleanup F-10-004; (2) S2: implementar mu-plugin custom akibara-login-rate-limit.php (5 fails/IP/30min → block 1h); (3) S3: investigar Hostinger 2FA cPanel; (4) S4+: mu-plugin TOTP custom si admins crecen
- **Esfuerzo:** S1 5 min password + S2 3h mu-plugin
- **Sprint:** S1 + S2

### F-10-024: nightmarekinggrimm26 (user ID 18) verificar legitimidad
- **Severidad:** P2 (info)
- **Descripción:** Username unusual flagged en F-PRE-010
- **Propuesta:** `bin/wp-ssh user get 18 --format=json` + verificar capabilities. Si role customer + actividad real → keep. Si admin/editor sin justificación → tratar como F-10-004
- **Esfuerzo:** S (10 min)
- **Sprint:** S1

### F-10-025: WP_AUTO_UPDATE_CORE='minor' deja majors sin patches automáticos
- **Severidad:** P2
- **Archivo(s):** `wp-config.php:89`
- **Descripción:** Aplica minor + security patches. Major requiere acción manual. Risk si major actual está EOL.
- **Propuesta:** Verificar versión current + supported. Considerar `WP_AUTO_UPDATE_CORE=true` para tienda con poco tráfico (riesgo bug menor que riesgo quedarse atrás)
- **Esfuerzo:** S (5 min)
- **Sprint:** S2

## Cross-cutting flags para mesa

**Para mesa-01 lead-arquitecto:**
- NO ejecutes CLEAN-001 ni CLEAN-003 sin antes leer F-10-002 y F-10-003. P0 risk romper Sentry y email transactional.
- F-10-006 (vendor/ prod) coordinar con tech-debt para definir bin/deploy.sh

**Para mesa-22 wp-master:**
- F-10-005 verify-checksums prerequisito bloqueante
- F-10-021 Application Passwords cleanup integrar a SEC-P0-001 workflow
- F-10-024 user 18 verificar antes de cleanup global
- F-10-014 WP_DEBUG_LOG explicit false housekeeping

**Para mesa-09 email-qa:**
- F-10-003 confirma F-09-001: mu-plugin Brevo SMTP es interceptor activo
- F-10-009 encargos puede saturar Brevo cap
- F-10-010 welcome-discount captcha

**Para mesa-23 PM:**
- Sprint S1 obligatorio: F-10-001/004/005/008/009/011/022/021/024 + step 1 F-10-023. ~1 día trabajo en serie.

## Áreas que NO cubrí

- No ejecuté wp core verify-checksums (no shell access). F-10-005 bloqueante hasta que mesa-22/lead corra
- coverage/ verificar contenido pendiente
- No analicé plugins third-party CVEs (mandato dice solo superficie de ataque)
- Theme inc/ resto archivos: cubrí magic-link y encargos. Resto requiere R2 si scope justifica
- No ejecuté grep masivo eval/base64 en filesystem prod (solo snapshot)
- No verifiqué .env, wp-config.php.bak en webroot
- No analicé seguridad integración MercadoPago (third-party plugin)
- No revisé scheduled cron jobs en wp_options (cron option serialized)
