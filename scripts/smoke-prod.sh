#!/usr/bin/env bash
# Akibara smoke-prod.sh
#
# Smoke test post-deploy contra https://akibara.cl. Cumple B-S1-SETUP-02 del BACKLOG.
# Diseño:
# - curl-based (no Playwright — tienda 3 clientes, KISS).
# - Parallel-friendly (usa bin background + wait).
# - Cache-buster en cada request (?nocache=$(date +%s)$RANDOM).
# - Exit code = N checks failed.
#
# Uso:
#   bash scripts/smoke-prod.sh         # 12-15 checks
#   bash scripts/smoke-prod.sh --quick # 6 checks core
#   bash scripts/smoke-prod.sh --verbose
#
# Tiempo objetivo: <30s estado green.
# Para reemplazar con Playwright después: agregar tests/e2e/smoke.spec.ts.

set -uo pipefail

PROD_URL="${AKB_PROD_URL:-https://akibara.cl}"
MODE="${1:-full}"
case "$MODE" in
  --quick)   QUICK=1; VERBOSE=0 ;;
  --verbose) QUICK=0; VERBOSE=1 ;;
  full|"")   QUICK=0; VERBOSE=0 ;;
  -h|--help)
    echo "Usage: bash scripts/smoke-prod.sh [--quick|--verbose|--help]"
    echo "  --quick    Solo 6 checks core (home/admin/product/checkout/mi-cuenta + xmlrpc)"
    echo "  --verbose  Imprime cada response code"
    exit 0
    ;;
  *) echo "Unknown mode: $MODE" >&2; exit 2 ;;
esac
QUICK="${QUICK:-0}"
VERBOSE="${VERBOSE:-0}"

# ─── colores ────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_DIM=""; C_OFF=""
fi

PASS=0
FAIL=0
RESULTS=()

now_ms() { python3 -c 'import time;print(int(time.time()*1000))' 2>/dev/null || date +%s000; }
cache_buster() { echo "nocache=$(date +%s)$RANDOM"; }

# ─── check helpers ──────────────────────────────────────────────────────────

# check_http <name> <url> <expected_code> [follow_redirects]
# expected_code puede ser un valor único (200) o lista pipe-separated (403|404).
check_http() {
  local name="$1" url="$2" expected="$3" follow="${4:-0}"
  local opts=("-s" "-o" "/dev/null" "-w" "%{http_code}")
  [[ "$follow" -eq 1 ]] && opts+=("-L")
  local actual
  actual=$(curl "${opts[@]}" "$url?$(cache_buster)" 2>/dev/null)
  # Match contra cualquier código en la lista pipe-separated.
  if [[ "|${expected}|" == *"|${actual}|"* ]]; then
    RESULTS+=("OK|$name|$actual")
    ((PASS++))
  else
    RESULTS+=("FAIL|$name|expected=$expected got=$actual")
    ((FAIL++))
  fi
}

# check_header <name> <url> <header_name> <expected_substring>
check_header() {
  local name="$1" url="$2" header="$3" expected="$4"
  local actual
  actual=$(curl -sI "$url?$(cache_buster)" 2>/dev/null | grep -i "^${header}:" | head -1 | tr -d '\r')
  if [[ "$actual" == *"$expected"* ]]; then
    RESULTS+=("OK|$name|$expected found")
    ((PASS++))
  else
    RESULTS+=("FAIL|$name|expected '$expected' in '$header', got: $actual")
    ((FAIL++))
  fi
}

# check_html_contains <name> <url> <pattern>
# Usa here-string `<<<` (no pipe) para evitar SIGPIPE 141 con grep -q + pipefail.
check_html_contains() {
  local name="$1" url="$2" pattern="$3" body
  body=$(curl -sL --retry 2 --retry-delay 1 --max-time 30 "$url?$(cache_buster)" 2>/dev/null)
  if [[ -z "$body" ]]; then
    RESULTS+=("FAIL|$name|empty response (network)")
    ((FAIL++))
    return
  fi
  if grep -qE "$pattern" <<<"$body"; then
    RESULTS+=("OK|$name|pattern found")
    ((PASS++))
  else
    RESULTS+=("FAIL|$name|pattern '$pattern' not found")
    ((FAIL++))
  fi
}

