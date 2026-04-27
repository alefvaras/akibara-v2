---
agent: design-system-auditor
round: 1
date: 2026-04-26
scope: Color contrast, focus rings, spacing tokens, motion tokens, design tokens cohesion en theme akibara + plugin akibara modules con UI customer-facing
files_examined: 28
findings_count: { P0: 0, P1: 6, P2: 9, P3: 5 }
---

## Resumen ejecutivo

1. **El theme tiene un design-system maduro** (`assets/css/design-system.css` — 1.992 LOC, tokens semánticos --aki-*, focus-visible global, prefers-reduced-motion universal de 3 capas, comments WCAG explícitos en cada token con ratios calculados). Refactor masivo NO se justifica.
2. **5 tokens fantasma usados solo via fallback** (`--aki-text`, `--aki-text-muted`, `--aki-text-dim`, `--aki-surface-1`, `--aki-primary-hover`) — 14+ uso sites en checkout/woocommerce.css resuelven siempre al string del fallback. P1.
3. **Focus replacement con box-shadow alpha 0.15 da contraste 1.07:1** (FAIL WCAG 2.4.13) — patrón usado en checkout/select2/popup forms. Forms accesibles solo via cambio de border. P1.
4. **3 popups customer-facing NO usan tokens**: hex literales `#888 #aaa #555 #ccc #1a1a1a`. 5 instancias FAIL contraste AA (`#555 on #1a1a1a = 2.33:1`). P1.
5. **Email metro-pickup-notice (HTML email + thank-you)** tiene `#888 on #f8f8fa = 3.34:1` FAIL AA texto pequeño. Email branding inconsistente con dark theme. P2.

## Findings P1

### F-08-001: Tokens fantasma usados solo via fallback string
- **Severidad:** P1
- **Categoría:** FRAGILE
- **Archivo(s):** `themes/akibara/assets/css/woocommerce.css:3058,4744,4828` + `checkout.css:254,384,449,477,554,1738,1788,1812,3113` + `inc/metro-pickup-notice.php:206,231,244`
- **Descripción:** Tokens `--aki-text`, `--aki-text-muted`, `--aki-text-dim`, `--aki-surface-1`, `--aki-primary-hover` NO existen en :root. 14+ use sites resuelven al fallback hardcoded. Algunos sitios usan #888 como `--aki-text-muted`, otros #A0A0A0, otros #9a9a9a. Sin token canónico, audit futuro requiere grep manual. `--aki-primary-hover, #d94a30` es ORANGE (cinnabar) — branding "Manga Crimson v3" se rompe si se renderiza ese fallback.
- **Propuesta:** Definir explícitamente en :root del design-system.css:
  ```css
  --aki-text: var(--aki-gray-100);
  --aki-text-muted: var(--aki-gray-400);
  --aki-text-dim: var(--aki-gray-500);
  --aki-surface-1: var(--aki-surface);
  --aki-primary-hover: var(--aki-red-hover);
  ```
- **Esfuerzo:** S (45 min)
- **Sprint:** S2

### F-08-002: box-shadow alpha 0.15 como focus replacement = 1.07:1 FAIL WCAG 2.4.13
- **Severidad:** P1
- **Categoría:** UX (a11y)
- **Archivo(s):** `checkout.css:294-297`, `woocommerce.css:1695-1702`, `account.css:181-185`, `pages-custom.css:6,51`
- **Descripción:** Patrón `outline: none + border-color + box-shadow rgba(217,0,16,0.15)`. Alpha 0.15 sobre surface #161618 = #331216, contraste 1.07:1 vs surface — INVISIBLE como focus indicator. Forms checkout, login, encargos, rastreo afectados.
- **Propuesta:** Cambiar a outline solid alineado con design-system:
  ```css
  outline: 2px solid var(--aki-red-bright);
  outline-offset: 2px;
  border-color: var(--aki-red-bright); /* refuerzo */
  ```
- **Esfuerzo:** S (1h fix 6 sites)
- **Sprint:** S1

