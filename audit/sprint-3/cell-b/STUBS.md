# Cell B — Sprint 3 STUBS pendientes de mockup Cell H

**Fecha:** 2026-04-27
**Estado:** bloqueado en Cell H (Design Ops)

---

Estos modulos tienen logica de backend implementada y funcionando, pero su
interfaz de administracion o sus templates visuales estan como STUB hasta que
Cell H apruebe el diseno.

**Los modulos NO deben activarse en produccion hasta que el mockup este aprobado
y los templates/UI implementados.**

---

## STUB-01: customer-milestones — Admin UI + Templates Brevo

**Archivo:** `wp-content/plugins/akibara-marketing/modules/customer-milestones/module.php`
**Linea de aviso en admin:** `akb_milestones_render_admin()` muestra banner STUB

### Que esta implementado (backend):

- Birthday: campo `billing_birthday` (DD/MM) en checkout, guarda en `_akb_birthday` user meta
- Daily cron `akb_milestones_daily_check` a las 09:00 — verifica cumpleanos y aniversarios
- Dedup por año: `_akb_birthday_sent_year` y `_akb_anniversary_sent_year` user meta
- Anniversary: query `wc_get_orders` con `date_created = {-1year}`, primer pedido
- Envio via Brevo REST API con `templateId` (configurable desde admin)

### Que falta (Cell H):

1. **Template Brevo de cumpleanos** — diseno del email transaccional
   - Variables disponibles: `{{ params.NOMBRE }}`
   - Trigger: dia del cumpleanos del cliente
   - Tono: festivo, descuento de cumpleanos (si aplica)

2. **Template Brevo de aniversario** — diseno del email transaccional
   - Variables disponibles: `{{ params.NOMBRE }}`
   - Trigger: exactamente 1 año desde el primer pedido completado
   - Tono: agradecimiento, fidelizacion

3. **Admin UI refinada** — la UI actual es funcional pero sin estilos akibara
   - Campo template ID Brevo (ya existe, funcional)
   - Toggle activo/inactivo por tipo (ya existe, funcional)
   - Metricas: cuantos emails enviados este año (pendiente implementacion)

### Bloqueador:
No activar en produccion hasta que los templates Brevo esten creados y sus IDs
configurados en el admin.

---

## STUB-02: finance-dashboard — Render de widgets

**Archivo:** `wp-content/plugins/akibara-marketing/src/Finance/DashboardController.php`
**Metodo:** `DashboardController::render()` — marcado como STUB

### Que esta implementado (backend):

5 widgets con logica de datos completa:
- `TopSeriesByVolume` — series mas vendidas por volumen
- `TopEditoriales` — editoriales top por revenue
- `EncargosPendientes` — ordenes con productos de preventa pendientes
- `TrendingSearches` — terminos de busqueda trending
- `StockCritico` — productos con stock bajo umbral

### Que falta (Cell H):

Layout de la pagina de dashboard en el admin:
- Grid de widgets (sugerido: 2 columnas en desktop, 1 en mobile)
- Cards con titulos, metricas destacadas y tablas/listas
- Paleta de colores akibara (mismo dark theme del admin)
- Estado "sin datos" (empty state) para cada widget

### Nota tecnica:
El metodo `DashboardController::data()` retorna un array con todos los datos
de los 5 widgets listos para renderizar. El render puede ser PHP puro o con
un template parcial — Cell H decide.

---

## No hay mas stubs en Cell B

Los modulos `welcome-discount` y `descuentos` tienen admin UI completa (liftada
del snapshot). Los modulos `banner`, `popup`, `brevo`, `referrals`,
`marketing-campaigns`, `review-request`, `review-incentive` tienen UI completa.

---

*STUBS.md generado por Cell B, Sprint 3 Paralelo, 2026-04-27*
