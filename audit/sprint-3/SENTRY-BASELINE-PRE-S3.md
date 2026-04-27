# Sentry Baseline pre-Sprint-3

**Tomado:** 2026-04-27 (decisión usuario: lanzar Sprint 3 antes del Sentry 24h watch formal del 2026-04-28 ~13:37)
**Org/Project:** akibara/php
**Tool:** MCP Sentry `find_releases` + `search_issues`

---

## Releases recientes (akibara/php)

Último release deployed prod = **`akibara-ccc5faf`** (2026-04-26 16:40 UTC, commit `ccc5faf86f160d110d42fb1c91872c3157292871` — `fix(ci): TSR restore + Lighthouse threshold + fixture via wp eval`).

**Sprint 2 cell-core commits NO están deployed prod aún:**
- `703faee` (Merge PR #1 cell-core phase1) — solo en main, no en Sentry releases
- `782d80a` (PR #2 F-pivot defensive layer) — solo en main
- `ad3c60f` (PR #3 hoisting fix postmortem) — solo en main

**Implicación:** la "ventana Sentry 24h" formal scheduled 2026-04-28 ~13:37 cuenta desde el deploy a prod, NO desde el merge a main. El cell-core está merged a main pero aún no live en prod. Esta sesión se enfoca en consolidar plugins (Cell A/B) y design ops (Cell H) — **el deploy prod ocurrirá al final de Sprint 3** (3 PRs merged + smoke staging verde). En ese momento nuevo el reloj de 24h Sentry empieza para los releases Sprint 2 + Sprint 3 juntos.

Adicional: aparece un release `4.6.2` con **353 New Issues** y First Event 2026-04-27T14:05:35Z — esto NO es un release Akibara nuestro. Es la versión interpretada por Sentry SDK del WP/WC/PHP runtime. Las 353 issues son las deprecaciones bluex que listamos abajo (mismo origen, agrupadas distinto por release-tag de Sentry).

---

## Issues unresolved (top 100, last 24h, sorted by event count)

**Conteo:** 100 issues unresolved
**Patrón ÚNICO:** **TODAS** son `ErrorException: Deprecated: Creation of dynamic property` en `wp-content/plugins/bluex-for-woocommerce/`
**First seen:** 3 horas ago (todas — apareció en bloque, probable activación post-update/restart PHP-FPM)
**Last seen:** 11–32 minutos ago (siguen ocurriendo)
**Users afectados:** 0 (es deprecation warning, no error funcional)

**Clases afectadas (todas en bluex-for-woocommerce):**
- `WC_Correios_Integration` (~16 properties dinámicas)
- `WC_Correios_TrackingEmail` (2 properties)
- `WC_BlueX_PY` (16 properties)
- `WC_BlueX_EX` (16 properties)
- `WC_BlueX_MD` (16 properties)

**Origen:** PHP 8.2+ deprecó la creación de propiedades dinámicas sin `#[\AllowDynamicProperties]`. Plugin third-party `bluex-for-woocommerce` no fue updated para PHP 8.2+. Esto es **ruido upstream NO causado por Sprint 2 ni Sprint 3**.

---

## Threshold para watch durante Sprint 3

**Ruido de fondo permitido (NO bloquear Sprint 3):**
- ✅ `ErrorException: Deprecated: Creation of dynamic property` con culprit en `wp-content/plugins/bluex-for-woocommerce/**`
- ✅ Cualquier `level:warning` o `level:info` pre-existente

**Señales que SÍ bloquean Sprint 3 (rollback inmediato per plan mitigación #4):**
- ❌ Cualquier `level:error` o `level:fatal` con culprit en:
  - `wp-content/plugins/akibara-core/**`
  - `wp-content/plugins/akibara-preventas/**` (Sprint 3 Cell A)
  - `wp-content/plugins/akibara-marketing/**` (Sprint 3 Cell B)
  - `wp-content/plugins/akibara-reservas/**` (legacy en migración)
  - `wp-content/themes/akibara/**`
  - `wp-content/mu-plugins/akibara-00-core-bootstrap.php`
- ❌ `count >= 3` en 30 min para issue NUEVO (firstSeen post-deploy)
- ❌ Cualquier `Akibara_*` class en culprit con exception type ≠ deprecation

---

## Comandos para verify checkpoint

**Pre-deploy cada cell (re-baseline):**
```
mcp__e954486c-025a-4747-9930-137e870fd2e3__search_issues
  organizationSlug: akibara
  projectSlugOrId: php
  naturalLanguageQuery: "unresolved issues in last hour with culprit not in bluex-for-woocommerce"
  limit: 30
```

**Post-deploy cada cell (Sprint 3 release verify):**
```
mcp__e954486c-025a-4747-9930-137e870fd2e3__find_releases
  organizationSlug: akibara
  projectSlug: php
  query: <commit-hash-prefix-7-chars>
```

**24h checkpoint formal post-Sprint-3 deploy:**
- Comparar conteo issues `firstSeen > <sprint3-deploy-timestamp>` vs baseline noise floor
- Issues nuevos con culprit `akibara-*` o `themes/akibara` → investigar 1 por 1
- Issues nuevos con culprit `bluex-for-woocommerce` → ignorar (ruido upstream conocido)

---

## Notas operacionales

1. **Backlog item nuevo (out-of-scope Sprint 3):** Las 100 deprecaciones BlueX son ruido permanente hasta que se actualice el plugin o se silence en `wp-config.php` con `error_reporting & ~E_DEPRECATED`. Crear ticket "BlueX PHP 8.2 deprecation cleanup" para Sprint 4+.
2. **Sentry release tagging:** GitHub Actions Sentry plugin tagea releases automáticamente por commit hash al deploy. Los 3 PRs Sprint 3 generarán 3 releases nuevos (akibara-preventas, akibara-marketing, theme-design-s3). Filtrar `release:akibara-<commit>` para aislar issues por cell.
3. **Decisión de continuidad:** Si durante Sprint 3 aparece un spike NO atribuible a bluex (e.g. un nuevo `Akibara_*` fatal), **pausar la cell que generó el push**, abrir incident report en `audit/sprint-3/cell-{a,b,h}/INCIDENT-NN.md`, y reportar al usuario antes de continuar.
