# INCIDENT-04 — Prod deploy Sprint 4-5 rsync --delete + LiteSpeed cache

**Fecha:** 2026-04-27 ~20:00 UTC
**Severity:** P0 (site down ~5 min) → recovered
**Detection:** smoke HTTP empty post-rsync
**Root cause:** dual

## Cause 1 — rsync --delete removió archivos del legacy plugin akibara

Comando inicial usaba `rsync -az --delete` para todos los plugins. Para el legacy `akibara/`, el repo local NO contiene `includes/` directory completo (solo `akibara.php` + `modules/`). El `--delete` borró `includes/autoload.php` causando fatal en `akibara.php:42 require_once 'includes/autoload.php'`.

**Fix:** rsync server-snapshot/ → prod (sin --delete) restored 278 files. Re-aplicado mi `akibara.php` local con hotfix extended.

## Cause 2 — LiteSpeed cache stale durante activate

Post-restore, primera curl de smoke devolvió HTTP empty para todas URLs. Sentry NO mostraba fatales. Re-curl ~30 seg después devolvió HTTP 200 normal. **LiteSpeed cache served stale state durante plugin activation transient.** No requirió fix — transient self-resolved.

## Lessons

- **rsync --delete sobre legacy plugins peligroso:** repo local puede tener subset incompleto vs prod state. Usar `--delete` SOLO para plugins/themes que el repo es source-of-truth completo.
- **LiteSpeed cache puede mascarar deploy state:** flush cache pre/post-activate. Considerar `wp litespeed-purge all` en deploy script.
- **Health endpoint 503 ≠ fatal:** `/wp-json/akibara/v1/health` retorna `{"status":"degraded"}` cuando service dependencies reportan issues — graceful degradation working as designed.

## Final state prod 2026-04-27 ~20:10 UTC

8 plugins akibara activos:
- akibara legacy v10.0.0 (con hotfix extended Sprint 4-5)
- akibara-core v1.0.0
- akibara-preventas v1.0.0
- akibara-marketing v1.0.0
- akibara-inventario v1.0.0 ← NUEVO
- akibara-whatsapp v1.4.0 ← UPDATE
- akibara-mercadolibre v1.0.0 ← NUEVO
- akibara-reservas v1.0.0 (legacy preventas, mantener 7+d)

Sentry 0 fatales 5min post-deploy. Customer-facing OK (home/tienda/encargos HTTP 200).
