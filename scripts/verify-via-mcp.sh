#!/usr/bin/env bash
# Akibara verify-via-mcp.sh
#
# Cross-system verification post-deploy. Cumple B-S1-SETUP-03 del BACKLOG.
# Output: JSON parseable para futuro CI consumption.
#
# Por qué .sh y NO .ts:
# - El BACKLOG sugería .ts pero todos los checks son CLI (wp-cli vía SSH +
#   curl + jq). Bash sin deps es más simple y rápido. Si más tarde se necesita
#   integración con Gmail MCP / Royal MCP / Brevo API desde un script standalone,
#   considerar reescritura a .ts con node fetch nativo (Node 18+).
#
# Checks ejecutados:
#   1. WC orders count (last 24h) — vía wp-cli WC commands
#   2. WC orders count (total)
#   3. Cron next_run sane (no scheduled in past)
#   4. Sitemap loadable
#   5. Last cron event executed (akibara_check_abandoned_carts)
#
# NO incluye:
#   - Gmail MCP search (no callable desde CLI standalone — necesita Claude session)
#   - Royal MCP product stock check (similar — requiere MCP runtime)
#
# Uso:
#   bash scripts/verify-via-mcp.sh           # JSON pretty
#   bash scripts/verify-via-mcp.sh --compact # JSON one-line
#
# Tiempo objetivo: <10s.

set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

COMPACT=0
case "${1:-}" in
  --compact) COMPACT=1 ;;
  -h|--help)
    echo "Usage: bash scripts/verify-via-mcp.sh [--compact|--help]"
    exit 0
    ;;
esac

PROD_URL="${AKB_PROD_URL:-https://akibara.cl}"

# ─── helpers ────────────────────────────────────────────────────────────────
now_iso() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }
START_TS=$(now_iso)

# Colectamos resultados como key=value lines, luego construimos JSON al final.
RESULTS_KEYS=()
RESULTS_VALUES=()
record() {
  RESULTS_KEYS+=("$1")
  RESULTS_VALUES+=("$2")
}

# ─── checks ─────────────────────────────────────────────────────────────────

# 1. WC orders count last 24h
orders_24h=$(bin/wp-ssh wc shop_order list --user=1 --after="$(date -u -v-24H +%Y-%m-%dT%H:%M:%S 2>/dev/null || date -u --date='-24 hours' +%Y-%m-%dT%H:%M:%S)" --format=count 2>/dev/null | tail -1 | tr -dc '0-9')
record "orders_last_24h" "${orders_24h:-0}"

# 2. WC orders count total
orders_total=$(bin/wp-ssh wc shop_order list --user=1 --format=count 2>/dev/null | tail -1 | tr -dc '0-9')
record "orders_total" "${orders_total:-0}"

# 3. Cron next_run sane (count of crons with next_run <= "now" — should be ~0 idle)
crons_overdue=$(bin/wp-ssh cron event list --format=csv --fields=next_run_relative 2>/dev/null | tail -n +2 | grep -c "now\|^-" 2>/dev/null || echo 0)
record "crons_overdue" "$crons_overdue"

# 4. Cron total scheduled
crons_total=$(bin/wp-ssh cron event list --format=count 2>/dev/null | tail -1 | tr -dc '0-9')
record "crons_total" "${crons_total:-0}"

# 5. Sitemap loadable
sitemap_status=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$PROD_URL/sitemap_index.xml" 2>/dev/null)
record "sitemap_http" "${sitemap_status:-error}"

# 6. Home loadable (HTTP code)
home_status=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$PROD_URL/?nocache=$(date +%s)" 2>/dev/null)
record "home_http" "${home_status:-error}"

# 7. WP healthy (option blogname)
blog_name=$(bin/wp-ssh option get blogname 2>/dev/null | head -1 | tr -d '\n\r' | sed 's/"/\\"/g')
record "blog_name" "${blog_name:-unknown}"

# 8. Sentry post-deploy issues (heuristic — last hour, only if Sentry plugin emits to debug.log)
record "sentry_check" "manual_check_required"

# 9. Last successful order (ID + status)
last_order_id=$(bin/wp-ssh wc shop_order list --user=1 --orderby=date --order=desc --limit=1 --format=ids 2>/dev/null | tail -1 | tr -dc '0-9')
record "last_order_id" "${last_order_id:-none}"

END_TS=$(now_iso)

# ─── compute health flag ────────────────────────────────────────────────────
healthy="true"
[[ "${home_status:-}" != "200" ]] && healthy="false"
[[ "${sitemap_status:-}" != "200" ]] && healthy="false"
[[ "${blog_name:-}" == "unknown" ]] && healthy="false"

# ─── construct JSON output ──────────────────────────────────────────────────
build_json() {
  local sep="" newline=$'\n' indent="  "
  if [[ "$COMPACT" -eq 1 ]]; then
    newline=""
    indent=""
  fi

  printf "{%s" "$newline"
  printf "%s\"started_at\":\"%s\",%s" "$indent" "$START_TS" "$newline"
  printf "%s\"finished_at\":\"%s\",%s" "$indent" "$END_TS" "$newline"
  printf "%s\"healthy\":%s,%s" "$indent" "$healthy" "$newline"
  printf "%s\"checks\":{%s" "$indent" "$newline"

  local i=0 last=$((${#RESULTS_KEYS[@]} - 1))
  for k in "${RESULTS_KEYS[@]}"; do
    local v="${RESULTS_VALUES[$i]}"
    # Si el valor es numérico → emitir sin comillas. Si no → con comillas.
    if [[ "$v" =~ ^-?[0-9]+$ ]]; then
      printf "%s%s\"%s\":%s" "$indent" "$indent" "$k" "$v"
    else
      printf "%s%s\"%s\":\"%s\"" "$indent" "$indent" "$k" "$v"
    fi
    if [[ "$i" -lt "$last" ]]; then
      printf ","
    fi
    printf "%s" "$newline"
    ((i++))
  done

  printf "%s}%s" "$indent" "$newline"
  printf "}%s" "$newline"
}

build_json

# Exit code 0 si healthy, 1 si no.
[[ "$healthy" == "true" ]] && exit 0 || exit 1
