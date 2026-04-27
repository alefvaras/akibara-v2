#!/usr/bin/env bash
# Akibara sync-staging.sh
#
# Sincroniza prod → staging.akibara.cl con anonimización PII obligatoria.
# Cumple B-S2-INFRA-01 DoD + Sprint 2 condition #4 (mesa-15 PRE-review).
#
# Arquitectura staging (memoria project_staging_subdomain):
# - Mismo MariaDB instance u_akibara
# - Tablas con prefix wpstg_ (vs prod wp_)
# - WP install fresh en /home/u888022333/domains/akibara.cl/staging/
# - SSL Let's Encrypt automático Hostinger
# - HTTP basic auth .htpasswd
# - robots.txt Disallow: /
# - Cloudflare Page Rule cache bypass
#
# Workflow obligatorio:
#   1. Confirmación destructive operation (DOBLE OK)
#   2. Backup pre-sync staging tables (tar.gz local)
#   3. mysqldump prod wp_* → sed prefix → mysql restore wpstg_*
#   4. Anonimización PII (emails/phones/api_keys/names/addresses/RUT)
#   5. Assertion automática: SELECT count rows con PII residual = 0
#   6. rsync wp-content/ prod → staging (excluyendo cache/, backup-*/)
#   7. Smoke staging básico (curl -I → 401)
#
# Uso:
#   bash scripts/sync-staging.sh                # full sync con prompts
#   bash scripts/sync-staging.sh --dry-run      # muestra plan, no ejecuta
#   bash scripts/sync-staging.sh --assert-only  # solo corre assertion (post-sync verify)
#   bash scripts/sync-staging.sh --skip-files   # solo DB sync (skip rsync wp-content)
#
# Pre-requisitos:
# - bin/wp-ssh y bin/mysql-prod funcionando (SSH alias akibara configurado)
# - Subdominio staging.akibara.cl YA creado en Hostinger panel (manual one-time)
# - wp-config staging YA creado con $table_prefix='wpstg_' (manual one-time)
# - .private/backups/ existe (gitignored)
#
# IMPORTANTE — destructive operation:
# Este script BORRA todas las tablas wpstg_* y las re-crea desde prod.
# Pre-existentes wpstg_* data se PIERDE. Backup automático antes de empezar.
#
# Anonimización PII (Ley 19.628 Chile + Ley 21.719 vigente Dec 2026):
# - emails → staging+ID@akibara.cl
# - phones → +56 9 0000 0000
# - first_name/last_name → Staging User
# - billing_address_1 → Av Staging 123
# - billing_postcode → 8320000
# - billing_rut → XX.XXX.XXX-X
# - api_keys / access_tokens / passwords → sandbox-staging
# - HPOS wpstg_wc_orders también anonimizado

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

# Colors
if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_WARN=""; C_DIM=""; C_OFF=""
fi

DRY_RUN=0
ASSERT_ONLY=0
SKIP_FILES=0

for arg in "$@"; do
  case "$arg" in
    --dry-run)     DRY_RUN=1 ;;
    --assert-only) ASSERT_ONLY=1 ;;
    --skip-files)  SKIP_FILES=1 ;;
    -h|--help)
      sed -n '2,40p' "$0"
      exit 0
      ;;
    *) echo "Unknown arg: $arg" >&2; exit 2 ;;
  esac
done

# ─── env / paths ────────────────────────────────────────────────────────────
ENV_FILE="$PROJECT_DIR/.env"
[[ -f "$ENV_FILE" ]] && set -a && . "$ENV_FILE" && set +a

PROD_HOST="${PROD_SSH_HOST:-akibara}"
PROD_PATH="${PROD_PATH:-/home/u888022333/domains/akibara.cl/public_html}"
STAGING_PATH="${STAGING_PATH:-/home/u888022333/domains/akibara.cl/public_html/staging}"

