# CELL DESIGN Akibara — Refactor Sprint 2-5 + Sprint Execution

**Fecha:** 2026-04-26
**Status:** ARQUITECTURA TARGET ESTABLE. Diseño de células multi-agente para refactor Core + 5 Addons + Cell H horizontal Design Ops.
**Stack:** Claude Code subagents + git worktrees + file-based coordination. NO frameworks externos (PraisonAI/CrewAI/MetaGPT descartados).

> **Cómo se usa:** este archivo es la única fuente de verdad para CÓMO se ejecutan los sprints de refactor. Cada cell tiene prompt template embebido — una sesión nueva (main session de Claude Code) puede arrancar cualquier cell sin contexto previo. La arquitectura target está en memoria `project_architecture_core_plus_addons.md`.

---

## Resumen ejecutivo

```
Sprint 1 (foundation cleanup)    SECUENCIAL — 30-32h     Cell H low intensity (~6-8h)
       ↓
Sprint 2 (Core extraction)        SECUENCIAL — 30-35h     Cell Core + Cell H medium (12-15h)
       ↓                          + B-S2-INFRA-01 staging (week 1)
Sprint 3 (paralelo A + B + H)     PARALELO — 60h equiv    Cell A preventas + Cell B marketing + Cell H high
       ↓
Sprint 3.5 (Lock release)         SECUENCIAL — 6-8h       mesa-15 + mesa-01 RFC arbitration + Cell H consolidate + LambdaTest
       ↓
Sprint 4 (paralelo C + D + H)     PARALELO — 35h equiv    Cell C inventario + Cell D whatsapp + Cell H medium
       ↓
Sprint 4.5 (Lock release)         SECUENCIAL — 4-6h       Same pattern
       ↓
Sprint 5 (mercadolibre)           SECUENCIAL — 15-20h     Cell E + Cell H low

Total esfuerzo: ~150-180h refactor (~6-7 semanas calendar a 25h/sem capacity)
```

---

## Stack tecnológico

```
┌────────────────────────────────────────────────────────────┐
│ ORCHESTRATION LAYER                                        │
│ • Main Claude Code session (Alejandro orchestra)           │
│ • Agent tool con parallel tool_use blocks                  │
│ • git worktrees para sessions paralelas (Sprint 4+ opcional)│
│ • /schedule slash command para tareas autónomas             │
└────────────────────────────────────────────────────────────┘
                          │
┌────────────────────────────────────────────────────────────┐
│ AGENT LAYER (ya instalado en .claude/agents/)              │
│ • 22 mesa-NN-* subagents                                   │
│ • Cada uno con tools, model assignment, system prompt      │
└────────────────────────────────────────────────────────────┘
                          │
┌────────────────────────────────────────────────────────────┐
│ COORDINATION LAYER (file-based, versionado git)            │
│ • audit/sprint-{N}/cell-{X}/<HANDOFF|BACKLOG|PROGRESS>.md  │
│ • audit/sprint-{N}/rfc/CORE-CHANGE-{NN}.md                 │
│ • audit/sprint-{N}/rfc/THEME-CHANGE-{NN}.md                │
│ • Git branches feat/<addon>                                │
└────────────────────────────────────────────────────────────┘
                          │
┌────────────────────────────────────────────────────────────┐
│ EXECUTION LAYER                                            │
│ • bin/quality-gate.sh (Docker pipeline 17 tools)           │
│ • GitHub Actions (.github/workflows/quality.yml)           │
│ • Playwright @critical                                     │
│ • LambdaTest visual regression                             │
│ • Sentry 24h post-deploy monitoring                        │
└────────────────────────────────────────────────────────────┘
```

---

## Arquitectura de células — 6 totales

### 5 Verticales (1 por addon)

| Cell | Plugin | Sprint | Branch git | Esfuerzo |
|---|---|---|---|---|
| **Cell Core** | akibara-core | Sprint 2 secuencial | `feat/akibara-core` | 25-30h |
| **Cell A** | akibara-preventas | Sprint 3 paralelo | `feat/akibara-preventas` | 25-30h |
| **Cell B** | akibara-marketing + finance rebuild | Sprint 3 paralelo | `feat/akibara-marketing` | 30-40h |
| **Cell C** | akibara-inventario | Sprint 4 paralelo | `feat/akibara-inventario` | 25-30h |
| **Cell D** | akibara-whatsapp | Sprint 4 paralelo | `feat/akibara-whatsapp` | 5-8h |
| **Cell E** | akibara-mercadolibre | Sprint 5 secuencial | `feat/akibara-mercadolibre` | 15-20h |

### 1 Horizontal — Cell H (Design Ops)

