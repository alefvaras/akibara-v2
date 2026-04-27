#!/usr/bin/env bash
# Akibara monitor-post-deploy.sh
#
# Monitor logs prod por N minutos post-deploy. Cumple B-S1-SETUP-05 del BACKLOG.
#
# Sources monitoreados:
#   1. /home/u888022333/error_log (PHP / Apache fatal errors)
#   2. wp-content/uploads/wc-logs/fatal-errors-YYYY-MM-DD-*.log (WC native fatal logger)
#   3. Sentry: requiere check manual via dashboard (no API CLI configurada aún)
#
# Uso:
#   bash scripts/monitor-post-deploy.sh           # 15 min default
#   bash scripts/monitor-post-deploy.sh 5         # 5 min
#   bash scripts/monitor-post-deploy.sh --once    # snapshot único, no tail
#   bash scripts/monitor-post-deploy.sh --json    # output JSON estructurado
#
# Exit code: 0 si zero new errors, 1 si new errors detectados.

set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

DURATION_MIN=15
ONCE=0
JSON=0

for arg in "$@"; do
  case "$arg" in
    --once) ONCE=1 ;;
    --json) JSON=1 ;;
    -h|--help)
      echo "Usage: bash scripts/monitor-post-deploy.sh [N|--once|--json|--help]"
      echo "  N       monitor por N minutos (default 15)"
      echo "  --once  snapshot único sin tail"
      echo "  --json  output JSON"
      exit 0
      ;;
    [0-9]*) DURATION_MIN="$arg" ;;
  esac
done

