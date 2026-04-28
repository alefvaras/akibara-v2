---
agent: mesa-23-pm-sprint-planner
round: prioritization
date: 2026-04-27
scope: Backlog SEO Sprint 4 — priorización data-driven sobre 11 findings de mesa-12 + validación mesa-22
inputs: mesa-output/12-seo-tech.md (11 findings), mesa-output/22-wordpress-master.md (feasibility), mesa-output/_input-gsc-data.md (90d GSC), BACKLOG-2026-04-26.md
items_total: 11
items_sprint_4: 7
items_continuo: 2
items_parking: 1
items_blocked_editorial: 1
regression_candidates: 1
---

# mesa-23-pm-sprint-planner — Backlog SEO Sprint 4
**Fecha:** 2026-04-27 | **Lead audit:** mesa-12 + mesa-22 | **Periodo data:** GSC 90d 2026-01-28..2026-04-27

## Executive summary

11 findings de mesa-12 priorizados sobre 4 ejes (impact GSC + effort dev + feasibility WP + dependency edit). **7 items entran a Sprint 4** (~7h dev + ~3h QA = ~10h total, dentro del cap 16-20h con margen para incidentes). **2 items continuo** (F-10 autor pages, F-11 hreflang — MEDIUM impact, M effort). **1 parking-lot** (F-09 IndexNow — LOW SEO impact, solo arquitectural). **1 bloqueado** por dependencia editorial mesa-06 (F-07 blog FAQ schema — el código ya existe, solo falta H2 interrogativos en posts). **1 regression candidate** detectado: B-S3-SEO-05 marcado DONE pero el guard `RANK_MATH_VERSION` suprime Organization schema en producción → F-02 es completion del fix incompleto, no item nuevo.

**Impacto total estimado Sprint 4 SEO:** +40-60 clicks/mo recuperables (consolidación signals product-brand + Organization schema + meta description optimizations + sitemap cleanup), realizables en horizonte 60-90d post-deploy.

---

## Decisiones de priorización

### Items para Sprint 4 (sprint candidate, ~7h dev + ~3h QA)

| ID | Finding | Impact | Effort | Justificación |
|---|---|---|---|---|
| **B-S4-SEO-01** | F-01 | HIGH (15-25 clicks/mo) | XS (45 min) | Quick win: 1 línea .htaccess + curl verify. Resuelve signal split principal del audit. Quick wins primero para validar workflow Sprint 4 SEO. |
| **B-S4-SEO-02** | F-02 (regression de B-S3-SEO-05) | HIGH (prerequisito sitelinks/Knowledge Panel) | S (1.5h) | Regression: B-S3-SEO-05 marcado DONE pero guard `RANK_MATH_VERSION` suprime Organization schema. Sin esto, F-05 desktop CTR no mejora. |
| **B-S4-SEO-03** | F-03 | MEDIUM (crawl health) | S (1.5h) | 22 warnings de 11 URLs es ratio anómalo. Mesa-22 sugiere usar `rank_math_robots` meta vs hardcoded array — más robusto. |
| **B-S4-SEO-04** | F-04 | HIGH (8-12 clicks/mo) | S (1.5h) | Title brand optimization. Depende de F-01 (redirect ya aplicado). Mesa-22 propone alternativa más segura para charset budget de 60 chars. |
| **B-S4-SEO-05** | F-05 | HIGH (+26 clicks/mo si CTR sube a 2.5%) | XS (1h admin) | Admin-only en Rank Math. Depende de F-02 (Organization schema). Sin deploy de código. |
| **B-S4-SEO-06** | F-06 | LOW (0.3 clicks/mo, preventive) | XS (15 min) | Mesa-22 corrigió: `/manhwa/` es product_cat, no page custom. Fix vía WC term description o filter `rank_math/frontend/description`. |
| **B-S4-SEO-07** | F-08 | MEDIUM (2-3 clicks/mo) | XS (15 min) | Literalmente 2 líneas en array `$fallbacks` de meta.php. Diferencia snippet `/nosotros/` vs home. |

**Total Sprint 4 SEO:** 6h 30min dev + 3h 30min QA = **10h** (dentro de cap 16-20h con margen)

### Items para Sprint 4.5 / continuo (~3h dev)

