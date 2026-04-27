---
sprint: 4
cell: H
date: 2026-04-26
consumer: Cell D — akibara-whatsapp
items: [13]
tokens_source: wp-content/themes/akibara/assets/css/tokens.css
---

# UI-SPECS WhatsApp — Item 13

## Item 13 — WhatsApp Button Placement Variants

### Descripcion
Especificacion de 3 variantes de placement para el boton de WhatsApp.
La Variante A (float) es el estado actual en prod — las otras dos son opcionales para Cell D.

### Variante A — Float button (PROD ACTUAL)

**Estado actual en `akibara-whatsapp.css`:**
```css
.akibara-whatsapp-button {
  position: fixed;
  right: 20px;
  bottom: 76px;  /* encima del WC cart bar en mobile */
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #25D366;  /* WA brand */
  z-index: 9999;
}
@media (min-width: 768px) {
  .akibara-whatsapp-button { bottom: 20px; }
}
```

**Regla de comportamiento (Cell D HANDOFF):** NO cambiar este CSS sin mockup aprobado.
La politica `feedback_minimize_behavior_change.md` prioriza mantener comportamiento existente.

**A1 — Icon only (actual):** boton circular 56px, solo SVG WA icon.
**A2 — Expanded (opcional):** clase `.akb-wa-float--expanded` con label "Consultar". Activar via JS post 3s delay.

### Especificaciones float button
| Prop | Mobile (<768px) | Desktop (>=768px) |
|---|---|---|
| `position` | `fixed` | `fixed` |
| `bottom` | `76px` | `20px` |
| `right` | `20px` | `20px` |
| `width` | `56px` | `56px` |
| `height` | `56px` | `56px` |
| `border-radius` | `50%` | `50%` |
| `z-index` | `9999` | `9999` |
| `safe-area-inset-bottom` | `env(safe-area-inset-bottom, 0px)` | no aplica |
| Touch target | `56px` > minimo 44px OK | idem |

**Safe area iPhone (CSS):**
```css
bottom: max(76px, calc(76px + env(safe-area-inset-bottom, 0px)));
```

### Variante B — Inline CTA (NUEVA, pagina de producto)

**Placement hook:** `woocommerce_single_product_summary` priority 25 (post-titulo, pre-precio)
o priority 35 (post-precio) — Cell D decide.

**B1 — Rich card:**
```css
.akb-wa-inline-cta {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px;
  background: rgba(37,211,102,0.08);
  border: 1px solid rgba(37,211,102,0.25);
  border-radius: 4px;          /* --radius-md */
  min-height: 44px;            /* --touch-target-min */
  max-width: 360px;
}
```

**B2 — Boton simple:** igual que akb-btn primary pero color WA green.

**Mensaje pre-cargado en URL:**
```php
$msg = urlencode("Hola, consulto por {$product->get_name()}");
$url = "https://wa.me/{$number}?text={$msg}";
```

### Variante C — Sticky footer bar (NUEVA, mobile only)

**Cuando usar:** alternativa al float button en mobile, NO coexistir con float simultaneamente.
Visible solo en `@media (max-width: 767px)`.

```css
.akb-wa-sticky-bar {
  position: fixed;   /* o sticky dentro del template */
  bottom: 0; left: 0; right: 0;
  background: var(--aki-surface);  /* #111111 */
  border-top: 1px solid var(--aki-border);
  padding: 12px 16px;
  padding-bottom: max(12px, env(safe-area-inset-bottom, 12px));
  display: flex; gap: 12px;
  z-index: 200;      /* --z-sticky */
}
.akb-wa-sticky-bar__main-btn { flex: 1; min-height: 44px; background: #25D366; }
.akb-wa-sticky-bar__add-btn  { flex-shrink: 0; min-height: 44px; background: var(--aki-red); }
```

### Tokens consumidos
| Token | Valor | Uso |
|---|---|---|
| `--wa-green` (custom) | `#25D366` | Background float + inline + sticky. NO en tokens.css — usar hex directo. |
| `--wa-green-hover` | `#1DA851` | Hover state |
| `--aki-red` | `#D90010` | Sticky bar "Agregar al carrito" CTA |
| `--aki-surface` | `#111111` | Sticky bar background |
| `--aki-border` | `#2A2A2E` | Sticky bar border-top |
| `--aki-focus-outline` | `3px solid #D90010` | Focus ring todos los elementos |
| `--touch-target-min` | `44px` | Minimo todos los botones |

### ARIA requerido
- Float: `aria-label="Contactar via WhatsApp"` (sin nombre del producto — es global)
- Inline: `aria-label="Consultar por [nombre producto] via WhatsApp"`
- Sticky: `<nav aria-label="Acciones rapidas del producto">`
- SVG icons: `aria-hidden="true"` en todos (el texto del anchor provee nombre accesible)
- `rel="noopener noreferrer"` en todos los `target="_blank"`
- Focus ring 3px en todos los elementos interactivos

### Recomendacion para Cell D
Per politica `feedback_minimize_behavior_change.md`: mantener Variante A sin cambios es la opcion default.
Si Cell D quiere agregar Variante B (inline en producto), es bajo riesgo — NO requiere quitar el float.
Variante C (sticky) REQUIERE quitar el float en mobile para evitar doble CTA — mayor riesgo de regresion.

**Riesgo de regresion por variante:**
| Variante | Riesgo | Requiere staging smoke |
|---|---|---|
| A (status quo) | Ninguno | No |
| B (inline add) | Bajo | Recomendado |
| C (sticky replace) | Medio | Obligatorio |

### Mockup
`audit/sprint-4/cell-h/mockups/13-whatsapp-button-variants.html`
