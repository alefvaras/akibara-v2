# Cell A — UI Stubs pendientes Cell H

**Sprint:** 3
**Fecha:** 2026-04-27
**Estado:** Stubs temporales activos — esperan mockup de Cell H

Estos stubs son marcadores en el código que indican UI incompleta.
Cell H consolida en Sprint 3.5 una vez que entregue mockup aprobado.

---

## ENC-01 — Encargos form styling

**Archivo:** `wp-content/plugins/akibara-preventas/modules/encargos/module.php` (shortcode `akb_encargos_shortcode()`)
**Stub:** El formulario usa clases CSS genéricas (`akb-field`, `akb-btn`, `akb-encargos-form`) con estilos del tema. No tiene tokens de diseño propios ni estado visual diferenciado.
**Mockup requerido:** "Encargos checkbox styling" (item #1 en Cell H priority queue).
**Qué falta:**
- Estado visual de los campos (focus, error, success).
- Mensaje de feedback styling.
- Responsive layout del form.
**Impacto si no llega:** Funciona, se ve genérico. Sin regresión funcional.

---

## PRE-01 — Preventa card 4 estados

**Archivo:** `wp-content/plugins/akibara-preventas/modules/next-volume/module.php` — función `akibara_next_volume_widget_render()`
**Stub:** El widget `.akb-nv-widget` usa clases bare (`akb-nv-widget__status--{in_stock,preorder,out_of_stock}`) sin colores definitivos del sistema.
**Mockup requerido:** "Preventa card 4 estados" (item #3 en Cell H priority queue).
**Estados a diseñar:**
- `pending` — reserva registrada, no procesada.
- `confirmed` — reserva confirmada por admin.
- `shipping` — en camino.
- `delivered` — entregado.
**Impacto si no llega:** Los 4 estados muestran distintos textos pero sin diferenciación visual por color/icono.

---

## OOS-01 — Auto-OOS preventa "fecha por confirmar"

**Archivo:** `wp-content/plugins/akibara-preventas/includes/class-reserva-frontend.php`
**Stub:** Cuando `expected_date` es null, se muestra "Fecha por confirmar" como texto plano.
**Mockup requerido:** "Auto-OOS preventa fecha por confirmar" (item #5 en Cell H priority queue).
**Qué falta:** Badge/pill visual para distinguir "fecha confirmada" vs "fecha pendiente".
**Impacto si no llega:** Texto funciona. Sin diferenciación visual.

---

## Notas operacionales

- Todos los stubs son additive: agregar clases CSS cuando Cell H entregue no rompe lo actual.
- ENC-01 es el más visible para el cliente final (formulario encargos).
- PRE-01 y OOS-01 afectan product pages — LambdaTest visual debe cubrir ambos post-mockup.
