# INCIDENT-05/06/07 — Polish #1 Deploy Rollback 2026-04-27

**Status:** Polish #1 (merge 9 modules → akibara-core) rolled back en prod.
**Branch en repo:** `feat/akibara-core-merge-legacy` (commit cfe9069) preservado para iteración futura con plan diferente.
**Prod:** restaurado a state pre-#1 (commit 8dd7b46 deployment).

## Issues encontrados en cascade

### INCIDENT-05: ServiceLocator::register() undefined
- 4 addons llamaban `services()->register()` pero ServiceLocator solo tiene `set()`
- AddonContract auto-recovery disabled 4 addons → 500 en /mi-cuenta/, /checkout/, /mis-reservas/
- Fix forward: `register()` alias agregado (commit f3a34a9). FUNCIONÓ.

### INCIDENT-06: series-autofill namespace mal posicionado
- `namespace Akibara\SeriesAutofill;` en línea 47 después de defines/requires
- PHP requiere namespace as first statement
- Fix: bracketed namespace syntax (commit b69525c). FUNCIONÓ.

### INCIDENT-07: EmailTemplate class no carga via PSR-4
- composer mapping es `Akibara\Core\` → src/, pero clase es `Akibara\Infra\EmailTemplate`
- Fix: explicit require_once en shim (commit ?). NO RESOLVIÓ — el 500 persistió.

### Causa raíz desconocida
- HTTP 500 sin logging a error_log standard
- WP_DEBUG_DISPLAY=true no mostraba detalle
- Sentry 0 issues últimos 10min
- Síntoma: solo /mi-cuenta/, /mi-cuenta/mis-reservas/ afectadas (no /tienda/, no /, no /checkout/)

## Decisión: ROLLBACK forward fix

Decidido por user "no me importa que esté caído" + tiempo invertido sin diagnóstico claro.

Restore akibara-core a commit 8dd7b46 + akibara/akibara.php a esa misma versión via git archive + rsync --delete.

## Lecciones para Polish #1 retry future

1. **Pre-deploy testing en staging FIRST** — Polish #1 nunca corrió en staging antes de prod. Repetiríamos INCIDENT-01 lección L-05.
2. **Lock policy no respetada** — el subagent #1 cambió Core API (ServiceLocator.set vs .register undocumented). Modificaciones a Core requieren coordination con addons que ya consumen API.
3. **Class migration namespace consistency** — al migrar `Akibara\Infra\EmailTemplate` a akibara-core, mantener namespace `Akibara\Infra\` requiere mapping en composer PSR-4 O renombrar a `Akibara\Core\Infra\`.
4. **Sentinel en files con namespace** — bracketed namespace `namespace { ... }` permite global namespace + namespaced en mismo archivo. Subagent NO conocía este patrón.
5. **Fatal hidden by sentry-customizations** — el mu-plugin captura fatal y muestra "Error crítico" UI sin mostrar detalle. Para debug futuro, temporariamente desactivar mu-plugin sentry o usar `WP_DEBUG_DISPLAY` desde wp-config.php directly.

## State final prod post-rollback

```
7 plugins akibara activos:
  • akibara legacy 10.0.0 (con hotfix #11 extended skip)
  • akibara-core 1.0.0 (Phase 1 de Sprint 2 — pre-Polish #1)
  • akibara-preventas 1.0.0
  • akibara-marketing 1.0.0
  • akibara-inventario 1.0.0
  • akibara-mercadolibre 1.0.0 (con BOOK_COVER fix)
  • akibara-whatsapp 1.4.0

Smoke: HTTP 200 / + tienda + mi-cuenta + mis-reservas + encargos
       HTTP 302 checkout (login redirect normal)

Sentry: 0 fatales últimos 10min.
```

## Polish #1 retry plan (futuro)

Cuando se intente Polish #1 de nuevo, requisitos pre-deploy:
1. Subagent debe usar staging.akibara.cl primero (no direct a prod)
2. Verify ServiceLocator API antes de migrar — confirmar `register()` o `set()` consistente entre Core + addons
3. Namespace migration: cambiar a `Akibara\Core\Infra\EmailTemplate` para alinearse con composer mapping
4. Series-autofill: usar bracketed namespace pattern explicitly documented
5. Mantener archivos legacy en plugin akibara/modules/* hasta Sentry T+24h verde post-deploy de migration
6. Plan deactivate plugin akibara legacy entero solo cuando ALL modules NOT migrated también estén en akibara-core

## Branches en repo

- `feat/akibara-core-merge-legacy` (commit cfe9069) — Polish #1 implementación, preservada
- `main` — last good state es commit 8dd7b46 (pre-Polish #1) + ML BOOK_COVER fix

Para retry: cherry-pick commits específicos de feat/akibara-core-merge-legacy con análisis archivo-por-archivo.
