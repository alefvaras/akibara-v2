# Cell H Sprint 4 — Design Ops HANDOFF

**Sprint:** 4
**Branch:** `feat/theme-design-s4`
**Fecha:** 2026-04-26
**Status:** DONE — listo para revision y merge review

---

## Resumen

6 mockups HTML/CSS + 3 UI-SPECS + INDEX navegable producidos en Sprint 4.
Todos consumen `wp-content/themes/akibara/assets/css/tokens.css` como source of truth,
con fallback inline para visualizacion standalone.

---

## Files entregados

| Archivo | LOC aprox | Consumer |
|---|---|---|
| `mockups/11-stock-alerts.html` | ~430 | Cell C — akibara-inventario |
| `mockups/12-back-in-stock-form.html` | ~390 | Cell C — akibara-inventario |
| `mockups/13-whatsapp-button-variants.html` | ~380 | Cell D — akibara-whatsapp |
| `mockups/14-editorial-color-palette.html` | ~340 | Cell H / Cell B / akibara-inventario |
| `mockups/15-customer-milestones-email.html` | ~340 | akibara-marketing / Brevo |
| `mockups/16-logo-canonical.html` | ~370 | Cell H Design Ops |
| `mockups/INDEX.html` | ~220 | Navegacion |
| `UI-SPECS-inventario.md` | — | Cell C |
| `UI-SPECS-whatsapp.md` | — | Cell D |
| `UI-SPECS-branding.md` | — | Cell H / akibara-marketing |
| `HANDOFF.md` | — | Mesa tecnica |

**Total LOC HTML/CSS:** ~2,470

---

## Compliance checks

| Check | Estado |
|---|---|
| mobile-first (min-width queries) | PASS — todos los mockups |
| lang="es-CL" | PASS — todos los archivos |
| Tuteo neutro chileno (sin voseo) | PASS — texto copy revisado |
| `--aki-on-success: #000` | PASS — aplicado en mockups 11, 12 |
| `--aki-on-info: #000` | PASS — aplicado en mockups 11 (fix mesa-08) |
| `--aki-border-aa` en inputs | PASS — mockup 12 input border |
| `--aki-red-bright` en CTA BIS | PASS — mockup 12 submit button |
| Touch targets 44px+ | PASS — todos los botones interactivos |
| prefers-reduced-motion override | PASS — al final de cada `<style>` |
| ARIA completo | PASS — labels, roles, states documentados |
| Email guard activo | NOTA en mockup 15 y UI-SPECS-branding |

---

## Bloqueadores resueltos

- **Cell C HANDOFF D-04 (BIS stub styling):** Mockup 12 provee el branding completo del form BIS.
  Cell C puede aplicar los cambios en `modules/back-in-stock/module.php` post-aprobacion de este mockup.

- **Cell D dependencia Cell H:** Mockup 13 entrega las 3 variantes de placement WhatsApp.
  Cell D elige variante y actualiza `akibara-whatsapp.css` segun la especificacion.

---

## Decisiones de diseno formalizadas

### D-H-01: WhatsApp — status quo float sin cambio de comportamiento
Per `feedback_minimize_behavior_change.md`: Variante A (float actual) se mantiene sin cambios.
Cell D puede agregar Variante B (inline) como aditivo sin quitar el float.
Variante C (sticky replace) requiere staging smoke obligatorio — riesgo medio.

### D-H-02: BIS CTA — --aki-red-bright mandatorio
El CTA del form BIS usa `--aki-red-bright: #FF2020` (4.6:1 PASS AA) per fix mesa-08 F-04.
NO usar `--aki-red: #D90010` (3.56:1 FAIL AA) en CTA sobre dark surface.

### D-H-03: Email hex hardcoded
Templates email item 15 usan hex hardcoded con comentarios de token equivalente.
Es el patron correcto para email (CSS variables no soportadas en la mayoria de clientes de email).
Referencia en `tokens.css`: excepcion documentada en el comentario de apertura.

### D-H-04: Ivrea y Panini solo en badges bold
Contraste de Ivrea (#60A5FA) y Panini (#F87171) con texto negro: 3.94:1 y 4.23:1 respectivamente.
FALLAN WCAG AA 4.5:1 para texto normal. Uso permitido solo en badges (font-weight bold, >=12px)
y graficos de barra. Documentado en UI-SPECS-branding.md y en el mockup 14.

### D-H-05: Logo canonical Manga Crimson v3
Clearspace = altura M. 6 variantes aprobadas. Usos prohibidos documentados.
Archivos SVG target en `wp-content/themes/akibara/assets/images/`.
Alt text logo: `alt="Akibara Manga"` — no usar "logo" como alt text.

---

## Pendientes para Sprint 4.5

1. **Aprobacion mockup 12 (BIS form):** Cell C aplica CSS post-aprobacion.
2. **Decision Cell D sobre variante WhatsApp:** A (status quo) / B (inline add) / C (sticky replace).
3. **Cupones WC para item 15:** crear `LECTOR5` (10%, 30 dias) y `VIP15` (15%, permanente) en WC.
4. **Assets logo SVG:** verificar que los 5 archivos de logo existen en `assets/images/` — si faltan, solicitar a Alejandro.
5. **LambdaTest visual QA:** cambios visuales que lleguen a prod requieren QA per `project_qa_lambdatest_policy.md`.

---

## Hipotesis para Sprint 5

1. **Filtros por editorial en catalogo:** Los tokens editoriales (item 14) habilitan un sistema de filtros con colores por editorial en la pagina de archivo. No fue requerido explicitamente pero es un candidato natural.

2. **Email automation Brevo:** Los 3 milestones (item 15) pueden dispararse via Brevo automation rules (order count trigger). Verificar si Brevo Automation del plan actual soporta triggers basados en order count — si no, Cell B necesita implementar via WC hook + Brevo transactional.

3. **Logomark + favicon SVG:** Si el archivo `logo.svg` en prod es PNG (no SVG), hay degradacion de calidad en pantallas retina. Verificar con `bin/wp-ssh` el tipo de archivo del logo del sitio.

---

## Notas de calidad

- Ningun mockup usa JS externo — solo HTML/CSS puro con inline SVG para iconos WA.
- Todos los mockups son standalone (abren sin servidor web).
- Tokens link: `href="../../../../../wp-content/themes/akibara/assets/css/tokens.css"` — funciona desde el path relativo `audit/sprint-4/cell-h/mockups/`.
- Si tokens.css no carga, el fallback inline `:root {}` garantiza visualizacion correcta.