| Cell H | Sprint activity |
|---|---|
| Sprint 1 | LOW (~6-8h): Figma MCP setup, branding constants doc, audit themes/akibara, LambdaTest baseline |
| Sprint 2 | MEDIUM (~12-15h): Component library v1 Figma, theme.json + tokens.css, mockups Sprint 3 items |
| Sprint 3 | HIGH (~10-12h): Mockups detalle Cell A+B, theme updates page-encargos/page-bienvenida |
| Sprint 3.5 | (~4-6h): consolidate theme deltas + LambdaTest run + token additions review |
| Sprint 4 | MEDIUM (~6-8h): Mockups Cell C+D (stock alerts, back-in-stock form, whatsapp button variants) |
| Sprint 4.5 | (~4-6h): consolidate + LambdaTest |
| Sprint 5 | LOW (~3-4h): admin UI mercadolibre minimal |

**Cell H esfuerzo total:** ~45-60h spread across 7 weeks.

---

## Skills mapping (subagents por cell)

### Cell Core
```
mesa-15-architect-reviewer    # Diseño Service Locator + ModuleRegistry + Lifecycle
mesa-22-wordpress-master      # WP idioms (HPOS facade, plugin headers, hooks priority)
mesa-16-php-pro               # PSR-4 autoload, namespace, contracts
mesa-17-database-optimizer    # Schema layer (helpers, no tablas propias del core)
mesa-11-qa-testing            # Test harness PHPUnit reusable
mesa-23-pm-sprint-planner     # Backlog Sprint 2 + DoD
mesa-01-lead-arquitecto       # Árbitro
```

### Cell A — preventas
```
mesa-16-php-pro               # Implementation
mesa-22-wordpress-master      # WP idioms
mesa-15-architect-reviewer    # Architecture review
mesa-11-qa-testing            # Tests + smoke
mesa-06-content-voz           # UI copy chileno preventa flow
mesa-23-pm-sprint-planner     # Cell backlog
mesa-01-lead-arquitecto       # Árbitro merges
```

### Cell B — marketing + finance rebuild manga-specific
```
mesa-16-php-pro               # Implementation
mesa-09-email-qa              # Brevo wiring + transactional templates (DOMAIN-SPECIFIC)
mesa-22-wordpress-master      # WP idioms
mesa-15-architect-reviewer    # Architecture
mesa-06-content-voz           # UI copy + email content
mesa-23-pm-sprint-planner     # Backlog + finance widgets specs
mesa-01-lead-arquitecto       # Árbitro
```

### Cell C — inventario
```
mesa-16-php-pro               # Implementation
mesa-17-database-optimizer    # Stock queries + depletion analytics + indexing (DOMAIN-SPECIFIC)
mesa-22-wordpress-master      # WP idioms
mesa-15-architect-reviewer    # Architecture
mesa-11-qa-testing            # Tests
mesa-23-pm-sprint-planner     # Backlog
```

### Cell D — whatsapp
```
mesa-16-php-pro               # Refactor mínimo
mesa-22-wordpress-master      # WP idioms
mesa-15-architect-reviewer    # Architecture (light)
mesa-11-qa-testing            # Tests
mesa-23-pm-sprint-planner     # Backlog
```

### Cell E — mercadolibre
```
mesa-16-php-pro               # Implementation refactor
mesa-22-wordpress-master      # WP idioms
mesa-15-architect-reviewer    # Architecture
mesa-11-qa-testing            # Tests
mesa-04-mercadopago           # OAuth/webhook patterns reutilizables (DOMAIN-SPECIFIC)
mesa-23-pm-sprint-planner     # Backlog
```

### Cell H — Design Ops horizontal
```
mesa-13-branding-observador   # Lead — observa inconsistencias (no propone visuales — convención)
mesa-07-responsive            # Breakpoints 375/430/768/1024, container queries, fluid typography
mesa-08-design-tokens         # WCAG AA/AAA contrast, focus rings, motion tokens, spacing tokens
mesa-05-accessibility         # ARIA, keyboard nav, semantic HTML
mesa-22-wordpress-master      # Theme idioms (theme.json, blocks, template hierarchy)
mesa-06-content-voz           # UI copy chileno
mesa-23-pm-sprint-planner     # Coordina con verticales (recibe requirements, devuelve specs)
```

---

## Lock policy — Core + Theme

**Industry standard:** Stable API Contract + RFC + Scheduled Merge Windows.

**Reglas duras durante Sprints paralelos (3, 4):**
- `plugins/akibara-core/` es **read-only** desde Cells verticales A/B/C/D
- `themes/akibara/` es **read-only** desde Cells verticales (Cell H owns)
- Si Cell A necesita cambio en Core → abre RFC en `audit/sprint-{N}/rfc/CORE-CHANGE-{NN}.md`
- Si Cell B necesita cambio en theme → abre RFC en `audit/sprint-{N}/rfc/THEME-CHANGE-{NN}.md`
- mesa-15 + mesa-01 lead arbitran RFCs en Sprint X.5
- Aprobado → cambio aplicado en X.5 + cells re-sync main; Rechazado → cell hace workaround