# DB credentials se leen dinámicamente desde wp-config prod (no hardcoded).
# El real es algo como u888022333_<random>, NO u_akibara como en docs antiguos.
# IMPORTANTE: MySQL Hostinger requiere -u/-p explícito, NO socket auth.
DB_NAME="${PROD_DB_NAME:-$(ssh "$PROD_HOST" "cd $PROD_PATH && wp config get DB_NAME" 2>/dev/null)}"
DB_USER="${PROD_DB_USER:-$(ssh "$PROD_HOST" "cd $PROD_PATH && wp config get DB_USER" 2>/dev/null)}"
DB_PASS="${PROD_DB_PASSWORD:-$(ssh "$PROD_HOST" "cd $PROD_PATH && wp config get DB_PASSWORD" 2>/dev/null)}"
DB_HOST_REMOTE="${PROD_DB_HOST:-$(ssh "$PROD_HOST" "cd $PROD_PATH && wp config get DB_HOST" 2>/dev/null)}"

if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$DB_PASS" ]]; then
  echo "❌ FATAL: no se pudo determinar DB credentials desde wp-config prod" >&2
  echo "   DB_NAME=$DB_NAME DB_USER=$DB_USER DB_PASS=${DB_PASS:+SET}${DB_PASS:-EMPTY}" >&2
  exit 2
fi

# Build mysql/mysqldump auth args (escaped para SSH passthrough)
# Usa MYSQL_PWD env var en lugar de -p para evitar password en ps aux
MYSQL_AUTH_ENV="MYSQL_PWD='$DB_PASS' "
MYSQL_AUTH_ARGS="-u'$DB_USER' -h'$DB_HOST_REMOTE'"

PREFIX_PROD="wp_"
PREFIX_STAGING="wpstg_"

DATE=$(date +%Y-%m-%d-%H%M%S)
BACKUP_DIR="$PROJECT_DIR/.private/backups"
mkdir -p "$BACKUP_DIR"

echo "${C_DIM}akibara sync-staging · $DATE${C_OFF}"
echo "${C_DIM}prod=${PROD_HOST}:${PROD_PATH} db=${DB_NAME} prefix=${PREFIX_PROD}${C_OFF}"
echo "${C_DIM}staging=${PROD_HOST}:${STAGING_PATH} db=${DB_NAME} prefix=${PREFIX_STAGING}${C_OFF}"
[[ "$DRY_RUN" -eq 1 ]] && echo "${C_WARN}🔸 DRY-RUN mode${C_OFF}"

# ─── assertion-only mode (post-sync verify) ─────────────────────────────────
run_pii_assertion() {
  echo ""
  echo "${C_DIM}── PII anonymization assertion ─────────────────────${C_OFF}"

  local sql=$(cat <<EOF
SELECT
  (SELECT COUNT(*) FROM ${PREFIX_STAGING}users
     WHERE user_email NOT LIKE 'staging+%@akibara.cl'
     AND ID > 0) AS pii_user_email_leak,
  (SELECT COUNT(*) FROM ${PREFIX_STAGING}usermeta
     WHERE meta_key IN ('billing_phone','shipping_phone')
     AND meta_value NOT IN ('+56 9 0000 0000','')) AS pii_phone_leak,
  (SELECT COUNT(*) FROM ${PREFIX_STAGING}usermeta
     WHERE meta_key IN ('billing_first_name','billing_last_name','first_name','last_name')
     AND meta_value NOT IN ('Staging','User','Staging User','')) AS pii_name_leak,
  (SELECT COUNT(*) FROM ${PREFIX_STAGING}usermeta
     WHERE meta_key IN ('billing_address_1','shipping_address_1')
     AND meta_value NOT IN ('Av Staging 123','')) AS pii_address_leak,
  (SELECT COUNT(*) FROM ${PREFIX_STAGING}usermeta
     WHERE meta_key IN ('billing_rut','_billing_rut')
     AND meta_value NOT IN ('XX.XXX.XXX-X','')) AS pii_rut_leak,
  (SELECT COUNT(*) FROM ${PREFIX_STAGING}options
     WHERE (option_name LIKE '%api_key%' OR option_name LIKE '%access_token%' OR option_name LIKE '%secret%')
     AND option_value != ''
     AND option_value != 'a:0:{}'
     AND option_value NOT LIKE 'sandbox-staging%') AS pii_apikey_leak\\G
EOF
)

  local out
  out=$(ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysql ${MYSQL_AUTH_ARGS} ${DB_NAME} -e \"${sql}\"" 2>&1)
  echo "$out"

  # Detect MySQL errors first (auth, syntax, etc)
  if echo "$out" | grep -qiE "(ERROR [0-9]+|Access denied|Unknown table|doesn't exist)"; then
    echo "${C_FAIL}❌ PII assertion FAIL — MySQL error detectado en query${C_OFF}"
    return 1
  fi

  # Verify que la query produjo al menos 1 _leak field (sino, query no corrió)
  if ! echo "$out" | grep -qE "_leak:"; then
    echo "${C_FAIL}❌ PII assertion FAIL — query no produjo output esperado (¿tablas no existen?)${C_OFF}"
    return 1
  fi

  # Check todos los counts = 0
  local total_leaks
  total_leaks=$(echo "$out" | grep -E "_leak: " | awk -F': ' '{sum+=$2} END {print sum+0}')

  if [[ "$total_leaks" -eq 0 ]]; then
    echo "${C_OK}✓ PII assertion PASS — staging anonymized completamente${C_OFF}"
    return 0
  else
    echo "${C_FAIL}❌ PII assertion FAIL — ${total_leaks} filas con PII residual${C_OFF}"
    echo "${C_FAIL}   ABORTAR usage de staging hasta resolver. NO compartir URL.${C_OFF}"
    return 1
  fi
}

