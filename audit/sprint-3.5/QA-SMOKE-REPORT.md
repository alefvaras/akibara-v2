# QA-SMOKE-REPORT — Sprint 3.5 Lock Release

**agent:** mesa-11-qa-testing
**round:** Sprint 3.5
**date:** 2026-04-27
**scope:** Pre-merge QA analysis + Sentry 24h checkpoint plan. Covers smoke coverage gaps, Sentry watch plan, legacy module state, E2E spec gap analysis, per-branch gate plan, and post-merge timeline.
**files_examined:** 14
**findings_count:** { P0: 1, P1: 2, P2: 3, P3: 1 }

---

## 1. Smoke Prod Analysis

### 1.1 The 6 Core Checks (`--quick` mode)

| # | Check | URL | Expected | Method |
|---|---|---|---|---|
| 1 | `home` | `https://akibara.cl/` | HTTP 200 | `check_http` |
| 2 | `wp-admin redirect` | `https://akibara.cl/wp-admin/` | HTTP 302 | `check_http` |
| 3 | `checkout` | `https://akibara.cl/checkout/` | HTTP 302 | `check_http` |
| 4 | `mi-cuenta` | `https://akibara.cl/mi-cuenta/` | HTTP 200 | `check_http` |
| 5 | `xmlrpc deny` | `https://akibara.cl/xmlrpc.php` | HTTP 403 | `check_http` |
| 6 | `REST users hidden` | `https://akibara.cl/wp-json/wp/v2/users` | HTTP 404 | `check_http` |

Full mode adds: 6 security header checks, 3 functional checks (branding, sitemap, robots), 2 SEO checks (BreadcrumbList JSON-LD). Total: 17 checks in full mode.

### 1.2 Coverage Analysis

**Cubierto:** home loads, wp-admin redirect, checkout redirect, mi-cuenta, xmlrpc deny, REST users hidden, security headers, branding home, sitemap, robots.

**NO cubierto — gaps críticos para Sprint 3:**

| Gap | Por qué importa |
|---|---|
| `/encargos/` endpoint 200 | Cell A movió encargos a akibara-preventas. Si el plugin no activa correctamente → 500 silencioso. |
| `wp_ajax_akibara_encargo_submit` responde sin 500 | Coexistencia shim theme + plugin = riesgo double-registration. |
| `akibara-preventas` plugin activo en prod | No hay check de `AKB_PREVENTAS_LOADED` definida. |
| WooCommerce orders page funcional | Si akibara-preventas rompe hooks WC en boot, las órdenes pueden fallar. |
| REST health endpoint akibara | `GET /wp-json/akibara/v1/health` devuelve 200 (cubierto en E2E pero NO en smoke). |
| Cron hooks registrados | `akb_reservas_check_dates` + `akibara_next_volume_check` sin smoke wp-cron. |
| `/mis-reservas/` endpoint | Nuevo endpoint del plugin akibara-preventas. |

**Recomendación Sprint 3.5 (PRE-merge):** Agregar 3 checks a `run_core_checks()`:

```bash
check_http "encargos page"        "$PROD_URL/encargos/"       "200"
check_http "mis-reservas redirect" "$PROD_URL/mis-reservas/"   "200|302"
check_http "akibara REST health"   "$PROD_URL/wp-json/akibara/v1/health" "200"
```

### 1.3 Timing de ejecución

**Pre-merge (por branch):**

```bash
# Tras quality-gate verde:
BASE_URL=https://staging.akibara.cl bash scripts/smoke-prod.sh --quick
```

**Post-merge:** T+0 inmediato, T+15min full mode, T+1h checkpoint, T+24h Sentry formal.

---

## 2. Sentry 24h Checkpoint Plan Formal

### 2.1 Baseline Pre-S3

Del archivo `audit/sprint-3/SENTRY-BASELINE-PRE-S3.md`:

- Último release Sentry deployed prod: `akibara-ccc5faf` (2026-04-26 16:40 UTC).
- Sprint 3 cells **no están deployed en prod todavía** — el reloj 24h empieza al primer deploy.
- **Noise floor conocido:** 100 issues `ErrorException: Deprecated: Creation of dynamic property` con culprit en `bluex-for-woocommerce`. Tolerable indefinidamente.
- Sprint 2 cell-core commits merged a main pero no deployed.