### RFC template

`audit/sprint-{N}/rfc/CORE-CHANGE-{NN}.md`:

```markdown
# CORE-CHANGE-REQUEST {NN}

**Cell origen:** A | B | C | D | E
**Sprint:** {N}
**Solicitante:** mesa-{NN}-{role}
**Status:** PENDING | APPROVED | REJECTED | DEFERRED
**Fecha:** YYYY-MM-DD

## Problema

¿Qué necesita la cell que el Core actualmente no provee?

## Workaround disponible

¿Puede la cell continuar sin este cambio? ¿Costo del workaround en horas/complejidad?

## Cambio propuesto

API exacta:
- Namespace: `Akibara\Core\<...>`
- Method signature: `public function get<X>(int $id): <Type>`
- Behavior: ...
- Backward compat: SÍ/NO

## Impacto en otras cells

¿Afecta API que otras cells consumen? Coordination needed?

## Decisión Sprint X.5

Approver: mesa-15 + mesa-01
Decision: APPROVED | REJECTED | DEFERRED to Sprint Y
Rationale: ...
Implementation responsible: mesa-XX
```

---

## File coordination — estructura completa

```
audit/
├── CELL-DESIGN-2026-04-26.md            # Este archivo
├── HANDOFF-2026-04-26.md                 # Handoff original
├── AUDIT-SUMMARY-2026-04-26.md           # TL;DR ejecutivo audit foundation
│
├── _inputs/                              # Contextos auditoría (4 files)
├── round1/                               # 12 R1 outputs (findings audit)
├── round2/                               # 3 R2 outputs (post-cleanup)
│   ├── ARCHITECTURE-ROBUSTNESS-REFACTOR-COST.md
│   ├── MESA-TECNICA-PROPUESTAS.md
│   └── QA-STRATEGY-2026-04-26.md
│
├── sprint-1/                             # Foundation cleanup (sequential, mostly main session)
│   ├── BACKLOG-PROGRESS.md
│   └── cell-h/                           # H low intensity (Figma setup, baseline)
│       ├── HANDOFF.md
│       └── PROGRESS.md
│
├── sprint-2/                             # Core extraction (sequential)
│   ├── cell-core/
│   │   ├── BACKLOG.md                    # micro-backlog Cell Core
│   │   ├── HANDOFF.md                    # output a main session
│   │   └── PROGRESS.md                   # status update
│   └── cell-h/                           # H medium intensity
│
├── sprint-3/                             # Paralelo: A + B + H
│   ├── cell-a/
│   ├── cell-b/
│   ├── cell-h/
│   └── rfc/
│       ├── CORE-CHANGE-001.md
│       ├── CORE-CHANGE-002.md
│       └── THEME-CHANGE-001.md
│
├── sprint-3.5/                           # Lock release + RFC consolidation
│   ├── RFC-DECISIONS.md
│   ├── LAMBDATEST-REPORT.md
│   ├── ARCHITECTURE-POST-VALIDATION.md
│   └── RETROSPECTIVE.md
│
├── sprint-4/                             # Paralelo C + D + H
├── sprint-4.5/
└── sprint-5/                             # mercadolibre (sequential)
```

---

## Modos de ejecución

### Opción simple (Sprint 3 default) — 1 sesión main

Tú abres Claude Code en la sesión main:

```bash
cd /Users/alefvaras/Documents/akibara-v2
git checkout main && git pull
claude
```

Le das este prompt al main:

> "Sprint 3 paralelo. Lee `audit/CELL-DESIGN-2026-04-26.md`. Lanza Cell A (preventas), Cell B (marketing+finance) y Cell H (design) en paralelo en 1 mensaje. Coordina outputs en `audit/sprint-3/cell-{a,b,h}/`. Avísame cuando alguna requiera aprobación destructiva o RFC core."

Yo (main) lanzo 3 Agent tool calls en paralelo:

```
Agent({subagent_type:"mesa-23-pm-sprint-planner", description:"Cell A preventas Sprint 3", prompt:"<prompt template Cell A>"})
Agent({subagent_type:"mesa-23-pm-sprint-planner", description:"Cell B marketing Sprint 3", prompt:"<prompt template Cell B>"})
Agent({subagent_type:"mesa-13-branding-observador", description:"Cell H design Sprint 3", prompt:"<prompt template Cell H>"})
```