### F-08-003: 3 popups customer-facing con tokens hardcoded + 5 instancias FAIL AA
- **Severidad:** P1
- **Categoría:** UX (a11y) + REFACTOR
- **Archivo(s):** `plugins/akibara/modules/popup/popup.css:99-183` + `welcome-discount/popup.css:54-178`
- **Descripción:** Puntos PRIMARIOS captura leads. Hex literales `#888 #aaa #555 #1a1a1a`. 5 contraste FAIL AA:
  - `.aki-popup__legal #555 on #1a1a1a = 2.33:1` FAIL
  - `.aki-popup__no-thanks #555 on #1a1a1a = 2.33:1` FAIL
  - `.aki-popup__input::placeholder #555 on #111 = 2.53:1` FAIL
  - `.akb-wd-popup__legal #555 on #1a1a1a = 2.33:1` FAIL
  - `.akb-wd-popup__no-thanks #555 on #1a1a1a = 2.33:1` FAIL
  Adicional: `.akb-wd-popup__close` sin `:focus-visible` declarado.
- **Propuesta:** Reemplazar hex por tokens existentes:
  - `#888 → var(--aki-gray-400)` (5.47:1 PASS)
  - `#555 → var(--aki-gray-400)` (PASS) o `--aki-gray-500` solo decorativo
  - `#1a1a1a → var(--aki-surface-2)`, `#111 → var(--aki-surface)`
  - Agregar :focus-visible a buttons close
- **Esfuerzo:** S (1h por popup × 2 + smoke test)
- **Sprint:** S2

### F-08-004: --aki-border #2A2A2E falla WCAG 1.4.11 UI components (1.32:1)
- **Severidad:** P2
- **Archivo(s):** `design-system.css:25` + 100+ use sites form inputs/cards/dropdowns
- **Descripción:** Border #2A2A2E sobre --aki-surface #111 = 1.32:1. FAIL WCAG 1.4.11 Non-text Contrast (3:1 mínimo UI components). Token correcto existe: `--aki-border-aa #6A6A6A` (3.41:1 PASS) — solo se usa en pills/checkboxes/drawer CTAs. Form inputs usan invisible border.
- **Propuesta:** Migrar a --aki-border-aa solo UI components que delimitan ZONA INTERACTIVA (inputs, dropdowns, search bar, buttons outline). NO migrar borders decorativos.
- **Esfuerzo:** M (2h identificar + cambiar + visual review)
- **Sprint:** S2
- **Requiere mockup:** SÍ

### F-08-005: Email metro-pickup-notice rompe brand (light bg + #888 FAIL contraste)
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/metro-pickup-notice.php:145-158, 188-273`
- **Descripción:** Email HTML inline tiene bg `#f8f8fa` (light) mientras otros emails (magic-link, brevo) son DARK con `#0D0D0F`. Inconsistencia branding. `#888 on #f8f8fa = 3.34:1` FAIL AA texto 13px. WhatsApp CTA verifica icono SVG no es blanco sobre verde.
- **Propuesta:** (1) Email a dark bg consistente con otros emails Akibara, ajustar text colors a paleta dark; o (2) mantener light pero subir #888 → #666 (5.41:1)
- **Esfuerzo:** S (1h email + 30 min thank-you tokenization)
- **Sprint:** S3
- **Requiere mockup:** SÍ

### F-08-006: 28 declaraciones outline:none necesitan auditoría sistemática
- **Severidad:** P2
- **Descripción:** Tema remueve outline en 28 lugares. Mayoría en :focus-visible (correcto). Al menos 2 en :focus no-visible: `woocommerce.css:1695-1702` `.woocommerce input:focus`, `:24` `.woocommerce-ordering select:focus`. account.css:172 y pages-custom.css:6,51 forms con outline:none directo + focus-visible solo cambia border.
- **Propuesta:** Audit manual cada outline:none. Verificar: `:focus-visible` o `:focus`? reemplazo cumple WCAG 2.4.13? perceptible sin trigger? Crear `audit/round1/_focus-removal-audit.md` con 28 items.
- **Esfuerzo:** M (2-3h)
- **Sprint:** S3

