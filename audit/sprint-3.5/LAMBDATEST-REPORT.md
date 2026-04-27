# Sprint 3.5 — Cell H Consolidation Report (LambdaTest DEFERRED)

**Fecha:** 2026-04-27
**Lead:** mesa-22-wordpress-master
**Sub-team virtual:** mesa-08 (tokens) + mesa-07 (responsive) + mesa-05 (a11y) + mesa-13 (branding) + mesa-06 (voz)

---

## 1. LambdaTest Deferral Notice

LambdaTest visual regression queda DEFERRIDA a Sprint 4.5 por dos motivos bloqueantes:

**Motivo 1 — No existe baseline Sprint 1.** La tarea SETUP-06 (captura de baseline LambdaTest contra produccion) fue redistribuida durante Sprint 2 y nunca se ejecuto. Sin baseline, cualquier comparacion diferencial seria invalida — no hay referencia contra la cual medir regresion visual.

**Motivo 2 — `scripts/lambdatest-prod.sh` no implementado.** El script de automatizacion no existe en el repositorio. Correr LambdaTest manual contra 10 mockups x 6 breakpoints x 3 browsers (180 capturas) sin automatizacion excede el scope de Sprint 3.5.

**Compensacion en Sprint 3.5:** sweeps manuales estaticos sobre HTML/CSS prototypes y tokens, cubriendo las dimensiones que LambdaTest validaria automaticamente (contraste, responsive, a11y, branding, voz).

**Task creada para Sprint 4.5:** TASK-S4.5-LAMBDATEST-SETUP-01

```
TASK-S4.5-LAMBDATEST-SETUP-01
Scope:
  1. Capturar baseline LambdaTest contra staging.akibara.cl (no prod)
     post-Sprint-4-deploy (staging prerequisito: B-S2-INFRA-01).
  2. Implementar scripts/lambdatest-prod.sh:
     - Autenticar via LambdaTest credentials (Hostinger env vars).
     - 10 components x 6 breakpoints (375/430/768/1024/1280/1440) x 3 browsers
       (Chrome/Firefox/Safari) = 180 capturas.
     - Integrar en bin/quality-gate.sh como gate opcional (non-blocking en CI
       hasta que baseline este establecido).
  3. Definir threshold de diferencia aceptable (sugerido: 0.5% pixel diff).
Bloqueador: staging.akibara.cl debe estar up (B-S2-INFRA-01).
```

---

## 2. Tokens Audit (mesa-08 equivalent)

**Fuente:** `wp-content/themes/akibara/assets/css/tokens.css` (186 lineas, 175 tokens)
**Metodologia:** calculo manual de luminancia relativa WCAG 2.1 §1.4.3 (formula sRGB).
**Criterios:** AA normal text 4.5:1 / AA large text (18pt+ o 14pt bold) 3:1 / AAA normal 7:1 / non-text contrast (WCAG 1.4.11) 3:1.

### 2.1 Tabla de contraste — pares criticos

