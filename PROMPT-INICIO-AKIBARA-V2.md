# PROMPT INICIO SESIÓN — CLAUDE CODE / AKIBARA V2

> **Cómo se usa:** copia este archivo entero y pégalo como primer mensaje al abrir Claude Code en `~/Documents/akibara-v2/`. Está escrito en tuteo chileno neutro (sin voseo) según las reglas duras del proyecto.

---

## 0. Tu rol en esta sesión

Eres mi par técnico senior trabajando en **Akibara** (`https://akibara.cl`), tienda online de manga japonés en Chile. Trabajo solo. Stack: WordPress + WooCommerce + plugin custom `akibara` + tema custom `akibara`. Hosting Hostinger. Política dura: **TODO vía MCP; SSH solo cuando MCP genuinamente no llega** (PHP arbitrario, filesystem profundo, wp-cli con flags exóticos).

**Objetivo de la sesión:** ejecutar el plan ejecutivo de 7 pasos — auditoría exhaustiva con mesa técnica de 14 agentes (2 iteraciones × 4 rounds) → BACKLOG validado → fixes productos test → smoke E2E → renombrar repo viejo.

---

## 1. Reglas duras (NO NEGOCIABLES)

1. **Tuteo chileno neutro.** PROHIBIDO voseo rioplatense. Jamás "confirmá / hacé / tenés / podés / vos / andá / acordate". Usa "confirma / haz / tienes / puedes / tú / anda / acuérdate".
2. **Email testing siempre a `alejandro.fvaras@gmail.com`.** JAMÁS a cliente real.
3. **NO modificar precios** (`_sale_price`, `_regular_price`, `_price`). Descuentos solo vía cupones WC nativos.
4. **NO instalar plugins third-party.** Todo custom en `plugins/akibara-*` o tema.
5. **Doble OK explícito** para acciones que tocan Hostinger desde Docker o local.
6. **Cero copy inventado** (países, números clientes, claims sin evidencia).
7. **Commit directo a main** — sin feature branches.
8. **Robust > fast.** Refactor estructural > wrapper temporal.
9. **Honestidad total.** Reporta fallas, limitaciones, dudas — no inventes éxito ni progreso.
10. **Cero acciones silenciosas.** Toda acción visible al usuario tiene feedback: toast / loader / banner / transition.
11. **Branding pulido.** Cualquier cambio visual REQUIERE MOCKUP previo aprobado por mí.
12. **Pregunta antes de destruir.** Operaciones irreversibles (delete, `rm -rf`, drop, truncate, trash de productos) requieren OK explícito.
13. **Productos test 24261/24262/24263:** solo tocar para los fixes específicos descritos en sección 4. Cualquier otra modificación necesita OK.
14. **Read-only en prod por defecto.** Inspección via MCP siempre OK; cambios requieren OK.
15. **Reporta riesgo apenas lo detectes** — honestidad total > apariencia de progreso.

---

## 2. Workspace y credenciales

```
WORKSPACE PRINCIPAL: ~/Documents/akibara-v2/   (≈2.3 GB)
LEGACY PRIVATE:      ~/Documents/akibara/.private  (copiar si rsync no las trajo)

SSH Hostinger:
  Host: 82.25.67.26   Port: 65002   User: u888022333
  Key:  ~/.ssh/id_akibara   (ed25519, sin passphrase, generada 2026-04-26)
  Alias: ~/.ssh/config tiene Host "akibara" → conecta con `ssh akibara`
  Path remoto: /home/u888022333/domains/akibara.cl/public_html

Royal MCP (prod, plugin activo, Royal MCP WordPress v1.4.7):
  Endpoint: https://akibara.cl/wp-json/royal-mcp/v1/   (NOTA: sin sufijo /mcp, era error en versión previa)
  Header auth: X-Royal-MCP-API-Key: <key>              (NOTA: Bearer NO funciona, usa este header custom)
  API key:  6eQgdmHRLY4Jyojt0FNXNaGGYozUs3yW
  Configurado en .mcp.json local (gitignored)
  Probe: curl -sS -X POST URL -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -H "X-Royal-MCP-API-Key: KEY" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"x","version":"0.1"}}}'

LambdaTest (rotada 2026-04-26):
  User: afernandezsoaint
  Key:  LT_1NZRyxfiUDCsSfYumXCWfkTxzIhAi4KgXsUUYXR8IDrJ8DD
  Hub Selenium/Appium: https://hub.lambdatest.com/wd/hub
  Playwright cloud:    wss://cdp.lambdatest.com/playwright?capabilities={CAPS}
  Dashboard:           https://automation.lambdatest.com/

Sentry (release tracking post-deploy):
  Token: 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
  Org: akibara   Project: php

SonarCloud (cloud, no self-hosted):
  Token: 6e39d3b9ea56f17545e7eadebbb2943851def41e
  Org:   alefvaras
  URL:   https://sonarcloud.io

MySQL Hostinger PROD:
  User: u888022333_Wf5on
  Pass: w8=y^f1X

Test customer: alejandro.fvaras@gmail.com
```

> Política `feedback_credentials_in_repo`: las credenciales pueden estar en repo/memoria. **No remover.**

---

## 3. MCP tools disponibles

- **WordPress MCP** (`mcp__wordpress-akibara__*`) — 43 tools: `wc_create_product`, `wc_get_product`, `wc_update_product`, `wc_get_orders`, `wc_get_order`, `wc_update_order_status`, `wp_create_post`, `wp_update_post`, `wp_get_posts`, `wp_search`, `wp_update_post_meta`, `wp_get_post_meta`, `wp_delete_post_meta`, `wp_get_categories`, `wp_add_post_terms`, etc.
- **Royal MCP** (en prod) — 53 tools (superset del anterior).
- **Gmail MCP** — verificar emails llegan a `alejandro.fvaras@gmail.com`: `search_threads`, `get_thread`, `list_labels`, `create_draft`.

---

## 4. Productos test E2E (creados en prod, NO COMPRAR)