| ID | Finding | Impact | Effort | Justificación |
|---|---|---|---|---|
| **B-S4-SEO-08** | F-10 | MEDIUM (1-3 clicks/mo por autor activo) | M (1.5h) | Mesa-22 corrigió: el handler de meta.php para taxonomías está bajo guard que NUNCA corre con Rank Math. Fix debe ir en filter `rank_math/frontend/description`. Schema CollectionPage replica pattern de product_brand. Effort no encaja en S4 quick wins. |
| **B-S4-SEO-09** | F-11 | LOW (consistency, no impacto directo) | S (1.5h) | Sitio monolingue: hreflang correcto pero no urgente. Continuo / S4.5. |

### Items parking-lot

| ID | Finding | Impact | Effort | Razón parking |
|---|---|---|---|---|
| **B-PARKING-SEO-01** | F-09 | LOW (impacto SEO nulo — IndexNow keys son públicas por diseño) | S (30 min) | Cleanup arquitectural sin payoff SEO. Mover cuando haya otro change a mu-plugins/akibara-indexnow.php. |

### Items bloqueados por dependencia editorial

| ID | Finding | Impact | Effort dev | Bloqueador |
|---|---|---|---|---|
| **B-S4-SEO-10** | F-07 | MEDIUM (+2-4 clicks/mo blog combined) | 0h dev (código listo) + 2-4h editorial mesa-06 | El código `schema-article.php` ya genera FAQPage automático desde H2 interrogativos. Falta editorial: agregar/editar H2 en posts existentes. **NO bloquea Sprint 4** — espera ciclo editorial mesa-06. |

---

## Regression candidates

### F-02 ↔ B-S3-SEO-05 — Organization schema NO emite en prod

**Contexto:** B-S3-SEO-05 ("google-business-schema + seo/schema-organization priority fix") está marcado en BACKLOG como Sprint 3 done. Sin embargo, mesa-12 detectó vía GSC URL Inspection que `/` retorna `rich_results: None`.

**Root cause:** `themes/akibara/inc/seo/schema-organization.php:9` tiene `if (defined('RANK_MATH_VERSION')) return;`. Este guard se aplica al bloque `wp_head` que emite el JSON-LD Organization custom. En producción Rank Math está activo → guard hace que el bloque nunca corra → cero JSON-LD Organization en home.

**El filtro `rank_math/json_ld` que sí corre (línea 36-88) limpia datos incorrectos pero NO inyecta nodo Organization nuevo**.

**Resolución:** B-S4-SEO-02 (item nuevo) extiende el filter `rank_math/json_ld` existente para inyectar Organization si Rank Math no lo emitió. **NO** marcar B-S3-SEO-05 como reabierto — agregar nota cross-ref en el item Sprint 3 indicando que fix completo requiere B-S4-SEO-02.

**Verificación post-deploy:** `curl https://akibara.cl/ | grep -A20 '"@type":"Organization"'` debe retornar al menos un nodo. GSC Rich Results Test sobre `/` pasa de None → Organization detectado.

---

## Effort sumado

| Bloque | Dev | QA | Total |
|---|---|---|---|
| **Sprint 4 SEO (7 items)** | 6h 30min | 3h 30min | **~10h** |
| Sprint 4.5 / continuo (2 items) | 3h | 1h | ~4h |
| Parking (1 item) | 30 min | 15 min | ~45 min |
| Bloqueado editorial (1 item) | 0h dev | 30 min QA | ~2-4h editorial mesa-06 |

**Cross-check con Sprint 3 SEO velocity (B-S3-SEO-01..05):** Sprint 3 SEO tuvo 5 items entregados en bloque ~6-8h efectivas (within Cell B Sprint 3 paralelo). Sprint 4 SEO con 7 items en ~10h **es realista** dada la madurez del workflow (mesa-22 ya validó feasibility, mesa-12 ya entregó código snippet ready-to-paste).

**Capacity Sprint 4 (post-cells C+D+E DONE 2026-04-26/27):** Cells paralelas Sprint 4 ya cerradas. Bloque SEO es track secuencial puro — solo dev en horizonte único. Sprint 4 SEO puede ejecutarse en 1 sesión continua de 1.5 días con QA day-after.

---

## Dependencias bloqueantes

### Items que requieren mesa-06 (copy/editorial)
- **F-07 / B-S4-SEO-10** — Edición de H2 interrogativos en blog posts existentes. NO bloquea Sprint 4 (deferred).

### Items que requieren MOCKUP
- Ninguno. Todos los SEO items son textuales/técnicos, sin cambios visuales.