**Timestamp de inicio del reloj 24h:** Momento del deploy completo + primer request post-deploy. El Sentry release tag será `akibara-<commit-hash-7-chars>` del merge commit. Verificar con `find_releases`.

### 2.2 Comando MCP del Checkpoint Formal

Ejecutar a las 13:37 del 2026-04-28 (T+24h estimado):

```
mcp__e954486c-025a-4747-9930-137e870fd2e3__search_issues(
  organizationSlug='akibara',
  projectSlug='php',
  naturalLanguageQuery='unresolved issues firstSeen >= 2026-04-27 18:00 UTC with culprit not in bluex-for-woocommerce'
)
```

Reemplazar `2026-04-27 18:00 UTC` con timestamp real del deploy T+0. Usar `firstSeen` del release encontrado en `find_releases` como corte.

Segundo query para namespace akibara:

```
mcp__e954486c-025a-4747-9930-137e870fd2e3__search_issues(
  organizationSlug='akibara',
  projectSlug='php',
  naturalLanguageQuery='level:error OR level:fatal firstSeen last 24h culprit contains akibara'
)
```

### 2.3 Trigger Criteria

**GREEN:**
- 0 issues con `firstSeen >= T+0` con culprit en namespaces:
  - `wp-content/plugins/akibara-preventas/`
  - `wp-content/plugins/akibara-marketing/`
  - `wp-content/themes/akibara/`
  - `wp-content/mu-plugins/akibara-`
  - `wp-content/plugins/akibara/`
- Issues nuevos que existan deben ser `level:warning|info` sin users afectados.

**RED (rollback):**
- Cualquier `level:error|fatal` en namespaces akibara.
- Cualquier issue nuevo con `count >= 3` en 30min.
- Cualquier `Cannot redeclare` / `Fatal error` con clase `Akibara_*`.
- Exception type ≠ Deprecated en akibara-preventas/marketing.

**Ruido tolerado:**
- BlueX deprecations (100+ issues pre-existentes).
- `level:warning|info` pre-existentes sin cambio frecuencia.
- Sentry release `4.6.2` (runtime PHP/WC).

### 2.4 Action Plan Post-Checkpoint

**Si GREEN:**
1. Documentar en `audit/sprint-3.5/SENTRY-24H-RESULT.md`.
2. Escalar Opción A RFC THEME-CHANGE-01 (require condicional en functions.php) en hotfix branch separado `hotfix/theme-encargos-conditional-load` (gated por T+7d verde, NO inmediato).
3. Hotfix pasa quality-gate completo + smoke staging antes de deploy.
4. Iniciar ticket BlueX PHP 8.2 deprecation cleanup para Sprint 4.

**Si RED:**
1. Identificar PR culpable comparando `culprit` contra los 3 PRs Sprint 3.
2. Rollback inmediato del PR específico (`bin/deploy.sh --rollback` o revert manual Hostinger).
3. Abrir `audit/sprint-3.5/INCIDENT-01.md` con: timestamp, culprit, Sentry URL, PR culpable, root cause hipótesis.
4. Reabrir RFC correspondiente.
5. NO redeployar hasta reproducir en staging y confirmar fix.

---

## 3. Plugin Akibara Legacy Verification

### 3.1 Módulos Migrados (legacy registry intentará cargar paths inexistentes post-deploy)

| Módulo legacy | Migrado a | Riesgo |
|---|---|---|
| `brevo` | `akibara-marketing/modules/brevo/` | Bajo — guard `AKB_MARKETING_BREVO_LOADED` |
| `banner` | `akibara-marketing/modules/banner/` | Bajo |
| `popup` | `akibara-marketing/modules/popup/` | Bajo |
| `review-request`, `review-incentive`, `referrals` | `akibara-marketing/modules/*/` | Bajo |
| `marketing-campaigns`, `finance-dashboard` | `akibara-marketing/modules/*/` | Bajo |
| `descuentos`, `welcome-discount` | `akibara-marketing/modules/*/` | Bajo |
| `next-volume` | `akibara-preventas/modules/next-volume/` | **MEDIO** — ver F-01 |
| `series-notify` | `akibara-preventas/modules/series-notify/` | Medio |
| `editorial-notify` | `akibara-preventas/modules/editorial-notify/` | Bajo |

