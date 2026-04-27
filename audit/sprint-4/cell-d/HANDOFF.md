# Cell D — akibara-whatsapp HANDOFF

**Sprint:** 4 paralelo
**Branch:** `feat/akibara-whatsapp`
**Commit hash:** `adcd49a`
**Fecha:** 2026-04-26
**Status:** DONE — listo para merge review

---

## Files refactored

| File | Acción | LOC |
|---|---|---|
| `wp-content/plugins/akibara-whatsapp/akibara-whatsapp.php` | NUEVO (reemplaza snapshot) | 141 |
| `wp-content/plugins/akibara-whatsapp/src/Plugin.php` | NUEVO | 462 |
| `wp-content/plugins/akibara-whatsapp/src/index.php` | NUEVO (security silence) | 1 |
| `wp-content/plugins/akibara-whatsapp/akibara-whatsapp.css` | COPIADO sin cambios | 145 |
| `wp-content/plugins/akibara-whatsapp/akibara-whatsapp.js` | COPIADO sin cambios | 88 |
| `wp-content/plugins/akibara-whatsapp/index.php` | COPIADO sin cambios | 1 |
| `tests/e2e/critical/whatsapp-button.spec.ts` | NUEVO | 99 |

**LOC total archivos modificados/creados:** 604 (excluyendo assets copiados sin cambios).
**Archivo original v1.3.1:** 396 LOC (single file monolítico).
**Split:** entry 141 LOC + Plugin 462 LOC = separación de responsabilidades.

---

## AddonContract implementado

```
Akibara\WhatsApp\Plugin implements AddonContract
  ├── manifest(): AddonManifest  → slug 'akibara-whatsapp', version '1.4.0', type 'addon'
  └── init(Bootstrap $bootstrap) → registra 'whatsapp.number' en ServiceLocator
```

Patrón idéntico a Cell A (`Akibara\Preventas\Plugin`) y Cell B (`Akibara\Marketing\Plugin`).
Bootstrap::register_addon() envuelve init() en per-addon try/catch — falla de este addon
NO crashea el sitio ni afecta otros addons.

---

## ServiceLocator integration

`bootstrap->services()->register('whatsapp.number', fn() => akibara_whatsapp_get_business_number())`

Esto expone el número de negocio como servicio consumible por otros addons sin depender
de la función global (la función sigue existiendo para backward compat con el tema).

---

## Preservado intacto

- `akibara_whatsapp_get_business_number()` — función global, mismo contrato, mismo default `56944242844`
- `akibara_wa_phone()` y `akibara_wa_url()` — helpers de tema registrados en `after_setup_theme`
- Float button `wa.me/<número>` — CSS y JS sin modificar
- Admin panel WooCommerce → WhatsApp — mismo form, misma opción `akibara_whatsapp`
- CTA email order confirmation — misma lógica, movida a `Plugin::inject_order_email_cta()`
- HPOS compatibility declaration — preservada, movida a group wrap

---

## Tests Playwright @critical

Archivo: `tests/e2e/critical/whatsapp-button.spec.ts`

Tests escritos (3 casos):
1. Botón visible en página de producto — verifica presencia + visibilidad post-delay + URL wa.me
2. Botón visible en home — verifica mismos checks en homepage
3. Número default 56944242844 — verifica patrón `^56\d{8,9}$`

**Estado ejecución:** tests no corrieron en esta sesión (Playwright no instalado en
entorno local — requiere `npm install` + `npx playwright install` browsers). El archivo
está listo para pipeline CI. Los tests corren en GHA quality-gate workflow tag `@critical`.

Comando cuando pipeline disponible:
```bash
npm run test:e2e:critical -- --grep '@critical WhatsApp'
```

---

## Dependencias Cell H

Per CELL-DESIGN: Cell H provee mockups "WhatsApp button placement variants mobile vs desktop".
**Estado actual:** no recibimos mockups de Cell H en esta ronda. El CSS del botón está
sin cambios (posición derecha, offset 20px, bottom 76px mobile con safe-area-inset).

Si Cell H entrega mockup con placement diferente → cambio va en `akibara-whatsapp.css`
únicamente (sin tocar PHP). Riesgo de regresión: bajo.

---

## Notas arquitecturales

**Clase renombrada:** `Akibara_WhatsApp` → `Akibara_WhatsApp_Controller`. El nombre
original colisionaba con el namespace `Akibara\WhatsApp` (PSR-4 reserva ese prefijo).
Backward compat: la clase anterior no era pública API — nadie la instancia externamente.

**Single-file src/:** Plugin.php contiene Plugin + Akibara_WhatsApp_Controller en el
mismo archivo. Decisión YAGNI — el plugin tiene 1 responsabilidad y no justifica split
adicional de archivos en src/ (per `feedback_no_over_engineering.md`).

**PHP syntax check:** ambos archivos PHP pasaron `php -l` via Docker PHP 8.3 en
instancia local (output: "No syntax errors detected").

---

## Pre-commit hooks

Todos pasaron en el commit `adcd49a`:
- grep-voseo: sin voseo rioplatense detectado
- grep-secrets: sin secrets detectados
- grep-claims: sin claims absolutos detectados

---

## Para merge en Sprint 4.5

1. `git merge feat/akibara-whatsapp` en main post Sprint 4.5 Lock
2. Deploy runbook: activar plugin en staging primero (`bin/wp-ssh plugin activate akibara-whatsapp`)
3. Smoke: verificar botón visible en prod producto + home
4. Sentry 24h check post-deploy
5. Playwright @critical con `--grep '@critical WhatsApp'` contra staging

**Riesgo de regresión:** bajo. Toda la funcionalidad visible (frontend, admin, email) es
código existente reorganizado — sin cambio de comportamiento observable para el cliente.
