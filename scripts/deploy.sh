#!/usr/bin/env bash
# Akibara deploy.sh
#
# rsync wp-content/ → prod akibara.cl (Hostinger). Cumple B-S1-SETUP-04 del BACKLOG.
#
# Workflow obligatorio (per memoria project_deploy_workflow_docker_first):
#   1. quality-gate (PHPCS+Stan+ESLint+content gates...)
#   2. backup pre-deploy (DB dump + snapshot remoto)
#   3. rsync con excludes obligatorios (no vendor, tests, docs, .env, etc.)
#   4. LiteSpeed purge post
#   5. smoke-prod
#
# IMPORTANTE — política rsync:
# - SIN --delete por defecto. Solo agrega/actualiza archivos. NO elimina nada.
#   Razón: workspace solo tiene los archivos que editamos (no el árbol completo
#   de plugins/themes). Con --delete nukeríamos prod.
# - Post Sprint 2 (Cell Core extraction → árbol completo editable), considerar
#   --delete-after con whitelist a paths akibara-* específicos.
#
# Uso:
#   bash scripts/deploy.sh                # full deploy con quality-gate + backup + smoke
#   bash scripts/deploy.sh --dry-run      # rsync -n (no escribe, solo muestra)
#   bash scripts/deploy.sh --skip-gate    # omite quality-gate (uso ocasional, anti-pattern)
#   bash scripts/deploy.sh --skip-backup  # omite DB dump (solo si <5 min cambios sin DB risk)
#   bash scripts/deploy.sh --no-smoke     # omite smoke-prod post (debug)

set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

# Colors
if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_WARN=""; C_DIM=""; C_OFF=""
fi

DRY_RUN=0
SKIP_GATE=0
SKIP_BACKUP=0
SMOKE=1

for arg in "$@"; do
  case "$arg" in
    --dry-run)     DRY_RUN=1 ;;
    --skip-gate)   SKIP_GATE=1 ;;
    --skip-backup) SKIP_BACKUP=1 ;;
    --no-smoke)    SMOKE=0 ;;
    -h|--help)
      echo "Usage: bash scripts/deploy.sh [--dry-run|--skip-gate|--skip-backup|--no-smoke|--help]"
      exit 0
      ;;
    *) echo "Unknown arg: $arg" >&2; exit 2 ;;
  esac
done

# ─── pre-flight ─────────────────────────────────────────────────────────────
[[ -d "$PROJECT_DIR/wp-content" ]] || { echo "${C_FAIL}❌ wp-content/ no existe en workspace${C_OFF}"; exit 1; }

DATE=$(date +%Y-%m-%d-%H%M%S)
BACKUP_DIR="$PROJECT_DIR/.private/backups"
mkdir -p "$BACKUP_DIR"

PROD_HOST="akibara"
PROD_PATH="/home/u888022333/domains/akibara.cl/public_html"
PROD_WP_CONTENT="$PROD_PATH/wp-content"

echo "${C_DIM}akibara deploy · $DATE · target=${PROD_HOST}:${PROD_WP_CONTENT}${C_OFF}"
[[ "$DRY_RUN" -eq 1 ]] && echo "${C_WARN}🔸 DRY-RUN mode — no se escribirá en prod${C_OFF}"

# ─── 1. Quality gate ────────────────────────────────────────────────────────
if [[ "$SKIP_GATE" -eq 1 ]]; then
  echo "${C_WARN}⚠️  quality-gate SKIPPED (--skip-gate)${C_OFF}"
else
  echo ""
  echo "${C_DIM}── 1/5 quality-gate ──────────────────────────────${C_OFF}"
  if ! bash "$PROJECT_DIR/scripts/quality-gate.sh" --quick; then
    echo "${C_FAIL}❌ quality-gate FAILED — abort deploy${C_OFF}"
    exit 1
  fi
fi

# ─── 2. Backup pre-deploy (sólo si no --skip-backup y no --dry-run) ──────────
if [[ "$SKIP_BACKUP" -eq 1 ]]; then
  echo "${C_WARN}⚠️  backup pre-deploy SKIPPED (--skip-backup)${C_OFF}"
elif [[ "$DRY_RUN" -eq 1 ]]; then
  echo "${C_DIM}── 2/5 backup pre-deploy SKIPPED (--dry-run) ─────${C_OFF}"
