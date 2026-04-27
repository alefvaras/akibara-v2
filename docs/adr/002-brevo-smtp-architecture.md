# ADR 002 — Brevo SMTP bridge (mu-plugin load-bearing) + AKB_BREVO_API_KEY constante

**Status:** Accepted (2026-04-26)
**Origin:** B-S1-CLEAN-04 · CLEAN-003 + CLEAN-004 cancelaciones · F-09-001 / F-10-003 / F-02-002
**Decision-makers:** mesa-02 / mesa-09 / mesa-10 / mesa-15
**Supersedes:** ninguno
**Superseded-by:** ninguno

---

## Contexto

Durante el audit foundation 2026-04-26, dos seeds del usuario sugerían eliminar:

- **CLEAN-003**: `wp-content/mu-plugins/akibara-brevo-smtp.php` (presumido como duplicado del plugin oficial Sendinblue/Brevo)
- **CLEAN-004**: la constante `AKB_BREVO_API_KEY` en `wp-config.php`

Ambas hipótesis apuntaban a "limpieza por ser redundancia". El audit concluyó **CANCELACIÓN ambas** — son el path crítico de email transaccional.

## Por qué el mu-plugin es load-bearing

### Hostinger BLOQUEA `mail()` PHP nativo

Hostinger shared hosting intercepta el binario `sendmail` y bloquea cualquier llamada `mail()` desde PHP. WordPress `wp_mail()` por default usa `mail()` → falla silenciosamente. Antes de tener este mu-plugin, WP llamaba `mail()` repetidamente y Hostinger marcaba el dominio como "bouncing source", llegando a **bloquear la cuenta entera por abuse** (incident histórico Akibara).

### El mu-plugin intercepta `wp_mail` vía `phpmailer_init`

El hook `phpmailer_init` permite redirigir el SMTP backend. Nuestro mu-plugin:

1. Lee `AKB_BREVO_API_KEY` (ahora en `wp-config-private.php` per B-S1-EMAIL-03)
2. Configura `$phpmailer` con: host = `smtp-relay.brevo.com`, port = 587, auth = SMTP user/password Brevo (NO la API key — keys distintas)
3. Cualquier `wp_mail(...)` ahora envía por Brevo SMTP, NO por Hostinger PHP `mail()`

### 7 callsites dependen de wp_mail/this path

Confirmed durante audit:
- WC order confirmation emails (admin + customer × 5 statuses)
- WC stock low alerts
- WC password reset
- Akibara welcome-series (3 emails per signup)
- Akibara abandoned cart (Brevo upstream + local fallback)
- Akibara encargos notification a admin
- Akibara magic-link login

Todos pasan por `wp_mail()` → `phpmailer_init` filter → mu-plugin reescribe a Brevo SMTP.

### 72 emails ya enviados via este path (datos pre-audit)

Stats Brevo dashboard pre-audit confirmaron 72+ emails delivered últimos 30 días. Eliminar el mu-plugin = romper inmediatamente todos los emails transaccionales.

## Por qué la constante NO se elimina

`AKB_BREVO_API_KEY` alimenta:

- `akibara-brevo-smtp.php` (CLEAN-003 — load-bearing per arriba)
- Akibara welcome-series (`wp-content/plugins/akibara/modules/marketing-campaigns/welcome-series.php`) — usa Brevo API REST `/v3/contacts/{email}` para check blacklist
- Akibara abandoned-cart tracker — `/v3/events` para lanzar workflow upstream

Si se elimina la constante: las 3 features rompen. CLEAN-004 cancelado.

## Issue real ≠ eliminar constante

El issue real era: la API key estaba **revocada en Brevo dashboard** (HTTP 401 "API Key is not enabled"). Confirmado y arreglado en B-S1-EMAIL-03 (2026-04-27): nueva key generada (`akibara-prod-2026-04-27`) + migrada a `wp-config-private.php` con triple defensa (chmod 600 + Apache deny + PHP `AKB_CONFIG_LOADER` guard).

## Decisión

**Mantener `akibara-brevo-smtp.php` como mu-plugin permanente.** Mantener `AKB_BREVO_API_KEY` constante (ahora en `wp-config-private.php`). NO eliminar ninguna de las dos.

Plugin oficial Sendinblue/Brevo (`woocommerce-sendinblue-newsletter-subscription/`) sigue activo en paralelo — cubre subscribers + lists + workflows panel UI. Nuestro mu-plugin cubre el SMTP redirect en kernel WP, scope distinto.

## Consecuencias

### Positivas
- Email transaccional opera sin riesgo de Hostinger bloqueo.
- API REST disponible para features custom (welcome-series blacklist check, abandoned cart events).
- Plugin oficial UI para campaigns sin tocar código.

### Negativas / costo
- 2 stacks Brevo paralelos (SMTP custom + plugin oficial REST). Risk: sync drift de credenciales (la API key del plugin oficial puede ser diferente de la nuestra constante).
- Manual config en wp-config-private.php — no UI para rotar la key sin redeploy.

### Mitigación
- Smoke test post-deploy: `wp_mail()` test a `alejandro.fvaras@gmail.com` + Brevo `/v3/account` HTTP 200 check (B-S1-EMAIL-03 incluye este smoke).
- Memory `project_no_key_rotation_policy` documenta no-rotation como decisión consciente.
- B-S1-SEC-04 disclosure note (`audit/sprint-1/sec-04-bluex-disclosure-2026-04-27.md`) ejemplo de cómo documentar issues con keys upstream para futuras integraciones.

## Archivos relacionados

- `wp-content/mu-plugins/akibara-brevo-smtp.php` — el mu-plugin SMTP bridge
- `wp-config-private.php` — `AKB_BREVO_API_KEY` constante (B-S1-EMAIL-03 migration)
- `wp-content/plugins/akibara/modules/marketing-campaigns/welcome-series.php` — consumidor
- `wp-content/plugins/woocommerce-sendinblue-newsletter-subscription/` — plugin oficial paralelo

## Trigger para re-evaluar

- Hostinger desbloquea PHP `mail()` (improbable). En ese caso el mu-plugin podría simplificarse a thin wrapper.
- Akibara migra a otro hosting que NO bloquea `mail()` Y a otro provider de email (descarte completo de Brevo).
- Plugin oficial Sendinblue/Brevo expone configuración SMTP directa (currently NO la expone).

## Referencias

- F-09-001 audit finding (Hostinger mail() blocked)
- F-10-003 (API key plain-text en wp-config.php — fixed in EMAIL-03)
- F-02-002 (mu-plugin no documentado — fixed por este ADR)
- `audit/round1/09-email-qa.md` § Brevo stack analysis
- `audit/round2/MESA-TECNICA-PROPUESTAS.md` Decisión cancelación CLEAN-003/004
- `docs/RUNBOOK-DESTRUCTIVO.md` § rollback DB (key rotation procedure)
