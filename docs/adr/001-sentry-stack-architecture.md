# ADR 001 — Sentry stack architecture (mu-plugin custom load-bearing)

**Status:** Accepted (2026-04-26)
**Origin:** B-S1-CLEAN-04 · CLEAN-001 cancelación · F-10-002
**Decision-makers:** mesa-02 / mesa-09 / mesa-10 / mesa-15 (audit foundation 2026-04-26)
**Supersedes:** ninguno
**Superseded-by:** ninguno

---

## Contexto

Durante el audit foundation 2026-04-26, el seed inicial del usuario sugería eliminar el mu-plugin `wp-content/mu-plugins/akibara-sentry-customizations.php` (CLEAN-001 candidate). Hipótesis: era código duplicado del plugin oficial `wp-sentry-integration`.

El audit concluyó **CANCELACIÓN** — el mu-plugin NO es duplicado: es el bridge de configuración que el plugin oficial necesita para operar.

## Lo que el mu-plugin custom hace (no replicable con el plugin oficial)

1. **Define `WP_SENTRY_PHP_DSN` constante** — El plugin oficial `wp-sentry-integration` lee esta constante en bootstrap. Sin ella, Sentry SDK no inicializa y todos los errores se pierden silenciosamente.

2. **PII scrubbing chileno** — Strips antes del envío:
   - RUT (formato `XX.XXX.XXX-Y`)
   - Teléfonos chilenos (`+56 9 NNNN NNNN` y variantes)
   - Emails de customer
   Esto cumple Ley 19.628 (datos personales Chile) que prohíbe transferencia internacional sin consentimiento. Sentry corre en us.sentry.io.

3. **Tagging contexto Akibara** — Agrega tags `module`, `env=prod/dev`, `wp_user_role`, `wc_order_status` al payload para filtrar en dashboard.

4. **Sample rate dinámico** — `SENTRY_SAMPLE_RATE` constante consumida runtime. Permite bajar a 0.1 si Sentry quota se acerca al límite del free tier.

## Lo que pasa si se elimina

- ❌ Cero error tracking (constant undefined → SDK no init)
- ❌ Violación Ley 19.628 si SDK accidentalmente envía PII chilena sin scrub a us.sentry.io
- ❌ Pérdida de tagging — todos los issues mezclados sin contexto
- ❌ No control de quota → riesgo de hit free-tier limit en pico de tráfico

## Decisión

**Mantener `akibara-sentry-customizations.php` como mu-plugin permanente.** No es candidate de cleanup. Es load-bearing infra.

## Consecuencias

### Positivas
- Error tracking funcional + compliance Ley 19.628.
- Sample rate ajustable sin redeploy.
- Tags ricos para debug.

### Negativas / costo
- 1 archivo PHP adicional en mu-plugins/ (~150 líneas).
- Acoplamiento con la versión del plugin oficial — si `wp-sentry-integration` cambia el nombre de la constante (`WP_SENTRY_PHP_DSN` → otro), nuestro mu-plugin queda roto silenciosamente.

### Mitigación al acoplamiento
- Smoke test post-deploy: `bin/wp-ssh eval 'echo defined("WP_SENTRY_PHP_DSN") ? "OK" : "FAIL";'` en `scripts/smoke-prod.sh` upgrade.
- Sentry alert "no events received in 30 min" — detecta misconfig sin necesidad de smoke explícito.
- Plugin oficial pinned via `composer require wp-sentry-integration:X.Y.Z` (cuando exista composer.json runtime — Sprint 2).

## Archivos relacionados

- `wp-content/mu-plugins/akibara-sentry-customizations.php` — el mu-plugin
- `wp-content/plugins/wp-sentry-integration/` — plugin oficial consumidor
- `wp-config-private.php` (B-S1-EMAIL-03 2026-04-27) — `SENTRY_DSN` constante migrada acá
- `wp-config.php` línea — `SENTRY_ENVIRONMENT`, `SENTRY_SAMPLE_RATE` (no sensitive, quedan en wp-config)

## Trigger para re-evaluar

- Akibara migra Sentry self-hosted en EU/Chile (elimina la justificación de PII scrubbing por jurisdicción).
- Plugin oficial expone hooks suficientes para configurar todo desde admin UI (entonces el bridge mu-plugin se vuelve redundante).
- Akibara descarta Sentry como provider (Bugsnag, Datadog, etc.) — entonces este ADR se supersede.

## Referencias

- F-10-002 audit finding (2026-04-26)
- `audit/round1/10-security.md` § Sentry stack
- `audit/round1/09-email-qa.md` § PII scrubbing requirements
- mesa-15 architect-reviewer round 1 output
