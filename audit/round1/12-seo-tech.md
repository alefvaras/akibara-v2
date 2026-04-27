---
agent: mesa-12-seo-tech
round: 1
date: 2026-04-26
scope: Schema.org markup, Rank Math integration, sitemaps, canonical URLs, robots meta, IndexNow, CWV foundations, internal linking
files_examined: ~30
findings_count: { P0: 1, P1: 4, P2: 6, P3: 5 }
---

## Resumen ejecutivo

1. **🚨 DUPLICATE robots meta tag — conflict noindex/index** en queries con filter params (`/manga/?orderby=price`, `/manga/?filter_genero=accion`). Custom `noindex.php` emite `noindex,nofollow`, Rank Math emite `index,follow`. Search engines tratan como ambiguous → puede penalizar.
2. **JSON-LD Breadcrumb position como STRING** en lugar de int — Google warns en Search Console.
3. **Schema.org coverage sólido en single product** (FAQPage + Product confirmados via Chrome MCP). Falta validar Organization + LocalBusiness + BreadcrumbList completo.
4. **IndexNow funcional** pero valor para Chile cuestionable (Bing/Yandex traffic share <5% típico — mesa-14 confirma)
5. **Sitemap manejado por Rank Math** — no audited integrity (1.371 productos requiere paginación correcta)

## Findings

### F-12-001: Duplicate robots meta tag — noindex/index conflict
- **Severidad:** P0
- **Categoría:** SEO
- **Archivo(s):** `themes/akibara/inc/seo/noindex.php` + Rank Math runtime
- **Descripción:** En URLs con filter params (`/manga/?orderby=price`, `/manga/?filter_genero=accion`) se emiten DOS meta robots tags conflictivas:
  1. Custom: `<meta name="robots" content="noindex, nofollow" />`
  2. Rank Math: `<meta name="robots" content="follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large"/>`
- **Evidencia:** Confirmado via inspección DOM en /manga/?orderby=price y /manga/?filter_genero=accion
- **Propuesta:** Decidir UNA fuente de verdad para meta robots. Recomendado: usar Rank Math con custom rules para los casos noindex (filter params, paginación >5, etc.). Eliminar `noindex.php` o convertirlo en filter de Rank Math vía `rank_math/frontend/robots`.
- **Esfuerzo:** S (30 min)
- **Sprint sugerido:** S1
- **Robustez ganada:** Search engines respetan instrucciones claras, no penalizan por conflicto
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo (validar rastreo Google Search Console post-cambio)

### F-12-002: JSON-LD breadcrumb position como STRING
- **Severidad:** P1
- **Categoría:** SEO
- **Archivo(s):** Producto schema breadcrumb (verificar archivo origen)
- **Descripción:** Schema.org BreadcrumbList items emiten `position="X"` como string en lugar de integer. Google Schema validator y Search Console warns.
- **Evidencia:** Inspección JSON-LD en single product test
- **Propuesta:** Asegurar `position` se emite como `(int)$position` en el schema generator
- **Esfuerzo:** S
- **Sprint sugerido:** S1
- **Robustez ganada:** Schema válido, no warnings GSC
- **Requiere mockup:** NO

