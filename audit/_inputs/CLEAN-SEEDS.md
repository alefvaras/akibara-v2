# Cleanup seeds + cross-cutting findings pre-audit

**Confirmados por usuario o evidencia.** Mesa-01 lead-arquitecto los ratifica en R2 sin re-debate.
Mesa-10 security expande con findings de compromise assessment.

---

## CLEAN seeds confirmados (4 items)

### ~~CLEAN-001 — `mu-plugins/akibara-sentry-customizations.php`~~ **❌ CANCELADO 2026-04-26**

**🚨 REVERTIDO POR mesa-10 R1 (F-10-002):** Este mu-plugin **NO es customizations decorativas** — es **infraestructura load-bearing** del Sentry stack.

**Por qué NO se elimina:**
1. **Define `WP_SENTRY_PHP_DSN`** constante (líneas 49-58) que el plugin upstream `wp-sentry-integration v8.x` REQUIERE para inicializar. Sin esta constante, Sentry NO arranca → cero error tracking en prod.
2. **Scrub PII chileno** (líneas 67-151) — filtro `wp_sentry_before_send` redacta RUT, teléfono +56, email antes de enviar events a Sentry US. Sin este filtro, datos personales de clientes chilenos van plain text a servers Sentry en USA → **violación Ley 19.628 + Ley 21.719 + transferencia internacional sin base legal**.
3. **Override `sample_rate=1.0` y `send_default_pii=false`** (líneas 262-273) — defaults seguros que el plugin upstream no establece.

**Statement original del usuario "solo ocupamos el plugin no el custom" fue confusión** — el plugin upstream EXISTE pero el mu-plugin custom es lo que lo HACE FUNCIONAR. Sin el mu-plugin, el upstream queda sin DSN configurada y silenciosamente NO captura errores.

**Acción correctiva:** CLEAN-001 NO procede. Lead-01 R2 documenta como ❌ RECHAZADA.

**Mejora alternativa (S2-S3):** Si futuramente quieres reducir custom code, migración correcta requiere:
1. Mover `define('WP_SENTRY_PHP_DSN', SENTRY_DSN)` a `wp-config.php`
2. Configurar PII scrubbing del lado Sentry dashboard (Inbound Filters + Custom Regex para patterns RUT/+56)
3. Aceptar pérdida de breadcrumbs WC custom (order.created/payment.failed/email.failed/cron.missed)
4. Smoke test 24h post-deploy verificando Sentry sigue recibiendo events
NO ejecutar antes de tener los 3 pasos validados.

### CLEAN-002 — `plugins/akibara/modules/cart-abandoned/`
- **LOC:** ~539 + cron + tabla `akibara_abandoned_carts` + transients
- **Razón:** Duplicado del workflow Brevo upstream "Carrito abandonado" (Activo, configurado 08-04-2026)
- **Confirmación:** Screenshot usuario muestra workflow activo en `app.brevo.com/automation/automations`
- **Pre-cleanup:** verify Brevo upstream tracker firing (sender domain validado en Cloudflare con SPF/DKIM/DMARC); si no fire, mantener local hasta fix Brevo
- **Post-cleanup:** smoke test con producto test 24261 + email alejandro.fvaras@gmail.com → expect Brevo upstream email
- **Sprint:** S1 (depende de fix Brevo upstream tracker)
- **Esfuerzo:** M (2-4h incluyendo Brevo setup validation)

### ~~CLEAN-003 — `mu-plugins/akibara-brevo-smtp.php`~~ **❌ CANCELADO 2026-04-26**

**🚨 REVERTIDO POR mesa-09 R1 (F-09-001):** Este mu-plugin **NO es legacy** — es **infraestructura crítica load-bearing**.

**Por qué NO se elimina:**
1. Registra `pre_wp_mail` filter que intercepta TODO `wp_mail()` y routea a Brevo Transactional API
2. **Hostinger BLOQUEA PHP mail() directo** (incidente documentado en mu-plugin header líneas 11-13: "Hostinger bloqueó la cuenta por intentos repetidos de PHP mail() desde contextos donde el theme no se carga (cron, CLI)")
3. **7 archivos dependen de su función `akb_brevo_get_api_key()`:**
   - `plugins/akibara/src/Infra/Brevo.php:30-31`
   - `plugins/akibara/modules/health-check/module.php:149`
   - `plugins/akibara/modules/brevo/module.php:354`
   - `themes/akibara/inc/newsletter.php:90-91`
   - `themes/akibara/inc/health.php:110-111`
   - `themes/akibara/inc/encargos.php:72-73`
4. **Si se ejecuta CLEAN-003 → ROTURA TOTAL de email delivery + Hostinger vuelve a bloquear cuenta.**

**Statement original del usuario "no es necesario, nunca fue de iteración pasada" fue confusión** — el usuario probablemente lo confundió con OTRO componente (posiblemente el módulo `akibara/modules/brevo/` o un setup form en admin que nunca completó). El mu-plugin SÍ se usa en runtime crítico.

