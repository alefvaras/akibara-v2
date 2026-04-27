# QA Strategy Akibara — Round 2 (2026-04-26)

**Autor:** mesa-11-qa-testing
**Capacity baseline:** 25-30h efectivas/semana solo dev, sin staging
**Scope:** QA test plan integral para Sprints S1, S2A, S2B, S3, S4+
**Filosofía:** pragmático para 3 customers, robusto para detectar regressions ANTES de afectar clientes reales, eficiente reusando tools existentes (Playwright Docker + LambdaTest cloud + Royal MCP + Gmail MCP)

---

## Sección 1: Test Strategy Overview

### Niveles de testing y herramientas

| Nivel | Tool | Cuándo | Output esperado |
|---|---|---|---|
| **Smoke** | curl + Royal MCP + Gmail MCP | Pre-deploy local + post-deploy prod | HTTP 200, render correcto, email recibido |
| **Unit** | PHPUnit (Docker) | Pre-deploy items que tocan plugin akibara/akibara-reservas | Tests existentes pasan + nuevos para fixes críticos |
| **Integration** | Playwright Docker (local WP) | Pre-deploy items E2E flows | Flow completo cart→checkout→email funciona local |
| **E2E prod** | Playwright local vs prod + Royal MCP + Gmail MCP | Post-deploy prod | Producto 24261/24262/24263 flows funcionan |
| **Visual regression** | LambdaTest cloud (Playwright wss) | Pre-fix baseline + post-fix comparison para items VISUAL | Diff <5% en breakpoints críticos |
| **Cross-browser** | LambdaTest cloud | Items que tocan UI/CSS customer-facing | Render OK Chrome/Safari/Firefox/Edge + iOS/Android |
| **Performance** | Lighthouse CLI (Docker) + Sentry RUM | Pre/post deploy items que tocan home/single product/checkout | LCP <2.5s, CLS <0.1, INP <200ms en pages críticas |
| **Security** | curl penetration tests + Sentry alert rules | Post-deploy items SECURITY | 401/403/429 cuando deben, 200 cuando deben, sin errores Sentry |

### Roles per test type (ejecutables por subagent skill mesa)

| Test | Skill mesa que ejecuta |
|---|---|
| Smoke + curl penetration | `mesa-10-security` o `mesa-22-wordpress-master` |
| PHPUnit | `mesa-15-architect-reviewer` o `mesa-22` |
| Playwright local Docker | Cualquier mesa con skill backend o frontend |
| LambdaTest cloud | `mesa-07-responsive-design` (mobile/tablet) o `mesa-08-design-system` (visual/cross-browser) |
| Lighthouse | `mesa-07` o `mesa-22` |
| Royal MCP verify prod state | Cualquier mesa post-deploy |
| Gmail MCP verify email recibido | `mesa-09-email-qa` |
| Sentry post-deploy 24h monitoring | `mesa-10` o `mesa-22` |

**No-go:** instalar third-party plugin QA (política dura). Todo QA via tools ya en workspace.

---

## Sección 2: Pre-Sprint Setup (one-time, antes de S1)

### Setup #1 — Playwright Docker config (pre-S1)

Crear `playwright.config.ts` en root del workspace:

```typescript
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    headless: true,
  },
  projects: [
    { name: 'chromium-local', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-local', use: { ...devices['iPhone 13'] } },
  ],
});
```

Ejecutar via Docker:
```bash
bin/npm install -D @playwright/test
docker compose run --rm node npx playwright install --with-deps chromium
```

### Setup #2 — PHPUnit Docker validation

Plugin akibara YA tiene `phpunit.xml.dist`. Validar:
```bash
docker compose --profile cli run --rm composer install --working-dir=wp-content/plugins/akibara
docker compose --profile cli run --rm php vendor/bin/phpunit -c wp-content/plugins/akibara/phpunit.xml.dist
```

### Setup #3 — LambdaTest credentials

