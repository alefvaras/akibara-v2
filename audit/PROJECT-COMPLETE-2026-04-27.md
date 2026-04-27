# PROJECT COMPLETE — Akibara Refactor Core+Addons (2026-04-26 / 2026-04-27)

**Para:** Alejandro Vargas (solo dev)
**Tiempo de lectura:** 8 minutos
**Status:** ✅ Refactor arquitectónico Akibara Core+Addons COMPLETO. NO más refactor.
**Próxima sesión recomendada:** Deploy staging + smoke real + activate progresivo prod (NO continuar refactoring).

---

## TL;DR ejecutivo

El proyecto de auditoría + refactor arquitectónico arrancó 2026-04-26 con foundation audit (12 mesas, ~190 findings, 30 decisiones, 14 cleanup items) y cerró 2026-04-27 con **6 plugins extraídos** (1 core + 5 addons) reemplazando el plugin monolítico legacy `akibara`. El target arquitectónico definido inicialmente fue ejecutado en **5 sprints + 2 lock-releases** (Sprint 1-5 + 3.5 + 4.5).

**Resultado final:**
- 6 plugins en `wp-content/plugins/`: `akibara-core`, `akibara-preventas`, `akibara-marketing`, `akibara-inventario`, `akibara-whatsapp`, `akibara-mercadolibre`.
- Plugin legacy `akibara` y `akibara-reservas` siguen activos con guards (coexistencia controlada hasta verify staging).
- 33,333 LOC PHP distribuidos entre los 6 plugins, todos con AddonContract pattern + plugin headers WP 6.5+.
- 7 specs E2E @critical Playwright, 1 INCIDENT estructural recuperado (INCIDENT-01).
- 16 mockups HTML/CSS Cell H (10 Sprint 3.5 + 6 Sprint 4) con tokens.css consumido.

**Lo que NO está hecho (próxima sesión):**
- Deploy a prod NO ha ocurrido — todo el refactor vive en main local + remote. Plugins NO activados en prod aún.
- Staging smoke NO ejecutado.
- Plugin legacy `akibara` NO desactivado (guards in place pero no toggle final).
- 4 pendientes operacionales documentados abajo.

---

## Stats totales — esfuerzo real vs estimado