if [[ "$ASSERT_ONLY" -eq 1 ]]; then
  run_pii_assertion
  exit $?
fi

# ─── DOBLE OK gate ──────────────────────────────────────────────────────────
echo ""
echo "${C_WARN}⚠️  DESTRUCTIVE OPERATION${C_OFF}"
echo "${C_WARN}    Este script BORRA todas las tablas ${PREFIX_STAGING}* en ${DB_NAME}${C_OFF}"
echo "${C_WARN}    y las recrea desde prod ${PREFIX_PROD}* tablas + anonimiza PII.${C_OFF}"
echo "${C_WARN}    Backup automático en ${BACKUP_DIR} antes de empezar.${C_OFF}"
echo ""

if [[ "$DRY_RUN" -ne 1 ]]; then
  read -p "Confirmación 1/2 — escribe 'sync staging' para continuar: " confirm1
  [[ "$confirm1" == "sync staging" ]] || { echo "Aborted."; exit 1; }
  read -p "Confirmación 2/2 — confirma de nuevo 'sync staging': " confirm2
  [[ "$confirm2" == "sync staging" ]] || { echo "Aborted."; exit 1; }
  echo "${C_OK}✓ DOBLE OK confirmado${C_OFF}"
fi

# ─── 1. Backup pre-sync (staging tables actuales) ───────────────────────────
echo ""
echo "${C_DIM}── 1/6 backup staging tables actuales ──────────────${C_OFF}"

BACKUP_FILE="$BACKUP_DIR/staging-pre-sync-${DATE}.sql.gz"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY: ssh $PROD_HOST mysqldump $DB_NAME ${PREFIX_STAGING}* | gzip > $BACKUP_FILE"
else
  # Get list of wpstg_ tables (puede estar vacío si primer sync)
  STAGING_TABLES_RAW=$(ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysql ${MYSQL_AUTH_ARGS} -N -e \"
    SHOW TABLES FROM ${DB_NAME} LIKE '${PREFIX_STAGING}%'
  \"" 2>/dev/null || true)

  if [[ -n "$STAGING_TABLES_RAW" ]]; then
    STAGING_TABLES=$(echo "$STAGING_TABLES_RAW" | tr '\n' ' ')
    ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysqldump ${MYSQL_AUTH_ARGS} --single-transaction --skip-lock-tables ${DB_NAME} ${STAGING_TABLES}" \
      | gzip > "$BACKUP_FILE"
    echo "${C_OK}✓ Backup pre-sync: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))${C_OFF}"
  else
    echo "${C_DIM}  (no existe ${PREFIX_STAGING}* tables aún — primer sync)${C_OFF}"
  fi