Crear `.env.local` (gitignored):
```bash
LT_USERNAME=afernandezsoaint
LT_ACCESS_KEY=<obtener_de_dashboard>
LT_HUB_URL=https://hub.lambdatest.com/wd/hub
LT_PLAYWRIGHT_WSS=wss://cdp.lambdatest.com/playwright?capabilities=
```

### Setup #4 — Baseline screenshots visual regression

Capturar estado prod ACTUAL antes de cualquier cambio visual:
```bash
docker compose run --rm node node tests/visual/capture-baseline.js \
  --pages=home,single-product-24261,checkout,mi-cuenta,encargos \
  --browsers=chrome-latest,safari-15,firefox-latest \
  --devices=iphone-14,ipad,desktop-1440x900 \
  --out=tests/visual/baseline-2026-04-26/
```

### Setup #5 — Sentry alert rules

| Alert | Threshold | Notification |
|---|---|---|
| Error rate increase | >5x baseline 1h | Email Alejandro |
| New error type | Error nuevo no seen últimos 7d | Email Alejandro |
| Performance degradation | LCP >4s en 50% requests | Email Alejandro |
| Critical paths broken | Errores en `/checkout/`, `/wp-json/akb/v1/*`, `/wp-admin/` | Email Alejandro inmediato |

---

## Sección 3: QA test plan por Sprint

### Sprint 1 — QA Test Plan

**Pre-flight tests:**
```bash
curl -I https://akibara.cl/ | tee .private/qa/sprint1-baseline-home.txt
bin/mysql-prod -e "SELECT COUNT(*) AS users FROM wp_users; SELECT COUNT(*) AS orders FROM wp_wc_orders;" | tee .private/qa/sprint1-baseline-counts.txt
docker compose ps
docker compose up -d wp mariadb
```

**[SECURITY] verification post-fix:**
```bash
# verify-checksums
bin/wp-ssh core verify-checksums --version=$(bin/wp-ssh core version) 2>&1 | tee .private/qa/sprint1-checksums.txt

# Backdoors deleted
bin/wp-ssh user list --search="@akibara.cl.com" --format=table # expect 0

# Vendor cleanup
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/wp-content/plugins/akibara/coverage/html/index.html # 403
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/wp-content/plugins/akibara/composer.json # 403

# wp_bluex_logs TRUNCATE
bin/mysql-prod -e "SELECT COUNT(*) FROM wp_bluex_logs" # 0

# Security headers
curl -I https://akibara.cl/ | grep -iE "strict-transport-security|x-content-type-options|referrer-policy|permissions-policy"
curl -s https://akibara.cl/wp-json/wp/v2/users | jq . # 404
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/xmlrpc.php # 403

# BlueX webhook hard-fail
curl -X POST https://akibara.cl/?bluex-webhook=1 -d '{"order_id":"99999","status":"delivered"}' -w "\nHTTP: %{http_code}\n" # 401/403

# REST rate limits
for i in {1..11}; do
  curl -s -o /dev/null -w "$i: %{http_code}\n" -X POST https://akibara.cl/wp-json/akibara/v1/cart/add \
    -H "Content-Type: application/json" -d '{"product_id":24261,"quantity":1}'
done # request 11 → 429
```

**[PAYMENT] BACS RUT empresa:**
```bash
docker compose run --rm node npx playwright test tests/e2e/checkout-bacs-24261.spec.ts
# Verifica: thank-you page muestra RUT 78.274.225-6 + cuenta 39625300
# Email Gmail MCP recibido con RUT presente
```

**[EMAIL] Brevo SPF/DKIM/DMARC:**
```bash
dig akibara.cl TXT +short | grep -i "v=spf1"
dig brevo._domainkey.akibara.cl TXT +short
dig _dmarc.akibara.cl TXT +short
bin/wp-ssh eval 'wp_mail("alejandro.fvaras@gmail.com", "QA-S1-EMAIL-01 smoke", "Test Brevo SMTP S1");'
# Gmail MCP search últimos 5 min, NOT spam folder
# mail-tester.com score ≥8/10
```

