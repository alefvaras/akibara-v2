# Design Tokens — Manga Crimson v3 (formalizado Sprint 3)

**Cell H:** mesa-13-branding-observador (lead) + mesa-08-design-tokens (validación)
**Source of truth:** `wp-content/themes/akibara/assets/css/tokens.css`
**Status:** SPECS_READY — tokens.css escrito, pendiente enqueue en theme

Este archivo cataloga los tokens canónicos. Cells A+B y theme deltas DEBEN consumir tokens (no hardcodear hex).

---

## 1. Brand colors

| Token | Hex | Uso | Contraste vs |
|---|---|---|---|
| `--aki-red` | #D90010 | CTA primary, focus ring, badges quality | white 3.56:1 (AA borderline) |
| `--aki-red-hover` | #BB000D | :hover state primary | white 6.73:1 (AA) |
| `--aki-red-bright` | #FF2020 | Foreground text on dark surfaces only | #111 4.93:1 (AA) |
| `--aki-red-dark` | #8B0000 | Topbar, depth | white 5.96:1 (AA) |
| `--aki-yellow` | #FFD700 | Gold accent (seasonal/promo) | #111 14.6:1 (AAA) |

## 2. Semantic status

| Token | Hex | Uso |
|---|---|---|
| `--aki-success` | #00C853 | Confirmed, shipped, positive |
| `--aki-warning` | #F59E0B | Pending, attention, preventa |
| `--aki-error` | #FF3B3B | Errors, danger, validation fail |
| `--aki-info` | #3B82F6 | Info, shipping, links |

## 3. Neutral / surface (dark theme baseline)

| Token | Hex | Uso |
|---|---|---|
| `--aki-black` | #0A0A0A | Page background |
| `--aki-surface` | #111111 | Primary surface (cards, modals) |
| `--aki-surface-2` | #1A1A1A | Hover state surface |
| `--aki-surface-3` | #222222 | Subtle differentiation |
| `--aki-border` | #2A2A2E | Subtle borders |
| `--aki-border-light` | #333333 | Lighter border |
| `--aki-border-aa` | #6A6A6A | WCAG AA compliant border (3:1 vs #141414) |
| `--aki-white` | #FFFFFF | Primary text on dark |
| `--aki-gray-200` | #E0E0E0 | Light text on dark (11.69:1 AAA) |
| `--aki-gray-300` | #B0B0B0 | Medium-light gray |
| `--aki-gray-400` | #8A8A8A | Medium gray (5.47:1 vs #111, AA) |
| `--aki-text-muted` | alias gray-400 | Captions, secondary text |

## 4. Editorial publisher palette (formalizada)

Per audit O-13-104. Usados en finance dashboard widget 2 (Top Editoriales).

| Token | Hex | Editorial |
|---|---|---|
| `--aki-editorial-ivrea` | #60A5FA | Ivrea |
| `--aki-editorial-panini` | #F87171 | Panini |
| `--aki-editorial-planeta` | #4ADE80 | Planeta |
| `--aki-editorial-norma` | #34D399 | Norma |
| `--aki-editorial-ovni` | #C084FC | Ovni Press |
| `--aki-editorial-kamite` | #FB923C | Kamite |
| `--aki-editorial-utop` | #38BDF8 | Utop |
| `--aki-editorial-milky` | #A78BFA | Milky Way |
| `--aki-editorial-tmvn` | #FBBF24 | TMVN |

**Validación contraste pendiente** — algunos (lavender, sky) pueden fallar AA con white. Usar oscurecidos vía `color-mix()` si necesario.

## 5. Typography

| Token | Valor | Uso |
|---|---|---|
| `--font-heading` | Bebas Neue, Impact, Arial Narrow, sans-serif | Headings uppercase |
| `--font-display` | Russo One + fallback heading | Hero, large display |
| `--font-body` | Inter + system | Body text |
| `--font-mono` | JetBrains Mono, Fira Code, monospace | Code, numbers |

**Webfonts hosted self:**
- Bebas Neue: woff2 ~38KB
- Russo One: woff2 ~32KB
- Inter: woff2 4 weights (400/500/600/700) ~92KB

**Fluid type scale (clamp):**

| Token | Min → Max |
|---|---|
| `--text-xs` | 0.75rem → 0.8rem |
| `--text-sm` | 0.8125rem → 0.875rem |
| `--text-base` | 0.9rem → 1rem |
| `--text-lg` | 1rem → 1.125rem |
| `--text-xl` | 1.15rem → 1.25rem |
| `--text-2xl` | 1.4rem → 1.75rem |
| `--text-3xl` | 1.8rem → 2.5rem |
| `--text-4xl` | 2.2rem → 3.5rem |
| `--text-5xl` | 2.8rem → 5rem |
| `--text-6xl` | 3.5rem → 7rem |