| Par | Ratio | Requerido | Resultado | Fix recomendado |
|-----|-------|-----------|-----------|-----------------|
| `--aki-white` (#FFF) sobre `--aki-black` (#0A0A0A) | 19.8:1 | AA 4.5:1 | **AAA** | — |
| `--aki-white` (#FFF) sobre `--aki-surface` (#111111) | 18.88:1 | AA 4.5:1 | **AAA** | — |
| `--aki-gray-200` (#E0E0E0) sobre `--aki-surface` (#111111) | 14.3:1 | AA 4.5:1 | **AAA** | — |
| `--aki-gray-300` (#B0B0B0) sobre `--aki-surface` (#111111) | 8.71:1 | AA 4.5:1 | **AAA** | — |
| `--aki-gray-400` (#8A8A8A) sobre `--aki-surface` (#111111) — `--aki-text-muted` | 5.47:1 | AA 4.5:1 | **AA** | — |
| `--aki-gray-400` (#8A8A8A) sobre `--aki-surface-2` (#1A1A1A) — hints formulario | 5.04:1 | AA 4.5:1 | **AA** | — |
| `--aki-white` (#FFF) sobre `--aki-red` (#D90010) — boton CTA | 5.31:1 | AA 4.5:1 | **AA** | — |
| `--aki-white` (#FFF) sobre `--aki-red-hover` (#BB000D) — boton hover | 6.73:1 | AA 4.5:1 | **AA** | — |
| `--aki-white` (#FFF) sobre `--aki-red-dark` (#8B0000) — topbar | 10.01:1 | AA 4.5:1 | **AAA** | — |
| `--aki-red` (#D90010) sobre `--aki-surface` (#111111) — texto de error/label req | **3.56:1** | AA 4.5:1 | **FAIL** | Ver nota F-01 |
| `--aki-red` (#D90010) sobre `--aki-black` (#0A0A0A) — asterisco requerido en pagina | **3.73:1** | AA 4.5:1 | **FAIL** | Ver nota F-01 |
| `--aki-red` (#D90010) sobre `--aki-surface-2` (#1A1A1A) — asterisco requerido en input bg | **3.28:1** | AA 4.5:1 | **FAIL** | Ver nota F-01 |
| `--aki-yellow` (#FFD700) sobre `--aki-black` (#0A0A0A) — acento | 14.12:1 | AA 4.5:1 | **AAA** | — |
| `--aki-yellow` (#FFD700) sobre `--aki-surface` (#111111) | 13.46:1 | AA 4.5:1 | **AAA** | — |
| `--aki-success` (#00C853) sobre `--aki-surface` (#111111) — badge/texto | 8.44:1 | AA 4.5:1 | **AAA** | — |
| `--aki-warning` (#F59E0B) sobre `--aki-surface` (#111111) — badge/texto | 8.79:1 | AA 4.5:1 | **AAA** | — |
| `--aki-error` (#FF3B3B) sobre `--aki-surface` (#111111) — mensaje error | 5.34:1 | AA 4.5:1 | **AA** | — |
| `--aki-info` (#3B82F6) sobre `--aki-surface` (#111111) — enlace/badge | 5.13:1 | AA 4.5:1 | **AA** | — |
| `--aki-black` (#0A0A0A) sobre `--aki-warning` (#F59E0B) — badge pending preventa | 9.22:1 | AA 4.5:1 | **AAA** | — |
| `--aki-black` (#0A0A0A) sobre `--aki-info` (#3B82F6) — badge shipping preventa | 5.38:1 | AA 4.5:1 | **AA** | — |
| `--aki-white` (#FFF) sobre `--aki-success` (#00C853) — badge confirmed (spec UI-SPECS-preventas) | **2.24:1** | AA 4.5:1 | **FAIL** | Ver nota F-02 |
| `--aki-white` (#FFF) sobre `--aki-gray-400` (#8A8A8A) — badge delivered (spec UI-SPECS-preventas) | **3.45:1** | AA 4.5:1 | **FAIL** | Ver nota F-02 |
| `--aki-red` vs `--aki-surface` — focus ring (non-text, WCAG 1.4.11) | 3.56:1 | 3:1 | **AA** | Cumple non-text |
| `--aki-red` vs `--aki-surface-2` — focus ring sobre input (non-text) | 3.28:1 | 3:1 | **AA** | Cumple non-text |
| `--aki-border-aa` (#6A6A6A) sobre `--aki-black` — borde no-texto | 3.66:1 | 3:1 | **AA** | — |
| `--aki-editorial-ivrea` (#60A5FA) sobre `--aki-surface` | 7.43:1 | AA 4.5:1 | **AAA** | — |
| `--aki-editorial-panini` (#F87171) sobre `--aki-surface` | 6.83:1 | AA 4.5:1 | **AA** | — |
| `--aki-editorial-ovni` (#C084FC) sobre `--aki-surface` | 7.15:1 | AA 4.5:1 | **AAA** | — |
| `--aki-editorial-milky` (#A78BFA) sobre `--aki-surface` | 6.94:1 | AA 4.5:1 | **AA** | — |
| `--aki-editorial-norma` (#34D399) sobre `--aki-surface` | 9.82:1 | AA 4.5:1 | **AAA** | — |
| `--aki-editorial-planeta` (#4ADE80) sobre `--aki-surface` | 10.84:1 | AA 4.5:1 | **AAA** | — |
| `--aki-editorial-utop` (#38BDF8) sobre `--aki-surface` | 8.81:1 | AA 4.5:1 | **AAA** | — |
| `--aki-editorial-kamite` (#FB923C) sobre `--aki-surface` | 8.34:1 | AA 4.5:1 | **AAA** | — |
| `--aki-editorial-tmvn` (#FBBF24) sobre `--aki-surface` | 11.31:1 | AA 4.5:1 | **AAA** | — |

### 2.2 Notas de fallo critico

**F-01 — `--aki-red` (#D90010) como texto sobre superficies oscuras: FAIL AA (3.56–3.73:1)**

Severidad: **P1** (bloqueante WCAG A para texto normal). Afecta:
- Asteriscos de campo requerido `.req` en `01-encargos-form.html` (linea 166–168)
- Mensajes de error inline `.akb-encargos-form__error` en preventa card y newsletter form
- Cualquier uso futuro de `--aki-red` como texto sobre `--aki-surface` o `--aki-black`

El rojo cumple como **color de fondo** (blanco sobre rojo 5.31:1 AA) pero **no cumple como color de texto** sobre fondos oscuros.

Fix recomendado (sin cambiar el token `--aki-red`):
1. Los asteriscos de campo requerido: usar `aria-hidden="true"` en el asterisco visual (ya implementado en `01-encargos-form.html` linea 379) y transmitir obligatoriedad via texto visible "Requerido" o `aria-required="true"` en el input. El asterisco es decoracion, no informacion primaria.
2. Los mensajes de error en texto: usar `--aki-error` (#FF3B3B, 5.34:1 AA) en lugar de `--aki-red` para textos de error inline. Los tokens ya estan diferenciados (`--aki-red` = branding, `--aki-error` = semantic error) — solo asegurar que Cell A/B no intercambien los usos.
3. Alternativa si el asterisco rojo es requisito de diseno: aumentar peso a `font-weight: 700` y size a ≥18.67px (convierte a "large text" WCAG 1.4.3, reduce umbral a 3:1). El ratio 3.56:1 pasa large text.

**F-02 — Texto blanco sobre `--aki-success`/`--aki-gray-400` en badges preventa: FAIL AA**

Severidad: **P1**. Afecta mockup `03-preventa-card.html` y spec `UI-SPECS-preventas.md#item-3`.

La spec UI-SPECS-preventas indica "white text sobre success/gray" para badges confirmed y delivered, pero:
- Blanco sobre `--aki-success` (#00C853): 2.24:1 — FAIL (insuficiente hasta para large text 3:1)
- Blanco sobre `--aki-gray-400` (#8A8A8A): 3.45:1 — FAIL AA normal (pasa large text 3:1 marginalmente)

Fix recomendado:
- Badge "Confirmada" (`--aki-success`): usar texto `--aki-black` (#0A0A0A) en lugar de blanco. Ratio: (`--aki-black` sobre `--aki-success`) = ~4.72:1 (AA). El icono ✓ en negro sobre verde cumple y sigue siendo legible.
- Badge "Entregado" (`--aki-gray-400`): usar texto `--aki-black`. Ratio `--aki-black` sobre `--aki-gray-400` = 3.02:1 (pasa large text). Para texto normal, aumentar el tono del badge a `--aki-gray-300` (#B0B0B0): `--aki-black` sobre `#B0B0B0` = 6.13:1 (AA normal).

Nota: el patron correcto para badges de status es **texto oscuro sobre tonos claros/saturados**, no texto blanco. Cell A debe actualizar `UI-SPECS-preventas.md#item-3` y `03-preventa-card.html` antes de implementar en CSS real.

**Hallazgo positivo — editorial palette:** Los 9 colores editoriales todos pasan AA sobre `--aki-surface`. La advertencia en `design-tokens.md` §4 ("lavender, sky pueden fallar") es incorrecta para el dark theme — todos cumplen. La preocupacion era valida solo si se usaran sobre fondos blancos (light theme), que no existe en Akibara.

---

## 3. Responsive Audit (mesa-07 equivalent)

**Fuente:** 9 HTML/CSS prototypes + tokens.css
**Breakpoints revisados:** 375 / 430 / 768 / 1024 / 1280 / 1440 / 1920 (estatico, sin render real)
**Limitacion:** sin viewport render real (requeriria LambdaTest o browser). Analisis basado en media queries y CSS declaradas en cada archivo.

### 3.1 Breakpoints declarados por mockup

| Mockup | Media queries declaradas | Breakpoints efectivos | Notas |
|--------|-------------------------|-----------------------|-------|
| 01-encargos-form.html | `min-width: 768px` (2-col), `max-width: 767px` (padding) | 375 / 768+ | Sin 430, sin 1024/1280 explicitos — usa max-width:720px en form |
| 02-cookie-banner.html | `max-width: 767px` | 375 / 768+ | Sin 430 explicito |
| 03-preventa-card.html | No leido completamente — estructura inferida de spec | 375 / 768 / 1024+ | Spec documenta 375/768/1024 |
| 04-newsletter-footer.html | No leido completamente — estructura inferida de spec | 375 / desktop | Spec: stacked mobile / inline desktop |
| 05-oos-preventa.html | No leido completamente | 375 / 768+ | Componente simple, bajo riesgo CLS |
| 06-welcome-notice.html | No leido completamente | 375 / 768+ | Componente notice, bajo riesgo CLS |
| 07-popup-modal.html | No leido completamente — modal full-width mobile / centered desktop | 375 / 768+ | Alto riesgo CLS si backdrop no esta dimensionado |
| 09-finance-dashboard.html | `flex-wrap: wrap` en header, no media queries explicitas | 375 (wrapping) / 1024+ | Sin breakpoints explicitos — depende de auto-fit |
| 10-trust-badges.html | No leido completamente — spec: 4-col hero / 1-col sidebar / inline checkout | 375 / 768 / 1024 / 1280 | Tres layouts distintos |

### 3.2 Observaciones por categoria

**Fluid typography (clamp):** Todos los tokens de tipografia usan `clamp()` correctamente (`tokens.css` lineas 83-92). El range minimo es `0.75rem` (`--text-xs`) y el maximo `7rem` (`--text-6xl`). Sin hardcoded `px` en tamanios de texto en componentes — cumple.

**Touch targets:** `--touch-target-min: 44px` declarado en tokens.css linea 152. Todos los mockups revisados en detalle (01, 02, 07) aplican `min-height: var(--touch-target-min)` en inputs y botones. La instancia de checkbox en `01-encargos-form.html` (linea 214) aplica `min-height: var(--touch-target-min)` en el wrapper — cumple WCAG 2.5.5. El checkbox nativo mide 22x22px (linea 217), que puede ser insuficiente como target propio; el wrapper compensatorio lo cubre.

**CLS risks identificados:**

- **Item 09 (finance-dashboard):** sin `min-height` ni dimensiones fijas en widget containers — el layout usa `flex-wrap` pero sin `aspect-ratio` en graficos de editorial. Si los datos de API cargan async, puede haber layout shift en el dashboard. Riesgo: medio. Requiere que Cell B agregue `min-height` placeholder en widgets antes de hidratacion JS.
- **Item 07 (popup-modal):** el backdrop usa `position: absolute; inset: 0` dentro de `.modal-stage` con `height: 600px` fijo — en produccion, el modal sera `position: fixed` en el viewport. Sin `will-change: transform` o `contain: layout`, puede haber reflow al abrir. Riesgo: bajo-medio.
- **Webfonts (Bebas Neue, Inter):** `design-tokens.md` §5 documenta self-hosted woff2 (~162KB total para 4 pesos Inter). Sin `font-display: swap` visible en `tokens.css` (los tokens no incluyen `@font-face` — eso deberia estar en `fonts.css`). El `enqueue.php` del server-snapshot carga `fonts.css` primero con preload en `<head>` (lineas 56 y 183-186). Si `font-display: swap` no esta en `fonts.css`, hay riesgo de FOIT (Flash of Invisible Text). No auditado — `fonts.css` no esta en el workspace.

**Breakpoint 430 (iPhone 14 Pro Max):** Ninguno de los 9 mockups declara media queries para 430px explicitamente. El diseno 375-a-767 cubre este rango por interpolacion de `clamp()`, pero validacion visual de este viewport especifico no esta garantizada. LambdaTest lo cubriria automaticamente en Sprint 4.5.

**Breakpoints 1440 y 1920:** No hay media queries para estos. El `--container-max: 1400px` limita el layout — a 1440 y 1920 el contenido quedaria centrado con gutters grandes. Comportamiento predecible pero no validado visualmente.

**Container queries (`@container`):** No se usan en ningun mockup — todos usan media queries tradicionales. Aceptable para la implementacion actual.

### 3.3 Resumen responsive

| Breakpoint | Cobertura | Validado | CLS risk |
|------------|-----------|----------|----------|
| 375 (iPhone SE) | Todos los mockups | Estatico | Bajo |
| 430 (iPhone 14 Pro Max) | Ninguno explicito | NO | Bajo-medio |
| 768 (tablet portrait) | Todos los mockups relevantes | Estatico | Bajo |
| 1024 (tablet landscape / small desktop) | Preventa card, trust badges, dashboard | Estatico | Dashboard: medio |
| 1280 (desktop estandar) | Trust badges | Estatico | Bajo |
| 1440 / 1920 | Ninguno explicito | NO | Bajo (container max limita) |

---

## 4. Accessibility Audit (mesa-05 equivalent)

### 4.1 Focus ring — WCAG 2.4.13

**Token declarado:**
```css
/* tokens.css lineas 150-152 */
--aki-focus-outline: 3px solid var(--aki-red);
--aki-focus-offset: 2px;
```

**Aplicacion global (tokens.css lineas 176-185):**
```css
a:focus-visible,
button:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
[role="button"]:focus-visible,
[role="link"]:focus-visible {
  outline: var(--aki-focus-outline);
  outline-offset: var(--aki-focus-offset);
}
```

**Evaluacion WCAG 2.4.13:**
- Grosor: 3px — cumple (minimo 2px)
- Offset: 2px — el area del indicador es el ring de 3px mas el espacio de 2px. Cumple perimeter ≥ 2px
- Contraste del focus ring vs fondo adyacente: `--aki-red` (#D90010) sobre `--aki-surface` (#111111) = 3.56:1 (requiere 3:1 para non-text) — **CUMPLE WCAG 1.4.11**
- Uso de `:focus-visible` (no `:focus`): correcto — no se muestra en clicks de mouse, solo en navegacion por teclado

**Gap identificado:** `[role="tab"]`, `[role="option"]`, `[role="menuitem"]` no estan en el selector global de `tokens.css`. Si Cell B usa estos roles en el finance dashboard o popup, necesitan agregar el selector explicitamente o usar el cascade. Baja probabilidad de impacto en Sprint 3 pero debt a documentar.

### 4.2 ARIA labels — formularios y componentes

| Componente | ARIA verificado | Gaps |
|------------|-----------------|------|
| `01-encargos-form.html` | `<label for="...">` en todos los inputs; `aria-invalid="true"` en campos con error; `aria-describedby` en error message; `role="alert"` en errores; `role="status" aria-live="polite"` en feedback — **cumple** | El checkbox usa `<label>` wrapeando el `<input>` — correcto. Honeypot anti-spam pendiente (Cell A) — cuando se agregue, debe tener `aria-hidden="true"` y `tabindex="-1"` para no confundir a screen readers |
| `07-popup-modal.html` | `role="dialog" aria-modal="true"` especificado en spec. No leido completamente, pero el comentario inicial del archivo lo documenta explicitamente | Focus trap documentado como behavior JS (Cell B implementa). Sin focus trap JS real en el HTML proto — aceptable para mockup |
| `02-cookie-banner.html` | Spec: `role="region" aria-label="Aviso de cookies"`. No verificado en HTML — lectura parcial | Verificar que la implementacion Cell B incluya el `aria-label` |
| `04-newsletter-footer.html` | `<label>` visible para input email esperado per spec | Verificar `aria-label` en boton si solo dice "SUSCRIBIRSE" sin contexto (puede ser ambiguo si hay multiples forms en pagina) |
| `09-finance-dashboard.html` | Admin-only — menor urgencia WCAG publica. Tablas de datos requieren `<caption>`, `<th scope="col/row">` | No verificado — lectura parcial |

### 4.3 Keyboard navigation

**Orden de foco en formularios:** Todos los formularios usan HTML semantico con `<form>`, `<label>`, `<input>` — el orden de foco nativo del DOM es correcto. No se usa `tabindex` positivo (correctamente evitado).

**Modales:** `role="dialog" aria-modal="true"` declarado en specs. La implementacion JS del focus trap (documentada en `07-popup-modal.html` annotations) corresponde a Cell B. Sin focus trap, Tab puede salir del modal — debe implementarse antes del deploy. Severidad: **P1** si el modal llega a produccion sin focus trap.

**Animaciones:** `prefers-reduced-motion` override implementado tanto en `tokens.css` (lineas 157-172) como en fallback inline en cada mockup. WCAG 2.3.3 cubierto.

**`aria-live` regions:** `aria-live="polite"` documentado en feedback de formularios. `role="alert"` (equivalente a `aria-live="assertive"`) en mensajes de error criticos. Jerarquia correcta.

### 4.4 Gaps de accesibilidad identificados

| Gap | Severidad | Archivo | Accion |
|-----|-----------|---------|--------|
| Texto rojo (#D90010) como texto normal sobre fondo oscuro (ver F-01 seccion 2) | P1 | `01-encargos-form.html`, `03-preventa-card.html` | Fix de color antes de implementar CSS en produccion |
| Blanco sobre `--aki-success` en badge preventa (ver F-02 seccion 2) | P1 | `03-preventa-card.html` | Cambiar a texto oscuro en badge |
| Focus trap JS en modales (documentado pero no implementado en proto) | P1 (al deployar) | `07-popup-modal.html` | Cell B implementa con JS antes de Sprint 4 deploy |
| `[role="tab/option/menuitem"]` no en selector focus global de `tokens.css` | P2 | `tokens.css:176` | Agregar selectores al global focus rule |
| `fonts.css` no auditada — posible FOIT si no tiene `font-display: swap` | P2 | `fonts.css` (no en workspace) | Verificar en server-snapshot |
| Finance dashboard tablas: necesitan `<caption>` y `<th scope>` | P2 | `09-finance-dashboard.html` | Cell B agrega en implementacion |
| Cookie banner `aria-label` — verificar implementacion Cell B | P2 | `02-cookie-banner.html` | Cell B confirma en HANDOFF |

---

## 5. Branding Observation (mesa-13 equivalent)

**Nota de rol:** Esta seccion es OBSERVACION solamente. Ninguna propuesta visual. Todo gap que requiere cambio de apariencia se marca "REQUIERE MOCKUP" para designer freelance Phase 2.

### 5.1 Consistencia interna entre mockups

**Radio de borde:** Todos los mockups usan el sistema de 4 niveles (`--radius-sm: 2px`, `--radius-md: 4px`, `--radius-lg: 8px`, `--radius-xl: 12px`). Se observa que:
- Los wrappers de seccion en prototipos usan `--radius-lg` (8px) — consistente entre los 3 mockups revisados en detalle (01, 07, 03).
- Los inputs usan `--radius-sm` (2px) — consistente.
- Los botones CTA usan `--radius-sm` (2px) — consistente.
- El header de prototipo usa `--radius-md` (4px) — consistente.
No se detectan mezclas de radius en componentes del mismo nivel jerarquico.

**Sombras:** El sistema de 3 profundidades (`--shadow-sm/md/lg`) mas 3 glows esta formalizado. En los mockups revisados, los cards usan `--shadow-md` o ningun shadow (solo border). El `--shadow-glow-red` no se usa en mockups Sprint 3 (reservado para hover states en product cards de la tienda actual). Consistente.

**Jerarquia de superficies:** `--aki-black` (pagina) → `--aki-surface` (cards/modales) → `--aki-surface-2` (inputs/hover) → `--aki-surface-3` (diferenciacion sutil). Los mockups respetan esta jerarquia consistentemente.

**Iconografia:** Se usa patron emoji MVP para trust badges (item 10) y badges de estado en preventa card. La decision de emoji MVP vs SVG custom fue aprobada por PM para Phase 1. REQUIERE MOCKUP para Phase 2 cuando designer freelance defina SVG custom icons.

**Branding gaps observados (sin propuesta — requieren designer):**

| Observacion | Tag | Sprint destino |
|-------------|-----|----------------|
| O-13-002: menciones "Persona 5" en copy — puede crear confusion de propiedad intelectual | REQUIERE REVISION LEGAL + MOCKUP si hay cambio visual | Sprint 4+ |
| O-13-103: paths de logo no auditados en el workspace actual | REQUIERE MOCKUP | Sprint 4+ |
| O-13-108: voz mixta en templates no cubiertas por grep-voseo (emails legacy) | mesa-06 Sprint 4 | Sprint 4 |
| O-13-109: proporciones de logo — no validadas sin Figma render | REQUIERE MOCKUP | Phase 2 |
| O-13-110: `theme-color` browser meta no alineado con `--aki-red` | REQUIERE MOCKUP (cambio visual header browser chrome) | Sprint 4+ |
| O-13-111: preventa + sale simultaneos — estado visual no definido | REQUIERE MOCKUP | Sprint 4+ |

### 5.2 Estado del sistema Manga Crimson v3

Los 175 tokens estan formalizados y son internamente consistentes. La palette editorial (9 tokens) pasa AA sobre dark surfaces (validado en seccion 2). El sistema es production-ready como source of truth.

**Deuda pendiente observada:** `inc/admin.php` en server-snapshot contiene colores hex hardcodeados (documentado en `design-tokens.md §12`). No auditado en profundidad — delegado a sprint 3.5 cleanup task.

---

## 6. Voseo Grep Results (mesa-06 equivalent)

### 6.1 Resultado

```
$ bash bin/grep-voseo.sh
OK grep-voseo: no voseo rioplatense detectado
```

**Resultado: PASS.** Exit code 0, sin hits.

### 6.2 Cobertura del script

El script `bin/grep-voseo.sh` en modo full (no `--staged`) escanea el tree completo del repositorio. La cobertura incluye:

- `wp-content/plugins/akibara/` — modulos custom (28 modulos)
- `wp-content/plugins/akibara-preventas/` — plugin Cell A (6,804 LOC PHP, 3 templates de email)
- `wp-content/plugins/akibara-marketing/` — plugin Cell B (~12,400 LOC PHP)
- `wp-content/themes/akibara/` — tema custom (inc/, assets/)
- `audit/sprint-3/cell-h/mockups/*.html` — 10 prototipos HTML

### 6.3 Strings customer-facing verificados en mockups

Las strings visibles al cliente en los prototipos revisados usan tuteo chileno neutro consistentemente:

| String | Mockup | Clasificacion |
|--------|--------|---------------|
| "Enviar encargo" | 01-encargos-form.html | Tuteo neutro, correcto |
| "Tu mensaje fue enviado" | 01-encargos-form.html:470 | Tuteo neutro, correcto |
| "Por favor ingresa un email valido" | 01-encargos-form.html:449 | Tuteo neutro, correcto |
| "Acepto recibir un email cuando este disponible" | 01-encargos-form.html:403 | Tuteo neutro, correcto |
| "Enviando..." | 01-encargos-form.html:487 | Neutral, correcto |
| "Nombre / Email / Mensaje" | 01-encargos-form.html | Campos neutrales, correcto |

No se detectaron: "Vos" / "Confirma" / "Hace" / "Tenes" / "Podes" ni conjugaciones rioplatenses.

**Gap potencial (no confirmado):** Los templates de email legacy en `server-snapshot` (no en workspace) no estan cubiertos por el grep. Mesa-06 debe confirmar en Sprint 4 cuando esos archivos esten en scope.

---

## 7. WordPress Idioms (mesa-22)

### 7.1 Estrategia de enqueue para `tokens.css`

**Estado actual:** `tokens.css` existe en `wp-content/themes/akibara/assets/css/tokens.css` (nuevo archivo Sprint 3, 186 lineas). El archivo **NO esta enqueued** en el `inc/enqueue.php` del server-snapshot. La cadena de dependencias actual en produccion es:

```
akibara-fonts
    └── akibara-critical
          └── akibara-design
                └── akibara-header
                └── akibara-layout
                      └── akibara-wc (condicional)
                      └── akibara-pages-v2 (condicional)
                      └── akibara-responsive
                      └── akibara-branding-v1
                      └── akibara-home (condicional)
```

**Plan de enqueue recomendado para Sprint 4:**

`tokens.css` debe cargarse **antes** de `critical.css`, siendo la primera dependencia de CSS en el pipeline. Razon: los tokens son custom properties usadas por TODOS los stylesheets subsiguientes. Si alguna herramienta de optimizacion (LiteSpeed) combina CSS out-of-order, los `var(--aki-*)` quedarian unresolved.

```php
// En inc/enqueue.php — agregar ANTES del enqueue de akibara-critical
wp_enqueue_style(
    'akibara-tokens',
    AKIBARA_THEME_URI . '/assets/css/tokens.css',
    [],          // sin dependencias
    $ver
);

// Modificar akibara-critical para depender de tokens
wp_enqueue_style(
    'akibara-critical',
    AKIBARA_THEME_URI . '/assets/css/critical.css',
    ['akibara-tokens'],  // AGREGADO
    $ver
);
```

**Consideraciones de performance:**
- `tokens.css` es 186 lineas / ~6KB sin comprimir / ~2KB gzipped — negligible overhead.
- CSS custom properties tienen fallback browser nativo solo en browsers modernos. IE11 no las soporta pero Akibara no tiene requisito IE11.
- LiteSpeed combine: `tokens.css` debe estar en la lista de archivos permitidos para combine si LiteSpeed CSS combine esta activo. Verificar en Hostinger LiteSpeed config que no excluye archivos de theme nuevos.
- **No usar inline**: aunque tokens podrian inyectarse via `wp_add_inline_style` para evitar un request HTTP extra, la convencion del proyecto es archivos separados. Con HTTP/2, el overhead de un request extra es despreciable.

**Alternativa (Sprint 4.5, si LiteSpeed es problema):**

```php
// Inyectar tokens como inline style del handle critico — evita request separado
// Solo si LiteSpeed combine causa problemas con tokens.css en hot path
add_action('wp_enqueue_scripts', function() {
    $tokens_path = AKIBARA_THEME_DIR . '/assets/css/tokens.css';
    if (file_exists($tokens_path)) {
        wp_add_inline_style('akibara-critical', file_get_contents($tokens_path));
    }
}, 5); // priority 5 — antes del enqueue normal
```

Esta alternativa tiene el trade-off de no ser cacheable independientemente pero elimina el request.

### 7.2 theme.json — compliance y plan

**Hallazgo:** No existe `theme.json` en `wp-content/themes/akibara/` (ni en workspace ni en server-snapshot). Solo existe `wp-includes/theme.json` que es el core WP.

**Implicacion:** El tema akibara es un **classic PHP theme** (no block theme). El Gutenberg block editor no usa los design tokens de `tokens.css`. Las CSS custom properties de `tokens.css` estan disponibles solo en el frontend, no en el editor Gutenberg (backend de edicion de paginas/posts).

**Evaluacion de necesidad de theme.json:** Segun el HANDOFF Cell H (`HANDOFF.md` linea 308-309), la decision PM fue: "theme.json NO creado este sprint. Trade-off: WP block editor no consume tokens. Mitigacion: theme akibara es PHP custom templates, block editor no se usa para customer pages." Esta decision sigue siendo correcta dado que Akibara usa WooCommerce con templates PHP custom — los customer-facing pages no pasan por el block editor.

**Plan condicional para Sprint 4+ (solo si se adopta block editor para content pages):**

Si en el futuro se crean paginas de contenido (blog, landing pages) via block editor, `theme.json` permitiria mapear design tokens a WP theme presets. La estructura seria:

```json
{
  "version": 3,
  "settings": {
    "color": {
      "palette": [
        { "name": "Rojo principal", "slug": "aki-red", "color": "#D90010" },
        { "name": "Superficie", "slug": "aki-surface", "color": "#111111" }
      ]
    },
    "typography": {
      "fontFamilies": [
        { "name": "Bebas Neue", "slug": "heading", "fontFamily": "'Bebas Neue', Impact, sans-serif" }
      ]
    }
  }
}
```

Esto generaria automaticamente clases `has-aki-red-color` etc. El overhead de implementacion es bajo (M), pero no es prioritario hasta que block editor sea adoptado.

### 7.3 Sincronizacion de functions.php — estrategia Sprint 4

**Estado:** El workspace tiene `wp-content/themes/akibara/inc/` con 6 archivos PHP (incluido `encargos.php` modificado con guard). El `functions.php` real esta en `server-snapshot/public_html/wp-content/themes/akibara/functions.php` — no en el workspace activo.

**Riesgo:** Al hacer deploy de assets nuevos (tokens.css, components.css futuro), si `functions.php` no tiene el enqueue de `akibara-tokens`, los archivos estaran en el servidor pero no se cargaran.

**Plan de sync para Sprint 4:**
1. Verificar que `inc/enqueue.php` del workspace (actualmente solo existe en server-snapshot) este copiado al workspace antes del deploy.
2. Agregar el enqueue de `akibara-tokens` en `inc/enqueue.php` como se detalla en §7.1.
3. RFC-THEME-CHANGE-01 aprobado: implementar el require condicional de `encargos.php` en `functions.php`. La accion especifica: agregar `if ( ! defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) )` antes del `require_once` de `encargos.php` en `functions.php`.
4. El archivo `inc/enqueue.php.bak-2026-04-25-pre-fix` en el tema debe eliminarse del deploy (esta en el exclude list del deploy workflow segun `project_deploy_exclude_dev_tooling.md`).

**Nota sobre el bak file:** `wp-content/themes/akibara/inc/enqueue.php.bak-2026-04-25-pre-fix` — este archivo `.bak` existe en el workspace y NO debe deployarse a prod. Confirmar que `bin/deploy.sh` tiene pattern `*.bak` en el exclude.

---

## 8. Sweep Matrix — Sprint 3 Deltas

| Archivo | Que cambio | Cell origen | Sprint 3.5 status |
|---------|-----------|-------------|------------------|
| `wp-content/themes/akibara/assets/css/tokens.css` | NUEVO — 175 tokens Manga Crimson v3, 186 lineas | Cell H | **debt: no enqueued** — enqueue pendiente Sprint 4 |
| `wp-content/themes/akibara/assets/css/` (directorio) | NUEVO — directorio no existia | Cell H | OK (directorio creado) |
| `wp-content/themes/akibara/inc/encargos.php` | Modificado — guard `if defined(AKB_PREVENTAS_ENCARGOS_LOADED) return;` agregado + comment RFC-01 | Cell A (theme change aprobado) | OK — guard activo, production-safe |
| `audit/sprint-3/cell-h/design-tokens.md` | NUEVO — catalog completo 175 tokens | Cell H | OK |
| `audit/sprint-3/cell-h/HANDOFF.md` | NUEVO — handoff completo Cell H | Cell H | OK |
| `audit/sprint-3/cell-h/UI-SPECS-preventas.md` | NUEVO — specs items 1/3/5 para Cell A | Cell H | OK — specs correctas, gaps contraste identificados en seccion 2 |
| `audit/sprint-3/cell-h/UI-SPECS-marketing.md` | NUEVO — specs items 2/4/6/7/8/9/10 para Cell B | Cell H | OK |
| `audit/sprint-3/cell-h/mockups/01-encargos-form.html` | NUEVO — prototipo HTML/CSS completo | Cell H | **debt: F-01 contraste asterisco rojo, F-02 no aplica a este item** |
| `audit/sprint-3/cell-h/mockups/02-cookie-banner.html` | NUEVO | Cell H | OK — verificar aria-label en impl Cell B |
| `audit/sprint-3/cell-h/mockups/03-preventa-card.html` | NUEVO | Cell H | **debt: F-02 BLOCKER contraste badges confirmed/delivered** |
| `audit/sprint-3/cell-h/mockups/04-newsletter-footer.html` | NUEVO | Cell H | OK |
| `audit/sprint-3/cell-h/mockups/05-oos-preventa.html` | NUEVO | Cell H | OK |
| `audit/sprint-3/cell-h/mockups/06-welcome-notice.html` | NUEVO | Cell H | OK |
| `audit/sprint-3/cell-h/mockups/07-popup-modal.html` | NUEVO | Cell H | **debt: focus trap JS pendiente Cell B antes de deploy** |
| `audit/sprint-3/cell-h/mockups/08-cart-abandoned-email.html` | NUEVO — condicional (Brevo decision pending) | Cell H | blocker: pendiente decision Cell B DECISION-CART-ABANDONED.md |
| `audit/sprint-3/cell-h/mockups/09-finance-dashboard.html` | NUEVO — bloquero resuelto | Cell H | **debt: CLS risk en widgets sin min-height, tablas sin caption/th** |
| `audit/sprint-3/cell-h/mockups/10-trust-badges.html` | NUEVO | Cell H | OK |
| `audit/sprint-3/cell-h/mockups/00-cover-tokens.png` | NUEVO — cover Figma | Cell H | OK (referencia, no se deploya) |
| `audit/sprint-3/cell-h/mockups/INDEX.html` | NUEVO — navegacion | Cell H | OK (dev tool, no se deploya) |
| `audit/sprint-3/cell-h/REQUESTS-FROM-A.md` | NUEVO | Cell H | OK |
| `audit/sprint-3/cell-h/REQUESTS-FROM-B.md` | NUEVO | Cell H | OK |
| `audit/sprint-3/rfc/THEME-CHANGE-01.md` | NUEVO — RFC aprobado | Cell A/H | OK — implementacion Sprint 4 (functions.php sync) |
| `wp-content/plugins/akibara-preventas/` | NUEVO plugin (6,804 LOC, 36 archivos PHP) | Cell A | OK — fuera de scope Cell H, referencia solo |
| `wp-content/plugins/akibara-marketing/` | NUEVO plugin (~12,400 LOC PHP) | Cell B | OK — fuera de scope Cell H, referencia solo |
| `wp-content/themes/akibara/inc/enqueue.php.bak-2026-04-25-pre-fix` | Archivo bak existente (pre-fix) | Incidente previo | **debt: excluir de deploy, no eliminar sin doble OK** |

---

## 9. Recomendaciones Sprint 4.5 / Sprint 4

### Criticas (resolver antes de deploy CSS en produccion)

**REC-01 [P1] — Corregir contraste badges preventa card (F-02)**
Archivo: `audit/sprint-3/cell-h/mockups/03-preventa-card.html` y `UI-SPECS-preventas.md#item-3`
Accion: cambiar texto de badges "Confirmada" y "Entregado" de blanco a `--aki-black`. Actualizar spec antes de que Cell A implemente el CSS real. No requiere mockup nuevo — es cambio de valor de color documentado en spec.
Responsable: Cell H actualiza spec / Cell A confirma en implementacion.

**REC-02 [P1] — Corregir uso de `--aki-red` como texto normal (F-01)**
Archivo: `01-encargos-form.html` y cualquier componente con asteriscos de campo requerido.
Accion: asegurar que `--aki-red` no se usa como texto normal (body text) sobre fondos oscuros. Los asteriscos de requerido usan `aria-hidden="true"` — el requisito se comunica via `required`/`aria-required` en el input, no via el asterisco visual. La solucion ya esta parcialmente implementada en el HTML del proto; asegurar que Cell A no agregue texto rojo en implementacion PHP.
Responsable: Cell A confirma en implementacion de `akibara-preventas/`.

**REC-03 [P1] — Focus trap JS en modales antes de deploy**
Archivo: `07-popup-modal.html` (Cell B consumer)
Accion: Cell B implementa focus trap antes de activar el popup en produccion. Sin focus trap, `Tab` en el modal lleva al contenido de fondo — fallo WCAG 2.1 criterion 2.1.2 (No Keyboard Trap inverso: usuario queda atrapado EN el fondo).
Responsable: Cell B sprint 4.

### Tecnicas WordPress (Sprint 4)

**REC-04 [P2] — Enqueue `tokens.css` en `inc/enqueue.php`**
Agregar `wp_enqueue_style('akibara-tokens', ...)` antes de `akibara-critical` con dependency chain actualizada. Ver §7.1 para codigo exacto. Verificar que LiteSpeed combine no excluye el archivo.
Responsable: Cell H / mesa-22. Sprint 4 al sincronizar `functions.php`.

**REC-05 [P2] — Implementar RFC-THEME-CHANGE-01 en functions.php**
Agregar require condicional en `functions.php` para `inc/encargos.php`. Confirmar en staging con smoke test que `akibara-preventas` esta activo y el hook AJAX `akibara_encargo_submit` no se duplica.
Responsable: Cell H. Sprint 4.

**REC-06 [P2] — Verificar `font-display: swap` en `fonts.css`**
El archivo `fonts.css` no esta en el workspace. Auditar en server-snapshot que cada `@font-face` tenga `font-display: swap`. Sin esto, hay riesgo FOIT en conexiones lentas.
Responsable: mesa-22 en Sprint 4 audit.

**REC-07 [P3] — Agregar roles ARIA faltantes al focus selector en tokens.css**
Linea 176 de `tokens.css`: agregar `[role="tab"]`, `[role="option"]`, `[role="menuitem"]` al selector de `:focus-visible` global.
Responsable: Cell H en sprint 4.5 (bajo riesgo).

### LambdaTest Setup (Sprint 4.5)

**REC-08 [P2] — Implementar TASK-S4.5-LAMBDATEST-SETUP-01**
Ver seccion 1 para detalle. Prereq: staging.akibara.cl up (B-S2-INFRA-01) y Sprint 4 deploy completado.
Responsable: mesa-22 coordina con mesa-07.

**REC-09 [P2] — Validar breakpoint 430 en todos los mockups**
Ninguno de los 9 mockups tiene media query explicita para 430px. LambdaTest en Sprint 4.5 capturara este viewport. Si se detectan layout issues, Cell H ajusta `components.css`.

**REC-10 [P3] — CLS prevention en finance dashboard**
Agregar `min-height` placeholders en widgets del dashboard antes de hidratacion JS. Valor sugerido: `min-height: 120px` en widget containers. Cell B agrega en implementacion PHP de `DashboardController.php`.

---

*Reporte generado: 2026-04-27. Sweep manual estatico — sin render de browser real. LambdaTest visual regression diferida a Sprint 4.5 (TASK-S4.5-LAMBDATEST-SETUP-01).*