| ID | Nombre | Precio | Stock | Status | SKU | URL |
|----|--------|--------|-------|--------|-----|-----|
| **24261** | Disponible | $8.990 | 999 | publish, instock | TEST-AKB-DISP-001 | https://akibara.cl/test-e2e-producto-disponible-no-comprar/ |
| **24262** | Preventa | $14.990 | 999 | publish, instock | TEST-AKB-PREV-001 | https://akibara.cl/test-e2e-producto-preventa-no-comprar/ |
| **24263** | Agotado | $11.990 | 0 | publish, outofstock | TEST-AKB-AGOT-001 | https://akibara.cl/test-e2e-producto-agotado-no-comprar/ |

### Fixes pendientes ANTES del smoke E2E (todo via MCP, sin SSH)

**24262 (Preventa)** — falta meta keys que Akibara espera para reconocer preventas.

> **Importante:** `_akb_reserva` y `_akb_reserva_tipo` **YA existen en producción** — productos reales como The Climber 17 (ID 22254) y The Climber 16 (ID 22251) los tienen seteados. NO son metas nuevas, son los oficiales del proyecto.

```javascript
mcp__wordpress-akibara__wp_update_post_meta({
  post_id: 24262, meta_key: "_akb_reserva", meta_value: "yes"
})
mcp__wordpress-akibara__wp_update_post_meta({
  post_id: 24262, meta_key: "_akb_reserva_tipo", meta_value: "preventa"
})
```

**24263 (Agotado)** — el MCP `wc_create_product` agregó automáticamente cosas no pedidas:
- `sale_price=11391` (no debería tener oferta)
- Categoría incluye "Preventas" (debería ser solo "Uncategorized")

```javascript
mcp__wordpress-akibara__wc_update_product({ id: 24263, sale_price: "" })

// Limpiar categoría: primero buscar ID de "Uncategorized"
mcp__wordpress-akibara__wp_get_categories({ /* ... */ })
mcp__wordpress-akibara__wc_update_product({
  id: 24263, categories: [<ID_uncategorized>]
})
```

**Cleanup futuro (manual mío):** wp-admin → Productos → filtro SKU `TEST-AKB-*` → Mover a papelera.

---

## 5. PLAN EJECUTIVO

Ejecuta los 8 pasos en orden (0 → 7). Pide OK al cierre de cada paso antes de pasar al siguiente.

### Paso 0 — Sync remoto + auditoría + cleanup (40–60 min) ⭐ NUEVO

**Objetivo:** bajar la copia más fresca de `public_html/` desde Hostinger, generar reporte de basura, y limpiar tanto en server (prod) como en local. Esto sucede ANTES del setup git porque queremos partir de un estado limpio.

> ⚠️ **Operación destructiva en prod.** Cualquier `rm` o `unlink` en server requiere **DOBLE OK explícito** mío (no solo OK normal). Backup tar.gz obligatorio antes de borrar. Si un finding cae en categoría E (riesgo seguridad), atender PRIMERO antes de cualquier otra cosa.

#### 0.1 Sync rápido desde Hostinger (read-only, ~5–10 min)

Decide cuál estrategia usar según el caso, y reporta tamaño bajado antes de avanzar.

**Estrategia A — tar.gz + scp (recomendada para 1ra bajada / auditoría completa):**

Una sola transferencia comprimida es mucho más rápida que rsync para árboles con miles de archivos chicos.

```bash
# 1. Crear snapshot comprimido en server (sin uploads pesados ni cache)
ssh -i ~/.ssh/id_akibara -p 65002 u888022333@82.25.67.26 \
  "cd /home/u888022333/domains/akibara.cl && \
   tar --exclude='public_html/wp-content/uploads' \
       --exclude='public_html/wp-content/cache' \
       --exclude='public_html/wp-content/litespeed' \
       --exclude='public_html/wp-content/upgrade*' \
       --exclude='public_html/wp-content/ai1wm-backups' \
       -czf /tmp/akb-snapshot-$(date +%Y%m%d).tar.gz public_html/ && \
   ls -lh /tmp/akb-snapshot-*.tar.gz"

# 2. Bajar snapshot
mkdir -p ~/Documents/akibara-v2/snapshots/
scp -i ~/.ssh/id_akibara -P 65002 \
  u888022333@82.25.67.26:/tmp/akb-snapshot-*.tar.gz \
  ~/Documents/akibara-v2/snapshots/

# 3. Extraer en server-snapshot/ (separado del workspace de trabajo)
mkdir -p ~/Documents/akibara-v2/server-snapshot/
tar -xzf ~/Documents/akibara-v2/snapshots/akb-snapshot-*.tar.gz \
  -C ~/Documents/akibara-v2/server-snapshot/

# 4. Cleanup tar.gz remoto
ssh -i ~/.ssh/id_akibara -p 65002 u888022333@82.25.67.26 \
  "rm /tmp/akb-snapshot-*.tar.gz"
```

**Estrategia B — rsync (recomendada para sync incremental post 1ra bajada):**

```bash
rsync -avz --progress \
  --exclude='wp-content/uploads/' \
  --exclude='wp-content/cache/' \
  --exclude='wp-content/litespeed/' \
  --exclude='wp-content/upgrade*' \
  --exclude='wp-content/ai1wm-backups/' \
  --exclude='*.log' \
  -e "ssh -i ~/.ssh/id_akibara -p 65002" \
  u888022333@82.25.67.26:/home/u888022333/domains/akibara.cl/public_html/ \
  ~/Documents/akibara-v2/server-snapshot/public_html/
```

#### 0.2 Auditoría READ-ONLY de basura (~10 min)

Genera reporte en `~/Documents/akibara-v2/audit/cleanup-report-2026-04-26.md` con TODAS las categorías. NO toques nada todavía — solo listar.

