---
agent: responsive-design-specialist
round: 1
date: 2026-04-26
scope: Mobile-first audit del tema custom akibara — header, footer, front-page, single-product, cart, checkout, product-card, CSS responsive primitives + breakpoint strategy
files_examined: 35
findings_count: { P0: 0, P1: 2, P2: 8, P3: 5 }
---

## Resumen ejecutivo

1. **Fundamentos sólidos.** El tema implementa muchas mejores prácticas mobile-first: `clamp()` para typography fluida, `aspect-ratio` para CLS prevention, `font-display: swap`, `100vh`+`100dvh` fallback, `env(safe-area-inset-bottom)`, touch targets 44px, `prefers-reduced-motion`, `@media (hover: hover)` para evitar sticky-hover, swipe-to-close drawer, sticky add-to-cart mobile, bottom nav con safe area. La base es mobile-first real, no retrofit.
2. **Breakpoints fragmentados.** Se usan 26 valores distintos (`360`, `380`, `389`, `390`, `420`, `479`, `480`, `500`, `520`, `540`, `560`, `600`, `640`, `768`, `769`, `899`, `900`, `960`, `1024`, `1280`, `1440`, `1600`, `601`, etc.). Varios son reactivos (parche para 1 device específico), no estratégicos. Conviene consolidar a 4-5 standard antes que crezca más.
3. **F-PRE-013 (sale price layout) probablemente artefacto del accessibility tree extraction.** El CSS `.screen-reader-text` está bien definido. Hay que validar visualmente con screenshot real.
4. **Duplicación de `setup.php` (root + `inc/setup.php`)** — archivos byte-idénticos, cleanup obligatorio. También `inc/enqueue.php.bak-2026-04-25-pre-fix` debe ser borrado.
5. **No hay container queries.** Para Akibara con catálogo simple (cards, listings) NO se necesitan — YAGNI aplica.

## Findings

### F-07-001: F-PRE-013 (sale price duplicado en homepage) — validar antes que sea visualmente real
- **Severidad:** P2
- **Categoría:** UX
- **Archivo(s):** `assets/css/design-system.css:946-957` (.screen-reader-text), `assets/css/design-system.css:656-679` (.product-card__price), `template-parts/content/product-card.php:111-113`
- **Descripción:** El issue reportado puede provenir del accessibility tree extraction de Chrome MCP que expone texto SR-only por diseño. NO confirmé visualmente que el bug exista realmente en el render.
- **Propuesta:** Antes de cambiar código: validar visualmente con screenshot DevTools. Si el bug es real, debug si es LiteSpeed cache combined CSS issue. Si NO lo es, marcar F-PRE-013 como WONTFIX (false positive).
- **Esfuerzo:** S
- **Sprint sugerido:** S1 (validación) → S2 (fix si confirmado)
- **Requiere mockup:** NO

### F-07-002: Breakpoints inconsistentes — 26 valores distintos a través de 20 archivos CSS
- **Severidad:** P2
- **Categoría:** REFACTOR
- **Archivo(s):** Todos los archivos en `assets/css/`
- **Descripción:** Análisis cuantitativo: `768px` (21 usos), `480px` (21), `600px` (8), `769px` (7), `1024px` (5), `640px` (4), `380px` (3), etc. Algunos son reactivos (patch device-specific) no estratégicos.
- **Propuesta:** Consolidar a 4 breakpoints standard (`--bp-xs: 480px; --bp-sm: 768px; --bp-md: 1024px; --bp-lg: 1280px`). Refactor incremental.
- **Esfuerzo:** M (4-6h refactor + QA cross-device)
- **Sprint sugerido:** S2-S3
- **Requiere mockup:** NO

### F-07-003: 768/769 collision en boundary
- **Severidad:** P3
- **Categoría:** FRAGILE
- **Archivo(s):** `assets/css/responsive-v2.css:29` y `:417`
- **Descripción:** Mobile usa `max-width: 768px` y desktop usa `min-width: 769px`. Gap de 1px (768 < x < 769) sin reglas.
- **Propuesta:** Migrar TODO a mobile-first puro: `min-width: 768px` (no 769) consistente.
- **Esfuerzo:** M
- **Sprint sugerido:** S2-S3 (con F-07-002)
- **Requiere mockup:** NO

### F-07-004: Duplicación de `setup.php` (root) y `inc/setup.php` byte-idénticos
- **Severidad:** P2
- **Categoría:** DEAD-CODE
- **Archivo(s):** `setup.php`, `inc/setup.php`
- **Descripción:** `diff setup.php inc/setup.php` produce salida vacía. PHP fatal error si ambos se cargan; código muerto si solo uno se carga.
- **Propuesta:** Verificar cuál se carga, borrar el redundante.
- **Esfuerzo:** S (15 min)
- **Sprint sugerido:** S1
- **Requiere mockup:** NO

### F-07-005: `inc/enqueue.php.bak-2026-04-25-pre-fix` leftover
- **Severidad:** P3
- **Categoría:** DEAD-CODE
- **Archivo(s):** `inc/enqueue.php.bak-2026-04-25-pre-fix` (12.4 KB)
- **Descripción:** Backup leftover del fix CSS preload incident. Es noise + potencialmente cargable si regex fuera laxo.
- **Propuesta:** Borrar el archivo
- **Esfuerzo:** S (1 min)
- **Sprint sugerido:** S1
- **Requiere mockup:** NO

### F-07-006: F-PRE-014 — categoría "Uncategorized" como brand label en cards de productos test
- **Severidad:** P3
- **Categoría:** UX
- **Archivo(s):** `template-parts/content/product-card.php:50-54`
- **Descripción:** Cards de productos sin brand muestran "Uncategorized". En 380px puede romper layout.
- **Propuesta:** En product-card.php, si `$brand->name === 'Uncategorized'`, NO renderizar el overlay
- **Esfuerzo:** S (5 min)
- **Sprint sugerido:** S1
- **Requiere mockup:** NO

### F-07-007: Hero `<picture>` no usa srcset+sizes — pierde resolución switching
- **Severidad:** P2
- **Categoría:** REFACTOR
- **Archivo(s):** `template-parts/front-page/hero.php:69-109`
- **Descripción:** Cada `<source>` tiene UN solo `srcset`. En 4K se carga la misma imagen 1280px — pixelada.
- **Propuesta:** Generar variantes 480w/768w/1280w/1920w del hero, usar `srcset` con descriptores `w` y `sizes="100vw"`.
- **Esfuerzo:** M (2-3h)
- **Sprint sugerido:** S3
- **Requiere mockup:** NO

### F-07-008: Trust badges — strong/small font ratio en mobile no responsive
- **Severidad:** P3
- **Categoría:** UX
- **Archivo(s):** `assets/css/branding-v1.css:70-80`
- **Descripción:** En 320px (galaxy fold) badges columnas 2x2 con texto fragmentado.
- **Propuesta:** `@media (max-width: 380px)` con grid 1-col stack
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-07-009: Editorial overlay puede solapar badges en cards estrechos
- **Severidad:** P3
- **Categoría:** UX
- **Archivo(s):** `assets/css/design-system.css:456-499`
- **Descripción:** 3 elementos absolutos en card. En 140-160px (mobile estrecho) saturación visual.
- **Propuesta:** En 380px ocultar editorial overlay
- **Esfuerzo:** S (5 min)
- **Sprint sugerido:** S3
- **Requiere mockup:** SÍ

### F-07-010: Cart drawer header sin count items
- **Severidad:** P3
- **Categoría:** UX
- **Archivo(s):** `assets/css/layout-v2.css:6-17`
- **Descripción:** Drawer abierto pierde feedback de count
- **Propuesta:** Mostrar "Carrito (3)" en header
- **Esfuerzo:** S (15 min)
- **Sprint sugerido:** S3
- **Requiere mockup:** SÍ

### F-07-011: Mobile checkout — verificar sticky bottom CTA enqueued
- **Severidad:** P1
- **Categoría:** UX
- **Archivo(s):** `assets/js/checkout-sticky-mobile.js`
- **Descripción:** Existe el JS pero NO confirmé que está enqueued. Si no carga, sticky CTA mobile no funciona = fricción crítica conversion.
- **Propuesta:** (a) Verificar enqueue en checkout, (b) si falta agregarlo, (c) si broken JS debug
- **Esfuerzo:** S a M
- **Sprint sugerido:** S1
- **Requiere mockup:** NO

### F-07-012: Mobile cart actions sin min-height 44px en buttons
- **Severidad:** P2
- **Categoría:** UX
- **Archivo(s):** `assets/css/woocommerce.css:3568-3585`
- **Descripción:** Coupon input + update button sin `min-height: 44px`
- **Propuesta:** En `@media (max-width: 768px)`, agregar min-height 44px
- **Esfuerzo:** S (5 min)
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-07-013: Sticky add-to-cart sin safe-area-inset-left/right
- **Severidad:** P3
- **Categoría:** UX
- **Archivo(s):** `assets/css/responsive-v2.css:172-187`
- **Descripción:** En iPhone landscape (notch) contenido puede ir debajo del notch
- **Propuesta:** Agregar `env(safe-area-inset-left/right)` al padding
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Requiere mockup:** NO

### F-07-014: Quantity input — verificar touch target 44px
- **Severidad:** P2
- **Categoría:** UX
- **Archivo(s):** `template-parts/single-product/info.php:171-180`
- **Descripción:** Botones +/- pueden estar < 44px en mobile = WCAG fail
- **Propuesta:** Validar visualmente, agregar min 44px si necesario
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Requiere mockup:** NO

### F-07-015: `--header-height` redefinido en mobile sin sync JS
- **Severidad:** P3
- **Categoría:** FRAGILE
- **Archivo(s):** `assets/css/responsive-v2.css:30-33`
- **Descripción:** Si JS usa el var calculado al cargar y user resize de mobile a desktop (DevTools), valor stale
- **Propuesta:** Auditar JS si depende; agregar resize listener si lo hace
- **Esfuerzo:** S (audit) - M (fix)
- **Sprint sugerido:** S3 (defer)
- **Requiere mockup:** NO

## Cross-cutting flags

- mesa-08 design-tokens: F-07-002 breakpoints requiere coordinación tokens `--bp-*`
- mesa-22 wp-master: F-07-006 audit DB productos sin brand; F-07-011 verificar enqueue script
- mesa-15 architect: F-07-002 + F-07-003 son refactor cross-cutting
- mesa-02 tech-debt: F-07-004 + F-07-005 cleanup directo
- mesa-23 PM: F-07-001 PRE-SPRINT validación; F-07-011 alta prioridad conversión S1; F-07-002/003 candidatos S2-S3

## Áreas que NO cubrí

- account.css y página /mi-cuenta/ — solo verifiqué media queries
- pages.css (48 KB internas)
- Series hub/landing responsive
- Browser testing real (Safari iOS, Chrome Android, Firefox)
- JS deep audit (excepto sticky-mobile-checkout)
- Performance metrics reales (LCP/CLS/INP)
- Email templates responsive
