# Solicitudes de Cell B → Cell H (Design Ops)

**Fecha:** 2026-04-27
**Origen:** Cell B (akibara-marketing plugin)
**Destinatario:** Cell H (Design Ops / UX)

---

## REQUEST-B-01: Templates Brevo — Customer Milestones

**Prioridad:** Media (modulo OFF por defecto, no bloquea lanzamiento)
**Modulo:** `customer-milestones/module.php`
**Backend:** Listo y funcionando

### Email 1: Cumpleanos del cliente

**Trigger:** Dia del cumpleanos del cliente (campo `billing_birthday` DD/MM)
**Variables disponibles:**
```
{{ params.NOMBRE }}  — nombre del cliente
```

**Requerimientos:**
- Tono festivo, personalizado
- Puede incluir descuento de cumpleanos (CTA a tienda)
- Branding Akibara (dark, colores manga)
- Asunto sugerido: "¡Feliz cumpleanos, [NOMBRE]! Un regalo te espera en Akibara"

### Email 2: Aniversario del cliente (1 año)

**Trigger:** 1 año exacto desde el primer pedido completado
**Variables disponibles:**
```
{{ params.NOMBRE }}  — nombre del cliente
```

**Requerimientos:**
- Tono de agradecimiento y fidelizacion
- Puede mencionar cuanto tiempo llevan juntos
- CTA a novedades o wishlist
- Asunto sugerido: "1 año contigo, [NOMBRE] — gracias por ser parte de Akibara"

**Entregable esperado de Cell H:**
- IDs de template Brevo creados en la cuenta (Cell H coordina con dueno tienda)
- Los IDs se configuran en WC → Milestones en el admin

---

## REQUEST-B-02: Layout dashboard finance-dashboard

**Prioridad:** Baja (dashboard interno, no visible a clientes)
**Modulo:** `src/Finance/DashboardController.php`
**Backend:** Listo y funcionando

### Datos disponibles (ya calculados, listos para renderizar)

```php
$data = [
    'top_series'          => [ ['title' => '...', 'qty' => N, 'revenue' => N], ... ],
    'top_editoriales'     => [ ['name' => '...', 'revenue' => N, 'orders' => N], ... ],
    'encargos_pendientes' => [ ['order_id' => N, 'title' => '...', 'qty' => N], ... ],
    'trending_searches'   => [ ['term' => '...', 'count' => N], ... ],
    'stock_critico'       => [ ['title' => '...', 'stock' => N, 'sku' => '...'], ... ],
];
```

**Requerimientos del layout:**
- Grid 2 columnas en desktop, 1 en mobile
- Cards con titulo del widget + tabla/lista de items
- Estado "sin datos" para cada card (tabla vacia o mensaje)
- Paleta dark del admin Akibara (consistente con otros paneles)
- No necesita graficos — tablas simples son suficientes

**Entregable esperado de Cell H:**
- Mockup (puede ser CSS prototype o Excalidraw/Preview annotate)
- Una vez aprobado el layout, Cell B implementa el PHP render

---

*Solicitudes generadas por Cell B, Sprint 3 Paralelo, 2026-04-27*