**[BACKEND] productos test private:**
```bash
bin/wp-ssh post get 24261 --field=post_status # private
curl -s https://akibara.cl/ | grep -ic "TEST E2E\|test-e2e-producto" # 0
```

**[FRONTEND/VISUAL] LambdaTest:**
```bash
LT_USERNAME=afernandezsoaint LT_ACCESS_KEY=$LT_ACCESS_KEY \
docker compose run --rm node node tests/visual/run-lambdatest.js \
  --scenario=sprint1-sale-price \
  --browsers=chrome-latest,safari-mac-15,firefox,iphone-14,iphone-se \
  --pages=home,single-sale,manga-listing,cart-with-sale \
  --baseline=.private/visual-baselines/sprint1-pre/
```

**Post-sprint checklist S1:**
- [ ] Todos items DoD checked
- [ ] Smoke prod completo (paso 6 PROMPT-INICIO E2E con producto 24261)
- [ ] Regression tests pasados (admin count = 1, bluex_logs = 0, productos test private, headers presentes, REST users 404, xmlrpc 403)
- [ ] LambdaTest cross-browser pasado (FRONT-01 + FRONT-02)
- [ ] Smoke email pasado (alejandro.fvaras@gmail.com)
- [ ] Sentry 24h post-deploy sin errores nuevos vs baseline
- [ ] Backups accesibles (test extracción)
- [ ] Git commit message documenta cambios + F-IDs cerrados
- [ ] Documentación actualizada (ADRs creados, RUNBOOK-DESTRUCTIVO.md creado)

**Rollback S1:**
1. Code: `git revert <commit>` + rsync re-deploy
2. DB: restore SQL dumps específicos (backdoors, bluex_logs)
3. Config: restore wp-config.php / .htaccess / mu-plugin
4. DNS: additive (no rollback necesario)
5. Productos test: `bin/wp-ssh post update 24261 24262 24263 --post_status=publish`

**SLA rollback:** <30 min total, <5 min para mu-plugin add/remove.

---

### Sprint 2A — QA Test Plan

**Items críticos:** Cookie consent banner + CLEAN-002 cart-abandoned condicional + theme back-in-stock duplicate + MP hardening + auto-OOS-to-preventa opt-in + design tokens fantasma + popup contraste

**Pre-flight:**
```bash
# Brevo upstream tracker baseline (CRÍTICO para CLEAN-002)
# Manual: dashboard Brevo → Workflows → "Carrito abandonado" → últimos 7d → assert ≥1 trigger post-S1
# Si NO firing → CLEAN-002 NO ejecuta, queda S3+

docker compose run --rm node node tests/visual/capture-baseline.js \
  --pages=home,single-product-24261,popup-trigger,welcome-popup,checkout \
  --browsers=chrome-latest,safari-mac,safari-ios,firefox \
  --devices=iphone-se,iphone-14,ipad,desktop-1440 \
  --out=.private/visual-baselines/sprint2a-pre/
```

**[SECURITY] REST endpoints rate limiting batch:**
```bash
# /mkt/open rate limit
for i in {1..65}; do
  curl -s -o /dev/null -w "$i: %{http_code}\n" https://akibara.cl/wp-json/akibara/v1/mkt/open?cid=1
done # 60+ → 429

# track_order rate limit (10/min/IP)
for i in {1..12}; do
  curl -s -o /dev/null -w "$i: %{http_code}\n" -X POST https://akibara.cl/wp-json/akibara/v1/track-order \
    -d '{"order_id":"99999","email":"test@example.com"}'
done # 11+ → 429
```

**[SECURITY] sender keys → wp-config-private.php:**
```bash
bin/wp-ssh shell ls -la /home/.../public_html/wp-config-private.php # chmod 600
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/wp-config-private.php # 403/404
bin/wp-ssh eval 'echo defined("AKB_BREVO_API_KEY") ? "YES" : "NO";' # YES
```

