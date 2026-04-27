---
agent: mesa-15-architect-reviewer
gate: PRE-review
sprint: 2
date: 2026-04-27
scope: Sprint 2 (INFRA-01 staging + SETUP-01 GHA + Cell Core extraction + 4 CLEAN destructivos)
items_reviewed: 6
checklist_points: 8/8
---

## Resumen ejecutivo

- **GO con condiciones.** Sprint 2 es viable, pero introduce 4 acciones destructivas concentradas (DB clone staging, drop class, delete folders uploads/, disable GA4, disable finance-dashboard) más una refactorización foundation (Cell Core) — densidad de riesgo alta para solo dev.
- **Bloqueador hard:** B-S2-INFRA-01 staging es prerequisite estricto de Cell Core extraction. Si week 1 staging slip → week 2-3 Cell Core MUST run sin red de seguridad y eso cruza thresholds de `feedback_robust_default`. Recomiendo gate explícito: NO arrancar Cell Core hasta `curl -I staging.akibara.cl` retorna 401.
- **Riesgo cross-plugin invisible:** Cell Core extracta 13 módulos a `akibara-core`, pero el plugin `akibara/` monolítico SE MANTIENE en paralelo. Sin convención `class_exists`/`function_exists` aplicada en los call-sites externos (theme + akibara-reservas + akibara-whatsapp), una desactivación accidental del nuevo `akibara-core` rompe sitio. Esto NO está documentado en BACKLOG line 1003.
- **Lock policy NO tiene enforcer designado.** ADR 003 declara `akibara-core/` y `themes/akibara/` read-only durante Sprints paralelos, pero Sprint 2 establece el plugin Y no documenta quién enforces (no hay CODEOWNERS, no hay pre-commit guard, no hay branch protection). Sprint 3 paralelo Cell A/B/H pueden violarlo silenciosamente.
- **CLEAN-014/011/015/016 son 4 destructivos en un mismo sprint** sin dependencia técnica entre sí. Cada uno requiere DOBLE OK + backup + smoke test + 24h Sentry monitoring. Si todos se hacen en week 1, el solo dev queda observando 4 ventanas paralelas de 24h. Recomiendo serializar: 1 destructivo por week, no más.

---

## Checklist 8 puntos (ADR 004)

### 1. ¿Algún item impacta más de 1 plugin?

**SÍ — múltiples.**

- **Cell Core extraction (weeks 2-3)** afecta a: `plugins/akibara/` (origen, módulos extracted desactivan), `plugins/akibara-reservas/` (consume helpers core como `address-autocomplete`, `phone`, `rut` — `audit/round1/02-tech-debt.md` documenta `class_exists` cross-plugin), `plugins/akibara-whatsapp/` (consume helpers core), `themes/akibara/` (consume `series-autofill`, `email-template`, `email-safety`).
- **CLEAN-014** (`class-reserva-migration::run()` delete) afecta `plugins/akibara-reservas/` Y posiblemente cron registration en mu-plugins (verificar antes).
- **CLEAN-016** (finance-dashboard DISABLE) afecta `plugins/akibara/modules/finance-dashboard/` directamente, pero su rebuild Sprint 3 Cell B reescribe consumos de `_akibara_serie`, `akibara_brevo_editorial_lists`, `akibara_encargos_log` — todos producidos por otros módulos.

**Acción requerida:** Cell Core HANDOFF.md DEBE listar exhaustivamente call-sites externos al plugin `akibara/` original (theme + 2 plugins externos) y declarar contratos públicos antes de extraer. Sin este inventario, regression silenciosa es probable.

### 2. ¿Nueva dependencia cross-plugin?