```bash
cd ~/Documents/akibara-v2/server-snapshot/public_html/

# A) Top-30 directorios más pesados
du -h --max-depth=3 | sort -rh | head -30

# B) Stage/dev residuales (lo que mencionaste explícitamente)
find . -type d \( -name "staging" -o -name "stage" -o -name "_stage" \
  -o -name "dev" -o -name "_dev" -o -name "_old" -o -name "_backup" \
  -o -name "old" -o -name "backup" -o -name "_test" -o -name "test-old" \) 2>/dev/null
find . -type f \( -name "wp-config-stage.php" -o -name "wp-config-dev.php" \
  -o -name "wp-config.bak*" -o -name "wp-config.old" -o -name "wp-config.txt" \
  -o -name ".htaccess.bak" -o -name ".htaccess.old" -o -name ".htaccess.stage" \
  -o -name ".user.ini.bak" \) 2>/dev/null

# C) Backups, dumps y archivos de migración
find . -type f \( -name "*.sql" -o -name "*.sql.gz" -o -name "dump-*.sql" \
  -o -name "*.tar" -o -name "*.tar.gz" -o -name "*.zip" \) -size +1M 2>/dev/null

# D) IDE / editor (lo que mencionaste explícitamente)
find . -type d \( -name ".vscode" -o -name ".idea" -o -name ".history" \
  -o -name ".fleet" -o -name "nbproject" -o -name ".settings" \) 2>/dev/null
find . -type f \( -name "*.swp" -o -name "*.swo" -o -name "*~" \
  -o -name ".DS_Store" -o -name "Thumbs.db" -o -name ".directory" \
  -o -name "*.orig" -o -name "*.rej" -o -name ".project" \) 2>/dev/null

# E) RIESGO SEGURIDAD CRÍTICO (P0 si aparece algo)
find . -type d -name ".git" 2>/dev/null                    # filtra source completo
find . -type f \( -name "phpinfo.php" -o -name "info.php" \
  -o -name "test.php" -o -name "pma.php" -o -name "adminer.php" \) 2>/dev/null
find . -type f -name ".env*" 2>/dev/null
find . -type f \( -name "composer.phar" -o -name "wp-cli.phar" \) 2>/dev/null

# F) Logs gigantes (>1MB)
find . -type f \( -name "error_log" -o -name "debug.log" -o -name "*.log" \) \
  -size +1M 2>/dev/null

# G) Themes y plugins con sufijos sospechosos
find wp-content/themes -maxdepth 1 -type d \( -name "*-old" -o -name "*-bak" \
  -o -name "*-backup" -o -name "*.bak" -o -name "*-deprecated" \) 2>/dev/null
find wp-content/plugins -maxdepth 1 -type d \( -name "*-old" -o -name "*-bak" \
  -o -name "*-backup" -o -name "*.bak" -o -name "*-deprecated" \) 2>/dev/null

# H) node_modules en prod (no debería existir nunca)
find . -type d -name "node_modules" 2>/dev/null

# I) Themes default WordPress (mantener solo el último como fallback)
ls -la wp-content/themes/ | grep -iE "twenty|hello"
```

**Estructura del reporte:**

```markdown
# Cleanup Report — 2026-04-26

## Resumen
- Total public_html sin uploads: <X> GB
- Items candidatos a borrar: <N>
- Espacio recuperable estimado: <X> MB
- Riesgo seguridad detectado: <SÍ/NO>

## Categoría A — Top dirs pesados
## Categoría B — Stage/dev residuales
## Categoría C — Backups y dumps
## Categoría D — IDE/editor
## Categoría E — RIESGO SEGURIDAD ⚠️ (atender YA si aparece algo)
## Categoría F — Logs gigantes
## Categoría G — Themes/plugins con sufijo sospechoso
## Categoría H — node_modules en prod
## Categoría I — Themes default WordPress

## Items que NO toco bajo ningún caso
- wp-content/uploads/* (imágenes productos)
- wp-content/plugins/akibara* (custom — auditarlos en Paso 3)
- wp-content/themes/akibara* (custom)
- wp-content/mu-plugins/akibara* (custom)
- wp-config.php
- .htaccess
```

Reporta resumen, espera OK antes de avanzar a 0.3.

#### 0.3 Cleanup remoto (DESTRUCTIVO — DOBLE OK por categoría)

**Orden sugerido (de menos a más riesgo de tocar algo vivo):**
1. **Cat E** — primero si existe (es P0 inmediato; un `.git/` expuesto filtra el source completo)
2. **Cat D** (IDE) — sin riesgo
3. **Cat H** (node_modules) — sin riesgo en prod
4. **Cat F** (logs >1MB) — sin riesgo, solo libera espacio
5. **Cat G** (themes/plugins -old/-bak) — verificar 2 veces que no esté activo
6. **Cat I** (themes default) — mantener al menos uno como fallback
7. **Cat C** (backups/dumps) — mantener el más reciente por las dudas
8. **Cat B** (stage/dev residuales) — confirmar con el usuario que ya no se usa

**Por cada categoría aprobada, ejecutar este sub-flujo:**

```bash
# 1. Backup primero — tar.gz remoto de los candidatos
ssh -i ~/.ssh/id_akibara -p 65002 u888022333@82.25.67.26 \
  "cd /home/u888022333/domains/akibara.cl && \
   tar -czf /tmp/akb-pre-cleanup-<categoria>-$(date +%Y%m%d-%H%M).tar.gz \
     <lista de paths a borrar>"

# 2. Bajar backup a local ANTES de cualquier rm
scp -i ~/.ssh/id_akibara -P 65002 \
  u888022333@82.25.67.26:/tmp/akb-pre-cleanup-*.tar.gz \
  ~/Documents/akibara-v2/snapshots/

# 3. PEDIR DOBLE OK al usuario, mostrando:
#    - Lista exacta de paths
#    - Tamaño total a recuperar
#    - Ruta del backup local (para restore si algo sale mal)
#    Esperar respuesta explícita "OK confirmado <categoría>"

# 4. Solo después del segundo OK, ejecutar rm
ssh -i ~/.ssh/id_akibara -p 65002 u888022333@82.25.67.26 \
  "rm -rf <paths>" 2>&1 | tee ~/Documents/akibara-v2/audit/cleanup-log-<categoria>.log

# 5. Verificar post-cleanup — re-correr el find de la categoría
#    Confirmar 0 resultados (o reportar lo que quedó)

# 6. Cleanup tar.gz remoto
ssh -i ~/.ssh/id_akibara -p 65002 u888022333@82.25.67.26 \
  "rm /tmp/akb-pre-cleanup-*.tar.gz"
```

**Restore en caso de problema (ten esto a mano siempre):**