**[PAYMENT] MP hardening idempotency:**
```bash
PAYMENT_ID="$(date +%s)"
for i in 1 2; do
  curl -X POST https://akibara.cl/wp-json/mp/v1/webhook \
    -H "Content-Type: application/json" \
    -d "{\"id\":$PAYMENT_ID,\"action\":\"payment.created\"}"
done
bin/mysql-prod -e "SELECT COUNT(*) FROM wp_wc_orders WHERE billing_email LIKE '%mp-test%'" # ≤1
```

**[EMAIL] CLEAN-002 cart-abandoned (CONDICIONAL):**

Pre-cleanup smoke obligatorio: Brevo upstream cart abandonment funciona (24-48h validation post-S1).

```bash
# Pre-cleanup smoke
docker compose run --rm node npx playwright test tests/e2e/cart-abandonment-brevo-upstream.spec.ts
# Si pasa → ejecutar CLEAN-002
# Si NO → SKIP, mover a S3+

# Post-cleanup
bin/mysql-prod -e "SHOW TABLES LIKE '%abandoned%'" # 0
bin/wp-ssh cron event list | grep -i abandon # 0
```

**[EMAIL] theme back-in-stock duplicate:**
```bash
bin/wp-ssh wc product update 24263 --stock_status=outofstock
sleep 30
bin/wp-ssh wc product update 24263 --stock_status=instock
# Wait 60s
# Gmail MCP search "vuelve a estar disponible" newer_than:5m → expect 1 thread (no 2)
# Verify body contiene unsubscribe link
```

**[BACKEND] auto-OOS-to-preventa opt-in:**
```bash
# Pre-fix: producto sin flag → no auto-convert
bin/wp-ssh post meta update 24263 _akb_allow_auto_preventa no
bin/wp-ssh wc product update 24263 --stock_status=outofstock
sleep 30
bin/wp-ssh post meta get 24263 _akb_reserva # "" (no auto-converted)

# Con flag opt-in
bin/wp-ssh post meta update 24263 _akb_allow_auto_preventa yes
bin/wp-ssh wc product update 24263 --stock_status=outofstock
sleep 30
bin/wp-ssh post meta get 24263 _akb_reserva # "yes" (auto-converted)
bin/wp-ssh post meta get 24263 _akb_reserva_fecha_modo # "estimada" (no "sin_fecha")
```

**[FRONTEND/VISUAL] cookie consent banner LambdaTest:**

Browsers: Chrome + Safari macOS + Safari iOS + Firefox + Edge
Devices: iPhone SE 375, iPhone 14 390, iPad 768, desktop 1440

```javascript
// tests/visual/sprint2a-cookie-consent.lambdatest.js
// Tests:
// 1. banner-first-visit: localStorage.clear → banner appears
// 2. deny-analytics-blocks-clarity: assert NO request a clarity.ms en Network DevTools
// 3. accept-all-loads-ga4-clarity: assert GA4 + Clarity load
// 4. cookies-page-content: /cookies/ existe + 10+ cookies inventory
```

**[FRONTEND] design tokens fantasma:**
```javascript
const computedStyles = await page.evaluate(() => {
  const root = document.documentElement;
  return {
    'aki-text': getComputedStyle(root).getPropertyValue('--aki-text'),
    'aki-text-muted': getComputedStyle(root).getPropertyValue('--aki-text-muted'),
    'aki-primary-hover': getComputedStyle(root).getPropertyValue('--aki-primary-hover'),
  };
});
// Expect: --aki-primary-hover != "#d94a30" (orange fallback)
```

**[FRONTEND] popup contraste tokens (axe-core):**
```javascript
const { injectAxe, getViolations } = require('axe-playwright');
await page.goto('https://akibara.cl/');
await page.waitForSelector('.akb-wd-popup', { timeout: 10000 });
await injectAxe(page);
const violations = await getViolations(page, '.akb-wd-popup', {
  runOnly: { type: 'tag', values: ['wcag2aa'] },
});
const contrastViolations = violations.filter(v => v.id === 'color-contrast');
console.assert(contrastViolations.length === 0);
```