### Items bloqueados por Rank Math Free flag
- Ninguno. Mesa-22 confirmó: ningún finding requiere Rank Math Premium. Todo factible con Rank Math Free + theme custom hooks.

### Items con LiteSpeed cache purge en deploy checklist
- **B-S4-SEO-01** (F-01): purge `/product-brand/*` post-deploy
- **B-S4-SEO-02** (F-02): purge full home post-deploy (CRÍTICO — sin purge Google sigue cacheando HTML sin Organization)
- **B-S4-SEO-03** (F-03): purge `/page-sitemap.xml` post-deploy + Rank Math regenerate sitemap
- **B-S4-SEO-04** (F-04): purge `/marca/*` post-deploy
- **B-S4-SEO-06** (F-06): purge `/manhwa/` post-deploy
- **B-S4-SEO-07** (F-08): purge `/nosotros/` post-deploy

**Recomendación:** Agregar al Sprint 4 SEO deploy checklist un paso obligatorio "LiteSpeed Purge All post-deploy" para garantizar invalidación. Procedimiento: WP Admin > LiteSpeed Cache > Manage > Purge All, o `bin/wp-ssh lscache-purge --all`.

### Items con dependencia interna entre sí
- **B-S4-SEO-04 (F-04 brand title)** depende de **B-S4-SEO-01 (F-01 redirect product-brand)** — ejecutar B-S4-SEO-01 primero, deja propagar 24-48h.
- **B-S4-SEO-05 (F-05 desktop CTR)** depende de **B-S4-SEO-02 (F-02 Organization schema)** — sitelinks requieren Organization válido emitido. Verificar B-S4-SEO-02 vía GSC Rich Results Test antes de ejecutar B-S4-SEO-05.

### Orden de ejecución recomendado Sprint 4 SEO

1. **B-S4-SEO-01** (45 min) — quick win redirect, baja superficie de riesgo
2. **B-S4-SEO-07** (15 min) — quick win nosotros, 2 líneas
3. **B-S4-SEO-06** (15 min) — quick win manhwa
4. **B-S4-SEO-02** (1.5h) — Organization schema (filter extension)
5. **B-S4-SEO-03** (1.5h) — sitemap exclude_post filter
6. **B-S4-SEO-04** (1.5h) — brand title optimization (post B-S4-SEO-01)
7. **B-S4-SEO-05** (1h admin) — desktop CTR title home (post B-S4-SEO-02 verified)
8. **QA bloque** (3.5h) — curl + GSC Rich Results Test + LiteSpeed purge + GSC inspect_url_enhanced post 7-30d

---

## Cross-check con BACKLOG existente

Verificación de duplicados via grep en BACKLOG-2026-04-26.md:

| Item nuevo | Existente | Decisión |
|---|---|---|
| B-S4-SEO-01 (F-01 product-brand redirect) | Ninguno | NUEVO |
| B-S4-SEO-02 (F-02 Organization schema) | B-S3-SEO-05 marcado DONE pero **incompleto** | **REGRESSION FIX**: cross-ref pero NO duplicar. B-S3-SEO-05 mantiene status DONE histórico, B-S4-SEO-02 completa el fix |
| B-S4-SEO-03 (F-03 sitemap warnings) | B-S1-SEO-02 (sitemap validation) DONE — solo validó URLs, no warnings | NUEVO scope (warnings, no carga) |
| B-S4-SEO-04 (F-04 brand title) | Ninguno | NUEVO |
| B-S4-SEO-05 (F-05 desktop CTR) | Ninguno | NUEVO |
| B-S4-SEO-06 (F-06 manhwa meta) | Ninguno | NUEVO |
| B-S4-SEO-07 (F-08 nosotros desc) | Ninguno | NUEVO |
| B-S4-SEO-08 (F-10 autor pages) | Ninguno | NUEVO |
| B-S4-SEO-09 (F-11 hreflang filter path) | Ninguno (canonical.php emite hreflang sin guard) | NUEVO |
| B-PARKING-SEO-01 (F-09 IndexNow key) | Ninguno | NUEVO parking |
| B-S4-SEO-10 (F-07 blog FAQ) | Ninguno (código `schema-article.php` ya implementado) | NUEVO editorial |

**Sin items duplicados.** Sin items "ya cubiertos" que se vuelvan a proponer (mesa-12 ya filtró: og:locale, blog index meta, breadcrumb position int, etc.).

---

## Acceptance criteria objetivos (no subjetivos)

