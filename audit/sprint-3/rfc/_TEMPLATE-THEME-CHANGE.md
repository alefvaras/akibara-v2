# THEME-CHANGE-REQUEST {NN}

**Cell origen:** A | B | C | D | E
**Sprint:** 3 (o 4)
**Solicitante:** mesa-{NN}-{role}
**Status:** PENDING | APPROVED | REJECTED | DEFERRED
**Fecha:** YYYY-MM-DD

---

## Problema

¿Qué necesita la cell del theme `themes/akibara/` que actualmente no provee?

## Workaround disponible

¿Puede la cell continuar sin este cambio?

## Cambio propuesto

- Hook nuevo: `do_action('akibara_theme_<event>')`
- Filter nuevo: `apply_filters('akibara_theme_<filter>', $value)`
- Template part nuevo: `template-parts/<addon>/<part>.php`
- CSS class hook: `.akb-<addon>-<element>`

## Mockup requerido

¿Necesita Cell H provee mockup antes de implementar?

- [ ] Sí — adjuntar specs en `audit/sprint-3/cell-h/`
- [ ] No — change es non-visual

## Impact analysis

- ¿Visual regression risk? (LambdaTest cubrirá)
- ¿Performance impact? (theme hooks fire on every page)
- ¿Accessibility?

## Decision (mesa-15 + mesa-01 + Cell H)

**Status:** _PENDING_
