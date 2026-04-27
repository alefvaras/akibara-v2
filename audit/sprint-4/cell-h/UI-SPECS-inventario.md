---
sprint: 4
cell: H
date: 2026-04-26
consumer: Cell C — akibara-inventario
items: [11, 12]
tokens_source: wp-content/themes/akibara/assets/css/tokens.css
---

# UI-SPECS Inventario — Items 11 y 12

## Item 11 — Stock Alerts Table

### Descripcion
Panel de admin para visualizar productos con stock critico, bajo, o en preventa.
Mobile-first con dos layouts: card list en mobile y tabla con overflow-x en desktop.

### Breakpoints
| Breakpoint | Layout |
|---|---|
| < 640px | `.akb-stock-card-list` — cards apiladas (display flex col) |
| >= 640px | `.akb-stock-table` — tabla horizontal con `overflow-x: auto` en `.akb-stock-table-wrap` |

### Severidades de alerta
| Nivel | Token borde | Token pill | Clase CSS |
|---|---|---|---|
| Critico | `--aki-error #FF3B3B` | `stock-pill--critical` | `row--critical` / `akb-stock-card--critical` |
| Bajo | `--aki-warning #F59E0B` | `stock-pill--warning` | `row--warning` / `akb-stock-card--warning` |
| Preventa | `--aki-info #3B82F6` | pill custom | `row--info` / `akb-stock-card--info` |
| OK | `--aki-success #00C853` | `stock-pill--ok` | sin row class |

### Tokens criticos
- `--aki-on-success: #000` — texto sobre success (WCAG AAA 7.6:1)
- `--aki-on-warning: #000` — texto sobre warning (WCAG AAA 8.7:1)
- `--aki-on-info: #000` — texto sobre info (WCAG AA 5.71:1) — NO usar #FFF (fix mesa-08)
- `--aki-border-aa` — border inputs y botones ghost
- `--touch-target-min: 44px` — todos los botones de accion

### Componentes CSS (orden de implementacion)
```css
.akb-stock-panel           /* contenedor region */
.akb-stock-toolbar         /* titulo + badge conteo + acciones */
.akb-stock-filters         /* filtros aria-pressed */
.akb-filter-btn[aria-pressed="true"]  /* filtro activo */
.akb-stock-card-list       /* mobile: lista ul */
.akb-stock-card            /* mobile: item li */
.akb-stock-card--critical | --warning | --info
.akb-stock-table-wrap      /* overflow-x: auto wrapper */
.akb-stock-table           /* display: none mobile / table desktop */
.stock-pill                /* nivel badge */
.stock-pill--critical | --warning | --ok
.akb-icon-btn              /* 44px icon button */
.akb-icon-btn--edit | --notify
.akb-stock-pagination      /* nav paginacion */
.akb-page-btn[aria-current="page"]  /* pagina activa */
```

### ARIA requerido
- `.akb-stock-panel` → `role="region" aria-label="Panel de stock critico"`
- Filtros → `role="group"` + `aria-label` + `aria-pressed` por boton
- Tabla → `aria-label` en `<table>` con conteo total
- `th` → `scope="col"`
- Boton sort → `aria-label` describe columna + accion
- Empty state → `role="status" aria-live="polite"`
- Pagination → `<nav aria-label>` + `aria-current="page"` + `aria-label` por pagina + `disabled` en prev/next cuando aplica
- Color NO unico diferenciador: border-left + pill texto + label siempre juntos

### PHP binding (Cell C)
```php
// modules/inventory/module.php
// AJAX action: wp_ajax_akb_inv_products (admin only, nonce required)
// Response: { products: [{id, name, sku, stock, threshold, level, editorial, bis_count}] }
// levels: 'critical' | 'low' | 'preventa' | 'ok'
// Source: StockRepository::get_low_stock_products()
```

---

## Item 12 — Back-in-Stock Form

