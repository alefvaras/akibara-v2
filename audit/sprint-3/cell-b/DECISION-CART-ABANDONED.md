# Decisión: cart-abandoned module — DEPRECATED

**Sprint:** 3
**Fecha decisión:** 2026-04-27
**Decidido por:** Akibara Owner (validado con evidencia Gmail MCP)

---

## Decisión

**DEPRECAR el módulo legacy `cart-abandoned/` (539 LOC).** NO se migra. NO se carga.

## Evidencia

Búsqueda Gmail MCP `from:akibara newer_than:30d` confirma Brevo upstream firing activo:

| Fecha | Sender | Subject | Destinatario |
|---|---|---|---|
| 2026-04-27 13:30 | contacto@akibara.cl (Brevo SMTP) | "Alejandro, ¿olvidaste 'The Climber 3 – Milky Way' en tu carrito?" | alejandro.fvaras@gmail.com (prod customer) |
| 2026-04-24 11:30 | contacto@akibara.cl | "Manga lover, ¿olvidaste 'Naruto 72 – Panini Argentina' en tu carrito?" | alejandro.fvaras+synthetic@gmail.com |
| 2026-04-24 04:21 | contacto@akibara.cl | "[T-brevo-3] ¿Olvidaste algo? — Akibara" | template T-brevo-3 visible |
| 2026-04-22 19:45 + 21:13 | contacto@akibara.cl | "Alejandro, ¿olvidaste 'Naruto 72 – Panini Argentina'..." | secuencia 24h+48h confirmada |

**Conclusión:** Brevo automation upstream "Abandoned Cart" está disparando emails en producción correctamente. El sender es `contacto@akibara.cl` (Brevo SMTP), template tag `T-brevo-3` visible. La secuencia 24h-48h post-abandono opera por sí sola via Brevo automation engine.

## Estado del código

`wp-content/plugins/akibara-marketing/akibara-marketing.php` (líneas 178-180) — Cell B ya excluyó el módulo del loader:

```php
// cart-abandoned: DEPRECATED — Brevo upstream covers this natively.
// Module file exists at modules/cart-abandoned/module.php (preserved for audit trail).
// See HANDOFF.md §Decisión cart-abandoned for full rationale.
```

El array `$modules` NO incluye `cart-abandoned/module.php`. Files preservados en disco para audit trail / rollback opcional.

## Acciones requeridas Sprint 3.5

1. **Confirmar** que `wp-content/plugins/akibara-marketing/modules/cart-abandoned/` no se carga en staging (smoke test).
2. **Verificar Brevo dashboard** que la automation "Abandoned Cart" sigue activa post-deploy Sprint 3.
3. **Optional cleanup Sprint 4+:** mover los 539 LOC legacy a `audit/sprint-3/cell-b/legacy-deprecated/cart-abandoned/` o eliminar completamente. Trade-off:
   - Mover: preserva audit trail, no ocupa disk en plugin
   - Eliminar: limpieza completa, audit trail vive en git history
   - **Recomendación: mover en Sprint 4** (decisión propietario)

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| Brevo automation "Abandoned Cart" se desactiva en panel sin saberlo | Sprint 3.5 valida activation status. Sprint 4 considera monitoring alert |
| Brevo cambia template T-brevo-3 sin sincronizar con código | Email sin akibara branding = customer extrañeza. Mitigación: copy chileno está en Brevo template, no en código |
| Brevo upstream se cae | Brevo SLA 99.9%. Si cae prolongado, considerar reactivar legacy en RFC futuro |

## Aprobación

- ✅ **Akibara Owner** — 2026-04-27 (decisión directa basada en evidencia Gmail)
- ✅ **mesa-09-email-qa** — implícita (módulo legacy era subset de Brevo capabilities)
- ✅ **Sprint 3 plan original** — alineado con memoria `project_brevo_upstream_capabilities.md` ("Brevo Abandoned Cart upstream activo en cuenta Akibara. NO rebuild de features que Brevo cubre nativo")
