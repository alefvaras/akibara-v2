# Sweeps Consolidados Sprint 3.5 — mesa-05/06/07/08

**Fecha:** 2026-04-27
**Scope:** 10 HTML prototypes Cell H + tokens.css + UI-SPECS-{preventas,marketing}.md
**Total findings:** 51 (0 P0, 18 P1, 25 P2, 8 P3)

---

## Resumen ejecutivo

| Sweep | Findings | P0 | P1 | P2 | P3 | Highlight |
|---|---|---|---|---|---|---|
| mesa-05 a11y | 14 | 0 | 5 | 9 | 0 | Modal sin focus trap impl, star rating ARIA inconsistente, CTA 32px viola WCAG 2.5.8 |
| mesa-06 content-voz | 10 | 0 | 3 | 4 | 3 | **0 voseo (gate pasa)**; 3 claims sin evidencia legal Ley 19.496 (100% Original, Garantía, cupones) |
| mesa-07 responsive | 15 | 0 | 4 | 8 | 3 | Dashboard 09 stock table sin overflow, breakpoint 430px gap, 100vh sin dvh, mobile-first invertido |
| mesa-08 design-tokens | 12 | 0 | 6 | 4 | 2 | **--aki-on-info comment INCORRECTO (3.95:1 vs 4.97:1 doc)**; --aki-border fails WCAG 1.4.11 (1.32:1) |

---

## P1 bloqueantes Sprint 4 (18 totales)

### mesa-05 a11y — 5 P1

1. **F-01 Modal popup-modal:** Backdrop sin `aria-hidden`, sin focus trap implementado en markup. Cell B debe implementar JS antes de activate popup en prod.
2. **F-02 Star rating:** Botón con `aria-checked="false"` + `class="active"` — SR anuncian "no seleccionado" para estado visual activo.
3. **F-03 Stock CTA:** `min-height: 32px` (12px below WCAG 2.2 SC 2.5.8 AA mínimo 44px).
4. **F-04 Cookie banner:** `role="region"` sin focus management — keyboard users ignoran banner.
5. **F-05 Email cart table:** `role="presentation"` incorrecto en tabla de datos real (Producto/SKU/Qty/Precio).

### mesa-06 content-voz — 3 P1

1. **F-01 "100% Original":** Sin certificación visible — riesgo Ley 19.496 Chile. Propuesta: "Distribución oficial Ivrea/Panini/Planeta".
2. **F-02 "Garantía de autenticidad":** Sin proceso reclamo — Ley 19.496 art. 28 publicidad engañosa. Propuesta: "Ediciones oficiales licenciadas".
3. **F-03 Cupones WELCOME10/RETURN15/RECOVERY10:** Referenciados en 4 mockups sin verificar existencia activa en WC.

### mesa-07 responsive — 4 P1

1. **F-01 Dashboard 09 stock table:** Sin `overflow-x: auto`, columnas de 5 a 375px = ilegible.
2. **F-02 Breakpoint 430px gap:** iPhone 14 Pro Max sin coverage en 4 componentes (cookie banner expandido obstruye contenido).
3. **F-03 Modal 100vh sin dvh:** iOS Safari bug — modal puede cortarse por toolbar.
4. **F-04 Mobile-first invertido:** Todos los 10 mockups usan `max-width:767px` (desktop-first). Patrón incorrecto, transmite mala señal a Cell A/B.

### mesa-08 design-tokens — 6 P1