**SÍ.** Cell Core es por definición un nuevo punto de dependencia. El target arquitectónico (ADR 003 §Decisión #2) declara que `akibara-preventas`, `akibara-marketing`, etc. usarán `Requires Plugins: akibara-core` (WP 6.5+ header).

**Findings concretos:**
- `Requires Plugins: akibara-core` solo gatea **activación** del addon, NO previene fatal si el core se desactiva con un addon ya activo. Necesitamos también `class_exists('Akibara\\Core\\ServiceLocator')` defensive checks en cada entrada pública del addon.
- BACKLOG line 1003 menciona "API público documentado para Sprint 3 cells (audit/sprint-2/cell-core/HANDOFF.md)" pero NO especifica el patrón defensive. Recomiendo enforcing: cada hook callback en addons empieza con `if (!class_exists(...)) return;`.
- Memoria activa `feedback_robust_default` empuja a gates manuales: el HANDOFF.md de Cell Core debe incluir un grep adversarial verificando que TODA referencia externa al namespace `Akibara\Core\` está envuelta en defensive check.

**Acción:** Convención `AKB_REQUIRE_CORE()` helper documentada en HANDOFF.md sección "Defensive contract".

### 3. ¿Modifica mu-plugins load-bearing?

**NO directamente, pero PRÓXIMO.**

Los 3 mu-plugins críticos son:
- `akibara-brevo-smtp.php` (ADR 002)
- `akibara-sentry-customizations.php` (ADR 001)
- `akibara-email-testing-guard.php` (redirige outbound a `alejandro.fvaras@gmail.com`)

Sprint 2 no toca ninguno explícitamente. PERO:
- Cell Core extracta módulos `email-template` y `email-safety`. Si alguno de estos consume `wp_mail()` filter chain ya enganchado por `akibara-brevo-smtp`, el orden de carga importa (mu-plugins cargan antes que plugins). Verificar prioridades de `phpmailer_init` hooks.
- Smoke test obligatorio post-Cell-Core-extraction: enviar email de prueba (welcome new account o magic-link) y validar (a) llega vía Brevo SMTP, (b) destinatario es `alejandro.fvaras@gmail.com` (guard activo), (c) Sentry breadcrumbs preservados.

**Acción:** DoD Cell Core debe incluir `bin/wp-ssh eval 'wp_mail("alejandro.fvaras@gmail.com","S2 smoke","ok");'` y verificar Brevo logs.

### 4. ¿Hooks WC con priorities (race conditions)?

**RIESGO ALTO - parcialmente cubierto.**

Findings round 1 documentaron:
- Descuentos `priority=1000` hook (intencional override last-writer-wins)
- Popup vs welcome-discount priority `5` vs `50` (race documented)

Cell Core extracción NO debería tocar estos hooks (módulos en addons Sprint 3 marketing). PERO:
- Si Cell Core mueve `welcome-discount` o `popup` por error a core (ambos están en marketing scope per ADR 003), las prioridades viajan con el módulo.
- BACKLOG line 1003 lista 13 módulos foundation a migrar. NINGUNO de esos 13 toca priorities críticas (search, rut, phone, badges, address, customer-edit, checkout-validation, health-check, series-autofill, email-template, email-safety, category-urls, order). **OK.**

**Acción preventiva:** Constantes `AKB_FILTER_PRIORITY_*` mencionadas en ADR 004 punto 4 como pendientes — Sprint 2 NO las introduce (out of scope). Recomiendo agendarlas para Sprint 3 Cell B (marketing) cuando se extraigan los módulos de descuentos/popup. Documentar como debt en HANDOFF.md.

### 5. ¿Agrega módulo nuevo?

**TÉCNICAMENTE SÍ — el plugin `akibara-core` es nuevo plugin.** Pero NO es módulo nuevo de funcionalidad — es restructuring.

Aplicando regla "no nuevo módulo sin justificar":
- Justificación: ADR 003 §Decisión #2, memoria `project_architecture_core_plus_addons`. Lock policy + WP 6.5 `Requires Plugins` + boundaries dominio. **OK.**
- Sub-componentes nuevos del core (ServiceLocator, ModuleRegistry, Lifecycle, HPOSFacade): cada uno necesita justificación individual contra YAGNI (memoria `feedback_no_over_engineering` — abstrair solo cuando 2+ casos lo justifican).

**Auditoría YAGNI:**
- ServiceLocator: justified (5 addons consumirán). OK.
- ModuleRegistry: justified (13 módulos en core + N módulos en addons). OK.
- Lifecycle hooks (activate/deactivate/uninstall): WP estándar nativo, no es abstracción extra. OK.
- HPOSFacade: justified si HPOS está activo en prod (verificar). Si NO activo → DEFER hasta WC HPOS migration. **Verificar status HPOS antes de empezar.**

**Acción:** Cell Core PM (mesa-23) confirma estado HPOS via `bin/wp-ssh wc hpos status` antes de implementar HPOSFacade. Si legacy meta storage → DEFER facade Sprint 3.

### 6. ¿Viola alguna memoria del usuario?

**Posibles violaciones a verificar:**

- **`feedback_no_over_engineering`** — riesgo medio. Cell Core target lista ServiceLocator + ModuleRegistry + Lifecycle + HPOSFacade. Si HPOSFacade no se justifica todavía (ver §5), implementarlo viola YAGNI. → Mitigar con auditoría HPOS antes.
- **`feedback_minimize_behavior_change`** — riesgo bajo. ADR 003 explícitamente declara "Sprint 1 mantiene status quo en runtime", Cell Core target dice "behavior change minimizar". Si la migración es transparente para customers → OK. Smoke test mandatory.
- **`project_no_key_rotation_policy`** — sin riesgo. Sprint 2 no toca keys.
- **`project_deploy_workflow_docker_first`** — riesgo si Cell Core deploy directo. DoD debe incluir: Docker → quality-gate → smoke local → smoke staging → deploy prod → smoke prod → 24h Sentry monitor. **CRÍTICO porque B-S2-SETUP-01 GHA workflow recién se introduce esta misma semana.**
- **`project_brevo_upstream_capabilities`** — sin riesgo directo. Ver §3.
- **`project_figma_mockup_before_visual`** — sin riesgo Sprint 2 (refactor backend, no visual).
- **`project_test_products_visibility`** — sin riesgo (productos test ya eliminados; smoke usa órdenes existentes).

**Acción:** PM mesa-23 valida HPOSFacade scope antes Cell Core launch.

### 7. ¿Riesgo de regresión por cambio?

**ALTO. Sprint 2 es el mayor refactor del año.**

Áreas de mayor riesgo:
1. **Path resolution** — `wp-content/plugins/akibara/modules/X` → `wp-content/plugins/akibara-core/modules/X`. Si algún include relativo, asset URL, o `plugins_url(__FILE__)` dependa de la ubicación, se rompe silenciosamente.
2. **Autoload conflict** — el plugin `akibara/` actual ya tiene `composer.json` + `vendor/` (visto en server-snapshot). El nuevo `akibara-core/` también tendrá autoload PSR-4. Posibles class collisions si namespaces no se separan estrictamente.
3. **Database table ownership** — Cell Core target dice "schema layer helpers (sin tablas propias del core)". Validar: ¿quién owns `wp_akb_*` tablas durante extracción? Si el módulo ya migrado al core consulta tabla creada por addon legacy, race condition en `dbDelta`.
4. **Cron jobs** — `akb_bluex_logs_purge` (Sprint 1), `maybe_unify_types` (akibara-reservas), otros. Si cron callback class moved al core y el cron schedule queda registrado contra clase del plugin viejo, fatal silencioso.

**Acción - Smoke test post-deploy obligatorio:**
- 20/20 smoke checks como Sprint 1
- Adicional: `bin/wp-ssh cron event list` → todos los crons resuelven a callbacks vivos
- Adicional: `curl https://akibara.cl/wp-json/akibara/v1/health` → módulo health-check responde post-migración
- 24h Sentry monitoring con baseline alert si `>0 nuevos error types`

### 8. ¿Requiere mockup Figma?

**NO** para Sprint 2. Refactor 100% backend + infra. Cero cambios visuales customer-facing.

CLEAN-016 (finance-dashboard DISABLE) elimina UI admin pero NO requiere mockup (es disable, no rebuild — el rebuild es Sprint 3 Cell B y SÍ requerirá mockup).

---

## Bloqueadores ocultos detectados

1. **Lock policy SIN enforcer.** ADR 003 declara `akibara-core/` read-only durante Sprints paralelos pero NO hay mecanismo técnico (CODEOWNERS, branch protection, pre-commit). Sprint 3 paralelo Cell A/B/H podrían violar accidentalmente. **Sprint 2 DoD debe incluir:** `.github/CODEOWNERS` con `akibara-core/ @arquitectura-cell` (aún si owner es el solo dev — sirve como gate visual en GH PR review).

2. **Plugin antiguo `akibara/` operacional en paralelo durante extracción.** Cell Core BACKLOG dice "plugin antiguo akibara/ se mantiene en paralelo con módulos ya migrados eliminados". ¿Cómo se evita doble-registro de hooks (módulo X registra hooks tanto en akibara/ legacy como en akibara-core/ nuevo)? **Solución:** cada migración módulo a módulo debe (a) deshabilitar registration en plugin viejo, (b) habilitar en core, (c) testear en mismo deploy atomic.

3. **`vendor/` y `coverage/` dentro del plugin akibara/.** Sprint 1 SEC-03 ya añadió `.htaccess` defensivo, pero Cell Core extracción crea NUEVO `akibara-core/composer.json` + `vendor/` que también necesita protección. DoD: deploy excludes per `project_deploy_exclude_dev_tooling` aplicados explícitamente al nuevo plugin.

4. **GHA workflow B-S2-SETUP-01 NO está corriendo cuando Cell Core empieza.** Week 1 sequential: staging + GHA. Cell Core arranca week 2 — SI GHA se atrasa a finales week 1, Cell Core week 2 corre sin segundo nivel de gate. Mitigación: gate manual `bin/quality-gate.sh` local OBLIGATORIO antes de cada commit en feat/akibara-core.

5. **Staging DB clone "anonymized" — quién valida la anonymization?** B-S2-INFRA-01 DoD incluye anonimización (emails staging+ID@akibara.cl, phones +56 9 0000 0000, api_keys → sandbox). Sin script versionado verificable, una omisión filtra PII a entorno con basic auth débil. DoD necesita: `bin/sync-staging.sh` versionado en git + assertion final `SELECT COUNT(*) WHERE billing_email NOT LIKE 'staging+%'` = 0.

---

## Risk register top 5

| # | Riesgo | Severidad | Probabilidad | Mitigación |
|---|---|---|---|---|
| 1 | Cell Core extracción rompe call-site theme/addon (path/autoload/include) | P0 | Media | Inventario exhaustivo call-sites externos en HANDOFF.md ANTES de migrar; smoke prod 20/20 + 24h Sentry post cada módulo |
| 2 | Lock policy no enforced → Sprint 3 paralelo modifica core silenciosamente | P1 | Alta sin mitigación | CODEOWNERS file Sprint 2 DoD; PR template gate visual |
| 3 | Staging DB clone filtra PII por anonymization parcial | P0 | Baja con script | `bin/sync-staging.sh` versionado + assertion query post-sync |
| 4 | 4 destructivos CLEAN concentrados en mismo sprint (014/011/015/016) sobrepasan capacidad de monitoring del solo dev | P1 | Alta si paralelos | Serializar: 1 destructivo por week máximo, 24h gap Sentry monitoring entre cada uno |
| 5 | HPOSFacade implementado sin HPOS activo (over-engineering YAGNI) | P2 | Media | mesa-23 PM verifica `wc hpos status` antes de Cell Core launch; si NO activo → DEFER facade |

---

## Dependency graph

```
Week 1 (sequential, no paralelo):
  B-S2-INFRA-01 staging  ──►  B-S2-SETUP-01 GHA workflow
        │                            │
        │                            └─►  CLEAN-014 (1 destructivo)
        │
        └──►  ready week 2

Week 2 (Cell Core start):
  Cell Core extraction (mesa-15/22/16/17/11/23/01)
  Migration módulo a módulo, ATOMIC per módulo
  Smoke prod + Sentry 24h GAP entre módulos críticos
        │
        └──►  CLEAN-011 (1 destructivo, después de smoke)

Week 3 (Cell Core finish + cleanups):
  Cell Core HANDOFF.md complete
  CLEAN-016 finance-dashboard DISABLE (1 destructivo)
        │
        └──►  CLEAN-015 GA4 disable (1 destructivo, último)
              │
              └──►  POST-validation Sprint 2 (mesa-15 + mesa-22 + mesa-02)
```

**Reglas estrictas:**
- B-S2-INFRA-01 staging es PREREQUISITE de TODO en week 2-3. Si slip → re-plan, NO empezar Cell Core sin staging.
- B-S2-SETUP-01 GHA NO bloquea Cell Core técnicamente, pero `bin/quality-gate.sh` local SÍ es bloqueador per commit.
- 4 CLEAN destructivos NO son paralelos entre sí. 1 por week, 24h gap Sentry.
- Cell Core extracción de cada módulo es atomic deploy (módulo entero, no parcial).

---

## DoD verificable per item

### B-S2-INFRA-01 staging
- `curl -I https://staging.akibara.cl` → HTTP 401
- `bin/wp-ssh --staging eval 'echo AKIBARA_EMAIL_TESTING_MODE;'` → `true`
- `bin/mysql-prod --staging -e "SELECT COUNT(*) FROM wp_users WHERE user_email NOT LIKE 'staging+%@akibara.cl';"` → 0
- `bin/sync-staging.sh` corre clean + commited en git
- DOBLE OK explícito previo (DB clone destructive)

### B-S2-SETUP-01 GHA
- `gh workflow list` muestra `quality.yml` activo
- Push a feat/test-gate dispara workflow → green en <5min
- Pre-commit hook bloquea voseo en archivo .php (test con `confirmá` triggers fail)

### Cell Core extraction
- `bin/wp-ssh plugin list | grep akibara-core` → status active
- `bin/wp-ssh eval 'echo class_exists("Akibara\\\\Core\\\\ServiceLocator") ? "OK":"FAIL";'` → OK
- `curl https://akibara.cl/wp-json/akibara/v1/health` → JSON 200
- 20/20 smoke prod + 24h Sentry GREEN
- HANDOFF.md publicado con: módulos migrados list + API público (namespaces) + call-site inventory + RFC pendientes

### CLEAN-014 (class-reserva-migration::run delete)
- `grep -r "Akibara_Reserva_Migration::run" wp-content/` → 0
- `bin/wp-ssh cron event list | grep maybe_unify_types` → still scheduled (cron register sigue vivo)
- akibara-reservas plugin activate sin error

### CLEAN-011 (uploads/ leftover folders)
- `find wp-content/uploads/ -name "*.php"` → 0
- Home + admin → HTTP 200
- backup tar.gz en `.private/snapshots/`

### CLEAN-015 (GA4 disable)
- `bin/wp-ssh eval 'echo function_exists("akb_ga4_server_purchase") ? "FAIL":"OK";'` → OK
- DevTools Network producto checkout → 0 requests a `google-analytics.com/g/collect`
- Sentry sin nuevos errors 24h post-disable

### CLEAN-016 (finance-dashboard DISABLE)
- Admin menu finance dashboard ausente
- `bin/wp-ssh eval 'echo class_exists("Akibara_Finance_Dashboard") ? "FAIL":"OK";'` → OK
- 24h Sentry GREEN

---

## Recomendaciones para mesa-23 PM (advertencias al solo dev)

1. **NO arranque Cell Core hasta `curl -I https://staging.akibara.cl` retorne 401.** Si week 1 staging slip, retrasar Sprint 2 entero antes que correr Cell Core sin red.
2. **NO concentre 4 destructivos en mismo día.** Serialice: 1 por week, 24h gap Sentry entre cada uno.
3. **Verifique HPOS status antes de implementar HPOSFacade.** `wc hpos status`. Si legacy meta storage → DEFER.
4. **Inventory call-sites externos al plugin `akibara/` ANTES de migrar primer módulo.** Sin esto, regression silenciosa es probabilidad alta.
5. **Establezca CODEOWNERS file en Sprint 2 DoD.** Lock policy sin enforcer es promesa rota.
6. **Cada migración de módulo es atomic deploy.** No mezcle 2 módulos en mismo PR. Smoke prod + 24h Sentry GAP entre críticos (search, checkout-validation, email-template).
7. **`bin/sync-staging.sh` versionado en git + assertion anonymization automated.** Sin esto, PII a staging es high-risk.
8. **`bin/quality-gate.sh` local es bloqueador hard per commit.** GHA workflow es 2do gate; primer gate es local.
9. **POST-validation Sprint 2 ejecuta el checklist 9 puntos de ADR 004**, NO se cierra sprint sin output `audit/sprint-2/ARCHITECTURE-POST-VALIDATION.md`.
10. **Si Sentry detecta nuevo error type 24h post cualquier deploy del sprint → STOP next destructivo, investigar antes.**

---

## Verdict

**GO con 5 condiciones bloqueantes:**

1. Inventario call-sites externos en HANDOFF.md week 2 day 1.
2. Serialización 4 CLEAN destructivos (1 por week, no paralelos).
3. CODEOWNERS file deployed Sprint 2 DoD.
4. `bin/sync-staging.sh` versionado + assertion anonymization.
5. HPOS status verification antes HPOSFacade implementation.

Sin estas 5 condiciones, el sprint cruza thresholds de robustness aceptables para un solo dev sin staging dedicado todavía operando. Con ellas, GO.