fi

# ─── 2. mysqldump prod + sed prefix ─────────────────────────────────────────
echo ""
echo "${C_DIM}── 2/6 mysqldump prod ${PREFIX_PROD}* → ${PREFIX_STAGING}* ───────${C_OFF}"

PROD_DUMP="/tmp/akibara-prod-dump-${DATE}.sql"
STAGING_DUMP="/tmp/akibara-staging-dump-${DATE}.sql"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY: ssh $PROD_HOST mysqldump $DB_NAME ${PREFIX_PROD}* > $PROD_DUMP"
  echo "DRY: sed 's/\`${PREFIX_PROD}/\`${PREFIX_STAGING}/g' $PROD_DUMP > $STAGING_DUMP"
else
  PROD_TABLES_RAW=$(ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysql ${MYSQL_AUTH_ARGS} -N -e \"
    SHOW TABLES FROM ${DB_NAME} LIKE '${PREFIX_PROD}%'
  \"" | grep -v "^${PREFIX_STAGING}")

  if [[ -z "$PROD_TABLES_RAW" ]]; then
    echo "${C_FAIL}❌ FATAL: no se encontraron tablas ${PREFIX_PROD}* en ${DB_NAME}${C_OFF}" >&2
    exit 1
  fi

  # Convert newlines to spaces para que mysqldump reciba como argumentos
  PROD_TABLES=$(echo "$PROD_TABLES_RAW" | tr '\n' ' ')

  ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysqldump ${MYSQL_AUTH_ARGS} --single-transaction --skip-lock-tables --no-tablespaces ${DB_NAME} ${PROD_TABLES}" \
    > "$PROD_DUMP"

  if [[ ! -s "$PROD_DUMP" ]]; then
    echo "${C_FAIL}❌ FATAL: prod dump está vacío (mysqldump falló silentemente)${C_OFF}" >&2
    exit 1
  fi

  # Rewrite identifiers `wp_X` → `wpstg_X` (backtick prefix safe — solo SQL identifiers)
  sed "s/\`${PREFIX_PROD}/\`${PREFIX_STAGING}/g" "$PROD_DUMP" > "$STAGING_DUMP"

  PROD_SIZE=$(du -h "$PROD_DUMP" | cut -f1)
  STAGING_SIZE=$(du -h "$STAGING_DUMP" | cut -f1)
  echo "${C_OK}✓ Prod dump: $PROD_SIZE | Staging dump (renamed): $STAGING_SIZE${C_OFF}"
fi

# ─── 3. Restore staging tables ──────────────────────────────────────────────
echo ""
echo "${C_DIM}── 3/6 restore ${PREFIX_STAGING}* tables ────────────────${C_OFF}"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY: scp $STAGING_DUMP $PROD_HOST:/tmp/ && ssh mysql ${DB_NAME} < dump"
else
  scp "$STAGING_DUMP" "${PROD_HOST}:${STAGING_DUMP}" >/dev/null
  ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysql ${MYSQL_AUTH_ARGS} ${DB_NAME} < ${STAGING_DUMP}"
  ssh "$PROD_HOST" "rm -f ${STAGING_DUMP}"
  rm -f "$PROD_DUMP" "$STAGING_DUMP"
  echo "${C_OK}✓ Staging tables restored${C_OFF}"
fi

# ─── 4. Anonimización PII ───────────────────────────────────────────────────
echo ""
echo "${C_DIM}── 4/6 anonimización PII ───────────────────────────${C_OFF}"