Las 3 corren en paralelo. Tú watcheás 1 transcript con 3 outputs. Apruebas destructives en orden.

### Opción avanzada (Sprint 4+ opcional) — git worktrees

```bash
# Setup once
cd /Users/alefvaras/Documents/akibara-v2
git worktree add ../akibara-cell-c feat/akibara-inventario
git worktree add ../akibara-cell-d feat/akibara-whatsapp
git worktree add ../akibara-cell-h-s4 feat/theme-design-s4

# Terminal 1 — Cell C
cd ../akibara-cell-c && claude
# Prompt: "Eres Cell C inventario Sprint 4. Lee audit/CELL-DESIGN-2026-04-26.md sección Cell C. Ejecuta backlog."

# Terminal 2 — Cell D
cd ../akibara-cell-d && claude
# similar para Cell D

# Terminal 3 — Cell H
cd ../akibara-cell-h-s4 && claude
# similar para Cell H

# Cleanup post-merge
git worktree remove ../akibara-cell-c
git worktree remove ../akibara-cell-d
git worktree remove ../akibara-cell-h-s4
```

Pros: aislamiento físico filesystem (NO pueden tocar archivos de otras cells por accidente).
Cons: más switching mental para solo dev (3 terminals open).

**Recomendación:** Sprint 3 = Opción simple. Sprint 4+ = considerar worktrees post-experiencia.

---

## Prompt templates por cell

### Cell Core (Sprint 2)

```
Eres Cell Core. Sprint 2 secuencial. Tu objetivo: extraer akibara-core como plugin separado del actual plugin akibara monolítico.

CONTEXTO:
- Lee audit/CELL-DESIGN-2026-04-26.md sección "Cell Core"
- Lee memorias project_architecture_core_plus_addons.md + project_cell_based_execution.md
- Lee CLEANUP-PLAN-2026-04-26.md y BACKLOG-2026-04-26.md secciones Sprint 2
- Stack target: ServiceLocator + ModuleRegistry + Lifecycle hooks + WC HPOS facade + 13 módulos core
- Branch: feat/akibara-core

SUBAGENTS A USAR:
- mesa-15-architect-reviewer: diseño Service Locator + ModuleRegistry
- mesa-22-wordpress-master: WP idioms (plugin headers Requires Plugins, hooks priority, HPOS facade)
- mesa-16-php-pro: PSR-4 autoload Akibara\Core\*, namespace migration
- mesa-17-database-optimizer: schema layer helpers (sin tablas propias del core)
- mesa-11-qa-testing: test harness PHPUnit reusable por addons
- mesa-23-pm-sprint-planner: backlog Sprint 2 + DoD verification
- mesa-01-lead-arquitecto: árbitro decisiones

OUTPUT:
- Plugin akibara-core en plugins/akibara-core/ funcionando independiente
- composer.json con autoload PSR-4
- Tests passing en tests/akibara-core/
- Migration path documentado para Sprint 3 cells
- audit/sprint-2/cell-core/HANDOFF.md con specs API público para addons

LOCK POLICY:
- Mismo plugin akibara antiguo se mantiene activo en paralelo durante extracción
- Migration progresiva: módulos pasan a akibara-core uno a uno con backward compat
- Sin behavior change en frontend customer-facing (memoria feedback_minimize_behavior_change)

REGLAS DURAS:
- NO romper customer-facing flows (3 customers en prod)
- Cada commit debe pasar bin/quality-gate.sh
- Smoke prod después de cada deploy
- Sentry 24h check post-deploy
- DOBLE OK explícito Alejandro para destructivos

REPORTA AL FINAL:
- Lista de módulos migrados a akibara-core
- API pública documentada (namespaces, contracts)
- Issues encontrados (workarounds aplicados)
- RFC pendientes para Sprint X.5
- Recomendación arrancar Sprint 3 (qué cell primero)
```

### Cell A — preventas (Sprint 3 paralelo)

