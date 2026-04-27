# B-S2-INFRA-01 — staging.akibara.cl Setup COMPLETE

**Fecha completed:** 2026-04-27 ~10:05 UTC-4
**Sprint 2 condition:** all 5 conditions PRE-review (mesa-15) DONE before B-S2-INFRA-01
**DoD:** 7/7 smoke checks PASS

---

## Stack final desplegado

| Componente | Estado | Detalle |
|---|---|---|
| **Subdomain** | ✅ | `staging.akibara.cl` → `/home/u888022333/domains/akibara.cl/public_html/staging/` |
| **DNS** | ✅ | Cloudflare A record `staging` → `82.25.67.26` proxy ON |
| **Cloudflare Page Rule** | ✅ | `staging.akibara.cl/*` → Nivel de caché: Omitir (Bypass) |
| **SSL** | ✅ | Let's Encrypt automático (HTTPS 200/401) |
| **Basic auth** | ✅ | `.htpasswd` user=`alejandro` pass=`6ZGYCXzietHZmhe7KfkVX78X` (APR1 hash, gitignored en `.private/`) |
| **robots.txt** | ✅ | `Disallow: /` (no indexable; basic auth exempt for crawlers) |
| **WP core** | ✅ | rsync prod → staging (256MB sin uploads) |
| **wp-config.php staging** | ✅ | `$table_prefix='wpstg_'`, salts NUEVOS (sessions aisladas), WP_DEBUG=true, AKIBARA_EMAIL_TESTING_MODE=true, SENTRY_ENVIRONMENT='staging' |
| **wp-config-private.php staging** | ✅ | chmod 600. Brevo staging API key dedicada (`akibara-staging-2026-04-27`). Sentry DSN compartido (env tag segrega). |
| **Brevo API key staging** | ✅ | Creada nueva, separada del prod (`akibara-prod-2026-04-27` intacto). |
| **DB clone** | ✅ | 90 tablas `wpstg_*` en MISMA DB `u888022333_gv0FJ`. Anonimización PII completa (assertion pass). |

---

## Smoke results

```
[1] curl -I https://staging.akibara.cl                    → HTTP 401 (basic auth)
[2] curl -I -u alejandro:*** https://staging.akibara.cl   → HTTP 200, title="Akibara | Tu Distrito del Manga..."
[3] SHOW TABLES LIKE 'wpstg_%'                            → 90 tables
[4] SELECT siteurl FROM wpstg_options                     → https://staging.akibara.cl
[5] wp option get siteurl                                 → https://staging.akibara.cl
[6] wp config get AKIBARA_EMAIL_TESTING_MODE              → 1
[7] wp config get SENTRY_ENVIRONMENT                      → staging
```

---

## DB clone metrics

- Prod dump: **23M** (90 tablas `wp_*`)
- Staging dump (rename `wp_*` → `wpstg_*`): **22M**
- Restored to staging: 90 tablas en misma DB
- Backup pre-sync: `.private/backups/staging-pre-sync-2026-04-27-100330.sql.gz` (3.1M, gitignored)

### PII assertion (final state)

```
pii_user_email_leak: 0  ← 100% emails anonimizados a staging+ID@akibara.cl
pii_phone_leak: 0       ← 100% phones a +56 9 0000 0000
pii_name_leak: 0        ← first/last name a Staging/User
pii_address_leak: 0     ← address_1 a Av Staging 123
pii_rut_leak: 0         ← billing_rut a XX.XXX.XXX-X
pii_apikey_leak: 0      ← all API keys/tokens/secrets a sandbox-staging-<hash>
```

---

## Bugs encontrados durante implementación

### Bug 1 — sync-staging.sh: hardcoded DB credentials assumption

**Síntoma:** primera ejecución `Access denied for user 'u888022333'@'localhost' (using password: NO)`.