### F-08-007: Touch targets sub-44px (WCAG 2.5.5 AAA)
- **Severidad:** P3
- **Descripción:** WCAG 2.5.8 AA requiere 24x24 con espacio. Elementos cumplen AA. WCAG 2.5.5 AAA recomienda 44x44 — algunos elementos menores (`.product-card__wishlist 32x32`, `.cart-complete-serie__add 28x28`, `.akb-trust-item__icon 32x32 no clickable OK`).
- **Propuesta:** Agregar padding invisible para touch area. Solo si usuario reporta problema. YAGNI 3 clientes.
- **Sprint:** S4+

### F-08-008: hero-section.css duplicado (theme/root vs assets/css/) — DEAD CODE
- **Severidad:** P3
- **Archivo(s):** `themes/akibara/hero-section.css` (306 líneas, NO enqueued, OBSOLETO) + `themes/akibara/assets/css/hero-section.css` (243 líneas, ENQUEUED)
- **Descripción:** Diff confirma diferencias significativas. Root no se carga via inc/enqueue.php.
- **Propuesta:** Borrar `theme/akibara/hero-section.css` (root). Cross-cutting flag mesa-02
- **Esfuerzo:** S (1 min)
- **Sprint:** S1

### F-08-009: homepage-h1__separator opacity 0.5 = 1.97:1 FAIL
- **Severidad:** P3
- **Archivo(s):** `homepage.css:39-43`
- **Descripción:** Separador "·" entre brand y tagline con `color: var(--aki-red-bright); opacity: 0.5` = #871010 sobre #111 = 1.97:1 FAIL. Decorativo.
- **Propuesta:** Quitar opacity y color directo `#7a3030`, O agregar `aria-hidden="true"` al span (más correcto semantically + permite mantener opacity)
- **Sprint:** S3