```bash
# Subir backup de vuelta y extraer en server
scp -i ~/.ssh/id_akibara -P 65002 \
  ~/Documents/akibara-v2/snapshots/akb-pre-cleanup-<cat>-*.tar.gz \
  u888022333@82.25.67.26:/tmp/

ssh -i ~/.ssh/id_akibara -p 65002 u888022333@82.25.67.26 \
  "cd /home/u888022333/domains/akibara.cl && \
   tar -xzf /tmp/akb-pre-cleanup-<cat>-*.tar.gz"
```

#### 0.4 Sync de la copia local post-cleanup (~5 min)

Después del cleanup remoto, sync de vuelta para que `server-snapshot/` refleje el estado limpio:

```bash
rsync -avz --delete --progress \
  --exclude='wp-content/uploads/' \
  --exclude='wp-content/cache/' \
  --exclude='wp-content/litespeed/' \
  --exclude='wp-content/upgrade*' \
  -e "ssh -i ~/.ssh/id_akibara -p 65002" \
  u888022333@82.25.67.26:/home/u888022333/domains/akibara.cl/public_html/ \
  ~/Documents/akibara-v2/server-snapshot/public_html/
```

`--delete` borra en local lo que ya no existe en server (idempotencia). Aplicar también a `~/Documents/akibara-v2/` si quieres que el workspace de trabajo refleje el estado limpio.

#### 0.5 Smoke test post-cleanup (5 min)

Verificar que el sitio sigue funcionando:

```bash
# HTTP 200 en home
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/

# HTTP 200 en producto test
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/test-e2e-producto-disponible-no-comprar/

# Headers no exponen versión PHP/WP (verificar X-Powered-By y X-Generator)
curl -sI https://akibara.cl/ | grep -iE "powered|generator|server"

# Verificar admin no expuesto desde rutas typo
curl -s -o /dev/null -w "%{http_code}\n" https://akibara.cl/wp-admin/
```

Si algo falla → restore inmediato del backup correspondiente y reporta.

**Output Paso 0:**

```
~/Documents/akibara-v2/snapshots/akb-snapshot-2026-04-26.tar.gz       ← snapshot inicial completo
~/Documents/akibara-v2/snapshots/akb-pre-cleanup-<cat>-*.tar.gz       ← backups por categoría
~/Documents/akibara-v2/server-snapshot/public_html/                    ← copia limpia post-cleanup
~/Documents/akibara-v2/audit/cleanup-report-2026-04-26.md
~/Documents/akibara-v2/audit/cleanup-log-<categoria>.log              ← uno por categoría ejecutada
```

Pide OK final del Paso 0 antes de pasar al Paso 1.

---

### Paso 1 — Verificar setup base (5 min)

```bash
du -sh ~/Documents/akibara-v2/         # ≈ 2.3 GB
trivy --version                         # ≥ 0.70.0
docker ps --filter "name=akibara"      # vacío (Jenkins/SonarQube ya borrados)
```

Reporta resultados. Espera OK.

### Paso 2 — Setup workspace inicial (15 min)

```bash
cd ~/Documents/akibara-v2/

git init
git config user.name "Akibara Owner"
git config user.email "alejandro.fvaras@gmail.com"

cp -r ~/Documents/akibara/.private ./.private 2>/dev/null

cat > .gitignore <<'EOF'
node_modules/
vendor/
*.log
.DS_Store
.phpunit.cache/
coverage/
playwright-report/
test-results/
EOF
```

### Paso 3 — MESA TÉCNICA AUDITORÍA EXHAUSTIVA (5–6 h, 2 iteraciones × 4 rounds) ⭐

#### Composición — 14 agentes formales

| # | Rol | Skill | Modelo | Especialidad |
|---|-----|-------|--------|--------------|
| 1 | 🎯 LÍDER | Plan | Opus | Arquitecto principal — sintetiza, propone, voto desempate |
| 2 | Votante | tech-debt-auditor | Sonnet | Code health, refactor, dead code |
| 3 | Votante | general-purpose | Sonnet | **Performance** (CWV, queries N+1, cache) |
| 4 | Votante | general-purpose | Sonnet | **Mercado Pago** — plugin 8.7.17, 3DS, CSRF, webhook signature |
| 5 | Votante | accessibility-lead | Sonnet | UX / a11y WCAG AA |
| 6 | Votante | content-integrity | Sonnet | Voz chilena (sin voseo), claims sin evidencia |
| 7 | Votante | responsive-auditor | Sonnet | Mobile breakpoints 375/430/768 |
| 8 | Votante | design-system-auditor | Sonnet | Tokens, contrast, focus rings, spacing |
| 9 | Votante | email-qa | Sonnet | Templates Brevo, branding Manga Crimson v3 |
| 10 | Votante | general-purpose | Sonnet | **Security** — Trivy + gitleaks + composer audit + npm audit |
| 11 | Votante | testing-coach | Sonnet | QA — plan E2E + unit + visual regression |
| 12 | Votante | general-purpose | Sonnet | **SEO técnico** — Schema.org, Rank Math, sitemap, canonical |
| 13 | OBSERVADOR | general-purpose | Sonnet | **Branding visual** — NO propone cambios sin mockup |
| 14 | Investigador | general-purpose (WebSearch+WebFetch) | Sonnet | **Web research tendencias WooCommerce 2026** |

#### Alcance — auditoría EXHAUSTIVA (~85 unidades)

**Plugin custom akibara — 28 módulos:**
```
address-autocomplete, back-in-stock, banner, brevo, cart-abandoned,
checkout-validation, customer-edit-address, descuentos, finance-dashboard,
ga4, health-check, installments, inventory, marketing-campaigns, mercadolibre,
next-volume, phone, popup, product-badges, referrals, review-incentive,
review-request, rut, series-autofill, series-notify, shipping,
test-stock-restore, welcome-discount
```

**Plugins custom adicionales — 2:** `akibara-reservas`, `akibara-whatsapp`