### 3.2 Módulos NO migrados — siguen ACTIVOS en legacy `akibara.php`

| Módulo | Sprint desactivación |
|---|---|
| `shipping` (BlueX + 12Horas) | No antes Sprint 5 |
| `rut`, `phone`, `installments`, `checkout-validation` | Sprint 4 |
| `back-in-stock`, `product-badges` | Sprint 4-5 |
| `health-check` | Sprint 4 (a akibara-core) |
| `inventory` | Sprint 5 (akibara-inventario) |
| `mercadolibre` | Sprint 5 |
| `ga4`, `series-autofill` | Sprint 4-5 |
| `customer-edit-address`, `address-autocomplete` | YA migrado — guard `AKIBARA_CORE_PLUGIN_LOADED` |

### 3.3 Verificación Double-Registration de Hooks

**`wp_ajax_akibara_encargo_submit`:**
- Plugin: `akibara-preventas/modules/encargos/module.php:184-185` define `AKB_PREVENTAS_ENCARGOS_LOADED`.
- Theme: `themes/akibara/inc/encargos.php:25-26` guarded por `if (defined('AKB_PREVENTAS_ENCARGOS_LOADED')) return;`.
- **Estado en prod ACTUAL (pre-deploy):** la versión `server-snapshot` NO tiene el guard. El guard solo existe en workspace post-Sprint-3.
- Conclusión: double-registration NO ocurrirá post-deploy (guard llega junto). Pero si el deploy de akibara-preventas llega ANTES del tema actualizado → ventana de riesgo.
- **Mitigación:** deploy debe ser atómico o secuenciado: **primero tema actualizado, luego activar akibara-preventas**.

---

## 4. E2E Playwright @critical Analysis

### 4.1 Estado Actual — 4 Specs Existentes

| Spec | Tag | Tests | Cobertura |
|---|---|---|---|
| `golden-flow.spec.ts` | @critical | 6 | Home, catalog, product detail, search AJAX, sitemap, health REST |
| `preorder-flow-confirmada.spec.ts` | @critical | 5 | Preventa page, add-to-cart AJAX, admin orders, mis-reservas auth, email guard |
| `preorder-flow-cancelada.spec.ts` | @critical | 6 | PHP fatal check, panel cancelación, WC email classes, DB tables |
| `preorder-flow-lista.spec.ts` | @critical | 5 | `/encargos/` page, form fields, AJAX submit, admin panel, DB health |

**Total tests:** 22 en 4 specs. Target: 16 specs. Gap: 12 specs (75%).

### 4.2 Casos Críticos NO Cubiertos

| Flujo faltante | Prioridad |
|---|---|
| Checkout completo (guest + registrado) | P0 |
| BACS payment processing | P0 |
| Mercado Pago redirect smoke | P1 |
| RUT validation frontend | P1 |
| Customer address edit (akibara-core) | P1 |
| Brevo subscription on order complete | P1 |
| Login / register flow | P1 |
| Product search autocomplete | P2 |
| Preventa fecha-cambiada email flow | P2 |
| Series-notify cron smoke | P2 |
| Admin Brevo backfill panel | P2 |
| Product badges visible in catalog | P3 |

### 4.3 Recomendación Sprint 4-5 — 12 Specs Adicionales

**Sprint 4 (6 specs P0/P1):**
1. `checkout-flow-guest.spec.ts`
2. `checkout-flow-bacs.spec.ts`
3. `login-register-flow.spec.ts`
4. `rut-validation.spec.ts`
5. `customer-address-edit.spec.ts`
6. `brevo-subscription-smoke.spec.ts`

**Sprint 5 (6 specs P1/P2):**
7. `mercadopago-redirect-smoke.spec.ts`
8. `preventa-fecha-cambiada.spec.ts`
9. `search-autocomplete.spec.ts`
10. `admin-brevo-panel.spec.ts`
11. `series-notify-admin.spec.ts`
12. `product-badges-catalog.spec.ts`

---

## 5. Pre-Merge QA Gate por Branch

### 5.1 `feat/akibara-preventas`