**Post-sprint checklist S2A:** ver criterios universales abajo.

---

### Sprint 2B — QA Test Plan

**Items críticos:** UX matrix preventa/encargo/agotado + Module Registry guard DRY + RTBF eraser + refactor regression

**[BACKEND] UX matrix 4 estados (Decisión #13):**
```javascript
// LambdaTest visual + functional
const states = [
  { name: 'preventa-stock', expected_cta: 'Disponible para reservar' },
  { name: 'preventa-hot', expected_cta: 'Reservar ahora' },
  { name: 'agotado', expected_cta: 'Avísame cuando vuelva' },
  { name: 'encargo', expected_cta: 'Solicitar encargo' },
];
// Por estado: assert single badge + cta correcto + screenshot LambdaTest
```

**[BACKEND] Module Registry DRY:**
```bash
bin/wp-ssh option update akibara_modules '{"discounts":{"enabled":false}}' --format=json
bin/wp-ssh eval 'echo class_exists("Akibara_Discounts_Module") ? "LOADED" : "NOT_LOADED";' # NOT_LOADED
bin/wp-ssh option update akibara_modules '{"discounts":{"enabled":true}}' --format=json
bin/wp-ssh eval 'echo class_exists("Akibara_Discounts_Module") ? "LOADED" : "NOT_LOADED";' # LOADED
```

**[COMPLIANCE] RTBF eraser:**
```bash
docker compose run --rm node npx playwright test tests/e2e/rtbf-eraser.spec.ts
# Test interno: button "Solicitar eliminación" → DB rows 0 + RUT anonymized "XX.XXX.XXX-X"
```

---

### Sprint 3 — QA Test Plan

**Items:** Refactor 11 callsites Brevo + a11y audit completo + outline:none audit 28 sites

**[EMAIL] Refactor Brevo callsites:**
```bash
# Pre-fix
grep -rn "wp_remote_post.*api\.brevo\.com" plugins/ themes/ | wc -l # 11

# Post-fix
grep -rn "wp_remote_post.*api\.brevo\.com" plugins/ themes/ | wc -l # 0
grep -rn "AkibaraBrevo::" plugins/ themes/ | wc -l # +11

# Smoke email para CADA callsite refactorizado (referrals 4x, encargos, newsletter, welcome-series, cumpleaños, magic-link, back-in-stock, cart-abandoned, review-request)
```

**[FRONTEND] axe-core a11y completo:**
```bash
docker compose run --rm node npx playwright test tests/a11y/full-audit.spec.ts
# Expect: 0 keyboard accessibility violations en home, single product, checkout, mi-cuenta, cookies, encargos
```

---

### Sprint 4+ — Trigger-driven QA

**NO sprint commitment.** Tests se diseñan when item activates per milestone.

- **M1 (5 customers/mo):** Newsletter signup + Welcome series 1 email — smoke subscriber path
- **M2 (25 customers/mo):** Back-in-stock activate + Welcome series 3-step — sequence delay
- **M3 (50 customers/mo):** Brevo Standard upgrade + Marketing campaigns — pre/post quota verify
- **M4 (100 customers/mo):** A/B testing + CI/CD básico — split traffic + GitHub Actions

---

## Sección 4: Test fixtures + data

### Productos test prod

| Producto | S1 | S2A | S2B | S3 |
|---|---|---|---|---|
| **24261** Disponible | E2E checkout BACS/MP/Flow + cart add + smoke email | E2E MP webhook idempotency + Brevo upstream | Sin uso primary | Refactor regression |
| **24262** Preventa | Atomic stock race + preventa checkbox + status private | Auto-OOS-to-preventa flag + UX preventa-stock | UX preventa-hot + flow refactor | Preventa regression |
| **24263** Agotado | Status private + theme BIS duplicate baseline | Theme BIS cleanup + restock smoke | UX agotado state | BIS module refactor regression |

**Reglas:**
- NO modificar precios (regla dura)
- NO cambiar estado fuera del flow esperado
- Email único: `alejandro.fvaras@gmail.com` (NUNCA cliente real)
- Para emails masivos: aliases `alejandro.fvaras+sX@gmail.com`

### User test admin
- ID 1 / `ale.fvaras` (único administrator post-CLEAN-012)

### Order IDs históricos regression
- 23632 / 23628 / 23542 (MP Custom Gateway, históricos)

---

## Sección 5: LambdaTest scenarios críticos

### Scenario 1: B-S1-FRONT-01 sale price layout
- Browsers: Chrome desktop + Safari macOS + Firefox + Safari iOS (iPhone 14 + iPhone SE)
- Páginas: home con sale producto + single product sale + listing /manga/ + cart con sale
- Breakpoints: 375, 390, 768, 1024, 1440
- Threshold visual diff: <5% pixel diff vs baseline

### Scenario 2: B-S1-FRONT-02 focus rings WCAG
- Browsers: Chrome + Safari macOS + Firefox (keyboard nav crítico)
- Páginas: checkout, login, encargos, rastreo
- Aceptación: focus ring visible en TODOS browsers/inputs (alpha ≥0.5, contraste ≥3:1)

### Scenario 3: B-S1-FRONT-03 sticky CTA mobile checkout
- Devices: iPhone SE, iPhone 14, Pixel 7, Galaxy S22
- Test: scroll abajo en checkout → verify CTA "Realizar pedido" stays visible bottom

### Scenario 4: Decisión #11 auto-OOS-to-preventa preventa copy
- Devices: iPhone 14 + iPad + Desktop
- Páginas: cart-preventa, checkout-preventa
- Assert: advertencia "Refund 100% si pasan +90 días" visible

### Scenario 5: Decisión #13 UX matrix 4 estados
- Browsers: Chrome + Safari macOS + Safari iOS
- Páginas: 4 productos en 4 estados
- Assert: solo 1 badge + CTA esperado por estado

### Scenario 6: Decisión #8 cookie consent banner
- Browsers: Chrome + Safari macOS + Safari iOS + Firefox + Edge
- Páginas: home first visit + /cookies/ + footer "Gestionar cookies"
- Network monitoring: deny analytics → NO Clarity/GA4 requests

---

## Sección 6: Performance baselines + monitoring

### Lighthouse targets

| Página | Performance | A11y | Best Practices | SEO |
|---|---|---|---|---|
| Home | ≥70 | ≥90 | ≥85 | ≥95 |
| Single product | ≥75 | ≥90 | ≥85 | ≥95 |
| Checkout | ≥65 | ≥90 | ≥85 | N/A |
| Mi-cuenta | ≥75 | ≥90 | ≥85 | N/A |
| Manga listing | ≥70 | ≥90 | ≥85 | ≥95 |

### Core Web Vitals targets
- **LCP** <2.5s
- **CLS** <0.1
- **INP** <200ms

### Sentry alerts
- Error rate spike >5x baseline 1h → Email Alejandro inmediato
- New error type últimos 7d → Email Alejandro
- Critical path error (checkout, REST API, MP webhook) → Email Alejandro inmediato
- LCP p75 >4s last 1h → Email Alejandro
- wp_mail return false rate >5% → Email Alejandro
- Webhook auth failure rate >10% → Email Alejandro (posible attack)

---

## Sección 7: Anti-patterns QA

NO proponer (per anti over-engineering + minimize behavior change):

1. **Test coverage 100% goal** — Para 3 customers, over-engineering. Default: smoke + critical paths + items por sprint.
2. **CI/CD pipeline complejo (GitHub Actions multi-env + PR gates)** — No hay PR review, no staging. Default: workflow Docker → tools → tests local → smoke prod manual. CI/CD básico defer M4.
3. **Mocking elaborado (Mockery, Prophecy)** — Para 3 customers, fixtures simples bastan.
4. **Visual regression para CADA componente** — Solo flujos críticos.
5. **Performance tests sintéticos elaborados (k6, Locust load >100 concurrent)** — Sin tráfico real, defer M3.
6. **A/B testing framework custom** — Sin baseline, defer M4.
7. **Service workers + offline-first PWA** — Out of scope.
8. **Multi-tenant testing** — 1 site Chile, NO multi-*.
9. **Headless CMS testing** — WordPress server-rendered, NO aplica.
10. **Test pyramid puro** — Para Akibara invertir: 50% smoke + 30% E2E + 15% integration + 5% unit (atomic_stock_check crítico).
11. **Mutation testing** — Over-engineering absoluto, NO proponer.
12. **Contract testing (Pact)** — No multi-service, NO aplica.
13. **Property-based testing (Hypothesis)** — Edge cases finitos, NO proponer.
14. **Penetration testing third-party (Burp Suite Pro)** — Política dura no-third-party. Default: curl + manual + Sentry alerts.

---

## Resumen ejecutivo QA

| Sprint | Focus QA | Tools | LambdaTest? | Email smoke? | Esfuerzo QA |
|---|---|---|---|---|---|
| **S1** | Security penetration + smoke regression + visual focus rings | curl + Playwright Docker + LambdaTest + Gmail MCP | SÍ (FRONT-01/02) | SÍ (EMAIL-01/02/03) | ~6-8h |
| **S2A** | Cookie banner cross-browser + cart-abandoned upstream + popup contraste | LambdaTest + axe-core + Brevo dashboard | SÍ (COMP-01, FRONT-02) | SÍ (EMAIL-01/03/05) | ~8-10h |
| **S2B** | UX matrix consistency + RTBF eraser + refactor regression | LambdaTest + Playwright + grep | SÍ (BACK-02 UX matrix) | SÍ (EMAIL-04/06) | ~6-8h |
| **S3** | Refactor 11 callsites Brevo + a11y completo + outline:none audit | grep + Gmail MCP + axe-core | OPCIONAL | SÍ (todos refactors) | ~10-12h |
| **S4+** | Trigger-driven, no commit | TBD | TBD | TBD | TBD |

**Reglas duras QA universales:**

1. Sprint NO se cierra hasta TODOS items DoD checked + smoke prod E2E + Sentry 24h sin nuevos errores.
2. Cambio visual → LambdaTest cross-browser obligatorio.
3. Cambio email → smoke a alejandro.fvaras@gmail.com obligatorio + Gmail MCP verify.
4. Backups pre-cambios accesibles (test extracción) obligatorio antes destructivo.
5. Rollback path documentado en commit message obligatorio.
6. NO testing en producción que altere data real (orders, customers reales).
7. NO instalar third-party plugin QA. Solo tools workspace + LambdaTest cloud + MCP servers.

**Output esperado QA por sprint:**
- `.private/qa/sprint{N}-baseline-*.txt`
- `.private/qa/sprint{N}-results-*.txt`
- `.private/visual-baselines/sprint{N}-pre/` y `.private/visual-results/sprint{N}-{item}/`
- `.private/lighthouse/sprint{N}-*.json`
- Git commit message linkea findings F-IDs cerrados

**Capacity QA agregada:**
- S1: ~7h (28% del 25-30h)
- S2A: ~9h (36% del 25h)
- S2B: ~7h (28% del 25h)
- S3: ~11h (variable)

QA = ~25-35% del esfuerzo total cada sprint. Sin esto, regression no detectada afecta a 3 customers reales (revenue impact directo).

**Próximo paso:** Pre-S1 setup (Sección 2) — crear `playwright.config.ts`, configurar `.env.local` LT credentials, capturar baselines pre-S1 visual. Tiempo: 2-3h.