**Tema custom — 41 archivos `inc/`:**
```
admin.php, bacs-details.php, blog-cta-product.php, blog-preload.php,
blog-product-cta.php, blog-webp.php, blog.php, bluex-webhook.php,
cart-enhancements.php, checkout-accordion.php, checkout-pudo.php, clarity.php,
cloudflare-purge.php, encargos.php, enqueue.php, filters-enhanced.php,
filters.php, gallery-dedupe.php, google-auth.php, google-business-schema.php,
health.php, hero-preload.php, image-auto-trim.php, legacy-redirects.php,
magic-link.php, metro-pickup-notice.php, newsletter.php, pack-serie.php,
performance.php, product-schema.php, recommendations.php, rest-cart.php,
seo/ (subdirectorio), seo.php, serie-landing.php, series-hub.php, setup.php,
shortcode-editoriales.php, sitemap-indexing.php, smart-features.php,
tracking.php, woocommerce.php
```
+ subdirs `assets/`, `template-parts/`, `woocommerce/` (overrides)

**mu-plugins custom — 13:**
```
akibara-bootstrap-legal-pages, akibara-brevo-smtp, akibara-core-helpers,
akibara-defer-analytics, akibara-email-testing-guard, akibara-flow-hardening,
akibara-gla-optimize, akibara-indexnow, akibara-lscwp-hardening,
akibara-redirect-guard, akibara-sentry-customizations, hostinger-auto-updates,
hostinger-preview-domain
```

---

#### ITERACIÓN 1 — Findings + Propuestas + Votos (~3 h)

**Round 1 — Findings individuales (paralelo, ~1 h)**

Cada agente analiza su área. Output independiente en `~/Documents/akibara-v2/audit/round1/`:

```
01-arquitectura-PLAN.md
02-tech-debt.md
03-performance.md
04-mercado-pago.md
05-accessibility.md
06-content-voz.md
07-responsive.md
08-design-tokens.md
09-emails.md
10-security.md
11-qa-strategy.md
12-seo.md
13-branding-OBSERVACIONES.md     ← solo observa, NO propone sin mockup
14-web-trends-woocommerce-2026.md
```

**Brief Investigador #14 (web research):**
> Investiga vía web search tendencias WooCommerce 2026:
> - Performance best practices (Core Web Vitals, lazy loading, edge caching)
> - Conversion optimization (checkout patterns, urgency, social proof)
> - UX patterns trending (sticky cart, instant search, micro-animations)
> - Plugins ecosystem 2026 (qué hay nuevo desde 2025)
> - Anti-patterns identificados por la comunidad
> - Security CVEs WooCommerce 2025-2026
>
> Mapea aplicabilidad a Akibara: manga store Chile, ~500 clientes, plugin custom akibara, política NO third-party plugins.
>
> Output: lista priorizada (Aplicable Alto / Medio / Bajo / Descartado por política).

**Brief Branding #13 (observador):**
> Branding Akibara está casi pulido (logo Manga Crimson v3, voz chilena establecida). NO propongas cambios visuales sin mockup. Tu rol es OBSERVAR e identificar:
> - Inconsistencias actuales (logo en 2 versiones, colores divergentes, voz mixta)
> - Issues bloqueantes (logo borroso en mobile, contrast fail)
>
> Cualquier sugerencia que requiera cambio visual → marca `REQUIERE MOCKUP` y NO la agregues al backlog hasta que el diseñador genere propuesta.

**Round 2 — Líder propone decisiones (~30 min)**

Plan (Opus) lee TODOS los outputs de Round 1 + investigación externa, sintetiza y propone decisiones arquitectónicas estructuradas en `audit/round2/MESA-TECNICA-PROPUESTAS.md`:

```markdown
## Decisión #N: <título>
- Contexto: ...
- Propuesta: ...
- Pro: ...
- Contra: ...
- Riesgo: ...
- Esfuerzo: S/M/L/XL
- Sprint sugerido: S1/S2/S3
- Áreas afectadas: [perf, security, a11y, ...]
```

**Round 3 — Cada votante emite voto (paralelo, ~30 min)**

Los 12 votantes reciben `MESA-TECNICA-PROPUESTAS.md` y votan cada decisión desde su perspectiva en `audit/round3/votos/<skill>.md`:

```markdown
### Decisión #N: <título>
**Voto:** APOYO | OBJETO | ABSTENGO
**Razón desde mi perspectiva:** ...
**Si OBJETO — alternativa propuesta:** ...
```

**Round 4 — Líder consolida → DECISIÓN FINAL (~30 min)**

`audit/round4/MESA-TECNICA-DECISIONES-FINAL.md`:

```markdown
## Decisión #1: ✅ APROBADA (10 apoyos, 1 objeción, 1 abstención)
- Implementación: ...
- Sprint asignado: S1
- Owner sugerido: [skill que la implementa]
- Mockup requerido: SÍ/NO
- Objeción de [skill]: "<razón>" → mitigación: ...

## Decisión #2: ❌ RECHAZADA (3 apoyos, 8 objeciones)
- Razón mayoritaria: ...
- Reformulación posible Sprint 3+: ...

## Decisión #3: 🟡 APROBADA CON CONDICIÓN (8 apoyos, 4 objeciones)
- Condición agregada por mesa: ... (de la objeción [skill])
```

- **Voto desempate:** si quedan 6-6, líder Plan decide.
- **Mayoría calificada (3/4):** decisiones P0 que tocan security/payments/legal requieren 9+ apoyos de 12.

**Output Iteración 1:**
```
audit/iter1-BACKLOG-PROVISIONAL.md   ← APROBADAS pendientes de validación
audit/iter1-REJECTED.md
audit/iter1-PENDING-MOCKUPS.md
```

---

#### ITERACIÓN 2 — Red team / Challenge (~2 h)

Las decisiones aprobadas en Iter 1 NO entran al BACKLOG final hasta sobrevivir un segundo paso de validación crítica. Esta iteración busca activamente fallas, blind spots y riesgos no cubiertos.

**Round 5 — Challenge individual (paralelo, ~45 min)**

Cada uno de los 13 agentes (líder + 12 votantes) recibe `iter1-BACKLOG-PROVISIONAL.md` y se le pide **intentar ROMPER cada decisión aprobada** desde su perspectiva.

**Brief para cada agente:**
> Tu rol esta vez es **adversarial**. Para cada decisión aprobada en Iteración 1, identifica:
> - Edge cases no cubiertos
> - Interacciones con otros módulos que no se consideraron
> - Riesgos de regresión
> - Suposiciones implícitas que pueden no cumplirse
> - Conflictos con otras decisiones de la mesa
> - Si la decisión soluciona el síntoma pero no la causa raíz
>
> Si NO encuentras problema, declaralo explícito ("CONFIRMADO sin objeciones").
> Si encuentras algo, marcalo como **CHALLENGE** con severidad (crítico/medio/menor).