# check_html_not_contains <name> <url> <pattern>
check_html_not_contains() {
  local name="$1" url="$2" pattern="$3" body
  body=$(curl -sL --retry 2 --retry-delay 1 --max-time 30 "$url?$(cache_buster)" 2>/dev/null)
  if [[ -z "$body" ]]; then
    RESULTS+=("FAIL|$name|empty response (network)")
    ((FAIL++))
    return
  fi
  if grep -qE "$pattern" <<<"$body"; then
    RESULTS+=("FAIL|$name|pattern '$pattern' should NOT be present")
    ((FAIL++))
  else
    RESULTS+=("OK|$name|pattern absent (good)")
    ((PASS++))
  fi
}

# ─── check sets ─────────────────────────────────────────────────────────────

# Core (--quick)
run_core_checks() {
  check_http "home"               "$PROD_URL/"           "200"
  check_http "wp-admin redirect"  "$PROD_URL/wp-admin/"  "302"
  check_http "checkout"           "$PROD_URL/checkout/"  "302"
  check_http "mi-cuenta"          "$PROD_URL/mi-cuenta/" "200"
  check_http "xmlrpc deny"        "$PROD_URL/xmlrpc.php" "403"
  check_http "REST users hidden"  "$PROD_URL/wp-json/wp/v2/users" "404"
}

# Security defenses (B-S1-SEC-03/05 verifications)
# 403 = Apache .htaccess deny activa, 404 = file/path no existe (ambos OK).
run_security_checks() {
  check_http "composer.json deny" "$PROD_URL/wp-content/plugins/akibara/composer.json" "403|404"
  check_http "vendor/ deny"       "$PROD_URL/wp-content/plugins/akibara/vendor/autoload.php" "403|404"
  check_http "tests/ deny"        "$PROD_URL/wp-content/plugins/akibara/tests/phpunit/bootstrap.php" "403|404"
  check_http "coverage/ deny"     "$PROD_URL/wp-content/plugins/akibara/coverage/html/index.html" "403|404"
  check_http "wp-config-private deny" "$PROD_URL/wp-config-private.php" "403|404"
  check_http "debug.log deny"     "$PROD_URL/wp-content/debug.log" "403"
  check_header "HSTS header"      "$PROD_URL/" "strict-transport-security" "max-age"
  check_header "X-Frame-Options"  "$PROD_URL/" "x-frame-options" "SAMEORIGIN"
  check_header "X-Content-Type-Options" "$PROD_URL/" "x-content-type-options" "nosniff"
}

# WP / WC functional
run_functional_checks() {
  check_html_contains "WP loaded (akibara branding)" "$PROD_URL/" "Akibara"
  check_http "sitemap_index"      "$PROD_URL/sitemap_index.xml" "200"
  check_http "robots.txt"         "$PROD_URL/robots.txt" "200"
}

# SEO checks
run_seo_checks() {
  check_html_contains "BreadcrumbList position int" "$PROD_URL/manga/" '"position":[0-9]+,'
  check_html_not_contains "BreadcrumbList NO position string" "$PROD_URL/manga/" '"position":"[0-9]+"'
}

# ─── ejecución ──────────────────────────────────────────────────────────────
START_MS=$(now_ms)
echo "🌐 Akibara smoke-prod · $PROD_URL · mode=${MODE:-full}"

run_core_checks
if [[ "$QUICK" -ne 1 ]]; then
  run_security_checks
  run_functional_checks
  run_seo_checks
fi

END_MS=$(now_ms)
TOTAL_MS=$((END_MS - START_MS))

# ─── reporte ────────────────────────────────────────────────────────────────
echo ""
echo "─── resultado ────────────────────────────────────────────────"
for r in "${RESULTS[@]}"; do
  IFS='|' read -r status name detail <<<"$r"
  case "$status" in
    OK)   color="$C_OK" ;;
    FAIL) color="$C_FAIL" ;;
  esac
  if [[ "$VERBOSE" -eq 1 ]] || [[ "$status" == "FAIL" ]]; then
    printf "${color}%-5s${C_OFF} %-35s %s\n" "$status" "$name" "$detail"
  else
    printf "${color}%-5s${C_OFF} %s\n" "$status" "$name"
  fi
done
echo "──────────────────────────────────────────────────────────────"
printf "Total: ${C_OK}%d PASS${C_OFF} · ${C_FAIL}%d FAIL${C_OFF} · %dms\n" "$PASS" "$FAIL" "$TOTAL_MS"

if [[ "$FAIL" -gt 0 ]]; then
  echo ""
  echo "${C_FAIL}❌ Smoke FAILED${C_OFF} — investigar antes de continuar."
  exit "$FAIL"
fi
echo ""
echo "${C_OK}✅ Smoke PASSED${C_OFF}"
exit 0
