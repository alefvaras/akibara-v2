# Decisión: customer-milestones module — DEFERRED Sprint 5+

**Sprint:** 3
**Fecha decisión:** 2026-04-27
**Decidido por:** Akibara Owner (validado con evidence Brevo panel)

---

## Decisión

**DIFERIR módulo `customer-milestones/` a Sprint 5+.** Lifted desde server-snapshot por Cell B en Sprint 3, pero NO activable hasta que las pre-condiciones Brevo + customer base se cumplan.

## Evidencia (Chrome MCP — Brevo panel)

Panel Brevo Automatizaciones (https://app.brevo.com/automation/automations) hoy 2026-04-27:

| Automatización | Estado | Última modificación |
|---|---|---|
| Carrito abandonado #1 | 🟢 Activo | 2026-04-27 02:41 |

**Total automatizaciones creadas: 1.** No existen automatizaciones para:
- Birthday / cumpleaños trigger
- Anniversary / aniversario primera compra
- Welcome series formal (no automation, solo transactional template "BIENVENIDA")
- Re-engagement / dormant customers
- Milestone purchases (5°, 10°, 20° compra)

## Por qué NO crear las automatizaciones ahora

Per memoria `project_audit_right_sizing.md`: **"Tienda con 3 clientes. Re-evaluar scope cuando >50 clientes/mes."**

ROI análisis:
- **Birthday/aniversario** asume base de clientes con `_billing_birthday` meta poblado. Akibara: 29 contactos Brevo, ~3 customers reales con compras. Cumpleaños emails a 3 personas/año = ROI cero.
- **Welcome series** asume conversion uplift sobre nuevos signups. Akibara: ~0.1 signup/día actual. No hay volume para validar conversion lift.
- **Milestone purchases** asume long-tail de customers repeat. Akibara: customers únicos en mayoría, no hay long-tail.

Per memoria `feedback_no_over_engineering.md`: "abstrair solo cuando 2+ casos concretos lo justifiquen". customer-milestones automation NO tiene casos concretos justificados con la data actual.

## Estado del código Cell B

`wp-content/plugins/akibara-marketing/modules/customer-milestones/module.php` — lifted desde server-snapshot, presente en `$modules` array de `akibara-marketing.php`.

**Acción Sprint 3.5:** Comentar/excluir del loader hasta Sprint 5+ que se decida activar.

```php
// En akibara-marketing.php línea ~174:
'customer-milestones/module.php', // DEFERRED Sprint 5+ (ver DECISION-CUSTOMER-MILESTONES.md)
```

Opciones:
- **A.** Comentar el include con deprecation note (preferida — preserva código, evita load)
- **B.** Mover a `audit/sprint-3/cell-b/legacy-deferred/customer-milestones/`
- **C.** Dejar loaded pero las funciones no se ejecutan sin `_billing_birthday` data — silencioso

**Recomendación: A** — Sprint 3.5 implementation, simple comment, reactivar fácil en Sprint 5+.

## Pre-condiciones para activar Sprint 5+

Antes de activar customer-milestones en producción:

1. **Customer base ≥50/mes** (per memoria right-sizing threshold)
2. **`_billing_birthday` populated** en checkout/account form (Cell A puede agregarlo si justified)
3. **Crear automatizaciones Brevo** en panel:
   - Birthday trigger (date attribute = today)
   - Anniversary first-purchase (date attribute = year ago)
   - Optional: milestone 5°/10° purchase (count trigger)
4. **Capturar template IDs** Brevo y poner en config WP option `akb_marketing_customer_milestones_templates`
5. **A/B test** subject lines + send timing antes de full rollout

## Decisión cart-abandoned ya cubierta en archivo separado

Ver [DECISION-CART-ABANDONED.md](DECISION-CART-ABANDONED.md) — Brevo upstream "Carrito abandonado" #1 firing confirmado en panel + Gmail evidence. cart-abandoned legacy DEPRECATED (no loaded).

## Aprobación

- ✅ **Akibara Owner** — 2026-04-27 (decisión directa, evidence Chrome MCP Brevo panel)
- ✅ **Sprint 3 plan** — alineado con memoria `project_audit_right_sizing.md` y `feedback_no_over_engineering.md`