Output en `audit/round5/challenges/<skill>.md`.

**Round 6 — Líder consolida challenges (~30 min)**

`audit/round6/CHALLENGES-CONSOLIDADOS.md`:

```markdown
## Decisión #N
- Estado original: ✅ APROBADA Iteración 1
- Challenges recibidos:
  ├── tech-debt: CHALLENGE-CRITICO — "el refactor toca módulo X que tiene tests rotos"
  ├── security: CONFIRMADO sin objeciones
  ├── performance: CHALLENGE-MENOR — "agregar telemetría antes para baseline"
  └── ...
- Síntesis líder:
  - Challenges válidos: tech-debt (crítico), performance (menor)
  - Recomendación: REVISAR antes de sprint — agregar precondición "fix tests módulo X"
```

**Round 7 — Voto final post-challenge (paralelo, ~30 min)**

`audit/round7/votos-finales/<skill>.md`:

```markdown
### Decisión #N: <título>
**Voto final:** CONFIRMAR | MODIFICAR | RETIRAR
**Razón post-challenges:** ...
**Si MODIFICAR — qué cambia:** ...
**Si RETIRAR — por qué la objeción es bloqueante:** ...
```

**Reglas de cierre:**
- Confirmada por mayoría calificada (9+/12) → entra al BACKLOG final
- Modificada por mayoría → se reformula con el cambio agregado
- Retirada por mayoría → se mueve a `REJECTED` con razón
- Empate → líder Plan decide

**Output FINAL Iteración 2 (única fuente de verdad para sprint planning):**
```
~/Documents/akibara-v2/BACKLOG-2026-04-26.md
~/Documents/akibara-v2/audit/REJECTED-DECISIONS.md
~/Documents/akibara-v2/audit/PENDING-MOCKUPS.md
~/Documents/akibara-v2/audit/AUDIT-SUMMARY-2026-04-26.md
```

---

### Paso 4 — Crear scripts del workflow (30 min)

```
~/Documents/akibara-v2/scripts/
├── quality-gate.sh         ← PHPCS+Stan+PHPUnit+ESLint+knip+purgecss+stylelint+Trivy+gitleaks+audits
├── smoke-prod.sh           ← Playwright E2E vs https://akibara.cl con producto 24261
├── verify-via-mcp.ts       ← Cross-system check (orders + stock + Gmail)
├── lambdatest-prod.sh      ← Cross-browser Safari+iPhone+Chrome via LambdaTest
├── deploy.sh               ← rsync push a Hostinger + LiteSpeed purge + verify
└── monitor-post-deploy.sh  ← tail logs prod 15 min
```

### Paso 5 — Crear `CLAUDE.md` mínimo (~30 líneas)

```markdown
# akibara.cl

## Idioma
Tuteo chileno neutro. PROHIBIDO voseo rioplatense (confirmá/hacé/tenés/podés).

## Workflow
1. Edit local
2. bash scripts/quality-gate.sh   (~30s)
3. bash scripts/deploy.sh --purge --verify
4. bash scripts/smoke-prod.sh     (~3 min — Playwright vs prod)
5. bash scripts/verify-via-mcp.ts (~10s — Gmail + WC + Royal)
6. bash scripts/monitor-post-deploy.sh 15

## Reglas duras
- NO voseo rioplatense
- Email testing siempre a alejandro.fvaras@gmail.com
- NO modificar precios (_sale_price, _regular_price, _price)
- NO instalar plugins third-party
- Doble OK explícito para Docker → Hostinger
- Cero copy inventado (países, números clientes)
- Honestidad total — reportar fallas, limitaciones
- Cero acciones silenciosas — feedback visual obligatorio
- Branding pulido: cualquier cambio visual REQUIERE MOCKUP

## Productos test E2E
- 24261 disponible $8.990 (instock 999)
- 24262 preventa $14.990 (necesita meta _akb_reserva=yes via MCP)
- 24263 agotado (stock 0, requiere fix sale_price + categoría)

## Skills disponibles
Plan, tech-debt-auditor, accessibility-lead, contrast-master, aria-specialist,
keyboard-navigator, modal-specialist, forms-specialist, alt-text-headings,
tables-data-specialist, link-checker, cognitive-accessibility, mobile-accessibility,
responsive-auditor, design-system-auditor, email-qa, content-integrity,
testing-coach, general-purpose (con brief: SEO/Mercado Pago/Branding/Security/Web research)
```

### Paso 6 — Smoke E2E producto test (30 min)

Una sola prueba completa con producto 24261:

```
1.  Playwright → add 1× $8.990 al cart en https://akibara.cl/test-e2e-producto-disponible-no-comprar/
2.  Checkout completo (form real, transferencia directa BACS, alejandro.fvaras@gmail.com)
3.  Submit → order on-hold
4.  Gmail MCP verify: email "Pedido recibido" llegó
5.  WP MCP wc_update_order_status → processing
6.  Gmail MCP verify: email "Pedido en preparación"
7.  WP MCP wc_update_order_status → completed
8.  ⚡ akb_ship_auto_dispatch_courier ejecuta → BlueX label real
9.  Gmail MCP verify: email "Pedido completado" + tracking BlueX
10. Reporta — yo cancelo label BlueX manualmente desde panel
```

**Cleanup post-test (todo via MCP donde aplique):**
- Stock 24261: 998 → 999 vía `wc_update_product`
- Order: trash desde admin (manual mío)
- BlueX label: cancelar desde panel BlueX (manual mío)

### Paso 7 — Renombrar repo viejo (1 min)

```bash
mv ~/Documents/akibara ~/Documents/akibara-historico-2026-04-26
```

(Solo cuando todos los pasos 1–6 hayan funcionado.)

---

## 6. Stack quality-gate.sh (~30 seg, todo en paralelo)

**Linting PHP:**
- PHPCS WordPress coding standards
- PHPStan level 6 (con baseline)
- PCP / plugin-check WordPress.org

**Linting JS/CSS:**
- ESLint (TS/JS)
- Stylelint (CSS) — INSTALAR
- Prettier check — INSTALAR

