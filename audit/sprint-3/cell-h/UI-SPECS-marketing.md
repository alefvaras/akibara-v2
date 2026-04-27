# UI Specs — Marketing (Cell B dependencies)

**Sprint:** 3
**Cell H lead:** mesa-13-branding-observador
**Items covered:** 2, 4, 6, 7, 8, 9 (BLOQUEANTE), 10
**Status:** SPECS_READY — mockups lightweight pendientes Sprint 3.5
**Consumer:** Cell B (`wp-content/plugins/akibara-marketing/`)

---

## Item 2 — Cookie consent banner UI

**Compliance:** GDPR + Ley 19.628 Chile.

**Layout fixed bottom (mobile + desktop):**

```
┌──────────────────────────────────────────────┐
│ 🍪 Usamos cookies para mejorar tu experiencia │
│                                                │
│ [Aceptar] [Rechazar] [Personalizar]          │
│                                                │
│ Política de privacidad →                      │
└──────────────────────────────────────────────┘
```

**Estilos:**
- Wrapper: `position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);` z-index `--z-overlay`
- Bg: `--aki-surface` con 95% opacity + `backdrop-filter: blur(4px)` (glassmorphism)
- Border: 1px `--aki-border` con `border-radius: --radius-lg`
- Padding: `--space-6`
- Width: `min(600px, calc(100vw - 40px))` — responsive

**Botones:**
- "Aceptar" (primary): bg `--aki-red`, white text, hover `--aki-red-hover`
- "Rechazar" (secondary): transparent + border 1px `--aki-border-aa`, white text
- "Personalizar" (tertiary): transparent, text `--aki-red` underlined

**Touch target:** ≥44×44px en los 3 botones.

**Motion:** slide-in bottom 300ms `--transition-base`. Respect `prefers-reduced-motion`.

**Persistencia:** `localStorage.setItem('akibara_cookie_consent', JSON)` con expiración 1 año.

**A11y:**
- `role="region" aria-label="Aviso de cookies"`
- Focus ring 3px `--aki-red` en botones
- Escape key cierra (con foco vuelve al trigger)

**CSS classes:** `.aki-cookie-banner`, `.aki-cookie-banner--visible`, `.aki-cookie-banner__container`, `.aki-cookie-banner__buttons`, `.aki-cookie-banner__button--{primary,secondary,tertiary}`, `.aki-cookie-banner__link`

---

## Item 4 — Newsletter footer (double opt-in)

**Form en footer + opcional homepage hero:**

```
SUSCRÍBETE A NUESTRO NEWSLETTER
Recibe lo último en manga, ofertas exclusivas

[ Tu email           ] [SUSCRIBIRSE]
✓ 10% descuento en tu primera compra
```

**Estilos:**
- Input: bg `--aki-surface-2`, border 1px `--aki-border`, focus border 2px `--aki-red`, padding `--space-4`, h≥44px
- Botón: bg `--aki-red`, text white, hover `--aki-red-hover`, h≥44px, font `--weight-semibold`
- Heading: `--font-heading` uppercase, `--text-xl`
- Subheading: `--text-sm`, `--aki-text-muted`

**Layout:**
- Mobile: stacked vertical
- Desktop: inline (input + botón side-by-side, gap `--space-4`)

**Estados:**
- Success: campo se limpia, mensaje "¡Gracias! Te enviaremos un email de confirmación" con ícono ✓
- Error inválido: border `--aki-error`, mensaje "Por favor ingresa un email válido" `role="alert"`
- Error duplicado: "Este email ya está suscrito"

**Brevo wiring (Cell B):**
- POST `/wp-json/akibara/v1/newsletter-subscribe` con `{ email, list_id: 4 }` (lista 4 = Newsletter general)
- Doble opt-in: Brevo envía confirmation email transactional, customer click → webhook activa subscription
- Welcome flow se dispara DESPUÉS de confirm (item 6)