```
Eres Cell A. Sprint 3 paralelo (corre simultáneo con Cell B + Cell H).

OBJETIVO: extraer akibara-preventas como addon separado depending de akibara-core. Consolida:
- Plugin actual akibara-reservas (1.0.0)
- Módulos del plugin akibara: next-volume, series-notify, editorial-notify
- Theme form themes/akibara/inc/encargos.php (refactor a addon API)
- Encargos UNIFIED como subtype (respetar migración Akibara_Reserva_Migration::maybe_unify_types)

CONTEXTO:
- Lee audit/CELL-DESIGN-2026-04-26.md sección Cell A
- Lee memorias proyecto_architecture_core_plus_addons.md + project_cell_based_execution.md
- Branch: feat/akibara-preventas
- Lock: plugins/akibara-core/ y themes/akibara/ son read-only — usa RFC si necesitas cambio

SUBAGENTS A USAR:
- mesa-16-php-pro: implementation
- mesa-22-wordpress-master: WP idioms (Requires Plugins header, hooks namespaced akibara_preventas_*)
- mesa-15-architect-reviewer: architecture review
- mesa-11-qa-testing: PHPUnit + Playwright @critical (preorder flow)
- mesa-06-content-voz: UI copy chileno (preventa form, emails confirmación/lista/cancelada)
- mesa-23-pm-sprint-planner: backlog + DoD
- mesa-01-lead-arquitecto: árbitro

OUTPUT:
- Plugin akibara-preventas en plugins/akibara-preventas/ funcionando
- Tablas wp_akb_preorders + wp_akb_preorder_batches + wp_akb_special_orders (encargos subtype)
- Migration data desde plugin akibara-reservas existente (preserve customer data)
- Tests E2E reservar→admin→fulfill→cliente recibe email
- audit/sprint-3/cell-a/HANDOFF.md con migration path verificado

DEPENDENCIES con Cell H:
- Cell H provee mockups: encargos checkbox styling, preventa card 4 estados (pending/confirmed/shipping/delivered), auto-OOS preventa "fecha por confirmar"
- Si mockup no listo → stub temporal + Cell H consolida en Sprint 3.5

REGLAS DURAS:
- NO romper preventa flow para 2 encargos activos en prod (Jujutsu kaisen 24/26)
- Migration plan rollback <30 min
- Behavior change minimizar (memoria feedback_minimize_behavior_change)
- Brevo email sender testing redirige a alejandro.fvaras@gmail.com

REPORTA:
- Items completados / blockers
- RFCs core changes pendientes
- Mockups requeridos a Cell H
```

### Cell B — marketing + finance rebuild (Sprint 3 paralelo)

```
Eres Cell B. Sprint 3 paralelo (corre simultáneo con Cell A + Cell H).

OBJETIVO: extraer akibara-marketing como addon, consolidar 13 módulos marketing + REBUILD finance-dashboard manga-specific (5 widgets prioritarios).

MÓDULOS A CONSOLIDAR EN akibara-marketing:
- banner, popup, brevo (segmentación 8 listas editoriales)
- cart-abandoned (CONDICIONAL — validar Brevo upstream firing 24-48h post DNS Sprint 1)
- review-request, review-incentive, referrals
- marketing-campaigns (welcome-series, tracking)
- customer-milestones (cumpleaños/aniversario)
- welcome-discount (descuento bienvenida)
- descuentos + descuentos-tramos (taxonomía + tramos volumen)

FINANCE-DASHBOARD REBUILD MANGA-SPECIFIC (ya disabled en Sprint 2):
NO migrar las 1,453 LOC over-engineered. Build NEW con 5 widgets prioritarios:
1. Top series por volumen vendido (consume _akibara_serie de core)
2. Top editoriales (consume akibara_brevo_editorial_lists 8 listas)
3. Encargos pendientes (consume akibara_encargos_log)
4. Trending searches (consume akibara_trending_searches — One Piece 196k, etc.)
5. Stock crítico <3 unidades (queries wc_orders + postmeta _stock)

Mockup obligatorio para finance widgets — coordinar con Cell H ANTES de implementar.

CONTEXTO:
- Lee audit/CELL-DESIGN-2026-04-26.md sección Cell B
- Branch: feat/akibara-marketing
- Lock Core + Theme (read-only)

SUBAGENTS A USAR:
- mesa-16-php-pro: implementation
- mesa-09-email-qa: Brevo wiring + transactional templates (DOMAIN-SPECIFIC)
- mesa-22-wordpress-master: WP idioms
- mesa-15-architect-reviewer: architecture
- mesa-06-content-voz: copy email + UI marketing
- mesa-23-pm-sprint-planner: backlog
- mesa-01-lead-arquitecto: árbitro

OUTPUT:
- Plugin akibara-marketing en plugins/akibara-marketing/
- Tablas wp_akb_campaigns + wp_akb_email_log + wp_akb_referrals
- finance-dashboard rebuild manga-specific (5 widgets) con mockup approved
- Tests Brevo wiring (smoke send → verify Brevo logs)
- audit/sprint-3/cell-b/HANDOFF.md

DEPENDENCIES Cell H:
- Mockups: cookie banner UI, popup styling refinements, finance dashboard manga widgets, cart-abandoned email template, trust badges treatment

REGLAS DURAS:
- NO migrar a MailPoet/Klaviyo (memoria project_brevo_upstream_capabilities — Brevo es plataforma definitiva)
- Email testing redirige a alejandro.fvaras@gmail.com (mu-plugin email-testing-guard)
- 8 listas editoriales Brevo preservar IDs (Ivrea AR=24, Panini AR=25, Planeta ES=26, etc.)
- NO modificar precios (_sale_price/_regular_price/_price)

REPORTA:
- Items / blockers
- RFCs
- Mockups Cell H needed
```