### F-08-010: hero-hit:focus-visible outline rgba(217,0,16,0.8) = 2.81:1 FAIL UI 3:1
- **Severidad:** P3
- **Archivo(s):** `hero-section.css:220-224`
- **Descripción:** Hotspots invisibles del hero (MANGA, COMICS, EXPLORAR). Focus-visible alpha 0.80 sobre bg dark = 2.81:1 FAIL WCAG 2.4.13.
- **Propuesta:** Outline solid `var(--aki-yellow)` (#FFD700 sobre #0D0D0D = 13.42:1 PASS) o `var(--aki-red-bright)` (5.07:1 PASS)
- **Sprint:** S2

### F-08-011: account.css strength meter colors hardcoded Tailwind
- **Severidad:** P3
- **Archivo(s):** `account.css:259-268`
- **Descripción:** Levels 2/3/5 hex literales orange/yellow/green Tailwind. Levels 1/4 SÍ usan tokens. Inconsistencia. Todos pasan AA.
- **Propuesta:** Definir tokens semánticos `--aki-strength-low/mid/high` o simplificar a 3 niveles
- **Sprint:** S4+

### F-08-012: Plugin akibara sin :root tokens propios — depende del theme
- **Severidad:** P2
- **Archivo(s):** `plugins/akibara/modules/popup/popup.css`, `welcome-discount/popup.css`, `checkout-validation/checkout-validation.css`, `phone/phone.css`, `shipping/tracking-unified.css`
- **Descripción:** 5 módulos CSS customer-facing. Ninguno define :root con tokens propios — heredan del theme. Si plugin se desactiva o theme cambia, módulos pasan a fallbacks (inconsistentes).
- **Propuesta:** Solo si quieres plugin truly portable. YAGNI 3 clientes + 1 theme. NO refactor proactivo.
- **Sprint:** S4+

### F-08-013: branding-v1.css typo --aki-surface-1 (token inexistente)
- **Severidad:** P3
- **Archivo(s):** `branding-v1.css:15`
- **Descripción:** Comment dice "fix typo: era --aki-surface-1 (token inexistente)". Fix aplicado al token usage pero `var(--aki-surface-1, ...)` aún se usa en woocommerce.css:4744. Inconsistencia cleanup.
- **Propuesta:** Cambiar a `var(--aki-surface)` o crear `--aki-surface-1` formal
- **Sprint:** S2

### F-08-014: Skip-link top:-100% problema iOS Safari
- **Severidad:** P3
- **Archivo(s):** `design-system.css:967-987`
- **Descripción:** Skip link bien implementado pero patrón `top:-100%` errático en iOS Safari. Theme tiene patrón canónico `.sr-only` con `clip:rect(0,0,0,0)` (L889-899) — no usado en skip-link.
- **Propuesta:** Refactor skip-link al patrón sr-only canónico
- **Sprint:** S4+

### F-08-015: 165 animaciones distribuidas pero prefers-reduced-motion universal ya existe (PASS)
- **Severidad:** P3 (positivo)
- **Descripción:** Theme tiene EXCELENTE cobertura prefers-reduced-motion. 165 animations + 28 @media específicos + override universal en design-system.css L1981-1992. WCAG 2.3.3 COMPLIANT.
- **Propuesta:** NINGUNA. Mantener.

### F-08-016: 5 hex colors var() fallbacks divergen del valor real del token
- **Severidad:** P2
- **Descripción:** `var(--aki-red-bright, #D90010)` dice "si token no carga, usa #D90010". Pero valor REAL del token es #FF2020. Color shift visible si token desaparece.
- **Propuesta:** Convención: fallback DEBE coincidir con valor real. Lint rule o auditoría manual
- **Sprint:** S3

### F-08-017: Editoriales-panel grouped border-bottom #333 = 1.49:1 FAIL UI border
- **Severidad:** P3
- **Archivo(s):** `header-v2.css:829`
- **Propuesta:** Cambiar a `var(--aki-border-aa)` (#6A6A6A → 3.41:1 PASS)
- **Sprint:** S3

### F-08-018: Footer newsletter input::placeholder rgba(255,255,255,0.3) = 2.43:1 FAIL
- **Severidad:** P2
- **Archivo(s):** `popup/popup.css:330`
- **Descripción:** Footer newsletter (key conversion). Placeholder alpha 0.3 sobre bg ≈ #161616 = 2.43:1 FAIL AA.
- **Propuesta:** Subir alpha a 0.55 (~5:1)
- **Sprint:** S2

### F-08-019: account.css aki-auth__input::placeholder rgba(255,255,255,.22) = 1.83:1 FAIL
- **Severidad:** P2
- **Archivo(s):** `account.css:177-179`
- **Descripción:** Account login/signup placeholder invisible
- **Propuesta:** Subir alpha a 0.55 mínimo
- **Sprint:** S2

### F-08-020: Spacing tokens consistentes — no findings
- **Severidad:** N/A (positivo)
- **Descripción:** Escala 4/8/12/16/20/24/32/40/48 (--space-1 a --space-32). 99% via var(). 4-5 instancias hex literales en plugin popup CSS aceptables.

## Cross-cutting flags

- **CF-08-A (mesa-09 email-qa):** 2 estilos email coexisten — magic-link.php dark + metro-pickup-notice.php light. Brand consistency cross-channel.
- **CF-08-B (mesa-22 wp-master):** `checkout-accordion.php:706` inline style hex literal #ccc rompe pattern.
- **CF-08-C (mesa-02 tech-debt):** `coverage/` y `vendor/` adentro de `plugins/akibara/` (~76 MB). Ya documentado por mesa-22 + mesa-15 + mesa-10.
- **CF-08-D (mesa-02 tech-debt):** `pages-custom.css` 106 LOC solo 2 archivos lo usan. Considerar consolidar.

## Áreas que NO cubrí

- JavaScript focus management dynamic (handoff R2)
- ARIA semantic correctness (roles, landmarks, headings hierarchy)
- Real browser test con Chrome MCP rendered (handoff)
- Forms validation visual states con bg variantes
- Print styles (@media print)
- Theme inc/admin.php login bg (admin-only baja prioridad)
- Color blindness simulation (deuteranopia/protanopia) — WCAG 2.4 AAA
- Mu-plugins customer-facing CSS (0 archivos PHP con CSS inline)
- Dark mode toggle (theme 100% dark, no toggle)
- Touch target spacing WCAG 2.5.8 entre targets adyacentes