### F-12-003: Canonical en /manga/page/N/ correcto pero noindex >5 hardcoded
- **Severidad:** P2
- **Categoría:** SEO
- **Archivo(s):** `themes/akibara/inc/seo/canonical.php` + `noindex.php`
- **Descripción:** Custom emite noindex para páginas paginadas >5. Rank Math acepta el canonical paginado correcto. Pero el threshold "5" es arbitrario para 1.371 productos / 24 per_page = 57 páginas. Las páginas 6-57 noindex pierden discoverability. Decidir si threshold tiene sentido.
- **Propuesta:** Evaluar SEO impact: páginas 6-57 son links válidos para crawl pero noindex. ¿Mejor noindex,follow (no index pero crawl links)? ¿O dejar todas indexable?
- **Esfuerzo:** S (decisión + 1 línea código)
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-12-004: Schema Organization + LocalBusiness Chile no auditado
- **Severidad:** P2
- **Categoría:** SEO
- **Descripción:** Home tiene Store schema confirmado. Falta validar Organization + LocalBusiness con datos Chile (sameAs redes sociales, address, RUT empresa, opening hours si aplica)
- **Propuesta:** Verificar `themes/akibara/inc/seo/schema-organization.php` tiene markup completo. Si falta, agregar para mejor knowledge graph.
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-12-005: IndexNow valor cuestionable para Chile
- **Severidad:** P2
- **Categoría:** SEO
- **Archivo(s):** `mu-plugins/akibara-indexnow.php`
- **Descripción:** IndexNow notifica Bing + Yandex sobre URLs nuevos. En Chile, Bing share ~3-5% del search traffic, Yandex prácticamente 0%. El valor incremental sobre Google (que tiene crawler propio) es marginal.
- **Propuesta:** Mantener si funciona y no consume recursos. NO priorizar refactor. Documentar como "low-value but free" en notes
- **Esfuerzo:** Trivial
- **Sprint sugerido:** Backlog
- **Requiere mockup:** NO

### F-12-006: Sitemap manejado por Rank Math — paginación 1371 productos
- **Severidad:** P2
- **Categoría:** SEO
- **Descripción:** Rank Math genera sitemap. Para 1.371 productos paginar correctamente (chunk size). Validar `/product-sitemap.xml` carga rápido, no contiene productos test, paginación funciona.
- **Propuesta:** Verificar via `curl https://akibara.cl/sitemap_index.xml` + sub-sitemaps. Si productos test aparecen, excluirlos via Rank Math settings.
- **Esfuerzo:** S
- **Sprint sugerido:** S1
- **Requiere mockup:** NO

### F-12-007: FAQ Schema confirmed en single product (positivo)
- **Severidad:** info
- **Categoría:** SEO
- **Descripción:** Single product muestra 5 FAQ Q&A inline (HOMEPAGE-SNAPSHOT.md confirmó). Si tienen FAQPage schema markup correcto, ranking en SERP rich results (FAQ).
- **Propuesta:** Validar JSON-LD FAQPage en single product test. Si presente, mantener. Si falta, agregar.
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-12-008: Open Graph + Twitter Cards (no auditado profundo)
- **Severidad:** P3
- **Categoría:** SEO
- **Descripción:** Rank Math debería generar OG + Twitter cards. Validar para shareability redes sociales (manga audience activa en Twitter/X, Instagram).
- **Propuesta:** Verificar via Facebook Sharing Debugger + Twitter Card Validator
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-12-009: Internal linking — series → tomos
- **Severidad:** P3
- **Categoría:** SEO
- **Descripción:** /serie/ landing tiene "Completa tu Colección" widget que linkea tomos. Single product tiene "Tu próximo tomo" + related products. Buen internal linking topology.
- **Propuesta:** Mantener. Considerar agregar link "Editorial" → archive editorial en single product breadcrumb
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Requiere mockup:** NO

### F-12-010: Long-tail keyword opportunities
- **Severidad:** P3
- **Categoría:** SEO
- **Descripción:** Akibara puede dominar long-tail "manga [serie] precio chile", "manga [editorial] importado", "akihabara chileno". Blog posts orientados a estas keywords + internal links a productos.
- **Propuesta:** Para sprint cuando tenga blog content
- **Esfuerzo:** L (content)
- **Sprint sugerido:** S4+
- **Requiere mockup:** NO

## Cross-cutting flags

- mesa-22 wp-master: F-22-007 productos test en home también afecta sitemap (verificar)
- mesa-15 architect: F-PRE-014 categoría Uncategorized visible afecta SEO (categoría custom diferente)
- mesa-23 PM: priorizar F-12-001 (duplicate robots) en S1 — bloqueante para crecimiento orgánico

## Áreas que NO cubrí

- Schema.org Article markup en blog (no audité blog)
- Hreflang (asumo es-CL único — no multi-país)
- CWV reales con datos GSC/PSI (sin tráfico)
- Performance LCP/CLS/INP (mesa-07 y mesa-15 cubren)
- Google Search Console integration / coverage report
- Backlink profile / off-page SEO