1. **F-01 Mockup 03 confirmed badge:** Sigue usando `color: var(--aki-white)` sobre success (2.24:1 FAIL AA). Token `--aki-on-success: #000000` existe pero no se consume.
2. **F-02 Mockup 03 shipping badge:** `color: white` sobre `--aki-info` = 3.68:1 FAIL AA normal text.
3. **F-03 tokens.css COMMENT INCORRECTO:** Línea 43 dice `/* #3B82F6 con #FFF: 4.97:1 PASS AA */` — ratio real es 3.68:1 FAIL. Cambio crítico: `--aki-on-info: #000000` (5.71:1 PASS).
4. **F-04 `--aki-red` como text dashboard action links:** 3.56:1 FAIL AA. Solución: usar `--aki-red-bright` o `--aki-error`.
5. **F-05 `--aki-border` falla WCAG 1.4.11:** 1.32:1 (mínimo 3:1 para inputs/UI components interactivos). Token `--aki-border-aa` (3.49:1) existe pero no se usa default.
6. **F-06 `--aki-info` compuesto rgba(0.15) FAIL AA:** Badge "Atencion" con texto info sobre fondo info-tinted = 4.35:1 (umbral 4.5:1).

---

## Verificación 3 P1 LAMBDATEST-REPORT previos

| ID | Estado tokens.css | Estado mockups | Veredicto |
|---|---|---|---|
| P1-a `--aki-red` text → `--aki-error` | Token correcto | FAIL: dashboard `--aki-red` action link (F-04 nuevo) | Parcial |
| P1-b White on success → black | Token `--aki-on-success` correcto | FAIL: mockup 03 línea 207 `color: var(--aki-white)` | NO resuelto código (F-01) |
| P1-c White on gray-400 → black | Token correcto | OK: mockup 03 línea 214 usa `var(--aki-black)` | RESUELTO |

**1 de 3 totalmente resuelto. 2 generan nuevos fails colaterales.**

---

## Recomendación priorización Sprint 4

### Bloqueantes pre-cells (deben fixearse ANTES Sprint 4 inicio)

- **F-03 mesa-08:** tokens.css comment `--aki-on-info` (1 línea fix). Riesgo Cell A/B confiar en token mal documentado.
- **F-01 mesa-08:** Mockup 03 badge confirmed (1 línea fix). Cell A va a copiar este código a PHP template.
- **F-02 mesa-08:** Mockup 03 badge shipping (1 línea fix). Mismo razón.
- **F-05 mesa-08:** `--aki-border` decision para inputs (cambiar a `--aki-border-aa` default). Decisión arquitectural.

### Bloqueantes durante Cells (atender en su Cell respectiva)

- mesa-05 F-01/F-02 → Cell B (popup-modal implementation)
- mesa-05 F-03 → Cell B (finance dashboard CTAs)
- mesa-05 F-04 → Cell B (cookie banner JS)
- mesa-05 F-05 → Cell B (cart-abandoned email)
- mesa-06 F-01/F-02 → Cell B (marketing copy review pre-deploy)
- mesa-06 F-03 → Cell B (verify WC coupons exist before activate)
- mesa-07 F-01 → Cell B (finance widgets)
- mesa-07 F-02/F-03 → Cell B + Cell C (responsive QA)
- mesa-08 F-04 → Cell B (finance dashboard color refactor)

### Diferibles a Sprint 4.5 / Sprint 5

- 25 P2 + 8 P3 hallazgos pueden documentarse como debt + atender en cells siguientes o sprint 4.5 polish.

---

## Action items consolidados pre-Sprint 4

### Inmediato (mi work, ~30 min)

- [ ] Fix tokens.css F-03: `--aki-on-info: #000000` + comment correcto
- [ ] Fix mockup 03 F-01: línea 207 `color: var(--aki-on-success)`
- [ ] Fix mockup 03 F-02: línea 211 `color: var(--aki-on-info)` (post fix F-03)
- [ ] Decision document: `--aki-border-aa` vs `--aki-border` para inputs (F-05)

### Cell-distributed (durante Sprint 4)

- [ ] mesa-05 5 P1 → Cell B implementation
- [ ] mesa-06 3 P1 → Cell B copy review
- [ ] mesa-07 4 P1 → Cell B + Cell C QA
- [ ] mesa-08 F-04 → Cell B finance refactor