**Font weights:** `--weight-{normal=400, medium=500, semibold=600, bold=700, black=900}`

## 6. Spacing (4px base)

| Token | rem | px |
|---|---|---|
| `--space-1` | 0.25 | 4 |
| `--space-2` | 0.5 | 8 |
| `--space-3` | 0.75 | 12 |
| `--space-4` | 1.0 | 16 |
| `--space-5` | 1.25 | 20 |
| `--space-6` | 1.5 | 24 |
| `--space-8` | 2.0 | 32 |
| `--space-10` | 2.5 | 40 |
| `--space-12` | 3.0 | 48 |
| `--space-16` | 4.0 | 64 |
| `--space-20` | 5.0 | 80 |
| `--space-24` | 6.0 | 96 |
| `--space-32` | 8.0 | 128 |

**Layout:** `--container-max: 1400px`, `--container-narrow: 900px`, `--header-height: 70px`, `--sidebar-width: 280px`

## 7. Border radius

`--radius-sm: 2px`, `--radius-md: 4px`, `--radius-lg: 8px`, `--radius-xl: 12px`

## 8. Shadows

```
--shadow-sm: 0 1px 3px rgba(0,0,0,0.4);
--shadow-md: 0 4px 12px rgba(0,0,0,0.5);
--shadow-lg: 0 8px 30px rgba(0,0,0,0.6);
--shadow-glow-red: 0 0 20px rgba(217,0,16,0.3);
--shadow-glow-info: 0 0 20px rgba(59,130,246,0.3);
--shadow-glow-yellow: 0 0 20px rgba(255,215,0,0.3);
```

## 9. Motion

```
--transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
--transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
--transition-slow: 400ms cubic-bezier(0.4, 0, 0.2, 1);
--transition-bounce: 500ms cubic-bezier(0.34, 1.56, 0.64, 1);
```

**Accesibilidad:** todos los tokens motion se override a 0ms en `@media (prefers-reduced-motion: reduce)`. Implementación en tokens.css.

## 10. Z-index stack

| Token | Valor | Capa |
|---|---|---|
| `--z-base` | 1 | Default |
| `--z-dropdown` | 100 | Dropdowns, popovers |
| `--z-sticky` | 200 | Sticky header |
| `--z-overlay` | 300 | Cookie banner, dimmers |
| `--z-modal` | 400 | Modales, popups |
| `--z-toast` | 500 | Toast notifications |
| `--z-lightbox` | 900 | Full-screen lightbox |

## 11. Accessibility tokens

```css
--aki-focus-outline: 3px solid var(--aki-red);
--aki-focus-offset: 2px;
--touch-target-min: 44px;
```

**Reglas WCAG enforced:**
- 2.4.13 Focus appearance: outline 3px ≥3:1 contrast
- 2.5.5 Touch targets: ≥44×44px en interactive elements
- 1.4.3 Color contrast: AA 4.5:1 normal text, 3:1 large text (18pt+)
- 1.4.11 Non-text contrast: 3:1 para borders/focus
- Color NO único differenciador (siempre paired con icon/text)

## 12. Migration path

**Files con hex hardcodeados a migrar (Sprint 3.5):**
- `wp-content/themes/akibara/inc/admin.php` — colors hardcoded en admin styles
- `wp-content/themes/akibara/inc/email-header.php` — limitación email clients (mantener hex)
- Files que coexisten en `server-snapshot/.../assets/css/header-v2.css` — migrar al hacer lift en Cell B

**Convención:**
```css
/* OLD (deprecated) */
.btn { background: #D90010; }

/* NEW (correct) */
.btn { background: var(--aki-red); }
```

**Email templates** (limitación email clients): hex hardcoded permitido pero documentar el token equivalente en comentario.

## 13. Referencias

- Branding observador audit Round 1: `audit/round1/13-branding-observador.md` (O-13-* findings)
- Contrast audit: `docs/CONTRAST-AUDIT-2026-04-25.md`
- WCAG 2.1: https://www.w3.org/WAI/WCAG21/quickref/
- Tokens CSS file: `wp-content/themes/akibara/assets/css/tokens.css`

---

## Pendientes Sprint 3.5

- [ ] Validar editorial colors contrast (lavender, sky pueden fallar AA)
- [ ] Migrar hex hardcoded de admin.php a tokens
- [ ] Enqueue tokens.css antes de critical.css en `wp-content/themes/akibara/inc/enqueue.php`
- [ ] Designer apruebe paleta editorial (Phase 2)
- [ ] Tokens.css agregado a quality gate stylelint config