```bash
bash scripts/quality-gate.sh                                  # Step 1
BASE_URL=https://staging.akibara.cl bash scripts/smoke-prod.sh --quick   # Step 2
# /encargos/ + /mis-reservas/ check manual (gap F-03)
npx playwright test tests/e2e/critical/preorder-flow-{confirmada,cancelada,lista}.spec.ts \
  --project=chromium --reporter=list                          # Step 4
bin/wp-ssh eval "echo defined('AKB_PREVENTAS_ENCARGOS_LOADED') ? 'OK' : 'MISSING';"  # Step 5
# Sentry pre-deploy baseline
```

**Exit criteria:** quality-gate 0 FAIL + smoke staging 0 FAIL + 3 preorder specs 0 FAIL + `AKB_PREVENTAS_ENCARGOS_LOADED` confirmado.

### 5.2 `feat/akibara-marketing`

```bash
bash scripts/quality-gate.sh
BASE_URL=https://staging.akibara.cl bash scripts/smoke-prod.sh
# Brevo wiring smoke MANUAL:
# - wp-admin → Akibara → Brevo panel carga sin fatal
# - WC → Settings → Emails: NO duplicate brevo email
# - Disparar Brevo sync, verificar Sentry 5min sin errors namespace akibara-marketing
bin/wp-ssh eval "var_dump(has_action('woocommerce_thankyou', 'akibara_ga4_purchase'));"
```

**Exit criteria:** quality-gate 0 FAIL + smoke 0 FAIL + Brevo panel manual + 0 errors Sentry.

### 5.3 `feat/theme-design-s3`

```bash
bash scripts/quality-gate.sh
bin/npx stylelint wp-content/themes/akibara/assets/css/tokens.css --allow-empty-input
BASE_URL=https://staging.akibara.cl bash scripts/smoke-prod.sh --quick
# LambdaTest visual regression: DEFERRED Sprint 4.5 per Q3 plan decision
```

**Exit criteria:** quality-gate 0 FAIL + tokens.css válido + smoke 0 FAIL + LambdaTest deferred.

---

## 6. Post-Merge QA Timeline

### T+0 — Merge / Deploy iniciado

```bash
BASE_URL=https://akibara.cl bash scripts/smoke-prod.sh --quick
mcp__e954486c-025a-4747-9930-137e870fd2e3__find_releases(
  organizationSlug='akibara', projectSlug='php', query='akibara-'
)
```

Si 1 FAIL en cualquier check core: **rollback inmediato sin esperar T+15.**

### T+15min — Monitoring

```bash
BASE_URL=https://akibara.cl bash scripts/smoke-prod.sh
mcp__e954486c-025a-4747-9930-137e870fd2e3__search_issues(
  organizationSlug='akibara', projectSlug='php',
  naturalLanguageQuery='level:error firstSeen last 15 minutes'
)
bin/wp-ssh log tail --bytes=5000 --type=error
bin/wp-ssh cron event list --format=table
```

### T+1h — Estabilización

```bash
BASE_URL=https://akibara.cl bash scripts/smoke-prod.sh --quick
bin/wp-ssh eval "
  \$orders = wc_get_orders(['limit' => 5, 'orderby' => 'date', 'order' => 'DESC']);
  foreach (\$orders as \$o) { echo \$o->get_id() . ' | ' . \$o->get_status() . PHP_EOL; }
"
```

### T+24h — Sentry Checkpoint Formal (2026-04-28 ~13:37)

Ejecutar plan Sección 2. GREEN o RED define escalation Opción A.

### T+7d — Stabilization Checkpoint

- 0 issues en namespaces akibara durante 7d → Opción A puede ejecutarse Sprint 4.
- Documentar en `audit/sprint-3.5/7D-STABILITY-REPORT.md`.

---

## Findings Consolidados

### F-01: Double-cron-hook silente — `next-volume` — P1

**Archivo(s):** `wp-content/plugins/akibara-preventas/modules/next-volume/module.php:51` + `server-snapshot/.../akibara/modules/next-volume/module.php:46`

**Descripción:** En prod actual, `akibara.php` carga el módulo legacy primero, definiendo `akibara_process_next_volume_emails()`. Cuando akibara-preventas inicia, su guard `if (! function_exists(...))` descarta la nueva función. El cron hook queda apuntando a la implementación LEGACY. La nueva lógica preventas (que usa `AkibaraBrevo` de akibara-core) nunca ejecuta — silent bug de comportamiento.