**A11y:** label visible o `.visually-hidden`, `type="email"` (validación nativa), focus ring 3px `--aki-red`

**Copy (mesa-06):**
- Heading: "Suscríbete a nuestro newsletter"
- Incentivo: "Recibe 10% descuento en tu primera compra" (verifica match con código WELCOME10 de Cell B `welcome-discount` module)

---

## Item 6 — Welcome "Bienvenida" transactional notice

**Disparador:** post-confirmation email subscription (double opt-in completado).

**Locations:**
- Customer account dashboard (top notice)
- Thank-you page post-checkout (si first order)

**Card layout:**

```
┌────────────────────────────────────┐
│ 🎁 ¡Bienvenido a Akibara!         │
│                                     │
│ Tu suscripción está activa.        │
│ Usa código WELCOME10 para         │
│ 10% descuento en tu primera       │
│ compra.                             │
│                                     │
│ [Copiar código] [Descartar]        │
└────────────────────────────────────┘
```

**Estilos:**
- Wrapper: bg `--aki-surface-2`, border-left 4px `--aki-red`, padding `--space-6`, `--radius-md`
- Heading: `--font-heading` uppercase `--text-xl`
- Botón "Copiar código": bg `--aki-red`, action `navigator.clipboard.writeText('WELCOME10')` + toast "Código copiado"
- Botón "Descartar": transparent + border `--aki-border-aa`, action `localStorage.setItem('akibara_welcome_notice_dismissed', '1')`

**Visibilidad:**
- Mostrar si: usuario logged-in + customer.welcome_notice_shown=false
- Persistir dismiss en localStorage
- 1 vez por sesión

**Copy (mesa-06):**
- Greeting: "¡Bienvenido a Akibara!" ✓
- Body: "Tu suscripción está activa. Usa código WELCOME10 para 10% descuento en tu primera compra."
- NO "Tu suscripción quedó activa" (voseo subtle)

**A11y:** `role="region" aria-label="Bienvenida"`, focus ring 3px en botones, touch target ≥44×44px

---

## Item 7 — Popup styling refinements

**Modal base reusable** para 3 variantes: newsletter signup, discount promo, review request.

**Estructura:**

```
[backdrop overlay rgba(0,0,0,0.6)]
  ┌─────────────────────────┐
  │ HEADER                ✕ │  ← close 24px icon, 44×44 touch
  ├─────────────────────────┤
  │ Body content            │
  ├─────────────────────────┤
  │ [Primary] [Secondary]   │  ← botones footer
  └─────────────────────────┘
```

**Estilos:**
- Backdrop: bg `rgba(0,0,0,0.6)` z-index `--z-overlay`, optional `backdrop-filter: blur(2px)` (perf check)
- Modal: bg `--aki-surface`, border 1px `--aki-border`, `--radius-lg`, `--shadow-lg`, max-w `min(500px, 90vw)`, max-h `80vh`
- z-index: `--z-modal`
- Padding: `--space-8` body, `--space-6` header/footer
- Close button: top-right, hover color `--aki-white`, focus ring `--aki-red`

**A11y (CRITICAL):**
- `role="dialog" aria-modal="true" aria-labelledby="modal-heading"`
- Focus trap (Tab cicla dentro)
- Escape cierra + foco regresa al trigger
- Backdrop click opcional (depende variant)

**Motion:** entrance fade-in 300ms + scale (0.95→1.0). Exit 200ms reverse. Respect `prefers-reduced-motion`.

**CSS classes:** `.aki-modal`, `.aki-modal--open`, `.aki-modal__backdrop`, `.aki-modal__container`, `.aki-modal__close`, `.aki-modal__header`, `.aki-modal__body`, `.aki-modal__footer`

---

## Item 8 — Cart-abandoned email template (CONDICIONAL)

**Status:** PENDING decisión Cell B sobre Brevo upstream firing 24-48h.