### Descripcion
Formulario frontend en pagina de producto cuando `$product->is_in_stock() === false`.
Reemplaza el boton "Agregar al carrito" o aparece debajo de el (hook WC priority 31).

### Estados
| Estado | Clase CSS principal | ARIA |
|---|---|---|
| Default | `.akb-bis-form` | — |
| Focus | `.akb-bis-input` con focus | `border-color: --aki-red; box-shadow ring` |
| Loading | `.akb-bis-form--loading` | `aria-busy="true"` en form + `disabled` en button |
| Success | `.akb-bis-success-state` | `role="status" aria-live="polite"` |
| Error email invalido | `.akb-bis-input--error` + `.akb-input-error-msg` | `aria-invalid="true"` + `aria-describedby` |
| Error duplicado | `.akb-bis-feedback--warning` | `role="alert"` |

### Color CTA
**`--aki-red-bright: #FF2020`** — fix mesa-08 F-04 (4.6:1 PASS AA sobre dark).
NO usar `--aki-red` (#D90010) en CTA sobre dark surface (3.56:1 FAIL AA body text).

### Tokens criticos
- `--aki-red-bright` — CTA button background
- `--aki-red` — focus ring input + border focus
- `--aki-error` — border input error + inline error message
- `--aki-border-aa` — border input default
- `--touch-target-min: 44px` — input min-height + button min-height

### Layout responsive
```
< 480px  → .akb-bis-form__row flex-direction: column (input encima, button debajo)
>= 480px → .akb-bis-form__row flex-direction: row (input + button inline)
```

### Componentes CSS
```css
.akb-bis-form               /* wrapper */
.akb-bis-form--loading      /* pointer-events none + opacity 0.7 */
.akb-bis-form__header       /* titulo + subtitulo */
.akb-bis-form__row          /* flex responsive */
.akb-bis-input              /* email input */
.akb-bis-input--error       /* estado error */
.akb-input-error-msg        /* mensaje error inline bajo input */
.akb-bis-submit             /* CTA --aki-red-bright */
.akb-bis-submit--loading    /* con spinner inline */
.spinner                    /* CSS animation spin */
.akb-bis-feedback           /* mensaje global feedback */
.akb-bis-feedback--success | --error | --warning
.akb-bis-success-state      /* reemplaza el form en exito */
.akb-bis-privacy            /* nota legal */
/* Contexto producto */
.akb-product-oos-context    /* mini product summary */
.akb-oos-badge              /* badge "Agotado" */
```

### ARIA requerido
- `<label for>` siempre — visualmente oculto con `.sr-only` si inline design
- `aria-required="true"` en input
- `aria-invalid="true"` + `aria-describedby` → ID del error msg en estado error
- Error inline: `role="alert"` para lectura inmediata
- Feedback global: `role="alert"`
- Success: `role="status" aria-live="polite"`
- Loading: `aria-busy="true"` + button `disabled`
- Spinner: `role="progressbar" aria-label="Cargando"`

### PHP / AJAX binding (Cell C)
```php
// modules/back-in-stock/module.php
// Shortcode: [akb_bis_form product_id="{{id}}"]
// Hook: woocommerce_single_product_summary priority 31
// AJAX nopriv: wp_ajax_nopriv_akb_bis_subscribe
// POST: { product_id, email, nonce: akb_bis_nonce }
// Response codes: 'ok' | 'invalid_email' | 'duplicate' | 'rate_limit' | 'error'
// Rate limit: BackInStockRepository::is_rate_limited($ip) — 3 subs/hora por IP
// Table: wp_akb_back_in_stock_subs
```

### Mockup
`audit/sprint-4/cell-h/mockups/12-back-in-stock-form.html`

### Bloqueador pendiente (de Cell C HANDOFF D-04)
El form BIS usa un stub styling simple actualmente en prod. Este mockup provee el branding completo.
Cell C aplica los cambios de UI post-aprobacion de este mockup.