| Sprint | Tipo | Cells | Estimate | Real | Δ | Notas |
|---|---|---|---|---|---|---|
| **1** Foundation cleanup | Secuencial | Main + H low | 30-32h | ~30h | 0% | 17/24 verificados, 7 redistribuidos a S3 Cell H. DOBLE OK 6 destructivos sin incidentes. |
| **2** Cell Core extraction + staging | Secuencial | Core + H med | 25-30h | ~10h transcript | -60% | Phase 1 cell-core released + GHA + staging setup. 6 cero-refs modules. |
| **3** Paralelo addons preventas+marketing | Paralelo | A+B+H high | 60h equiv | ~10-12h transcript | -80% (mult 5-7×) | Cells A+B+H. INCIDENT-01 mid-deploy (sitio caído ~3-4h, recuperado). |
| **3.5** Lock release + INCIDENT-01 + 2 hotfixes | Secuencial | mesa-15+01 + H | 6-8h | ~7h | 0% | Refactor estructural AddonContract (PR #9), 2 hotfixes (PR #10/#11). |
| **4** Paralelo addons inventario+whatsapp | Paralelo | C+D+H med | 35h equiv | ~31h | -11% | AddonContract pattern maduro 4ta aplicación. Cell H deferida (Haiku context fail). |
| **4.5** Lock release | Secuencial | mesa-23+11 | 4-6h | ~3h | -25% | NO-RFC + RETROSPECTIVE + QA-SMOKE. Sin Cell H reduces overhead. |
| **5** Cell E mercadolibre + Cell H carry | Secuencial | E + H deferred | 15-20h | ~14h transcript | -30% | 5ª aplicación AddonContract. Cell H Sprint 4 mockups entregados. |
| **TOTAL** | | | **~175-201h equiv estimate** | **~105h transcript** | **~ -40% vs estimate**, mult ~3-5× sequential | |

**Equivalente manual estimado:** ~250-300h sequential coding (multiplicador subagent ~2.5-3× sustained).

**Wall-clock real:** ~2 días (2026-04-26 / 2026-04-27) — sprints dispatcheados rápido, no calendar planificado.

---

## 6 plugins extraídos

| Plugin | Versión | LOC PHP | Función | Branch sprint | Merge commit |
|---|---|---|---|---|---|
| **akibara-core** | 1.0.0 | 4,484 | ServiceLocator + ModuleRegistry + Lifecycle + WC HPOS facade + 13 módulos foundation (search, rut, phone, product-badges, address-autocomplete, customer-edit-address, checkout-validation, health-check, series-autofill SEO, email-template, email-safety, category-urls, order) | feat/cell-core (Sprint 2) | PR #1 (3a86150 + ad3c60f) |
| **akibara-preventas** | 1.0.0 | 6,855 | Reservas + next-volume + series-notify + editorial-notify + encargos unified subtype. Tablas wp_akb_preorders + wp_akb_preorder_batches + wp_akb_special_orders. | feat/akibara-preventas (Sprint 3) | PR #6 (e8a88e6) |
| **akibara-marketing** | 1.0.0 | 12,441 | 13 módulos: banner + popup + brevo (8 listas editoriales) + cart-abandoned (deprecated stub) + review-request + review-incentive + referrals + marketing-campaigns + customer-milestones (deferred loader) + welcome-discount + descuentos + descuentos-tramos + finance-dashboard rebuild (5 widgets manga-specific). | feat/akibara-marketing (Sprint 3) | PR #8 (5a22245 + 0a1c65f) |
| **akibara-inventario** | 1.0.0 | 4,426 | Inventory admin tools + shipping (BlueX + 12 Horas couriers) + back-in-stock. Tablas wp_akb_stock_rules + wp_akb_back_in_stock_subs. | feat/akibara-inventario (Sprint 4) | 0f81462 (direct merge) |
| **akibara-whatsapp** | 1.4.0 | 605 | Refactor v1.3.1 → v1.4.0 con AddonContract + akibara-core dependency. WhatsApp business number 56944242844 helper. | feat/akibara-whatsapp (Sprint 4) | dcc67f2 (direct merge) |
| **akibara-mercadolibre** | 1.0.0 | 4,522 | OAuth PKCE + webhook handler + sync engine + publisher (764 LOC) + pricing markup + orders ML→WC. Tabla wp_akb_ml_items preservada. | feat/akibara-mercadolibre (Sprint 5) | f708476 (direct merge) |
| **TOTAL** | | **33,333 PHP LOC** | distribuidos en 6 plugins con AddonContract pattern, plugin headers WP 6.5+, `Requires Plugins: akibara-core` (excepto core) | | |

### Cell H mockups Sprint 4 deferred (entregados Sprint 5)

| Mockup | LOC HTML/CSS | Consumer plugin |
|---|---|---|
| 11-stock-alerts.html | 945 | akibara-inventario |
| 12-back-in-stock-form.html | 734 | akibara-inventario (BIS form) |
| 13-whatsapp-button-variants.html | 685 | akibara-whatsapp |
| 14-editorial-color-palette.html | 635 | akibara-inventario / akibara-marketing |
| 15-customer-milestones-email.html | 541 | akibara-marketing / Brevo |
| 16-logo-canonical.html | 680 | Cell H (theme/akibara) |
| INDEX.html | 401 | Navegación |
| UI-SPECS-{inventario,whatsapp,branding}.md | 485 | Cells C+D+H |
| HANDOFF.md | 119 | Mesa técnica |
| **Total Sprint 4 Cell H** | **5,225** | mergeado vía e4ebab9 (Sprint 5 cierre) |

**Sprint 3.5 Cell H también entregó 10 mockups previos** (audit/sprint-3/cell-h/) — total acumulado **16 mockups HTML/CSS** todos consumiendo `tokens.css`.

---

## Lecciones clave aprendidas (5 sprints)

### 1. INCIDENT-01 — Doc drift es síntoma, NO causa raíz

**Sprint 3 deploy resultó TypeError fatal — sitio caído ~3-4h.** Root cause: HANDOFF.md de Sprint 2 documentó signature `init($bootstrap)` con 1 arg, pero código real desde commit inicial pasaba 2 args (`init(Bootstrap $bootstrap, ?ServiceLocator $services = null)`). Cell A escribió type hint estricto siguiendo el HANDOFF → TypeError compile-time.

**Solución estructural (PR #9 refactor robust):**
- **AddonContract interface** (type-safe) — fail compile-time, zero maintenance burden vs custom PHPStan rules.
- **Bootstrap auto-recovery** — per-addon try/catch isolation. Falla aislada, otros addons siguen UP.
- **Drift-impossible HANDOFF** — doc NO contiene signatures. Source-of-truth = código (`Bootstrap.php`, `AddonContract.php` directos).

**8 lecciones formalizadas (L-01 a L-08) en `audit/sprint-3.5/INCIDENT-01.md`:**
- L-01 Doc drift es síntoma, no causa.
- L-02 Type system > tests + docs.
- L-03 Per-addon failure isolation > shared hook.
- L-04 "Backward compat" sin external consumers reales = trampa.
- L-05 Activations de plugins NO testean en CI por default.
- L-06 Smoke prod automatizado funcionó (parcialmente).
- L-07 Status quo bias es trampa cognitiva.
- L-08 User explicit "robustez máxima" supersede defaults pragmáticos.

### 2. AddonContract pattern probado 5 veces sin incidentes

| Cell | Sprint | AddonContract aplicado | Incidentes |
|---|---|---|---|
| A — preventas | 3 | post hoc tras INCIDENT-01 (Sprint 3.5) | INCIDENT-01 → resuelto |
| B — marketing | 3 | post hoc tras INCIDENT-01 (Sprint 3.5) | 0 |
| C — inventario | 4 | desde día 1 | 0 |
| D — whatsapp | 4 | desde día 1 | 0 |
| E — mercadolibre | 5 | desde día 1 | 0 |

**El pattern es terminal.** NO requiere variantes para futuras extracciones (si las hubiere). Ver template implementación en `audit/sprint-3.5/INCIDENT-01.md` recovery section + ejemplos vivos en cualquier addon `src/Plugin.php` + entry file `akibara-<name>.php`.

### 3. Cell H Design Ops es horizontal, no critical-path

Sprint 4 Cell H falló (Haiku context limit) y NO bloqueó Cells C+D — validó el diseño cell-based execution. Cell C aplicó 3 fixes Cell H preexistentes (mesa-07/08/05) sin bloqueo. Cell D quedó CSS verbatim — placement variants pasaron a Sprint 5.

**Acción sostenida para futuros cells horizontales:** si Cell H falla mid-sprint, escalar a Opus inmediato — no acumular deferral.

### 4. Plugin namespace collision policy (PR #10)

Email classes `Akibara_Email_*` en `akibara-preventas` colisionaron con plugin legacy `akibara-reservas` (sigue activo en prod). Solución: rename a `AKB_Preventas_Email_*`. Lección: addon plugins con names compartidos requieren prefix únicos (`AKB_Preventas_*`, `AKB_Marketing_*`).

### 5. Legacy plugin coexistence policy (PR #11)

Plugin legacy `akibara` carga modules ya migrados a addons → guard `AKB_MARKETING_LOADED || AKB_PREVENTAS_LOADED` en `akibara.php` skip migrated modules. Lección: durante migración progresiva, plugin source siempre necesita guard de plugin destination.

### 6. Living docs update policy en tiempo real

Memoria `project_living_docs_update_policy.md` post-Sprint 3.5: TODOS los agentes (main session + 22 mesa-NN-* + cells) DEBEN mantener BACKLOG/CLEANUP-PLAN/AUDIT-SUMMARY/SPRINT-EXECUTION-GUIDE updated en tiempo real, NO acumular catch-up retroactivo (gap detectado Sprint 1-3.5).

---

## Pendientes operacionales (NO bloqueantes pero awareness)

Estos pendientes NO bloquean el cierre del refactor pero requieren atención antes/durante deploy a prod:

### 1. Staging smoke obligatorio para Cells C+D+E (3 plugins) antes activate prod

Sprint 4 procedió sin staging porque scope era acotado. Antes de activar `akibara-inventario` + `akibara-whatsapp` + `akibara-mercadolibre` en prod, smoke en `staging.akibara.cl`:

- **akibara-inventario:** checkout BlueX + 12 Horas + BIS subscribe + restock notify
- **akibara-whatsapp:** link funciona desde producto + footer + email CTA
- **akibara-mercadolibre:** sandbox MLC API + listing One Punch Man health

### 2. Legacy coexistence verification

Plugin legacy `akibara` con módulos migrados sigue activo. Antes de activar nuevos addons en prod:

- Verificar guards `AKB_PREVENTAS_LOADED || AKB_MARKETING_LOADED || AKB_INVENTARIO_LOADED || AKB_ML_LOADED` skip migrated modules.
- AJAX action `akb_inv_products` no colisiona entre legacy y nuevo.
- BIS notify cron hook duplicado: hipótesis del Cell C HANDOFF — necesita verificación.
- Plugin `akibara-reservas` legacy sigue activo (reemplazado por `akibara-preventas` con prefix únicos).

### 3. Sentry T+24h post cada deploy adicional

Refactor solo consolidó código en main local + remote; deploy a prod es paso separado. Cuando Alejandro deploy a Hostinger:

- Smoke prod home + producto test + checkout flow.
- Sentry dashboard últimas 24h → 0 nuevos error types.
- Si nuevo `TypeError` con culprit `wp-content/plugins/akibara-mercadolibre*` → page Akibara Owner inmediato (B-S4-OBS-01 alert rule pendiente).

### 4. Decisión PM 3 rows mercadolibre incompletos

Tabla `wp_akb_ml_items` tiene 3 rows con `product_id` 21761 + 15326 (incompletos por OAuth flow legacy). Decisión Alejandro pendiente:

- **Opción A:** re-activar sync para esos 3 rows post-deploy → completar OAuth + verify listings.
- **Opción B:** marcar como `status=archived` + dejar 1 listing real activo (One Punch Man 3 Ivrea España $15,465 MLC).

NO bloquea cierre Sprint 5; se ejecuta cuando addon esté activo en prod.

### 5. Cleanup adicional pendientes

- Cupones WC: crear `LECTOR5` (10%, 30 días) y `VIP15` (15%, permanente) en WC Coupons (item 15 Cell H).
- Verificar archivos SVG logo en `wp-content/themes/akibara/assets/images/` (5 archivos esperados — `logo.svg` + variants).
- `bin/quality-gate.sh` local creation (orquesta GHA local equivalent — pendiente Sprint 4.5 carry-over).
- Branch hygiene: limpiar `feat/theme-design-s4` (vacío post-merge) + branches feat/akibara-* post-deploy.
- B-S4-INFRA-01 (staging.akibara.cl activación) — bloqueador formal para cualquier smoke real, pendiente.
- B-S4-OPS-01 (`.claude/settings.json` con permission allowlist `bin/emergency-disable-plugin.sh`).
- B-S4-CORE-01 (admin notice helper akibara-core surface `akibara_disabled_addons`).
- B-S4-OBS-01 (Sentry alert TypeError culprit `akibara*`).
- B-S4-HEALTH-01 (health-check module legacy chequea constantes obsoletas — endpoint reporta `degraded` perpetuo).
- B-S4-PREVENTAS-01 (`/mis-reservas/` 404 cuando user not logged → esperado redirect 302 login).

---

## Recomendación próxima sesión

**NO continuar refactor.** El refactor arquitectónico Akibara Core+Addons está COMPLETO. El siguiente trabajo NO es más refactor, es **deploy + activate + monitor**:

### Próxima sesión (~3-5h Alejandro time)

1. **Pre-deploy preparation (~30 min):**
   - Activar `staging.akibara.cl` (B-S4-INFRA-01) — Hostinger panel + DNS verify.
   - Sync server-snapshot → staging con bin/sync-staging.sh.
   - Anonimizar PII en wpstg_* tables.

2. **Staging smoke real (~1-2h):**
   - Activar 5 addons uno a uno: core → preventas → marketing → inventario → whatsapp → mercadolibre.
   - Smoke entre cada activación: home + producto test + checkout BlueX + checkout 12 Horas + BIS subscribe + WhatsApp link + ML listing health.
   - Sentry T+30 monitoring post-each-activate.

3. **Activate progresivo prod (~1h con Sentry monitoring):**
   - Solo después de staging smoke verde.
   - Deploy via `bin/deploy.sh` (rsync con excludes obligatorios — NO tests/vendor/coverage).
   - Activar addons mismo orden que staging.
   - Sentry T+30 + T+24h monitoring per-addon.
   - Plugin legacy `akibara` se mantiene activo con guards (NO desactivar hasta verify all addons OK 7+ días).

4. **Post-deploy cleanups (~30-60 min):**
   - Decidir 3 rows mercadolibre (Opción A / B).
   - Cupones WC LECTOR5 + VIP15.
   - Branch hygiene cleanup.

### Items growth-deferred (NO arrancar sin trigger customer/mo)

Ver `BACKLOG-2026-04-26.md` sección "Sprint 4+ Growth-deferred" — Milestones M1 (5 customers/mo), M2 (25), M3 (50), M4 (100), M5+ (250+ → re-audit completo). NO arrancar antes de hitar trigger cuantitativo.

### Re-evaluación arquitectónica @ M5+ (250+ customers/mo)

- Re-correr mesa técnica completa (no mini-foundation).
- Considerar: PHP version upgrade, headless si tráfico justifica, CDN imágenes producto, microservices.
- Decisión PM con dueño en sesión separada.

---

## Referencias clave

| Documento | Propósito |
|---|---|
| `audit/AUDIT-SUMMARY-2026-04-26.md` | Stats foundation audit + decisiones arquitectónicas + Sprint 1-5 status |
| `audit/SPRINT-EXECUTION-GUIDE-2026-04-26.md` | Prompts per sprint + DoD + lecciones por sprint + failure modes table |
| `audit/CELL-DESIGN-2026-04-26.md` | 6 cells design (5 verticales + 1 horizontal) + lock policy + RFC pattern |
| `audit/HANDOFF-2026-04-26.md` | Original audit handoff (pre-refactor target) |
| `BACKLOG-2026-04-26.md` | Backlog operational items por sprint + growth-deferred milestones |
| `CLEANUP-PLAN-2026-04-26.md` | 17 cleanup items con DOBLE OK requirements |
| `audit/sprint-{N}/RETROSPECTIVE.md` | Retrospective per sprint con lecciones aprendidas |
| `audit/sprint-3.5/INCIDENT-01.md` | Postmortem TypeError fatal + 8 lecciones (L-01 a L-08) |
| `audit/sprint-3.5/RFC-DECISIONS.md` | RFCs Sprint 3 arbitradas |

### Memorias activas (auto-cargan)

Ver `~/.claude/projects/-Users-alefvaras-Documents-akibara-v2/memory/MEMORY.md` — incluye:
- `project_architecture_core_plus_addons.md` — Target arquitectónico
- `project_cell_based_execution.md` — Stack execution
- `project_living_docs_update_policy.md` — Update policy
- `project_quality_gates_stack.md` — 17 tools
- `project_brevo_upstream_capabilities.md` — NO rebuild Brevo features
- `project_no_key_rotation_policy.md` — 7 keys NO rotar
- `project_deploy_workflow_docker_first.md` — Deploy workflow
- `project_figma_mockup_before_visual.md` — Mockup-before-visual
- `feedback_max_robustness.md` — Robustez > pragmatic (post-INCIDENT-01)
- `feedback_minimize_behavior_change.md` — Default minimize change

---

## Cierre del proyecto

**Refactor arquitectónico Akibara Core+Addons:** ✅ COMPLETO (Sprints 1-5 + 3.5 + 4.5).

**Estado del repositorio main local + remote:**
- 6 plugins extraídos en `wp-content/plugins/`.
- Plugin legacy `akibara` y `akibara-reservas` siguen presentes en repo (NO removidos hasta verify post-deploy).
- 7 specs E2E @critical Playwright presentes.
- 16 mockups HTML/CSS Cell H acumulados (Sprint 3.5 ×10 + Sprint 4 ×6).
- 1 INCIDENT (INCIDENT-01) recuperado con refactor estructural permanente.
- 0 RFCs pendientes (todos arbitrados Sprint 3.5).

**Estado de prod:** SIN cambios. Plugin legacy `akibara` activo. Addons NO activados aún.

**Recomendación final:** Próxima sesión NO refactor. Deploy staging + activate progresivo + monitor 24h. Después arrancar items growth-deferred solo cuando milestone customer/mo aplique.

---

**Cerrado por:** Sprint 5 closeout 2026-04-27
**Siguiente acción Alejandro:** Activar staging.akibara.cl + smoke real + deploy progresivo prod
