# Sprint 2 Condition #5 — WC HPOS Status Verification

**Fecha:** 2026-04-27
**Comando:** `bin/wp-ssh wc hpos status`
**Origen:** ARCHITECTURE-PRE-REVIEW.md §5 (YAGNI gate antes HPOSFacade implementation)

---

## Output prod

```
¿HPOS activado?: yes
¿Modo de compatibilidad activado?: no
Pedidos no sincronizados: 30
Pedidos sujetos a limpieza: 1
```

**Interpretación:**
- ✅ HPOS está activado y operacional
- ⚠️ Compatibility mode OFF — HPOS es authoritative; legacy postmeta no se sincroniza
- 30 órdenes solo en HPOS tables (no replicadas a postmeta)
- 1 orden con cleanup pendiente (ruido normal)

**Implicación técnica:** código que use `get_post_meta($order_id, $key)` directamente NO funciona contra órdenes WC en prod. Modules deben usar `wc_get_order()` + `$order->get_meta()` per WC API.

---

## Decisión Cell Core scope

**Gate PRE-review §5 PASSES:** HPOS activo → prerequisite cumplido para implementar HPOSFacade.

**PERO** aplicando YAGNI (memoria `feedback_no_over_engineering`):

### Análisis 13 módulos foundation vs orders coupling

| Módulo | Toca órdenes WC? | HPOSFacade needed? |
|---|---|---|
| search | NO (products only) | NO |
| category-urls | NO | NO |
| order (catálogo) | NO (catalog ordering, not WC orders) | NO |
| email-template | Indirect (consumed by addons emails) | NO en scope core |
| email-safety | NO (wp_mail filter) | NO |
| rut | Light (`update_order_meta` checkout) | Minimal helper |
| phone | Light (same as rut) | Minimal helper |
| product-badges | NO | NO |
| address-autocomplete | NO | NO |
| customer-edit-address | NO (user meta only) | NO |
| checkout-validation | NO (validation hooks) | NO |
| health-check | Light (count orders) | Minimal helper |
| series-autofill | NO (post_meta producto) | NO |

**Conclusión:** ningún módulo foundation requiere abstracción amplia tipo `OrderFacade`. Solo 3 módulos (rut, phone, health-check) tocan orden de forma ligera, y pueden usar `wc_get_order()` directo.

### Decisión

**DEFER full HPOSFacade a Sprint 3** (Cell A preventas + Cell B marketing + Cell E mercadolibre — esos sí tocan orders pesado).

**Cell Core scope mínimo:**
- Si algún módulo necesita helpers, agregar `Akibara\Core\WC\OrderUtils` con 2-3 métodos statics (no full facade pattern):
  ```php
  namespace Akibara\Core\WC;

  final class OrderUtils {
      public static function get_meta( int $order_id, string $key ): mixed {
          $order = wc_get_order( $order_id );
          return $order ? $order->get_meta( $key ) : null;
      }
      public static function update_meta( int $order_id, string $key, mixed $value ): bool {
          $order = wc_get_order( $order_id );
          if ( ! $order ) return false;
          $order->update_meta_data( $key, $value );
          $order->save();
          return true;
      }
  }
  ```
- Si NO se requiere → omitir entirely.

**Trigger upgrade a HPOSFacade completo:** Sprint 3 Cell A/B/E necesitan abstracción más rica (OrderQuery wrapper, batch operations, status transitions). RFC en sprint-3/rfc/ si lo requieren.

---

## Risk register

| Riesgo | Severidad | Mitigación |
|---|---|---|
| Algún módulo escribe `update_post_meta($order_id, ...)` directo y rompe en HPOS | P1 | Smoke checkout post-extracción + grep adversarial pre-merge |
| Sprint 3 cells subestimen HPOSFacade complexity | P2 | RFC explícito en sprint-3/rfc/ + mesa-15 arbitration sprint 3.5 |

---

## Acción concreta

- ✅ Condition #5 marcada DONE
- 📋 Cell Core PR template debe incluir grep check: `grep -rn 'update_post_meta.*order_id' plugins/akibara-core/` → 0 antes de merge
- 📋 Sprint 3 RFC template incluir sección "HPOS coupling — funciones order-related que el addon necesita"

---

**FIN. Próximo: condition #2 (serialization plan 4 CLEAN destructivos) + condition #4 (sync-staging.sh).**
