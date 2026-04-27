# CORE-CHANGE-REQUEST {NN}

**Cell origen:** A | B | C | D | E
**Sprint:** 3 (o 4)
**Solicitante:** mesa-{NN}-{role}
**Status:** PENDING | APPROVED | REJECTED | DEFERRED
**Fecha:** YYYY-MM-DD

---

## Problema

¿Qué necesita la cell que el Core actualmente no provee?

## Workaround disponible

¿Puede la cell continuar sin este cambio? ¿Costo del workaround en horas/complejidad?

## Cambio propuesto

API exacta:

- Namespace: `Akibara\Core\<...>`
- Method signature: `public function get<X>(int $id): <Type>`
- Constants: `AKB_NEW_CONST = 'value'`
- Hooks: `do_action('akibara_<event>', $payload)` con priority X

## Impact analysis

- ¿Otras cells afectadas?
- ¿Backward compat preservada?
- ¿Tests required en core?
- ¿Migration path para data existente?

## Ejemplo de consumo

```php
// Desde cell A:
$bootstrap = \Akibara\Core\Bootstrap::instance();
$value = $bootstrap->services()->get('mi.servicio')->getX($id);
```

## Decision (mesa-15 + mesa-01)

**Status:** _PENDING_

Razón aprobado/rechazado:
