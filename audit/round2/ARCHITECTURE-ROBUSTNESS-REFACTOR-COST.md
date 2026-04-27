---
agent: code-reviewer (mesa-02-tech-debt)
type: refactor cost analysis
date: 2026-04-26
question: ¿Cuál arquitectura plugin es MÁS ROBUSTA? Costo real de cada migración.
recommendation: Opción 1 (status quo) — cost/benefit no justifica refactor para 3 clientes y 1 dev
---

# Refactor cost analysis — arquitectura plugin Akibara

## Sección 1 — TL;DR cost analysis

**Pick: Opción 1 (status quo, 3 plugins separados).**

Razón en 1 párrafo: Opción 2 (split akibara) cuesta **120-180h** (4-6 semanas a capacity 25-30h/sem) para resolver un problema que NO existe (admin UX no se queja). Opción 3 (monolito) cuesta **80-120h** (3-4 semanas) y degrada la robustez actual al perder aislamiento de fallas (~4.4k LOC reservas mezclados con catálogo + checkout). Status quo cuesta **0h** y es el único plugin pattern probado en producción con 6 meses sin issue de boundaries. Para 3 clientes con 1 dev sin staging, ROI de cualquier refactor arquitectónico es **negativo** vs items reales del backlog (P0 security cleanup, growth-modules decisions, race conditions en preventas).

---

## Sección 2 — Cost breakdown por opción

### Opción 1: Status quo (3 plugins separados)

| Métrica | Valor |
|---|---|
| LOC tocadas | 0 |
| Esfuerzo total | 0 h |
| Tests rotos potenciales | 0 |
| DB migrations | 0 |
| Cron events re-registrar | 0 |
| Activation hooks tocados | 0 |
| Documentation update | mínima (BACKLOG cierra item con "no acción") |
| Regression risk | ZERO |
| Time to revert | N/A |

**Total: 0h, riesgo cero.** Es el baseline contra el cual se mide todo lo demás.

---

### Opción 2: Separar EN MÁS plugins (split akibara → core + payments + marketing + shipping + reservas + whatsapp)

Hipotético split del plugin `akibara` (35.667 LOC, 28 modules) en sub-plugins por dominio. Reservas y WhatsApp ya están separados — el split aplicaría solo al plugin grande.

#### Decomposition propuesta (hipotética)

| Sub-plugin | Modules incluidos | LOC aprox |
|---|---|---|
| akibara-core | brevo, ga4, health-check, rut, phone, banner, popup, descuentos | ~6.700 |
| akibara-marketing | marketing-campaigns, welcome-discount, referrals, back-in-stock, series-notify, review-request, review-incentive, cart-abandoned | ~9.500 |
| akibara-catalog | inventory, product-badges, series-autofill, next-volume, finance-dashboard | ~4.400 |
| akibara-shipping | shipping, address-autocomplete, customer-edit-address, checkout-validation | ~2.800 |
| akibara-mercadolibre | mercadolibre (4250 LOC) | ~4.250 |
| akibara (shell) | autoload, module registry, admin shell, AkibaraEmailTemplate | ~8.000 |

**Total: 6 nuevos plugins (5 split + 1 shell residual).**

#### Cost breakdown detallado

**(a) Activation hooks setup por nuevo plugin:** 6 plugins × ~50-100 LOC = **300-600 LOC nuevos**
- Cada uno necesita: register_activation_hook, register_deactivation_hook, dependency check (¿está akibara-core activo?), HPOS compatibility declaration

**(b) Cross-plugin contracts (NUEVO problema arquitectónico):**
- 78 cron event registrations encontradas → algunos crons cruzan dominios (ej: `akibara_brevo_weekly_sync` está en akibara-core pero invoca lógica de marketing-campaigns)
- 54 callsites de `AkibaraEmailTemplate` (clase compartida) → ahora vive en akibara-core, todos los demás necesitan `class_exists()` checks
- 8 callsites de `akb_ajax_endpoint()` → idem
- 25 callsites con `wp_akb_*` table refs → varios módulos cruzan tablas (marketing-campaigns lee `wp_akb_referrals`)
- 96 callsites de postmeta `_akb_*` → seguros (postmeta es global)

**(c) Update existing references (cross-plugin coupling actual):**
- 15 archivos de tema usan funciones expuestas por plugin akibara → seguros si se mantiene shell con autoload
- 120 callsites en mu-plugins a `akb_*` o `Akibara*` → cada uno requiere validar nuevo namespace
- ~40-60 callsites internos entre módulos (módulo X invoca función pública de módulo Y) → cada uno requiere `class_exists`/`function_exists` check

**(d) DB schema migration (custom tables move ownership):**
- Tablas custom existentes con `wp_akb_*` (10 confirmadas via CREATE TABLE):
  - `wp_akb_bis_subs` → akibara-marketing (back-in-stock)
  - `wp_akb_series_subs` → akibara-marketing (series-notify)
  - `wp_akb_referrals` → akibara-marketing (referrals)
  - `wp_akb_wd_subscriptions` → akibara-marketing (welcome-discount)
  - `wp_akb_wd_log` → akibara-marketing (welcome-discount)
  - `wp_akb_ml_items` → akibara-mercadolibre
  - `wp_akb_inventory_*` → akibara-catalog
  - `wp_akb_search_index` → akibara-core (search)
  - `wp_akb_unify_backup_202604` → migrar a akibara-catalog o drop
- **Ownership transfer:** dbDelta NO mueve tablas. La nueva arquitectura debe (a) NO recrear si existen, (b) en activation del nuevo plugin verificar exists antes de crear, (c) decidir si en deactivation del plugin viejo dropear (riesgo: data loss si el nuevo no se activa)

**(e) Cron events re-register:**
- 78 cron registrations identificadas → cada cron debe migrar al plugin que ahora "owns" el módulo. Activation hook nuevo plugin agrega, activation hook plugin viejo limpia. Si se equivoca el orden, jobs huérfanos
- **Riesgo concreto:** durante el deploy ventana, si akibara viejo se desactiva ANTES de activar los 5 nuevos, los 78 crons se borran (deactivation cleanup). Customer impact: emails de reservas no llegan, sync ML stop, weekly Brevo sync stop

**(f) REST endpoints re-register:**
- ~15 endpoints AJAX (`wp_ajax_akb_*`) entre módulos → cada uno migra al plugin que lo aloja. Cuidado con frontend JS que asume rutas estables

**(g) WP-CLI commands:**
- No detecté commands custom registrados → 0 LOC migration

**(h) Tests rewrite:**
- `plugins/akibara/tests/phpunit/` tiene PHPUnit setup compartido
- Cada nuevo plugin necesita: composer.json, phpunit.xml, autoload, bootstrap, mocks → 6 plugins × ~200 LOC config = **~1.200 LOC config**
- Tests existentes: ~5-10 archivos de tests, cada uno linked a 1-2 modules → reorganizar a su nuevo plugin

#### Suma esfuerzo Opción 2

| Tarea | Horas |
|---|---|
| Diseño split (decisión qué módulos van dónde) | 8 h |
| Crear 6 plugin shells + activation hooks + composer | 12 h |
| Mover modules a nuevos plugins (5 splits × ~3h promedio) | 15 h |
| Migrar referencias `AkibaraEmailTemplate` (54 callsites + add `class_exists` checks) | 8 h |
| Migrar referencias `akb_ajax_endpoint` (8 callsites + checks) | 2 h |
| Manejar 78 cron events (re-register + cleanup orden seguro) | 12 h |
| Custom tables ownership transfer (10 tablas + lógica idempotente) | 10 h |
| Cross-plugin dependencies management (40-60 callsites internos) | 16 h |
| Mu-plugins update (120 callsites a verificar) | 8 h |
| Tests rewrite + setup CI per plugin | 20 h |
| Smoke testing manual en local + staging si existiera (no existe) | 16 h |
| Deploy coordinado (ventana de mantención, customer comm) | 6 h |
| Bug fixing post-deploy (estimación conservadora 25% del esfuerzo total) | 30 h |
| Documentation update (CLAUDE.md, BACKLOG, CONTRIBUTING) | 6 h |

**Total: ~169 h** (~5-6 semanas a capacity 25-30h/sem)

| Métrica | Valor |
|---|---|
| LOC tocadas | ~1.500-2.500 (estimación: 6 shells nuevos + adapters + tests) |
| Esfuerzo total | **~169 h (4-6 semanas)** |
| Tests rotos potenciales | 100% (toda suite existente requiere rewrite) |
| DB migrations | 10 tablas con ownership transfer |
| Regression risk | **ALTO** (cada cron, hook priority, activation order es un punto de falla) |
| Time to revert if fails | 4-8 h (git revert + reactivar plugins viejos) si tablas NO se borraron; **>40h** si dropearon tablas |

---

### Opción 3: Unificar TODO (monolito akibara)

Mover `akibara-reservas` (4.423 LOC) y `akibara-whatsapp` (397 LOC) DENTRO del plugin `akibara`. Total a integrar: **4.820 LOC** + sus tablas/crons/hooks.

#### Cost breakdown detallado

**(a) LOC tocadas:**
- Mover `akibara-reservas/includes/` (~2.500 LOC) → `akibara/modules/reservas/`
- Mover `akibara-reservas/templates/` → `akibara/modules/reservas/templates/`
- Mover `akibara-whatsapp/akibara-whatsapp.php` (396 LOC) → `akibara/modules/whatsapp/module.php`
- Refactor adapters: namespace, autoload paths, asset paths, template paths
- **Total: ~4.820 LOC movidos + ~300 LOC adapters/refactors = ~5.120 LOC tocadas**

**(b) Activation hooks merge (~100-200 LOC):**
- Plugin akibara actual tiene activation simple (`akb_create_index_table` + flush rewrite). Activation reservas tiene 3 acciones (cron 15min, cron daily, rewrite endpoint `mis-reservas`). Activation whatsapp: 0 (solo register_uninstall_hook seguramente)
- Merged activation: ~50 LOC nuevas

**(c) Custom tables migration:**
- akibara-reservas usa **POSTMETA `_akb_*`** (no custom tables) — 96 callsites a postmeta. Postmeta es global, **no requiere migration**
- akibara-whatsapp NO tiene tablas
- **Net: 0 tablas a migrar.** Esto es el dato MÁS importante — la opción 3 es más barata de lo que parece porque reservas no tiene custom tables propias

**(d) Cron events merge:**
- `akb_reservas_check_dates` (cada 15 min)
- `akb_reservas_daily_digest` (diario)
- Re-registrar en activation del plugin akibara consolidado, limpiar en deactivation del plugin reservas viejo
- **~10 LOC cambio**

**(e) REST endpoints merge:**
- Endpoints AJAX de reservas (~3-5) movidos al plugin consolidado
- Custom rewrite endpoint `mis-reservas` (rewrite rule global, sobrevive)
- **~20 LOC**

**(f) Cross-plugin coupling resolution:**
- 54 callsites de `AkibaraEmailTemplate` desde reservas → ya no necesitan `class_exists` (mismo plugin) — **simplifica código**
- 20 callsites de `akibara_wa_*` desde theme/reservas → mantener globals como funciones públicas del plugin, **0 cambios**
- Activación coordinada elimina `function_exists` checks → **simplifica ~10 LOC**

**(g) Tests:**
- Tests reservas (si existen) → migrar a `tests/Unit/Reservas/`
- Tests whatsapp (no detecté) → 0
- Tests akibara existentes → verificar que reservas no rompe sus mocks
- **~8-12 h**

**(h) Theme + mu-plugins updates:**
- 15 callsites en theme a `akibara_wa_*` y `AkibaraEmailTemplate` → siguen funcionando si akibara plugin expone las mismas globals
- 120 callsites en mu-plugins a `akb_*`/`Akibara*` → la mayoría son a globals que sobreviven el merge

#### Suma esfuerzo Opción 3

| Tarea | Horas |
|---|---|
| Diseño merge (estructura modules/reservas/ + modules/whatsapp/) | 4 h |
| Mover archivos + ajustar paths/autoload | 12 h |
| Merge activation hooks + cron coordination | 4 h |
| Re-register endpoints AJAX + rewrite rules | 3 h |
| Eliminar `function_exists`/`class_exists` checks redundantes | 4 h |
| Smoke testing manual completo (preventas + WhatsApp + emails) | 16 h |
| Tests reescritura | 10 h |
| Plan deploy coordinado (deactivar plugins viejos sin perder data) | 6 h |
| Customer comm (ventana de mantención) | 2 h |
| Bug fixing post-deploy (25% del trabajo) | 25 h |
| Documentation update | 4 h |
| Validación con preventas test 24261/24262/24263 | 4 h |

**Total: ~94 h** (~3-4 semanas a capacity 25-30h/sem)

| Métrica | Valor |
|---|---|
| LOC tocadas | ~5.120 (4.820 movidos + 300 adapters) |
| Esfuerzo total | **~94 h (3-4 semanas)** |
| Tests rotos potenciales | 30-50% suite existente |
| DB migrations | 0 (postmeta es global) |
| Regression risk | **MEDIO-ALTO** (consolidación de hooks + activation puede generar deadlocks) |
| Time to revert if fails | 2-4 h si NO se borraron plugins viejos del filesystem; >12 h si se borraron |

---

## Sección 3 — Hidden costs (long-term)

### Opción 1 (status quo) — hidden costs

| Costo hidden | Magnitud | Frecuencia |
|---|---|---|
| Mantener boundaries explícitos (`function_exists` checks) | 1-2 LOC por callsite | en cada nueva feature que cruza plugins |
| Drift risk: cambio en `akb_ajax_endpoint()` rompe reservas silencioso | medio (si ocurre, debug ~2h) | <1 vez al año (mesa-15 R1 lo identificó como riesgo) |
| Testing matrix 3 plugins × N modules | bajo (no hay test suite running) | N/A actual |
| 3 admin menús separados (cognitive load admin) | 0 fricción medida | dueño no se quejó (mesa-15 R1) |
| Update granularity preservada (PRO no costo) | beneficio | continuo |

**Total hidden: bajo. Sin costos relevantes en horizon 12 meses.**

### Opción 2 (split akibara en MÁS plugins) — hidden costs

| Costo hidden | Magnitud | Frecuencia |
|---|---|---|
| Cross-plugin contract management (5 nuevos boundaries) | medio | continuo (cada feature nueva) |
| Activation order dependencies (qué plugin se activa primero) | alto | en cada `wp install` o re-activate |
| Update coordination (5 plugins separados, deploys parciales o full) | medio | en cada release |
| Learning curve nuevo dev (más superficies a entender) | alto | one-time + onboarding |
| Module Registry split entre plugins → admin UX MÁS fragmentado, no menos | medio | continuo |
| Composer/autoload duplicado en cada plugin (vendor/ por plugin) | alto disk + atac surface | continuo |
| Cron orphan risk (jobs sin owner si plugin desactivado) | alto si ocurre | bajo probability con cuidado |
| `dbDelta` corre 6 veces por activation en lugar de 1 | bajo perf | continuo |

**Total hidden: ALTO.** Esta opción **degrada** robustez en el largo plazo, no la mejora.

### Opción 3 (monolito) — hidden costs

| Costo hidden | Magnitud | Frecuencia |
|---|---|---|
| Update granularity perdida (todo o nada) | alto si ocurre | en cada release |
| Plugin file size grande (40k+ LOC = deploy más lento) | bajo | continuo |
| Reactivar después de bug = todo down | crítico | en cada activation event |
| Bug en reservas tira checkout WC entero (mismo namespace, errores fatales escalan) | alto | bajo probability |
| Pierde portabilidad: WhatsApp (autocontenido per mesa-15 R1) deja de poder publicarse a WP.org | irrelevant (no se planea publicar) | N/A |
| Imposible deploys parciales: si quiero cambiar un cron de reservas, deploy 40k+ LOC | medio | continuo |
| Deactivar plugin akibara consolidado por bug → tira checkout, preventas Y WhatsApp simultáneo | crítico | en bugs serios |

**Total hidden: ALTO.** Esta opción degrada aislamiento de fallas (mesa-15 R1 lo llamó "PRO crítico" del status quo).

---

## Sección 4 — Refactor risk matrix

### Opción 2 (split akibara) — TOP 5 riesgos

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Custom table data loss en transfer ownership (10 tablas) | Bajo (con backup) | Crítico | Backup full DB pre-migration obligatorio. Idempotente CREATE TABLE IF NOT EXISTS. NO borrar tablas en deactivation del plugin viejo. |
| Hook priority drift entre nuevos plugins | Medio | Alto | Tests integration completos pre-deploy. Documentar priorities con constantes. |
| Cron orphan durante deploy window (78 crons total) | Medio | Alto | Activación nueva ANTES de deactivación vieja. Validar `wp_next_scheduled` post-deploy. |
| Activation order dependency loop (akibara-marketing requiere akibara-core) | Alto | Medio | Plugin headers `Requires Plugins:` (WP 6.5+) para hard-deps. Fallback `class_exists` para soft-deps. |
| `function_exists` check missing en 1 de los 40+ callsites internos | Alto | Medio | Code review meticuloso + grep automatizado pre-deploy. |

### Opción 3 (monolito) — TOP 5 riesgos

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Activation merged corrompida si falla a mitad (cron + rewrite + dbDelta + adapters) | Medio | Crítico | Activation idempotente + transactional. Validar con `--dry-run` en local. |
| Deactivar plugin consolidado por bug tira preventas + WhatsApp + checkout | Bajo | Crítico | Tener plan B "kill switch" granular vía feature flags por module en lugar de deactivation full plugin. |
| Bug en reservas (`Akibara_Reserva_Cart::atomic_stock_check` ya identificado P1) escala a fatal del plugin entero | Medio | Alto | try/catch defensivo en cada module load. Sentry alerting. |
| Cron `akb_reservas_check_dates` deja de correr durante el deploy gap | Medio | Alto | Activation nueva ANTES de deactivar vieja. Validar `wp_next_scheduled`. |
| Postmeta `_akb_*` keys colisión con otros módulos (95+ keys ya en uso) | Bajo | Medio | Audit de keys pre-merge. `_akb_reservas_*` prefix consistente. |

---

## Sección 5 — Solo dev capacity reality check

Capacity asumida: 25-30 h/semana de mesa-23 (PM/dev solo).

| Opción | Esfuerzo total | Semanas | Bug-fixing buffer | Wall-clock realista |
|---|---|---|---|---|
| Opción 1 status quo | 0 h | 0 | 0 | 0 semanas |
| Opción 2 split akibara | 169 h | 5.6-6.8 | +30% (~50 h) | **6-8 semanas** |
| Opción 3 monolito | 94 h | 3.1-3.8 | +30% (~28 h) | **3-5 semanas** |

### ROI vs items del BACKLOG actual (audit/round2/BACKLOG-2026-04-26.md)

Items del BACKLOG con esfuerzo S-M (1-8h cada uno) que mueven la aguja YA:

- **F-02-001** P0: dev tooling 74MB en prod → S = ~2h, evita disclosure
- **CLEAN-003/004** clarification → S = ~1h, evita romper email transaccional
- **F-02-007** decisión growth modules (5500 LOC) → L decisión PM = ~4h, libera ~5500 LOC mantención futura
- **F-02-009** atomic_stock_check race → M = ~6h, evita oversold preventas Cyber Day
- **F-02-019** auto-preventa OOS edge case → S = ~3h, evita fulfillment imposible
- **Mesa-10 SEC-P0-* items** → variable, P0 obligatorios

**Suma de items P0+P1 actionables: ~30-50 h.** Estos generan VALUE INMEDIATO.

**Comparación honesta:**
- Opción 2 (169 h) = 3-5 ciclos de TODO el backlog P0+P1 actionable
- Opción 3 (94 h) = 2-3 ciclos del mismo
- Opción 1 (0 h) = libera capacity para los items que SÍ mueven la aguja

**Verdad cost-conscious:** Para 3 clientes y 1 dev, refactor arquitectónico es la opción MÁS CARA por su costo de oportunidad. Cada hora invertida en split/merge es 1 hora NO invertida en P0 security, race conditions, dead code real, o features que retienen clientes.

---

## Sección 6 — Recomendación cost-conscious

**Pick definitivo: Opción 1 (status quo).**

Cost/benefit honesto:

| Factor | Opción 1 | Opción 2 | Opción 3 |
|---|---|---|---|
| Costo (h) | 0 | 169 | 94 |
| Beneficio runtime | 0 | 0 | 0 |
| Beneficio admin UX | 0 (no se quejó) | -1 (más fragmentado, +5 menus) | +0.5 (1 menu menos) |
| Beneficio robustez aislamiento | baseline | -1 | -1 |
| Beneficio update granularity | baseline | 0 | -1 |
| Beneficio code health | 0 | 0 | +0.5 (-`function_exists` checks) |
| Riesgo regresión | 0 | ALTO | MEDIO-ALTO |
| Capacity desplazada de items P0/P1 | 0 | 169h displaces ~3.4 ciclos backlog | 94h displaces ~1.9 ciclos backlog |

**Veredicto:** Opción 3 (monolito) es **menos mala** que Opción 2 (split en MÁS) si el dueño insiste en hacer ALGO. Pero **ninguna de las dos** justifica su costo vs el backlog real.

Coherencia con memorias activas:
- `feedback_no_over_engineering.md` → Opción 1. Splits/merges sin business case son sobreingeniería arquitectónica
- `project_audit_right_sizing.md` → 3 clientes no justifican refactor masivo. "Re-evaluar cuando >50 clientes"
- `feedback_robust_default.md` → la opción más robusta es la que tiene cero riesgo de regresión = Opción 1

**Trigger explícito para re-evaluar:**
1. Aparece 4to plugin custom Akibara (segundo caso de uso real para "gestión multi-plugin")
2. Dueño explícitamente sufre fricción admin UX (no se quejó hasta ahora)
3. Tienda escala >50 clientes/mes (deploy granularity vale la pena medir)
4. Aparece equipo de 3+ devs (testing matrix justifica boundaries más explícitos)

Ninguno de los 4 triggers está activo hoy. **NO hacer nada arquitectónico hoy.**

---

## Sección 7 — Si pick = status quo, refactors PARCIALES que SÍ valen la pena

Mantener 3 plugins separados NO significa zero refactor. Existen mejoras incrementales LOW-COST que reducen drift risk y mejoran maintainability sin big-bang.

### Refactor menor 1: Documentar interfaces explícitas entre plugins
- **Esfuerzo:** S (~3-4 h)
- **Sprint sugerido:** S2
- **Acción:** Crear `docs/architecture/cross-plugin-interfaces.md` listando:
  - Funciones globales públicas que cada plugin EXPONE: `akb_ajax_endpoint`, `akibara_wa_phone`, `akibara_wa_url`, `AkibaraEmailTemplate`, etc.
  - Quién consume cada una (callsite count actual)
  - Política: "estas funciones son contrato — breaking changes requieren version bump y deprecation notice"
- **Beneficio:** previene drift cuando se modifica una función "interna" que en realidad es contrato cross-plugin
- **Robustez ganada:** alta (signal claro de qué tocar con cuidado)

### Refactor menor 2: Helpers públicos vía clase namespaceada (mesa-15 F-15-010 R1)
- **Esfuerzo:** M (~6-8 h)
- **Sprint sugerido:** S3
- **Acción:** Reemplazar `function_exists('akb_ajax_endpoint')` checks dispersos (8 callsites) por una clase `Akibara_Cross_Plugin_API` con métodos estáticos. Si akibara plugin no está activo, los métodos retornan defaults seguros. Documentar como API pública.
- **Beneficio:** punto único de validación + grep más limpio + no breaking si se renombra función interna

### Refactor menor 3: Hook priorities con constantes documentadas (mesa-22 F-22-009)
- **Esfuerzo:** S (~3 h)
- **Sprint sugerido:** S2
- **Acción:** Definir `define('AKB_HOOK_PRIORITY_EARLY', 5)`, `AKB_HOOK_PRIORITY_DEFAULT', 10)`, `AKB_HOOK_PRIORITY_LATE', 99)` en `akibara/includes/constants.php`. Usar las constantes en lugar de literales 5/10/99. Reservas y whatsapp pueden importarlas vía `class_exists` check.
- **Beneficio:** evita conflict cuando 2 plugins enganchan al mismo hook

### Refactor menor 4: Cross-plugin testing matrix mínima (smoke tests manuales documentados)
- **Esfuerzo:** S (~4 h)
- **Sprint sugerido:** S2
- **Acción:** Crear `docs/qa/cross-plugin-smoke-tests.md` con 5-10 escenarios manuales que SIEMPRE se prueban antes de deploy de cualquier plugin akibara:
  - Crear preventa → verificar email "Reserva confirmada" usa `AkibaraEmailTemplate` correctamente
  - Botón WhatsApp visible en home + PDP + checkout
  - Cambiar fecha estimada reserva → email "fecha cambiada" llega
  - WhatsApp NO visible en `/mi-cuenta/`
  - Custom rewrite `/mi-cuenta/mis-reservas/` resuelve
- **Beneficio:** evita regresiones cross-plugin sin necesidad de CI completo

### Refactor menor 5: Plugin headers `Requires Plugins` (WP 6.5+) para soft-deps
- **Esfuerzo:** S (~1 h)
- **Sprint sugerido:** S2
- **Acción:** Agregar `Requires Plugins: woocommerce` en cabecera de los 3 plugins akibara (akibara, akibara-reservas, akibara-whatsapp). Para reservas, considerar declarar `Requires Plugins: akibara` (soft, igual hace `class_exists` checks).
- **Beneficio:** WP-admin avisa al usuario si trata de activar reservas sin akibara. Reduce edge case "plugin a medio activar".

### Suma de refactors menores

| Refactor | Esfuerzo | Sprint |
|---|---|---|
| 1. Doc interfaces | 3-4 h | S2 |
| 2. Cross-plugin API class | 6-8 h | S3 |
| 3. Hook priorities constantes | 3 h | S2 |
| 4. Smoke tests doc | 4 h | S2 |
| 5. `Requires Plugins` headers | 1 h | S2 |
| **TOTAL** | **17-20 h** | S2-S3 |

**Comparación final:**
- Opción 1 + refactors menores = **17-20 h total** (1 sprint chico)
- Opción 2 = 169 h
- Opción 3 = 94 h

Opción 1 + refactors menores captura **80% del beneficio de "mejor arquitectura"** a **10-20% del costo** de cualquier refactor big-bang. Es el cost/benefit ganador.

---

## Conclusión

La opción MÁS ROBUSTA para Akibara hoy es **mantener los 3 plugins separados** (status quo). El motivo no es preferencia estética, es matemática de capacity:

- 169 h refactor split + 0 beneficio runtime + degrada admin UX (más fragmentado) = decisión IRRACIONAL
- 94 h refactor monolito + 0 beneficio runtime + degrada aislamiento de fallas = decisión SUBÓPTIMA
- 0 h status quo + 17-20 h refactors menores documentation/contracts = decisión ÓPTIMA

Para 3 clientes con 1 dev sin staging, **toda hora invertida en arquitectura es 1 hora NO invertida en items del backlog que mueven la aguja** (P0 security, race conditions, dead code real, growth-modules decisions).

Re-evaluar SOLO si: aparece 4to plugin custom, dueño reporta fricción admin, tienda escala >50 clientes/mes, o aparece equipo de 3+ devs.