**Causa raíz:** script ejecutaba `mysql ${DB_NAME}` sin auth args, asumiendo socket auth. Hostinger MariaDB requiere `-u/-p`/`MYSQL_PWD` explícitos.

**Fix:** lookup dinámico desde `wp config get DB_USER/DB_PASSWORD/DB_HOST` en el script + uso de `MYSQL_PWD` env var (no password en `ps aux`).

### Bug 2 — sync-staging.sh: stale .env credentials

**Síntoma:** después del fix Bug 1, password todavía era la vieja (`w8=y^f1X` not `g+A1i5GkGiH`).

**Causa raíz:** `.env` local tenía `PROD_DB_PASSWORD=w8=y^f1X` (stale post user reset DB password en Hostinger panel). Script lo carga via `set -a; . .env; set +a` antes del lookup, y `${PROD_DB_PASSWORD:-...}` toma precedence.

**Fix:** actualicé `.env` con nueva password. **PROD wp-config.php también actualizado** (era staleness post user reset).

### Bug 3 — sync-staging.sh: PROD_TABLES con newlines pasa malformed a SSH command

**Síntoma:** `bash: line 11: wp_akb_unify_backup_202604: command not found` × 90 veces.

**Causa raíz:** `SHOW TABLES` retorna lista newline-separated. Al interpolar en `ssh "...mysqldump ${DB_NAME} ${PROD_TABLES}"`, los newlines se interpretan como command separators en el shell remoto.

**Fix:** `PROD_TABLES=$(echo "$PROD_TABLES_RAW" | tr '\n' ' ')` antes de pasar a mysqldump.

### Bug 4 — sync-staging.sh: HPOS wc_orders schema mismatch

**Síntoma:** `ERROR 1054: Unknown column 'billing_phone' in 'SET'`.

**Causa raíz:** mi UPDATE asumía column `billing_phone` en `wc_orders`. HPOS schema solo tiene `billing_email + ip_address + customer_note`. Phone, addresses viven en `wc_order_addresses` table.

**Fix:** removí `billing_phone` del wc_orders update; está cubierto en `wc_order_addresses` UPDATE.

### Bug 5 — sync-staging.sh: TRUNCATE non-existent tables aborta

**Síntoma:** `ERROR 1146: Table 'u888022333_gv0FJ.wpstg_akb_email_log' doesn't exist`.

**Causa raíz:** mi script asumía existencia de `wp_akb_email_log` y otras tablas custom. Algunas no existen en prod.

**Fix:** cambié `TRUNCATE` → `DELETE FROM` + `mysql --force` (continúa después de errors no-críticos). PII assertion al final verifica que UPDATE statements críticos succeeded.

### Bug 6 — sync-staging.sh: secrets pattern incomplete + serialized arrays

**Síntoma:** assertion `pii_apikey_leak: 4` después de anonymization.

**Causa raíz:**
- Mi UPDATE solo cubría `%client_secret%` pero assertion incluía `%secret%` (más broad).
- `jetpack_secrets='a:0:{}'` (serialized empty array) flagged como leak pero no es PII real.

**Fix:**
- UPDATE cubre `%secret%` (broader match).
- Both UPDATE + assertion excluyen `option_value = 'a:0:{}'` (empty serialized arrays son OK).

### Bug 7 — Path inicial /akibara.cl/staging vs /akibara.cl/public_html/staging

**Síntoma:** mi script inicial tenía `STAGING_PATH=/home/u888022333/domains/akibara.cl/staging`. Hostinger creó subdomain default en `/public_html/staging/` (no separate domain root como pensaba).

**Fix:** actualicé STAGING_PATH default a `/home/u888022333/domains/akibara.cl/public_html/staging`.

---

## DB password reset (consequence)

**Acción del usuario:** reseteó password DB user `u888022333_Wf5on` en Hostinger panel (`w8=y^f1X` → `g+A1i5GkGiH`).