**Si Brevo upstream cubre** (verificar via Gmail MCP `from:brevo abandoned cart`):
→ NO migrar el módulo legacy 539 LOC. Documentar deprecación en `audit/sprint-3/cell-b/DECISION-CART-ABANDONED.md`.

**Si Brevo upstream NO firing:**
→ Cell H provee template HTML email. Spec resumida:

- Header: logo akibara + accent bar `--aki-red`
- Hero: "¿Olvidaste tu carrito?" + CTA "RECUPERAR CARRITO"
- Cart summary: tabla con thumb + name + qty + price (alt fallback text)
- Incentivo opcional: "Completa antes de [date] y recibe 10% descuento" o código RECOVERY10
- Trust section: 3 íconos (envío, payment, support)
- Footer: links + unsubscribe (legal)
- Email-safe CSS only (no flexbox, table layouts)

**Mockup:** `mockups/08-cart-abandoned-email.html` + render Photopea desktop/mobile.

---

## Item 9 — Finance dashboard 5 widgets (BLOQUEANTE)

**CRITICAL:** Cell B no puede implementar UI dashboard sin esta spec.

**Container grid (admin dashboard):**
- Desktop (≥1024px): `grid-template-columns: repeat(4, 1fr); gap: var(--space-6);`
- Tablet (768–1023px): `repeat(2, 1fr)`
- Mobile (<768px): `1fr`

**Widget base:**
- bg `--aki-surface`, border 1px `--aki-border`, `--radius-lg`, padding `--space-6`, `--shadow-md`
- Title: `--font-heading` uppercase `--text-lg`, color white
- Body: data viz (bar/list/table per widget type)

### Widget 1 — Top Series por Volumen Vendido
**Layout:** ranked bar chart top 5 series.
**Data:** `wp_akibara_index._akibara_serie_norm` JOIN `wc_order_items` GROUP BY series.
**Bars:** width proportional to volume count, color `--aki-red` para #1, gradient hacia `--aki-gray-400` para #5.
**Footer:** "[Ver más]" + "[Exportar CSV]" links.

### Widget 2 — Top Editoriales
**Layout:** stacked horizontal bar chart o pie con leyenda.
**Data:** Brevo subscribers count per editorial list (las 8 listas del Cell B).
**Colores:** consume `--aki-editorial-{ivrea,panini,planeta,milky,ovni,arechi}` (formalizados en design-tokens.md).
**Footer:** timestamp "Actualizado: hace 2h"

### Widget 3 — Encargos Pendientes
**Layout:** lista simple, max 5 items, scroll si más.
**Data:** `get_option('akibara_encargos_log')` filter por status pending/confirmed.
**Actualmente:** 2 activos en prod (Jujutsu Kaisen 24, Jujutsu Kaisen 26).
**CTA:** "[Administrar encargos]" → admin URL.