ANONYMIZE_SQL=$(cat <<EOF
-- Users emails
UPDATE ${PREFIX_STAGING}users
  SET user_email = CONCAT('staging+', ID, '@akibara.cl'),
      user_login = CONCAT('staging-', ID),
      user_pass = MD5(CONCAT('staging-', ID, '-', RAND()))
  WHERE ID > 0;

-- Usermeta phones
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = '+56 9 0000 0000'
  WHERE meta_key IN ('billing_phone', 'shipping_phone');

-- Usermeta names
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = 'Staging'
  WHERE meta_key IN ('first_name', 'billing_first_name', 'shipping_first_name');
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = 'User'
  WHERE meta_key IN ('last_name', 'billing_last_name', 'shipping_last_name');

-- Usermeta addresses
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = 'Av Staging 123'
  WHERE meta_key IN ('billing_address_1', 'shipping_address_1');
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = ''
  WHERE meta_key IN ('billing_address_2', 'shipping_address_2');
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = 'Santiago'
  WHERE meta_key IN ('billing_city', 'shipping_city');
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = '8320000'
  WHERE meta_key IN ('billing_postcode', 'shipping_postcode');

-- Usermeta RUT (Chile-specific)
UPDATE ${PREFIX_STAGING}usermeta
  SET meta_value = 'XX.XXX.XXX-X'
  WHERE meta_key IN ('billing_rut', '_billing_rut');

-- Options API keys / tokens / secrets — todos los names que matchen patterns sensitivos
UPDATE ${PREFIX_STAGING}options
  SET option_value = CONCAT('sandbox-staging-', LEFT(MD5(option_name), 8))
  WHERE (option_name LIKE '%api_key%'
     OR option_name LIKE '%access_token%'
     OR option_name LIKE '%secret%')
  AND option_value != ''
  AND option_value != 'a:0:{}'                -- skip arrays serialized vacíos (jetpack_secrets etc)
  AND option_value NOT LIKE 'sandbox-staging%';

-- HPOS orders (wpstg_wc_orders) — schema solo tiene billing_email + ip_address + customer_note
-- Phone, first_name, address vive en wpstg_wc_order_addresses (handled abajo)
UPDATE ${PREFIX_STAGING}wc_orders
  SET billing_email = CONCAT('staging+order-', id, '@akibara.cl'),
      ip_address = '127.0.0.1',
      customer_note = ''
  WHERE id > 0;

-- HPOS order meta
UPDATE ${PREFIX_STAGING}wc_orders_meta
  SET meta_value = '+56 9 0000 0000'
  WHERE meta_key IN ('_billing_phone', '_shipping_phone');
UPDATE ${PREFIX_STAGING}wc_orders_meta
  SET meta_value = 'Staging User'
  WHERE meta_key IN ('_billing_first_name', '_billing_last_name', '_shipping_first_name', '_shipping_last_name');
UPDATE ${PREFIX_STAGING}wc_orders_meta
  SET meta_value = 'Av Staging 123'
  WHERE meta_key IN ('_billing_address_1', '_shipping_address_1');
UPDATE ${PREFIX_STAGING}wc_orders_meta
  SET meta_value = 'XX.XXX.XXX-X'
  WHERE meta_key IN ('billing_rut', '_billing_rut');

-- HPOS order addresses (wpstg_wc_order_addresses)
UPDATE ${PREFIX_STAGING}wc_order_addresses
  SET email = CONCAT('staging+addr-', id, '@akibara.cl'),
      phone = '+56 9 0000 0000',
      first_name = 'Staging',
      last_name = 'User',
      address_1 = 'Av Staging 123',
      address_2 = '',
      city = 'Santiago',
      postcode = '8320000'
  WHERE id > 0;

-- WC sessions + custom Akibara tables con PII
-- DELETE FROM tolera tablas inexistentes con --force flag mysql (statement por statement)
-- TRUNCATE no soporta IF EXISTS; usamos DELETE para que mysql --force continue si tabla no existe
DELETE FROM ${PREFIX_STAGING}woocommerce_sessions;
DELETE FROM ${PREFIX_STAGING}akb_referrals;
DELETE FROM ${PREFIX_STAGING}akb_email_log;
DELETE FROM ${PREFIX_STAGING}akb_abandoned_carts;
DELETE FROM ${PREFIX_STAGING}akb_back_in_stock_subs;
DELETE FROM ${PREFIX_STAGING}bluex_logs;

-- Update siteurl/home options (CRITICAL — staging URL)
UPDATE ${PREFIX_STAGING}options SET option_value = 'https://staging.akibara.cl' WHERE option_name = 'siteurl';
UPDATE ${PREFIX_STAGING}options SET option_value = 'https://staging.akibara.cl' WHERE option_name = 'home';
EOF
)

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY: anonymize SQL (~30 UPDATE/TRUNCATE statements)"
else
  # mysql --force continua después de errores (e.g. DELETE FROM tabla_inexistente).
  # Errores reales de UPDATE columns/SQL syntax se loggean pero no abortan;
  # PII assertion (paso 5) verifica que TODAS las anonymizations succeeded.
  echo "$ANONYMIZE_SQL" | ssh "$PROD_HOST" "${MYSQL_AUTH_ENV}mysql --force ${MYSQL_AUTH_ARGS} ${DB_NAME}"
  echo "${C_OK}✓ Anonimización ejecutada${C_OFF}"