### Cell C — inventario (Sprint 4 paralelo)

```
Eres Cell C. Sprint 4 paralelo (corre simultáneo con Cell D + Cell H).

OBJETIVO: extraer akibara-inventario como addon. Consolida:
- inventory module (admin tools)
- shipping (BlueX + 12 Horas couriers integrados)
- back-in-stock (avísame restock)

CONTEXTO:
- Lee audit/CELL-DESIGN-2026-04-26.md sección Cell C
- Branch: feat/akibara-inventario
- Lock Core + Theme

SUBAGENTS A USAR:
- mesa-16-php-pro: implementation
- mesa-17-database-optimizer: stock queries + depletion analytics + indexing (DOMAIN-SPECIFIC clave)
- mesa-22-wordpress-master: WP idioms
- mesa-15-architect-reviewer: architecture
- mesa-11-qa-testing: tests
- mesa-23-pm-sprint-planner: backlog

OUTPUT:
- Plugin akibara-inventario en plugins/akibara-inventario/
- Tablas wp_akb_stock_rules + wp_akb_back_in_stock_subs
- Shipping integrations (BlueX webhook + 12 Horas) preservadas
- Tests E2E checkout BlueX + 12 Horas
- audit/sprint-4/cell-c/HANDOFF.md

DEPENDENCIES Cell H:
- Mockups: stock alerts UI, back-in-stock subscription form

REGLAS DURAS:
- NO rotar BlueX API key (memoria project_no_key_rotation_policy)
- BlueX webhook hard-fail validation (F-04-* Sprint 1 fixed)
- 12 Horas courier integration preservar
```

### Cell D — whatsapp (Sprint 4 paralelo)

```
Eres Cell D. Sprint 4 paralelo (corre simultáneo con Cell C + Cell H).

OBJETIVO: refactor mínimo akibara-whatsapp para depender de akibara-core. Plugin actual ya separate (v1.3.1) → solo añadir dependency check + ServiceLocator integration.

CONTEXTO:
- Plugin actual: plugins/akibara-whatsapp/ (1 archivo .php + .css + .js)
- Branch: feat/akibara-whatsapp
- Lock Core + Theme

SUBAGENTS A USAR:
- mesa-16-php-pro: refactor mínimo
- mesa-22-wordpress-master: plugin header Requires Plugins
- mesa-15-architect-reviewer: architecture (light)
- mesa-11-qa-testing: tests
- mesa-23-pm-sprint-planner: backlog

OUTPUT:
- Plugin akibara-whatsapp con plugin header `Requires Plugins: akibara-core`
- ServiceLocator integration (consume Core utilities)
- Function akibara_whatsapp_get_business_number() preserved (números 56944242844 default)
- audit/sprint-4/cell-d/HANDOFF.md

DEPENDENCIES Cell H:
- Mockups: WhatsApp button placement variants (mobile vs desktop)

REGLAS DURAS:
- NO romper WhatsApp button visible en frontend (customer comm crítico)
- Smoke test: WhatsApp link funciona desde producto + footer
```

### Cell E — mercadolibre (Sprint 5 secuencial)

```
Eres Cell E. Sprint 5 secuencial (single, post-experiencia Sprints 3+4).

OBJETIVO: extraer akibara-mercadolibre como addon separado del plugin akibara monolítico. 4,250 LOC + tabla wp_akb_ml_items.

CONTEXTO:
- Solo 1 listing real activo (One Punch Man 3 Ivrea España $15,465 MLC)
- 3 rows wp_akb_ml_items con ml_item_id vacío (sync iniciado nunca completado)
- Branch: feat/akibara-mercadolibre
- Lock Core + Theme

SUBAGENTS A USAR:
- mesa-16-php-pro: implementation
- mesa-22-wordpress-master: WP idioms
- mesa-15-architect-reviewer: architecture
- mesa-11-qa-testing: tests
- mesa-04-mercadopago: oauth/webhook patterns reutilizables (DOMAIN-SPECIFIC)
- mesa-23-pm-sprint-planner: backlog

OUTPUT:
- Plugin akibara-mercadolibre en plugins/akibara-mercadolibre/
- Tabla wp_akb_ml_items preservada
- OAuth flow + webhook handler + sync engine + publisher + pricing strategies
- Tests sandbox MLC API
- audit/sprint-5/cell-e/HANDOFF.md

REGLAS DURAS:
- NO romper listing One Punch Man activo
- ML API sandbox para testing (MLC site_id, CLP currency)
- Decisión PM con Alejandro previa: ¿re-activar sync para los 3 rows incompletos?
```