else
  echo ""
  echo "${C_DIM}── 2/5 backup pre-deploy ─────────────────────────${C_OFF}"

  # 2a. DB dump (full)
  echo "${C_DIM}  · DB dump...${C_OFF}"
  set -a
  # shellcheck disable=SC1091
  [[ -f "$PROJECT_DIR/.env" ]] && source "$PROJECT_DIR/.env"
  set +a
  DB_DUMP="$BACKUP_DIR/${DATE}-pre-deploy-FULL.sql"
  ssh "$PROD_HOST" "mysqldump --single-transaction --quick --skip-lock-tables -u '$PROD_DB_USER' -p'$PROD_DB_PASSWORD' '$PROD_DB_NAME'" > "$DB_DUMP" 2>/dev/null
  if [[ ! -s "$DB_DUMP" ]] || ! grep -q "Dump completed" "$DB_DUMP"; then
    echo "${C_FAIL}❌ DB dump fallido${C_OFF}"
    exit 1
  fi
  echo "${C_DIM}    $(ls -lh "$DB_DUMP" | awk '{print $5, $9}')${C_OFF}"

  # 2b. Snapshot remoto wp-content akibara-*
  echo "${C_DIM}  · snapshot remoto wp-content akibara-*...${C_OFF}"
  ssh "$PROD_HOST" "tar -czf /tmp/akb-pre-deploy-${DATE}.tar.gz \
    -C ${PROD_PATH} \
    wp-content/plugins/akibara wp-content/plugins/akibara-reservas wp-content/plugins/akibara-whatsapp \
    wp-content/themes/akibara \
    wp-content/mu-plugins" 2>&1 | head -3 || true
  ssh "$PROD_HOST" "ls -lh /tmp/akb-pre-deploy-${DATE}.tar.gz" 2>&1
fi

# ─── 3. rsync ───────────────────────────────────────────────────────────────
echo ""
echo "${C_DIM}── 3/5 rsync wp-content/ → prod ──────────────────${C_OFF}"

# Excludes per memoria project_deploy_exclude_dev_tooling.
RSYNC_EXCLUDES=(
  --exclude='vendor/'
  --exclude='node_modules/'
  --exclude='tests/'
  --exclude='test/'
  --exclude='*.test.php'
  --exclude='coverage/'
  --exclude='.phpunit.cache/'
  --exclude='phpunit.xml*'
  --exclude='phpcs.xml*'
  --exclude='phpstan.neon*'
  --exclude='*-baseline*'
  --exclude='composer.json'
  --exclude='composer.lock'
  --exclude='package.json'
  --exclude='package-lock.json'
  --exclude='package*.json'
  --exclude='README.md'
  --exclude='CHANGELOG.md'
  --exclude='LICENSE'
  --exclude='LICENSE.txt'
  --exclude='docs/'
  --exclude='.git/'
  --exclude='.gitignore'
  --exclude='.gitattributes'
  --exclude='.vscode/'
  --exclude='.idea/'
  --exclude='.DS_Store'
  --exclude='*.bak'
  --exclude='*.old'
  --exclude='*~'
  --exclude='.env'
  --exclude='.env.*'
  --exclude='.mcp.json'
  --exclude='.claude/'
)

# rsync flags:
# -a archive, -v verbose, -z compress, --stats summary
# --no-perms/owner/group = no toca metadata (Hostinger maneja perms)
# NO --delete por default (ver header del script)
RSYNC_FLAGS=(-avz --stats --no-perms --no-owner --no-group)
[[ "$DRY_RUN" -eq 1 ]] && RSYNC_FLAGS+=(-n)

rsync "${RSYNC_FLAGS[@]}" "${RSYNC_EXCLUDES[@]}" \
  "$PROJECT_DIR/wp-content/" \
  "${PROD_HOST}:${PROD_WP_CONTENT}/"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo ""
  echo "${C_WARN}🔸 dry-run completo. Para deploy real: bash scripts/deploy.sh${C_OFF}"
  exit 0
fi

# ─── 4. LiteSpeed purge ─────────────────────────────────────────────────────
echo ""
echo "${C_DIM}── 4/5 LiteSpeed cache purge ──────────────────────${C_OFF}"
ssh "$PROD_HOST" "cd $PROD_PATH && wp eval 'do_action(\"litespeed_purge_all\"); echo \"purged\";'" 2>&1 | head -3 || \
  echo "${C_WARN}⚠️  litespeed purge no ejecutó (continúo de todos modos)${C_OFF}"

# ─── 5. Smoke ───────────────────────────────────────────────────────────────
if [[ "$SMOKE" -eq 1 ]]; then
  echo ""
  echo "${C_DIM}── 5/5 smoke-prod.sh ──────────────────────────────${C_OFF}"
  if ! bash "$PROJECT_DIR/scripts/smoke-prod.sh" --quick; then
    echo "${C_FAIL}⚠️  smoke FAILED post-deploy — investigar urgente${C_OFF}"
    echo "${C_FAIL}    Para rollback: ver docs/RUNBOOK-DESTRUCTIVO.md §3${C_OFF}"
    exit 1
  fi
fi

echo ""
echo "${C_OK}✅ Deploy completo${C_OFF}"
echo "${C_DIM}   Backup: $DB_DUMP${C_OFF}"
echo "${C_DIM}   Snapshot remoto: ${PROD_HOST}:/tmp/akb-pre-deploy-${DATE}.tar.gz${C_OFF}"
echo ""
echo "${C_DIM}Siguiente paso: bash scripts/monitor-post-deploy.sh${C_OFF}"