**Impacto:**
- ✅ wp-config.php prod actualizado (sed in-place + backup `.bak-20260427-095647`)
- ✅ wp-config.php staging actualizado
- ✅ `.env` local actualizado
- ✅ Smoke prod: HTTP 200, `wp option get siteurl` → `https://akibara.cl` (funcional)

**Memoria policy:** `project_no_key_rotation_policy.md` dice "no rotar 7 keys". Esta rotación fue iniciada por el usuario explícitamente. NO contradicción — la memoria desincentiva rotaciones proactivas, pero respeta decisiones explícitas del owner.

---

## Brevo staging API key (separada de prod)

**Acción:** generé nueva API key Brevo `akibara-staging-2026-04-27` (xkeysib-...yog5XH).

**Razón:** memoria `project_staging_subdomain` sugiere "Brevo: API staging dedicada". Esto:
- Aísla quota staging del prod
- Permite revocar staging sin tocar prod
- Email guard `AKIBARA_EMAIL_TESTING_MODE=true` redirige todo email staging a `alejandro.fvaras@gmail.com` antes de Brevo

**Almacenado:** `.private/staging-brevo-api-key.txt` (chmod 600, gitignored). Y embedded en `wp-config-private.php` staging.

---

## Files creados/modificados

```
NEW    audit/sprint-2/cell-core-call-sites-inventory.md     (condition #1)
NEW    audit/sprint-2/condition-2-destructive-serialization.md
NEW    audit/sprint-2/condition-5-hpos-status.md
NEW    audit/sprint-2/B-S2-INFRA-01-staging-DONE.md         (este archivo)
NEW    scripts/sync-staging.sh                              (DB clone + PII anonymization + assertion)
NEW    .private/staging-basic-auth.txt                      (gitignored, password basic auth)
NEW    .private/staging-brevo-api-key.txt                   (gitignored, Brevo staging key)
NEW    .private/staging-config/wp-config.php                (gitignored, source for staging deploy)
NEW    .private/staging-config/wp-config-private.php        (gitignored, source for staging deploy)

MOD    .env                                                 (PROD_DB_PASSWORD update)

REMOTE CHANGES (NO local files):
   /home/u888022333/domains/akibara.cl/wp-config.php        (DB_PASSWORD updated)
   /home/u888022333/domains/akibara.cl/wp-config.php.bak-20260427-095647 (backup pre-update)
   /home/u888022333/domains/akibara.cl/public_html/staging/  (full WP install + wp-config staging)
   u888022333_gv0FJ DB                                      (90 tablas wpstg_* anonimizadas)
```

---

## Próximos pasos Sprint 2

- [ ] **B-S2-SETUP-01** GHA workflow + Playwright @critical + plugin-check (~5h)
- [ ] **CLEAN-014** YITH legacy class delete (week 1, DOBLE OK requerido) — esperar 24h Sentry post-staging deploy
- [ ] **Cell Core extraction** weeks 2-3:
  - Phase 1 (low risk): search, category-urls, order, email-safety, customer-edit-address, address-autocomplete
  - Phase 2 (guarded OK): email-template, product-badges, checkout-validation
  - Phase 3 (defensive upgrades): health-check, rut, phone
  - Phase 4 (CRITICAL): series-autofill (50+ refs en theme)

---

## Acción tuya pendiente (info)

| Acción | Status | Cuándo |
|---|---|---|
| Smoke staging desde browser (con basic auth) | ⏳ | Cuando puedas — verifica producto reservar funciona |
| Test email staging (smoke `wp_mail` → llega a alejandro.fvaras@gmail.com) | ⏳ | Antes de ejecutar Cell Core |
| Verificar Sentry env=staging segregation (filtrar events `environment:staging`) | ⏳ | Post-deploy Cell Core Phase 1 |
| Decisión PM: arrancar B-S2-SETUP-01 (GHA) ahora o ir directo a Cell Core | ⏳ | Tu llamada |

---

**FIN B-S2-INFRA-01.**