**Mitigación:** En el deploy Sprint 3, `akibara/modules/next-volume/module.php` ya no existe en el package (solo `shipping/`). El registry silencia el módulo legacy y akibara-preventas toma control. Verificar en staging pre-merge.

**Sprint:** S3.5. Esfuerzo: S.

### F-02: `akibara.php` registra módulos legacy sin guard — P2

**Archivo:** `wp-content/plugins/akibara/akibara.php:116-175`

**Descripción:** No hay `if (! defined('AKB_MARKETING_LOADED'))` antes de registrar módulos migrados. En prod post-deploy, paths físicos eliminados → no se carga. En staging con snapshot completo coexistiendo → riesgo medio.

**Propuesta:** Agregar guard condicional en `akibara.php` wrapping registros migrados a akibara-marketing/preventas.

**Sprint:** S3.5 (preventiva) o S4 (cleanup). Esfuerzo: S.

### F-03: Smoke prod cubre 0/3 endpoints críticos Sprint 3 — P1

**Descripción:** `/encargos/`, `/mis-reservas/`, `/wp-json/akibara/v1/health` no en `run_core_checks()`. PHP fatal en cualquiera no detectado en T+0 ni T+15min.

**Propuesta:** Agregar 3 checks ANTES del merge Sprint 3.

**Sprint:** S3.5 pre-merge. Esfuerzo: S.

### F-04: E2E gap del 75% — P2

4 specs vs 16 target. Distribuir 12 en Sprint 4-5 según Sección 4.3.

**Sprint:** S4-S5. Esfuerzo: L total.

### F-05: Deploy secuenciación no documentada — encargos shim — P1

**Descripción:** RFC THEME-CHANGE-01 documenta el workaround pero no especifica orden de deploy. Si tema actualizado llega DESPUÉS de activar akibara-preventas → ventana double-registration. Ambos handlers ejecutan, segundo dispara warning headers ya enviados.

**Propuesta:** Documentar en runbook deploy: orden SIEMPRE (1) deploy tema con guard, (2) activar akibara-preventas.

**Sprint:** S3.5. Esfuerzo: S.

### F-06: `ga4` module orphan en legacy — P3

Sigue registrado en `akibara.php` sin plugin destino. Sprint 4 decisión: ¿akibara-marketing analytics group o akibara-analytics future?

**Sprint:** S4 decisión. Esfuerzo: S.

---

## Hipótesis para Iter 2

1. **Brevo list IDs en legacy vs marketing:** Posible conflicto de IDs en staging con ambos plugins activos. Test en staging.
2. **`akibara_v9_deactivate` hook:** Si dueño desactiva legacy durante transición, cron hooks `akibara_next_volume_check` y `akibara_brevo_weekly_sync` (ahora propiedad nuevos plugins) quedan huérfanos.
3. **Staging vs Prod filesystem divergence:** Snapshot parcial sin eliminar legacy → double-registration que no ocurre en prod → false positives.
4. **`series_notify` y `editorial_notify` cron hooks** en deactivation hook akibara.php — no en `wp_clear_scheduled_hook`. Análisis hooks paralelos.
5. **WooCommerce email classes filter prioridad:** Si WC llama `woocommerce_email_classes` antes de bootear akibara-preventas, clases no quedan registradas.

---

## Areas que NO cubrí (out of scope)

- Análisis código PHP profundo de los 28 módulos legacy.
- LambdaTest visual testing real (deferred Sprint 4.5).
- PHPUnit test coverage.
- Security testing de endpoints AJAX (nonce, rate limiting).
- Performance benchmarks `akb_marketing_run_dbdelta()`.
- WCAG accessibility nuevos UI akibara-preventas (LAMBDATEST-REPORT.md cubre tokens).
- DB migration correctness (estructura SQL revisada superficialmente).

---

**Reporte completo.** El hallazgo más urgente pre-merge es F-03 (smoke sin cobertura de `/encargos/`, `/mis-reservas/`, health endpoint) combinado con F-05 (orden de deploy del shim). El checkpoint Sentry T+24h tiene plan preciso con triggers numéricos GREEN/RED. Los 12 specs E2E faltantes son deuda técnica conocida distribuida Sprint 4-5.