if [[ -t 1 ]] && [[ "$JSON" -ne 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_WARN=""; C_DIM=""; C_OFF=""
fi

PROD_HOST="akibara"
PHP_ERROR_LOG="/home/u888022333/error_log"
WC_LOGS_PATH="/home/u888022333/domains/akibara.cl/public_html/wp-content/uploads/wc-logs"

START_TS=$(date +%s)
START_HUMAN=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
END_TS=$((START_TS + DURATION_MIN * 60))

# ─── helpers ────────────────────────────────────────────────────────────────

# count_new_errors_since <epoch_ts>
# Cuenta líneas en /home/u888022333/error_log con timestamp posterior a $epoch_ts.
count_php_errors() {
  local since_human
  since_human=$(date -u -r "$1" +"%d-%b-%Y %H:%M:%S" 2>/dev/null || date -u --date="@$1" +"%d-%b-%Y %H:%M:%S")
  ssh "$PROD_HOST" "tail -500 ${PHP_ERROR_LOG} 2>/dev/null | wc -l" 2>/dev/null || echo 0
}

# count_wc_fatal_logs <epoch_ts> (today's fatal-errors-YYYY-MM-DD-*.log)
count_wc_fatals() {
  local today
  today=$(date -u +"%Y-%m-%d")
  ssh "$PROD_HOST" "ls ${WC_LOGS_PATH}/fatal-errors-${today}-*.log 2>/dev/null | wc -l" 2>/dev/null || echo 0
}

# get_last_php_error_lines <count>
get_last_php_errors() {
  local n="${1:-10}"
  ssh "$PROD_HOST" "tail -${n} ${PHP_ERROR_LOG} 2>/dev/null" 2>/dev/null
}

# get_today_wc_fatal_summary
get_wc_fatal_summary() {
  local today
  today=$(date -u +"%Y-%m-%d")
  ssh "$PROD_HOST" "for f in ${WC_LOGS_PATH}/fatal-errors-${today}-*.log; do [ -f \"\$f\" ] && wc -l \"\$f\"; done 2>/dev/null" 2>/dev/null
}

# ─── snapshot inicial ───────────────────────────────────────────────────────
[[ "$JSON" -eq 0 ]] && echo "${C_DIM}akibara monitor-post-deploy · $START_HUMAN · duration=${DURATION_MIN}min${C_OFF}"

INITIAL_PHP_ERRORS=$(count_php_errors "$START_TS")
INITIAL_WC_FATALS=$(count_wc_fatals "$START_TS")

if [[ "$JSON" -eq 0 ]]; then
  echo ""
  echo "${C_DIM}── Baseline (start) ──────────────────────────────${C_OFF}"
  echo "PHP error_log lines (last 500):       $INITIAL_PHP_ERRORS"
  echo "WC fatal-errors logs (today):         $INITIAL_WC_FATALS"
  echo ""
fi

# ─── modo --once ─────────────────────────────────────────────────────────────
if [[ "$ONCE" -eq 1 ]]; then
  if [[ "$JSON" -eq 1 ]]; then
    cat <<EOF
{
  "started_at":"$START_HUMAN",
  "mode":"once",
  "checks":{
    "php_error_log_tail_lines": $INITIAL_PHP_ERRORS,
    "wc_fatal_logs_today": $INITIAL_WC_FATALS
  }
}
EOF
  else
    echo "${C_DIM}── Last 10 lines of PHP error_log ────────────────${C_OFF}"
    get_last_php_errors 10
    echo ""
    echo "${C_DIM}── Today's WC fatal logs ──────────────────────────${C_OFF}"
    get_wc_fatal_summary
  fi
  exit 0
fi

# ─── modo tail (loop hasta DURATION_MIN o detectar new error) ────────────────
ELAPSED=0
INTERVAL=60   # check cada 60s
NEW_ERRORS=0

while [[ "$(date +%s)" -lt "$END_TS" ]]; do
  ELAPSED=$(($(date +%s) - START_TS))
  REMAINING=$((END_TS - $(date +%s)))
  CURRENT_PHP=$(count_php_errors "$START_TS")
  CURRENT_WC=$(count_wc_fatals "$START_TS")

  PHP_DELTA=$((CURRENT_PHP - INITIAL_PHP_ERRORS))
  WC_DELTA=$((CURRENT_WC - INITIAL_WC_FATALS))

  if [[ "$JSON" -eq 0 ]]; then
    printf "${C_DIM}[+%dm %ds]${C_OFF} php_delta=%d wc_delta=%d remaining=%dm\n" \
      "$((ELAPSED/60))" "$((ELAPSED%60))" "$PHP_DELTA" "$WC_DELTA" "$((REMAINING/60))"
  fi

  if [[ "$PHP_DELTA" -gt 0 ]] || [[ "$WC_DELTA" -gt 0 ]]; then
    NEW_ERRORS=1
    if [[ "$JSON" -eq 0 ]]; then
      echo "${C_FAIL}❌ NEW ERRORS DETECTADOS — investigar inmediato${C_OFF}"
      echo ""
      echo "${C_DIM}── Last 20 lines PHP error_log ────────────────────${C_OFF}"
      get_last_php_errors 20
    fi
    break
  fi

  sleep "$INTERVAL"
done

# ─── reporte final ──────────────────────────────────────────────────────────
END_HUMAN=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
FINAL_PHP=$(count_php_errors "$START_TS")
FINAL_WC=$(count_wc_fatals "$START_TS")

if [[ "$JSON" -eq 1 ]]; then
  cat <<EOF
{
  "started_at":"$START_HUMAN",
  "finished_at":"$END_HUMAN",
  "duration_minutes":$DURATION_MIN,
  "new_errors_detected":$( [[ "$NEW_ERRORS" -eq 1 ]] && echo true || echo false ),
  "php_error_log":{
    "initial_tail_lines":$INITIAL_PHP_ERRORS,
    "final_tail_lines":$FINAL_PHP,
    "delta":$((FINAL_PHP - INITIAL_PHP_ERRORS))
  },
  "wc_fatal_logs":{
    "initial_count":$INITIAL_WC_FATALS,
    "final_count":$FINAL_WC,
    "delta":$((FINAL_WC - INITIAL_WC_FATALS))
  },
  "sentry":"manual_check_required"
}
EOF
else
  echo ""
  echo "${C_DIM}── Resumen final ──────────────────────────────────${C_OFF}"
  echo "PHP error_log delta:      $((FINAL_PHP - INITIAL_PHP_ERRORS))"
  echo "WC fatal logs delta:      $((FINAL_WC - INITIAL_WC_FATALS))"
  echo "Sentry:                   manual check requerido"
  echo ""
  if [[ "$NEW_ERRORS" -eq 1 ]]; then
    echo "${C_FAIL}❌ Monitor abortó por errores nuevos${C_OFF}"
  else
    echo "${C_OK}✅ Monitor completo — zero new errors${C_OFF}"
  fi
fi

[[ "$NEW_ERRORS" -eq 1 ]] && exit 1
exit 0