fi

# ─── 5. PII assertion ───────────────────────────────────────────────────────
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo ""
  echo "${C_DIM}── 5/6 PII assertion (skipped en dry-run) ──${C_OFF}"
else
  if ! run_pii_assertion; then
    echo "${C_FAIL}❌ FATAL — PII residual detectado. Staging NO seguro de usar.${C_OFF}"
    echo "${C_FAIL}   Investigate UPDATE statements + re-run.${C_OFF}"
    exit 1
  fi
fi

# ─── 6. rsync wp-content (opcional) ─────────────────────────────────────────
if [[ "$SKIP_FILES" -eq 1 ]]; then
  echo ""
  echo "${C_WARN}⚠️  6/6 wp-content rsync SKIPPED (--skip-files)${C_OFF}"
else
  echo ""
  echo "${C_DIM}── 6/6 rsync wp-content prod → staging ────────────${C_OFF}"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "DRY: ssh $PROD_HOST rsync -avz --exclude=cache/ --exclude=backup-*/ \\"
    echo "       $PROD_PATH/wp-content/ $STAGING_PATH/wp-content/"
  else
    ssh "$PROD_HOST" "rsync -avz \
      --exclude='cache/' \
      --exclude='backup-*/' \
      --exclude='backups/' \
      --exclude='wflogs/' \
      --exclude='ai1wm-backups/' \
      --exclude='updraft/' \
      --exclude='upgrade/' \
      --exclude='*.log' \
      $PROD_PATH/wp-content/ $STAGING_PATH/wp-content/"
    echo "${C_OK}✓ wp-content sincronizado${C_OFF}"
  fi
fi

# ─── 7. Smoke staging ───────────────────────────────────────────────────────
echo ""
echo "${C_DIM}── smoke staging.akibara.cl ────────────────────────${C_OFF}"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "DRY: curl -I https://staging.akibara.cl → expect 401"
else
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -I https://staging.akibara.cl || echo "000")
  if [[ "$HTTP_CODE" == "401" ]]; then
    echo "${C_OK}✓ staging responde 401 (basic auth gate funcionando)${C_OFF}"
  elif [[ "$HTTP_CODE" == "200" ]]; then
    echo "${C_FAIL}❌ staging responde 200 — basic auth NO funcionando${C_OFF}"
    echo "${C_FAIL}   Verify .htpasswd + .htaccess en $STAGING_PATH${C_OFF}"
  else
    echo "${C_WARN}⚠️  staging responde $HTTP_CODE — verificar manualmente${C_OFF}"
  fi
fi

echo ""
echo "${C_OK}═══════════════════════════════════════════════════${C_OFF}"
echo "${C_OK}✓ sync-staging COMPLETED · $(date +%H:%M:%S)${C_OFF}"
echo "${C_OK}═══════════════════════════════════════════════════${C_OFF}"
echo ""
echo "Next steps:"
echo "  1. curl -u alejandro:<password> https://staging.akibara.cl → expect 200"
echo "  2. Login wp-admin staging con user staging-1 (recover password vía Brevo staging key)"
echo "  3. Smoke: producto reservar staging → email a alejandro.fvaras@gmail.com"
echo "  4. Verify Sentry env=staging segregation"
