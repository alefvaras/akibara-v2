# Cell H — Mockup Requests desde Cell A

**Sprint:** 3
**Fecha:** 2026-04-27
**Cell solicitante:** A (akibara-preventas)

---

## Request A-01: Encargos form styling

**Priority queue item:** #1 (Encargos checkbox styling)
**Urgencia:** Media — form funcional como stub. Bloquea Sprint 3.5 visual polish.
**Contexto:** Shortcode `[akb_encargos_form]` renderiza un form con clases `akb-field`, `akb-btn`, `akb-encargos-form`. Necesita:
- Estilos para estados campo: default, focus, error, success.
- Mensaje de feedback (`.akb-encargos-form__feedback`) styling.
- Responsive: single-column mobile, 2-col desktop.
- Consistent con sistema de diseño Manga Crimson v3.

**Deliverable esperado:**
- Mockup en `audit/sprint-3/cell-h/UI-SPECS-preventas.md` sección "Encargos form".
- CSS vars/tokens a usar (o nuevas a agregar a design tokens).
- No requiere nueva plantilla PHP — el HTML ya existe en el shortcode.

---

## Request A-02: Preventa card 4 estados

**Priority queue item:** #3 (Preventa card 4 estados)
**Urgencia:** Alta — afecta product pages (woocommerce_after_single_product hook).
**Contexto:** Widget `.akb-nv-widget` en next-volume module muestra el siguiente tomo recomendado. Tiene clase de estado `akb-nv-widget__status--{in_stock,preorder,out_of_stock}`. El widget necesita estados para las 4 etapas de una preventa en la tabla `wp_akb_preorders`:
- `pending` — esperando confirmación
- `confirmed` — confirmada por Akibara
- `shipping` — en camino desde distribuidora
- `delivered` — entregado al cliente

**Deliverable esperado:**
- Mockup 4 estados (puede ser Excalidraw/Photopea per workflow lightweight).
- CSS classes para cada estado + color/icono spec.
- Publicar en `audit/sprint-3/cell-h/UI-SPECS-preventas.md`.

---

## Request A-03: Auto-OOS "fecha por confirmar"

**Priority queue item:** #5 (Auto-OOS preventa "fecha por confirmar")
**Urgencia:** Baja — texto funcional. Solo polish visual.
**Contexto:** Cuando admin no asigna `expected_date` a una preventa, el frontend muestra "Fecha por confirmar". Necesita un tratamiento visual diferente a "Fecha: DD/MM/YYYY".
**Deliverable esperado:**
- Badge/pill spec (color, border, icono reloj/interrogación).
- CSS class name a usar en la plantilla.
- Puede ir en misma sección que A-02.

---

## Notas para Cell H

- Stubs documentados en `audit/sprint-3/cell-a/STUBS.md`.
- Cell A puede seguir sin estos mockups — todos son Sprint 3.5 visuals.
- Prioridad: A-02 > A-01 > A-03 (por impacto en producto visible al cliente).