Cada item del Sprint 4 SEO incluye verificación post-deploy via GSC tools (mcp-gsc instalado per memoria `reference_mcp_gsc`):

- **B-S4-SEO-01**: `inspect_url_enhanced` sobre `/product-brand/ivrea-argentina/` retorna verdict `Page with redirect` con `redirectsTo: /marca/ivrea-argentina/` (un solo hop). `compare_search_periods` 28d después → `/marca/ivrea-argentina/` impressions delta ≥ +50% (target: pasa de 37 imp a 60+ imp).
- **B-S4-SEO-02**: `inspect_url_enhanced` sobre `/` retorna `richResults: { detectedItems: ['Organization'] }`. GSC Rich Results Test pasa de `None` a `Organization`.
- **B-S4-SEO-03**: `list_sitemaps` retorna `page-sitemap.xml` con 0 warnings (era 22).
- **B-S4-SEO-04**: `compare_search_periods` 28d después → query "ivrea chile" position delta ≤ -5 (target: pasa de pos 24 a pos 19).
- **B-S4-SEO-05**: `get_search_analytics` filter `device=DESKTOP` 28d después → CTR delta ≥ +0.4 puntos absolutos (target: pasa de 1.77% a 2.2%+).
- **B-S4-SEO-06**: `inspect_url_enhanced` sobre `/manhwa/` retorna meta description con keyword "manhwa" + length entre 120-160 chars.
- **B-S4-SEO-07**: `inspect_url_enhanced` sobre `/nosotros/` retorna meta description distinta de `/`.

**No hay acceptance criteria subjetivos** ("mejor SEO", "más visible") — todos verificables via GSC API con números concretos.

---

## Riesgos cross-cutting

### Riesgo 1: LiteSpeed cache invalidation incompleta
- **Impacto:** Google sigue viendo HTML/sitemap viejo → cambios SEO no propagan.
- **Mitigación:** Step obligatorio "LiteSpeed Purge All" en cada item post-deploy. Documented en cada DoD.

### Riesgo 2: Solo dev sin tests automated SEO
- **Impacto:** Posible regresión silent en `meta.php` o `schema-organization.php`.
- **Mitigación:** Smoke test curl+grep post-deploy es DoD obligatorio. Sentry T+24h monitoring.

### Riesgo 3: GSC indexing latency 7-30d
- **Impacto:** No podemos verificar efecto real inmediato — items "DONE" técnicamente pero validation diferida.
- **Mitigación:** Acceptance criteria split en (a) técnico inmediato (curl + GSC URL Inspection same-day) y (b) impacto observado (compare_search_periods 28d después). Sprint 4 marca DONE con (a). Verificación (b) en Sprint 5 retrospect.

### Riesgo 4: F-02 fix introduce JSON-LD duplicado si Rank Math actualiza features
- **Impacto:** En el futuro Rank Math Free podría comenzar a emitir Organization en home → duplicado.
- **Mitigación:** El check `if (!$has_org)` en el filter inspecciona `$data` antes de inyectar. Si Rank Math agrega Organization → guard pasa → no duplicado. Defensive coding ya en propuesta mesa-12.

---

## Notas operativas

1. **Voz Akibara:** Todas las copy nuevas (descriptions, titles) respetan tuteo chileno neutro. NO voseo. Cero rioplatense.
2. **No mockup requerido:** Sprint 4 SEO 100% textual/técnico. Cero items en `PENDIENTE MOCKUP`.
3. **DOBLE OK destructivo:** Ningún item Sprint 4 SEO es destructivo. .htaccess edits son aditivas, code edits son extensions. Sin DOBLE OK requerido.
4. **Living docs update post-Sprint 4 SEO:** Per memoria `project_living_docs_update_policy`, post-cierre Sprint 4 SEO marcar items DONE en BACKLOG con commit hash + esfuerzo real + fecha. Actualizar `audit/AUDIT-SUMMARY-2026-04-26.md` métrica P1 SEO.
5. **Cross-cutting con mesa-15 (architecture):** Mesa-12 flag F-15 que el guard `if (defined('RANK_MATH_VERSION')) return;` puede repetirse en otros archivos `inc/seo/`. **Recomendación:** sweep architectural en próximo Sprint 4.5 o quarterly architecture audit (mesa-15). NO bloqueante para Sprint 4 SEO.

---

**FIN mesa-23-pm-sprint-planner.md**