### Cell H — Design Ops horizontal (todos los sprints)

```
Eres Cell H — Design Ops horizontal. Trabajas EN PARALELO a las verticales (Cells A-E + Core), proveyendo specs/mockups/theme code.

OBJETIVO POR SPRINT:
- Sprint 1 (LOW): Figma MCP setup, branding constants doc, audit themes/akibara, LambdaTest baseline screenshots
- Sprint 2 (MEDIUM): Component library v1 Figma, theme.json + tokens.css, mockups Sprint 3 items
- Sprint 3 (HIGH): Mockups detalle Cell A+B (preventa 4-state card, finance widgets, email templates), theme updates
- Sprint 3.5: consolidate theme deltas + LambdaTest run + token additions review
- Sprint 4 (MEDIUM): Mockups Cell C+D (stock alerts, back-in-stock form, whatsapp button variants)
- Sprint 4.5: consolidate + LambdaTest
- Sprint 5 (LOW): admin UI mercadolibre minimal

OWNS (locked durante sprints paralelos):
- themes/akibara/ (CSS, PHP templates, blocks, theme.json)
- Figma file (component library + mockups)
- LambdaTest baseline screenshots

SUBAGENTS A USAR:
- mesa-13-branding-observador: lead — observa inconsistencias visuales (NO propone — convención de su prompt)
- mesa-07-responsive: breakpoints 375/430/768/1024, container queries, fluid typography, CLS prevention
- mesa-08-design-tokens: WCAG AA/AAA contrast, focus rings (WCAG 2.4.13), motion tokens, spacing tokens (touch ≥44x44px)
- mesa-05-accessibility: ARIA, keyboard navigation, semantic HTML
- mesa-22-wordpress-master: theme idioms (theme.json, blocks, template hierarchy)
- mesa-06-content-voz: UI copy chileno neutro
- mesa-23-pm-sprint-planner: coordina queue mockups con verticales

OUTPUT POR SPRINT:
- audit/sprint-{N}/cell-h/UI-SPECS-<addon>.md (color hex, spacing, components per addon)
- Figma file actualizado (component library + mockups linked desde BACKLOG items)
- themes/akibara/ updates (CSS, PHP templates, blocks)
- LambdaTest baseline screenshots actualizadas

QUEUE MOCKUPS PRIORIZADOS (de BACKLOG PENDIENTE MOCKUP):
- Sprint 3 priority (10 items): encargos checkbox, cookie banner UI, preventa card 4 estados, newsletter footer double opt-in, auto-OOS preventa, welcome bienvenida transactional, popup styling, cart-abandoned email template, finance dashboard manga widgets, trust badges treatment
- Sprint 4 priority (6 items): back-in-stock form, stock alerts UI, whatsapp button variants, editorial color coding palette, customer milestones email cumpleaños, logo source canonical
- Branding observador items (3 deferidos M3+): tagline orden, editorial colors palette, email crimson Manga template

REGLAS DURAS:
- Mockup-before-visual obligatorio (memoria project_figma_mockup_before_visual)
- NO voseo rioplatense en copy (memoria + mesa-06)
- Touch targets ≥44x44px (WCAG 2.5.5)
- Contrast WCAG AA mínimo (AAA si es texto pequeño <18px)
- LambdaTest visual regression ejecuta en cada Sprint X.5

REPORTA:
- Items mockup completados (link Figma)
- Theme deltas pendientes para Sprint X.5 consolidate
- Specs delivered a verticales (qué Cell consumió qué spec)
- Accessibility issues encontrados (mesa-05 sweep)
```

---

## Carga de trabajo Alejandro por sprint

| Tarea | Frecuencia | Tiempo |
|---|---|---|
| Arrancar sprint (prompt main) | 1 vez/sprint | 10 min |
| Aprobar destructivos en transcript | 5-10 veces/sprint | 30 min |
| Aprobar RFCs core changes | 2-5 veces/sprint X.5 | 25 min |
| Mockups Figma (decisión + share link) | 5-10 veces/sprint | 1-2h |
| Smoke test post-deploy | 1 vez/item destructivo | 15 min × N items |
| Sentry 24h check | 1 vez/sprint | 5 min |
| Sprint X.5 RFC arbitration | 1 vez post-paralelo | 30 min |
| Retrospective | 1 vez/sprint | 15 min |
| **Total por sprint** | | **~5-7h** |

vs sprint manual ~25-30h. **Multiplicador ~5x con cells.**

---

## Quality gates obligatorios

Cada cell ANTES de merge a main:

```bash
# Layer 1: local Docker
bin/quality-gate.sh
# Corre: PHPCS WPCS, PHPStan L6, plugin-check, ESLint, Stylelint, Prettier, PHPUnit, Playwright @critical, knip, purgecss, composer-unused, Trivy, gitleaks, composer audit, npm audit, voseo grep, claims grep, secrets grep
# Target: <60s en estado green

# Layer 2: GHA workflow
git push origin feat/<addon>
# .github/workflows/quality.yml corre los mismos gates como segunda red
```

Solo si AMBOS pasan → merge a main.

---

## LambdaTest visual regression (Cell H + Sprint X.5)

Cell H ejecuta LambdaTest en:
- Sprint 1 finale: baseline screenshots (homepage, producto, checkout, mi-cuenta, encargos)
- Sprint 3.5: post-paralelo Cells A+B+H, comparar contra baseline
- Sprint 4.5: post-paralelo Cells C+D+H, comparar
- Sprint 5: post-mercadolibre admin pages

Devices/browsers:
- Mobile: 375px iPhone 12, 430px iPhone 14 Pro Max, 393px Pixel 7
- Desktop: 1280px, 1440px, 1920px
- Browsers: Chrome, Safari, Firefox

Threshold: 0% píxeles diff aceptable en componentes críticos (botón, card, form). Si falla → blocker para merge.

---

## Sentry 24h post-deploy monitoring

Por cada deploy a prod (Sprint 1 cleanups, Sprint 2 Core, etc.):
- Inicia monitoring 24h en Sentry dashboard
- Filtra: events últimas 24h vs baseline
- Threshold: 0 nuevos error types aceptable
- Si nuevo error type aparece → investigar + posible rollback

---

## Trigger arrancar Sprint 2 staging

`B-S2-INFRA-01 — Setup staging.akibara.cl` arranca al INICIO Sprint 2 week 1, NO al final.

Razón: Cell Core extraction Sprint 2 weeks 2-3 puede usar staging para integration tests. Sin staging Sprint 2, refactor Core es risk-heavy.

Esfuerzo: 4-6h setup + 2h script bin/sync-staging.sh.

---

## Failure modes + recovery

| Falla | Recovery |
|---|---|
| Cell A produce código que rompe Cell B integration | Sprint 3.5 mesa-15 + mesa-01 arbitran fix; rollback merge si severo |
| Cell H mockup no listo cuando Cell A lo necesita | Cell A stub temporal + Cell H consolida 3.5 |
| Quality gate falla en GHA pero pasa local | Sync versiones tools (Docker base image vs GHA runner) |
| LambdaTest falla en Sprint 3.5 visual regression | Cell H investiga deltas → si intencional update baseline; si no → fix CSS |
| Sentry 24h muestra nuevo error type | Investiga origen (qué deploy lo introdujo) → rollback o forward-fix |
| Cell crashea mid-sprint sin output | Re-launch cell con prompt correctivo + último HANDOFF.md como contexto |
| Branch divergence severa con main | git merge main → resolver conflicts con mesa-15 lead |
| RFC rechazado pero cell ya implementó workaround | Workaround se mantiene; RFC re-considerado en Sprint X+1.5 si problema persiste |

---

## Verificación end-to-end (smoke al inicio de cada sprint)

```bash
# 1. 22 mesa subagents instalados
ls .claude/agents/mesa-*.md | wc -l
# Expected: 23

# 2. CELL-DESIGN accesible
ls audit/CELL-DESIGN-2026-04-26.md

# 3. Memorias vigentes
ls /Users/alefvaras/.claude/projects/-Users-alefvaras-Documents-akibara-v2/memory/project_architecture_core_plus_addons.md
ls /Users/alefvaras/.claude/projects/-Users-alefvaras-Documents-akibara-v2/memory/project_cell_based_execution.md

# 4. Quality gate funciona
bin/quality-gate.sh --check
# Expected: exit 0

# 5. Git worktrees disponibles (Sprint 4+)
git worktree list

# 6. Sprint folder structure
ls audit/sprint-{1,2,3,3.5,4,4.5,5}/

# 7. Staging accesible (Sprint 2 onwards)
curl -I https://staging.akibara.cl
# Expected: HTTP 401 (basic auth gate)
```

---

**FIN DE CELL DESIGN. Próxima sesión arranca con:**

1. Lee `audit/HANDOFF-2026-04-26.md` para contexto general
2. Lee `audit/AUDIT-SUMMARY-2026-04-26.md` para state actual
3. Lee este archivo para HOW de execution
4. Lee `BACKLOG-2026-04-26.md` para qué items hacer
5. Lee memorias relevantes (`project_architecture_core_plus_addons`, `project_cell_based_execution`)
6. Arranca el sprint correspondiente con prompt template embebido aquí
