# UI Specs — Preventas (Cell A dependencies)

**Sprint:** 3
**Cell H lead:** mesa-13-branding-observador
**Items covered:** 1 (encargos form), 3 (preventa card 4 estados), 5 (auto-OOS preventa)
**Mapped requests:** A-01 (form styling), A-02 (card 4 estados), A-03 (fecha por confirmar)
**Status:** SPECS_READY — mockups lightweight pendientes Sprint 3.5
**Consumer:** Cell A (`wp-content/plugins/akibara-preventas/`)

---

## Item 1 / Request A-01 — Encargos form styling

**Shortcode:** `[akb_encargos_form]` (Cell A renderizado del form)

**Selectores HTML existentes (de Cell A):**
- `.akb-encargos-form` — wrapper
- `.akb-field` — input/textarea
- `.akb-btn` — submit button
- `.akb-encargos-form__feedback` — mensaje success/error

**Estados a estilar:**

| Estado | Treatment |
|---|---|
| `.akb-field` default | bg `--aki-surface-2`, border 1px `--aki-border` |
| `.akb-field:focus-visible` | border 2px `--aki-red`, outline 3px `--aki-focus-outline` offset 2px |
| `.akb-field--error` | border 2px `--aki-error`, mensaje rojo abajo |
| `.akb-field--success` | border 2px `--aki-success` |
| `.akb-btn` (primary) | bg `--aki-red`, hover `--aki-red-hover`, min-h 44px |
| `.akb-encargos-form__feedback--success` | bg `rgba(0,200,83,0.1)` + border-left 3px `--aki-success` |
| `.akb-encargos-form__feedback--error` | bg `rgba(255,59,59,0.1)` + border-left 3px `--aki-error` |

**Layout:**
- Mobile (375–767px): 1 columna, gap `--space-4`
- Desktop (≥768px): 2 columnas (`grid-template-columns: 1fr 1fr` con `gap: var(--space-6)`), submit full-width abajo

**Accesibilidad:**
- `<label for="...">` visible o `.visually-hidden`
- Touch target ≥44×44px en submit + checkbox
- Focus ring 3px `--aki-red` con offset 2px
- Mensaje feedback `role="status" aria-live="polite"`

**Copy (mesa-06 valida):** chileno tuteo neutro. "Tu mensaje fue enviado" ✓ — NO "Su" / "Vos enviaste" ✗

---

## Item 3 / Request A-02 — Preventa card 4 estados

**Selectores existentes (Cell A `.akb-nv-widget` extendido):**
- `.akb-preventa-card` — wrapper
- `.akb-preventa-card__image` — thumb 4:5 ratio
- `.akb-preventa-card__badge` — estado pill
- `.akb-preventa-card__meta` — title + volumen
- `.akb-preventa-card__date` — fecha esperada
- `.akb-preventa-card__cta` — button

**Estados (corresponden a `wp_akb_preorders.state`):**

| state | badge color | icono | label | date format | CTA |
|---|---|---|---|---|---|
| `pending` | `--aki-warning` (#F59E0B) | ⏳ | "Preventa pendiente" | "Entrega próximamente" | "Confirmar datos" |
| `confirmed` | `--aki-success` (#00C853) | ✓ | "Preventa confirmada" | "Entrega aprox. DD/MM/YYYY" | "Rastrear" |
| `shipping` | `--aki-info` (#3B82F6) | 📦 | "En camino" | "Entrega DD/MM/YYYY" | "Rastrear" |
| `delivered` | `--aki-gray-400` (#8A8A8A) | ✓ | "Entregado" | "Recibido DD/MM/YYYY" | "Reordenar" |

**CSS classes adicionales:** `.akb-preventa-card__badge--{pending,confirmed,shipping,delivered}` para variantes de color.

**Reglas accesibilidad:**
- Color NO es único diferenciador — siempre badge color + ícono + texto
- Contraste badge text vs bg ≥4.5:1: dark text (#0A0A0A) sobre warning/info, white text sobre success/gray
- Touch target en CTA ≥44×44px

**Layout responsive:**
- 375px: 1 columna, card 100% width
- 768px: 2 columnas grid (`grid-template-columns: repeat(2, 1fr)`)
- 1024px+: 4 columnas (en dashboard widget) o 3 columnas (en /mi-cuenta)

**Data binding (referencia para Cell A):**
```php
$state = get_post_meta($order_id, '_akibara_preventa_state', true);
$expected = get_post_meta($order_id, '_akibara_expected_date', true);
$product = wc_get_product(reset($order->get_item_ids()));
```

---

## Item 5 / Request A-03 — Auto-OOS preventa "fecha por confirmar"

**Caso de uso:** producto stock=0 + flag `_allow_preventa=true` → reemplaza badge "Agotado" del theme con tratamiento preventa-pendiente.

**Layout (single-product page, sección stock):**

```
┌──────────────────────────────┐
│ ⏳ PREVENTA                   │  ← badge --aki-warning
│ Fecha por confirmar           │  ← text --aki-gray-300
│                                │
│ Actualmente sin stock.        │  ← text --aki-text-muted
│ Puedes reservar ahora.        │
│                                │
│ [RESERVAR AHORA]              │  ← btn --aki-red, h≥44px
└──────────────────────────────┘
```

**CSS:**
- `.akb-stock-status--oos-preventa` wrapper
- `.akb-stock-status__badge--preventa` (yellow with dark text — contraste 9.52:1 AAA)
- Botón "Reservar ahora" hace scroll a `#akb-encargos-form` o abre modal item 7

**Copy (mesa-06):**
- "Fecha por confirmar" ✓
- "Puedes reservar ahora." ✓ (tuteo neutro chileno)
- NO "Reserve su copia" (formal) — NO "Reservá ahora" (voseo)
- NO incluir fecha hardcodeada — solo si `expected_date` existe en DB

**Trigger logic (Cell A):**
- `wc_get_product()->get_stock_quantity() <= 0`
- AND product meta `_allow_preventa = 'yes'`
- → Cell A renderea esta sección en lugar del badge "Agotado" estándar de WC

---

## Tokens consumidos (de tokens.css)

```
--aki-red, --aki-red-hover, --aki-red-bright
--aki-warning, --aki-success, --aki-error, --aki-info
--aki-gray-300, --aki-gray-400, --aki-text-muted, --aki-border-aa
--aki-surface, --aki-surface-2, --aki-border, --aki-white, --aki-black
--space-{2,3,4,6,8} (padding/gap)
--text-{xs,sm,base,lg,xl} (typography)
--radius-{sm,md,lg}
--transition-{fast,base}
--aki-focus-outline (3px solid --aki-red)
```

---

## Mockups (lightweight tools, Sprint 3.5)

| Item | Tool | Path |
|---|---|---|
| Form layout (focus + error states) | Mac Preview annotate / CSS prototype | `mockups/01-encargos-form.png` |
| Preventa card 4 estados | Excalidraw + Photopea | `mockups/03-preventa-card.png` |
| OOS preventa stock section | Mac Preview annotate | `mockups/05-oos-preventa.png` |

**Per memoria `project_figma_mockup_before_visual.md`:** Figma reservado Phase 2.

---

## Coordinación Cell A

Cell A puede continuar implementación SIN bloqueo:
- Stubs documentados en `audit/sprint-3/cell-a/STUBS.md`
- Mockups visuales son polish Sprint 3.5
- Cell H consolida theme deltas (CSS) en `wp-content/themes/akibara/assets/css/components.css` post-mockup

Si Cell A escribe CSS provisional en su plugin → Sprint 3.5 lo migra a tokens.