**Tests:**
- PHPUnit (unit)
- Playwright @critical (E2E local docker)

**Dead code:**
- knip (JS/TS dead exports)
- purgecss (CSS no usado)
- composer-unused (PHP deps) — INSTALAR

**Security:**
- Trivy (CVEs filesystem)
- gitleaks (secrets git)
- composer audit (PHP CVEs)
- npm audit (JS CVEs)

**Quality cloud:**
- SonarCloud (calidad + duplicates + complexity) — async, cloud

**Content gates (grep):**
- Voseo rioplatense
- Claims inventados
- Secret patterns

---

## 7. Stack smoke-prod.sh (~3 min post-deploy)

- Playwright vs `https://akibara.cl` (read-only + checkout test)
- LambdaTest cross-browser (Safari macOS + iPhone real + Chrome Win)
- MCP verify producto + order + email
- Monitor logs prod 15 min en background

---

## 8. Backlog — estructura, severidades y gates

`~/Documents/akibara-v2/BACKLOG-2026-04-26.md`:

```markdown
# Backlog Akibara — Mesa técnica 2026-04-26

## Sprint 1 — Críticos (1 semana)
| ID | Severidad | Categoría | Item | Esfuerzo | Mockup? | Votos |

## Sprint 2 — Alto (1 semana)
## Sprint 3 — Medio
## Sprint 4+ — Nice to have

## ⏸ Pendiente mockup branding
(Cambios identificados que NO se implementan hasta tener propuesta visual aprobada)

## ❌ Rechazadas
(Decisiones que la mesa rechazó — para retomar Sprint 3+ si cambian condiciones)
```

**Severidades:**
- **P0** Críticos (security, oversells, broken UX, data loss)
- **P1** Alto (performance, a11y AA, conversion)
- **P2** Medio (refactor, dead code, UX clarity)
- **P3** Bajo (nice to have, cosmético)

**Esfuerzo:**
- **S** <2h | **M** 2-8h | **L** 1-3 días | **XL** >3 días (split obligatorio)

**Gate de Branding:**

Cualquier item que toque visual de marca (logo, colores, tipografía, layout principal) tiene flag `Mockup? = REQUERIDO`.

Items con `Mockup? = REQUERIDO` no se ejecutan hasta:
1. Mockup generado (HTML estático, screenshot before/after, o wireframe descriptivo)
2. Yo apruebo mockup explícitamente
3. Solo entonces pasa a `Sprint asignado` activo

Branding actual está pulido — el default es **NO TOCAR**. Solo issues bloqueantes (contrast fail, logo blurry mobile) se permiten sin mockup.

---

## 9. Cómo arrancamos esta sesión

1. Lee este prompt completo de punta a punta.
2. Confirma en una respuesta breve que entendiste:
   - El alcance del Paso 0 (sync remoto + audit + cleanup con DOBLE OK por categoría, backups obligatorios)
   - El alcance del Paso 3 (auditoría exhaustiva de ~85 unidades vía mesa de 14 agentes × 2 iteraciones)
   - Las reglas duras (especialmente: no voseo, no plugins third-party, no precios, MCP-first, mockup-before-visual, doble OK destructivo en server)
   - Los 3 productos test y los fixes pendientes en 24262 y 24263
   - El plan ejecutivo de 8 pasos (0 → 7)
3. Arranca por **Paso 0 — Sync remoto + audit + cleanup**.

**Recordatorios finales:**
- NO hagas operaciones destructivas sin DOBLE OK explícito (especialmente en server).
- NO ejecutes `rm` en server sin haber bajado antes el backup tar.gz a local.
- NO toques productos test (24261, 24262, 24263) salvo los fixes específicos descritos en sección 4.
- NO toques `wp-content/uploads/`, `wp-config.php`, `.htaccess`, ni cualquier `akibara*` en plugins/themes/mu-plugins durante el cleanup del Paso 0 — esos son código vivo o data crítica.
- NO toques prod más allá de inspección read-only via MCP, salvo los fixes mencionados y el cleanup aprobado.
- Reporta cualquier ambigüedad o riesgo apenas lo detectes — honestidad total > apariencia de progreso.
- Si el smoke post-cleanup (0.5) falla → restore inmediato del backup correspondiente, sin pedir OK.

---

**FIN DEL PROMPT ORIGINAL.**

---

## 10. Estado del workspace al cierre de sesión 2026-04-26 (post Paso 0)

> Esta sección refleja lo que ya está hecho. Cuando abras una nueva sesión, lee esto antes de seguir el plan ejecutivo desde donde quedó.

### 10.1 Decisiones clave que cambiaron respecto al prompt original

| Tema | Original | Realidad |
|------|----------|----------|
| Royal MCP URL | `/wp-json/royal-mcp/v1/mcp` | `/wp-json/royal-mcp/v1/` (sin `/mcp`) |
| Royal MCP auth | `Authorization: Bearer <key>` | `X-Royal-MCP-API-Key: <key>` (Bearer rechazado) |
| Stack local | Implícito Homebrew (PHP/Node/MariaDB) | **OrbStack + docker-compose** (todo en `~/Documents/akibara-v2/`) |
| SSH key | `~/.ssh/id_akibara` (asumida existente) | Generada nueva ed25519 sin passphrase 2026-04-26; pública agregada a Hostinger |
| MCP local | `mcp__wordpress-akibara__*` 43 tools | NO disponible en este harness — usar Royal MCP HTTP vía `.mcp.json` o `bin/mysql-prod` SQL directo |

### 10.2 Stack local instalado

**Homebrew formulas activas:** `git`, `gitleaks`, `trivy`, `jq`, `gettext`, `libunistring`, `pcre2`.

**Homebrew casks:** `orbstack`.

**Eliminados explícitamente** (todo via Docker ahora): `php`, `composer`, `node`, `npm`, `wp-cli`, `mariadb`. NO reinstalar via Homebrew — usar containers.

**Servicios docker-compose** (definidos en `docker-compose.yml`):