**Acción correctiva:** Lead-01 R2 documenta CLEAN-003 como **❌ RECHAZADA con razón técnica + cross-ref F-09-001**. Mu-plugin se mantiene en prod permanentemente como infraestructura.

### ~~CLEAN-004 — Constante `AKB_BREVO_API_KEY` en `wp-config.php`~~ **❌ CANCELADO 2026-04-26**

**🚨 REVERTIDO POR mesa-09 R1:** La constante NO es legacy — alimenta `akb_brevo_get_api_key()` del mu-plugin (que ahora confirmamos critical, ver CLEAN-003 cancelado arriba). Sin la constante, **email delivery falla**.

**Issue real (que sí requiere acción):**
- La key actual returna `"API Key is not enabled"` en API call
- Esto sugiere: setup Brevo INCOMPLETO (sender domain no validated, API key generated pero key restrictions aplicadas)
- **Acción correctiva** (NO delete):
  1. **Mesa-09 F-09-005 P1:** completar setup Brevo (validar sender domain `akibara.cl` en Brevo dashboard, agregar SPF/DKIM/DMARC en Cloudflare DNS)
  2. **Generar nueva API key con permisos completos** en Brevo dashboard si la actual está restringida
  3. Update `AKB_BREVO_API_KEY` en wp-config.php con nueva key
  4. Smoke test: order processing → verify email llega via Brevo

**Acción adicional:** F-09-004 P1 — mover keys (Brevo, GA4 secret, Maps, Sentry) a `wp-config-private.php` (chmod 600, no served por Apache) en lugar de plain text en wp-config.php principal.

---

## P0 SEGURIDAD — Backdoor admin accounts (4 items)

**Contexto:** Usuario confirmó haber instalado plugins nulled en el pasado (ya removidos). 4 admin accounts persistieron como backdoors.

### SEC-P0-001 — Delete admin backdoor accounts (4)

| ID | Username | Email (typosquat .cl.com) | Created | Posts |
|---|---|---|---|---|
| 5 | `admin_a06d0185` | `admin_fc13558a@akibara.cl.com` | 2025-11-02 16:07:55 | 0 |
| 6 | `admin_3b4206ec` | `admin_32d980f4@akibara.cl.com` | 2025-11-02 16:08:13 | ~47 (verificar legitimidad) |
| 7 | `admin_eae090ac` | `admin_5a64f9c5@akibara.cl.com` | 2025-11-02 16:11:03 | 0 |
| 8 | `admin_55b96b0c` | `admin_e3d7bbed@akibara.cl.com` | 2025-11-02 16:11:16 | 0 |

**IOCs (Indicators of Compromise):**
- Created en 4 minutos = script automatizado
- Username pattern `admin_<hash>` = generado, no humano
- Email domain `@akibara.cl.com` = TLD typosquatting (real es `.cl`)
- Mismo TLD typosquat en 4 cuentas = mismo actor/script

**Pre-cleanup:**
1. Mesa-10 verifica si user 6 tiene posts legítimos (productos importados) o injection
2. Mesa-10 verifica posts authored vs other content (options, post_meta) creados por estos users
3. Mesa-10 audit cron jobs creados (`SELECT * FROM wp_options WHERE option_name LIKE 'cron%'` o equivalente)
4. Mesa-10 audit modificaciones a wp-config.php / .htaccess / mu-plugins por estos users

**Cleanup action (sprint S1, requiere DOBLE OK del usuario):**
```bash
# Reasign user 6 posts to user 1 if legítimos, then delete user 6
bin/wp-ssh user delete 6 --reassign=1 --yes
bin/wp-ssh user delete 5 --yes
bin/wp-ssh user delete 7 --yes
bin/wp-ssh user delete 8 --yes
```

**Esfuerzo:** S (15 min) | **Sprint:** S1 inmediato

---

## Cross-cutting findings detectados pre-audit (validar en R1)

Lead-01 los considera findings ya pre-validados. Cada agente owner expande:

### F-PRE-001 — BlueX API key plain text en logs DB
- **Severity:** P0
- **Owner:** mesa-10 security
- **Evidencia:** `.private/akb-prod-dump.sql` — múltiples hits de log table con `x-api-key: QUoO07ZRZ12tzkkF8yJM9am7uhxUJCbR7f6kU5Dz`
- **Riesgo:** Si dump SQL se filtra (gist, slack, debugging share), key expuesta
- **Propuesta:** (a) limpiar logs en `wp_options` o equivalente; (b) configurar logger para redact secretos; (c) considerar key rotation BlueX

### F-PRE-002 — Brevo upstream "Carrito abandonado" workflow tiene 0 traffic
- **Severity:** P1
- **Owner:** mesa-09 email-qa
- **Evidencia:** Screenshot dashboard Brevo: workflow Activo desde 08-04-2026, stats `0 Iniciado / 0 Terminado / 0 Suspendido`
- **Causa probable:** sender domain no validado en Brevo + plugin official tracker JS no firing
- **Propuesta:** (a) validar `akibara.cl` sender domain en Brevo; (b) configurar SPF/DKIM/DMARC en Cloudflare DNS; (c) verificar Brevo plugin tracker activo en wp-admin; (d) test cart abandonment con producto test