### Widget 4 — Trending Searches
**Layout:** lista top 5 con sparkline trend (▲/─/▼).
**Data:** `get_option('akibara_trending_searches')` last 7 days.
**Actualmente:** One Piece 196k, Jujutsu Kaisen 34, Berserk 9.
**Indicators:**
- ▲ trending up: `--aki-success` (#00C853)
- ─ neutral: `--aki-gray-400` (#8A8A8A)
- ▼ trending down: `--aki-error` (#FF3B3B)

### Widget 5 — Stock Crítico
**Layout:** lista alerta con count + producto.
**Data:** `wp_postmeta._stock <= 3` AND `_manage_stock = 'yes'`.
**Treatment:**
- Stock 0: color `--aki-error`, badge "AGOTADO"
- Stock 1–2: color `--aki-warning`, badge "BAJO"
- Stock 3: color `--aki-info`, badge "ATENCIÓN"
**CTA:** "[Crear preventa]" link (auto-rellena product_id).

**A11y para widgets:**
- `<table>` semántico para tabular data
- Color NO único differenciador — siempre ícono/símbolo + texto
- Heading hierarchy `<h2>` widget title
- Numbers en `<span class="number">` para screen reader clarity

**CSS classes:** `.aki-dashboard-widget`, `.aki-dashboard-widget__title`, `.aki-dashboard-widget__bar`, `.aki-dashboard-widget__metric--{up,down,neutral}`, `.aki-dashboard-widget__alert--{critical,warning,attention}`

---

## Item 10 — Trust badges treatment

**Current state (audit O-13-102):** 4 badges existen con SVG genérico Feather/Lucide. Refinar a brand-aligned.

**4 badges:**

| # | Label | Icon (emoji MVP) | Color tokens |
|---|---|---|---|
| 1 | Envío asegurado a todo Chile | 🚚 | `--aki-info` (#3B82F6) bg tint |
| 2 | Compra en 3 cuotas sin interés | 💳 | `--aki-success` (#00C853) bg tint |
| 3 | Manga 100% Original | ✓ | `--aki-red` (#D90010) bg tint |
| 4 | Soporte por WhatsApp | 💬 | `--aki-success` (#00C853) bg tint |

**Decision MVP:** emoji native (no asset dependency, accessible). SVG custom reservado Phase 2 designer freelance.

**Layout variants:**

| Location | Layout | Icon size |
|---|---|---|
| Homepage hero | 4-col grid (desktop) / 2-col (mobile) | 48–56px |
| Product page sidebar | vertical stack 1-col | 40px |
| Checkout payment | inline horizontal | 32px |

**Estilos:**
- Wrapper: bg `rgba(<color>, 0.1)` (tint), border-radius `--radius-md`, padding `--space-4`
- Label: `--font-heading` uppercase `--text-sm`, color `--aki-white`
- Description: `--text-xs`, `--aki-text-muted`

**A11y:** `aria-label` en wrapper si emoji solo, color + texto siempre paired.

**CSS classes:** `.aki-trust-badge`, `.aki-trust-badge--{shipping,payment,quality,support}`, `.aki-trust-badge--compact`, `.aki-trust-badge__icon`, `.aki-trust-badge__label`

---

## Tokens consumidos

```
--aki-red, --aki-red-hover, --aki-warning, --aki-success, --aki-error, --aki-info
--aki-surface, --aki-surface-2, --aki-border, --aki-border-aa, --aki-border-light
--aki-white, --aki-black, --aki-gray-{300,400}, --aki-text-muted
--aki-editorial-{ivrea,panini,planeta,milky,ovni,arechi}
--space-{1..16}
--text-{xs,sm,base,lg,xl,2xl}
--radius-{sm,md,lg}
--shadow-{sm,md,lg}, --shadow-glow-red
--transition-{fast,base,slow}
--z-{overlay,modal,toast}
--aki-focus-outline
```

---

## Mockups (lightweight tools, Sprint 3.5)

| Item | Tool | Path |
|---|---|---|
| 2 cookie banner | Excalidraw | `mockups/02-cookie-banner.png` |
| 4 newsletter footer | Excalidraw | `mockups/04-newsletter-footer.png` |
| 6 welcome notice | Photopea | `mockups/06-welcome-notice.png` |
| 7 popup modal | CSS prototype | `mockups/07-popup-modal.html` |
| 8 cart-abandoned email | HTML+Photopea | `mockups/08-cart-abandoned-email.html` (conditional) |
| **9 finance widgets** | Excalidraw + HTML prototype | `mockups/09-finance-widgets/` (BLOQUEANTE) |
| 10 trust badges | Photopea | `mockups/10-trust-badges.png` |

---

## Coordinación Cell B

Cell B puede implementar **lógica backend** sin bloqueo (Brevo wiring, finance data fetch, etc.). UI requiere mockup approval para item 9 (BLOQUEANTE).

Si Cell B llega a UI antes del mockup item 9 → stub temporal (Bootstrap card) + documenta en `audit/sprint-3/cell-b/STUBS.md`. Sprint 3.5 consolida.