| Servicio | Imagen | Puerto host | Profile | Estado |
|----------|--------|-------------|---------|--------|
| `mariadb` | `mariadb:11` | 3307 | (always) | running, healthy |
| `wp` | `wordpress:php8.3-apache` | 8080 | (always) | NO levantado todavía (`data/wp/` vacío) |
| `wpcli` | `wordpress:cli-php8.3` | — | `cli` | one-shot |
| `composer` | `composer:2` | — | `cli` | one-shot |
| `php` | `php:8.3-cli` | — | `cli` | one-shot |
| `node` | `node:22-alpine` | — | `cli` | one-shot |

### 10.3 Wrappers en `bin/`

```
bin/php          docker compose run --rm php php
bin/composer     docker compose run --rm composer
bin/node         docker compose run --rm node node
bin/npm          docker compose run --rm node npm
bin/npx          docker compose run --rm node npx
bin/wp           docker compose run --rm wpcli  (WP-CLI contra DB local)
bin/mysql        mariadb local (cliente exec en container running)
bin/wp-ssh       ssh akibara "wp <args>"  (WP-CLI contra prod via SSH)
bin/db-tunnel    {up|down|status}  (gestiona SSH tunnel localhost:3308 -> prod)
bin/mysql-prod   conecta a DB prod via tunnel; auto-arranca tunnel si no está
```

### 10.4 Conectividad a DB prod (sustituye al MCP wp ausente)

```bash
bin/db-tunnel up
bin/mysql-prod -e "SELECT ID, post_title FROM wp_posts WHERE ID IN (24261,24262,24263);"
bin/db-tunnel down   # cuando termines, libera el tunnel
```

Tunnel: `localhost:3308 -> akibara:127.0.0.1:3306`. Read-only por convención — escribir solo con OK explícito tuyo.

### 10.5 Archivos sensibles NO commiteables (en `.gitignore`)

```
.env                    creds locales + prod tunnel
.private/               SQL dumps prod, secretos
data/                   volúmenes mariadb local
snapshots/              tar.gz pre-cleanup (1.5 GB)
server-snapshot/        mirror prod limpio (1.4 GB)
audit/raw/              outputs find del audit
.mcp.json               contiene API key Royal MCP en headers
.claude/settings.local.json  permisos personalizados
```

### 10.6 Archivos clave del proyecto

| Path | Qué es |
|------|--------|
| `docker-compose.yml` | stack local |
| `.env.example` | template de creds |
| `.mcp.json` | config Royal MCP HTTP server (gitignored, contiene key) |
| `audit/cleanup-report-2026-04-26.md` | reporte completo del Paso 0 |
| `audit/raw/A_top_dirs.txt` … `I_themes.txt` | outputs find por categoría |
| `.private/akb-prod-dump.sql` (28M) | dump mysqldump nativo |
| `.private/u888022333_gv0FJ.sql` (32M) | dump phpMyAdmin |
| `server-snapshot/public_html/` | mirror prod limpio (1.4 GB, montado read-only en containers como `/srv/prod-mirror:ro`) |

### 10.7 Pasos del plan ejecutivo: estado

| Paso | Estado |
|------|--------|
| **0** Sync remoto + audit + cleanup | ✅ Completado. -900 MB recuperados en server. Reporte en `audit/cleanup-report-2026-04-26.md`. |
| **1** Verificar setup base | ✅ Completado parcialmente (tools verificadas). Workspace 3.1 GB (incluye snapshots). |
| **2** git init + .gitignore | ✅ Completado. Branch `main` local, sin remote. Primer commit `51a3f02`. |
| **3** Mesa técnica auditoría exhaustiva | ⏸ Pendiente. ~85 unidades a auditar via 14 agentes × 2 iteraciones. |
| **4** Scripts del workflow | ⏸ Pendiente (`scripts/quality-gate.sh`, `scripts/smoke-prod.sh`, etc.) |
| **5** CLAUDE.md mínimo | ⏸ Pendiente |
| **6** Smoke E2E producto 24261 | ⏸ Pendiente — se hace contra prod, NO contra WP local |
| **7** Renombrar repo viejo | ⏸ Pendiente |

### 10.8 Productos test: estado actual ✅

**Producto 24262 (Preventa)** — ✅ FIXEADO 2026-04-26
- `_akb_reserva = yes`
- `_akb_reserva_tipo = preventa`
- Stock 999 instock, precio 14990. URL https://akibara.cl/test-e2e-producto-preventa-no-comprar/ devuelve 200.

**Producto 24263 (Agotado)** — ✅ FIXEADO 2026-04-26
- `_sale_price` ya estaba limpio (no requería fix)
- Categorías: `Preventas` removida → solo queda `Uncategorized` (term_id 15)
- Stock 0 outofstock, precio 11990. URL https://akibara.cl/test-e2e-producto-agotado-no-comprar/ devuelve 200.

Aplicados via `bin/wp-ssh post meta update` y `bin/wp-ssh post term set --by=slug`. Cache flushed + 2456 transients borrados después.

**Comando de re-verificación rápida:**
```bash
ssh akibara 'cd /home/u888022333/domains/akibara.cl/public_html && \
  wp post meta list 24262 --keys=_akb_reserva,_akb_reserva_tipo --format=table && \
  wp post term list 24263 product_cat --fields=slug,name'
```

### 10.9 Items que NO se restaurarán

Confirmado eliminar (NO restore):
- `test_blur.webp`, `test_color.webp` (eran tests del usuario, ya no necesarios)
- `staging/` y todos sus niveles anidados
- `AGENTS.md`, `AUDIT_PROGRESS.md`, etc. en root público
- `test_smart.php`, archivo `0`
- `wp-config.php.bak-*`, `.htaccess.bk`

### 10.10 Cómo arrancar una sesión nueva (override de §9)

1. `cd ~/Documents/akibara-v2`
2. `docker compose ps` — confirma que `mariadb` sigue healthy
3. `bin/db-tunnel status` — si necesitas DB prod, `bin/db-tunnel up`
4. Lee `audit/cleanup-report-2026-04-26.md` para contexto del Paso 0
5. Continúa desde el siguiente paso pendiente (10.7)

**Si el tunnel se cayó (reboot, etc.):** `bin/db-tunnel up`. Si OrbStack se reinició: levanta el container con `docker compose up -d mariadb`.

**FIN DEL PROMPT (actualizado 2026-04-26 post Paso 0).**