### F-PRE-003 — `popup` module hardcoded a 1 step (welcome popup)
- **Severity:** P3
- **Owner:** mesa-15 architect
- **Evidencia:** Investigación previa: `plugins/akibara/modules/popup/module.php` solo tiene templates para welcome, requiere code edit para agregar nuevo popup type
- **Decisión usuario:** YAGNI por ahora. NO refactor proactivo. Cuando aparezca segundo caso de uso (ej: restock alert popup, exit-intent diferente), refactor + 2 popups en mismo sprint.
- **Propuesta:** Mesa-15 marca como `[OVER-ENGINEERED?]` candidato a abstracción FUTURA, NO sprint actual.

### F-PRE-004 — `next-volume` cron completo y wired pero `0 emails sent` runtime probable
- **Severity:** P1
- **Owner:** mesa-09 email-qa + mesa-22 wp-master
- **Evidencia:** Investigación previa muestra código completo + cron diario registrado. Usuario dijo "no funcionando" — runtime issue, no code issue.
- **Causa probable:** wp-cron real (Hostinger crontab) NO configurado → cron solo dispara con tráfico web. Sin tráfico = no fire.
- **Propuesta:** Verificar Hostinger crontab; si no, configurar `wget -q -O - https://akibara.cl/wp-cron.php > /dev/null 2>&1` cada 5 min

### F-PRE-005 — `descuentos` + `welcome-discount` + `marketing-campaigns` overlap en cupón generation
- **Severity:** P2
- **Owner:** mesa-15 architect + mesa-23 PM
- **Evidencia:** Investigación previa: `descuentos` usa cupones virtuales en cart; `welcome-discount` y `marketing-campaigns` usan WC_Coupon real; user goal "campañas flexibles tipo CyberDay/BlackFriday"
- **Propuesta:** Consolidar a sistema único de campañas con WC_Coupon as base. Sprint S2-S3.

### F-PRE-006 — `finance-dashboard` widget básico sin gráficos/filtros
- **Severity:** P2
- **Owner:** mesa-15 architect + mesa-23 PM
- **Evidencia:** Investigación previa: 4 widgets vanilla, no gráficos, no anomaly detection, sin filtros date range
- **Propuesta usuario:** Refactor mínimo manga-specific (top series + editoriales + preorder split) — Opción B de las 3 propuestas
- **Sprint:** S3

### F-PRE-007 — Brevo Free tier 300/día compartido marketing+transactional
- **Severity:** P1 (cuando crezca)
- **Owner:** mesa-09 email-qa + mesa-23 PM
- **Evidencia:** WebSearch Brevo Free tier 2026: 300 emails/día compartido entre marketing y transactional
- **Riesgo:** A 50+ clientes/mes con order confirmations + abandoned cart + welcome series + back-in-stock + next-volume → puede saturar cap → bloquear order confirmations (cliente compra y no recibe email)
- **Propuesta:** Recomendar upgrade a Standard $18/mo cuando marca tráfico estable >50 clientes/mes

### F-PRE-008 — WP core files modificados últimos 90 días (10 archivos)
- **Severity:** P0 (potencial)
- **Owner:** mesa-10 security
- **Evidencia:** `find wp-admin/ wp-includes/ -mtime -90` lista 10 archivos
- **Causa posible A:** WP core update legítimo (mtime se actualiza)
- **Causa posible B:** backdoor/injection
- **Propuesta:** Mesa-10 valida hashes vs WP source oficial (`wp checksum core`), busca patterns maliciosos (eval, base64_decode, gzinflate, str_rot13)

### F-PRE-009 — Leftover plugin folders en uploads/
- **Severity:** P3
- **Owner:** mesa-02 tech-debt
- **Evidencia:** `wp-content/uploads/` tiene `mailpoet/`, `wpseo-redirects/`, `wpml/` (con compiled twig templates), `smush/` — plugins NO activos
- **Propuesta:** CLEAN candidates. Verificar que no hay PHP backdoors (mesa-10), luego rm -rf folders

### F-PRE-010 — Cuenta `nightmarekinggrimm26` (ID 18, customer)
- **Severity:** info (verificar)
- **Owner:** mesa-10 security
- **Evidencia:** Username pattern unusual (no email standard), inserted en lista de customers
- **Propuesta:** Verificar si es cliente real o cuenta sospechosa. Solo info hasta confirmar.

---

## Resumen para mesa-01 lead

**Items pre-confirmados que entran al BACKLOG sin debate:**
- 4 CLEAN seeds (CLEAN-001 a CLEAN-004) → ~1.082 LOC borrados + 1 constante
- 1 SEC-P0 (4 admin backdoors deletion) → segurización inmediata
- 10 cross-cutting findings (F-PRE-001 a F-PRE-010) → expandidos por agente owner en R1

**Esto es el seed.** Mesa-01 expande con findings R1 + sintetiza propuestas en R2.
