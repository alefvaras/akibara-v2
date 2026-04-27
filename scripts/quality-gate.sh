#!/usr/bin/env bash
# Akibara quality-gate.sh
#
# Pipeline de calidad ejecutado antes de deploy. Cumple B-S1-SETUP-01 del BACKLOG.
# Diseño:
# - Wraps Docker via bin/composer, bin/npm, bin/php (no requiere PHP/Node nativo).
# - Cada gate corre como función con su log en .private/quality-gate/<gate>.log.
# - Gates independientes corren en paralelo (background + wait + PIDs).
# - Si un gate no tiene los manifests/targets requeridos: SKIP (no falla).
# - Exit code = N gates failed (0 = todo verde o skipped).
#
# Uso:
#   bash scripts/quality-gate.sh             # todos los gates
#   bash scripts/quality-gate.sh --quick     # solo content gates + git staged + skip lint pesado
#   bash scripts/quality-gate.sh --serial    # secuencial en lugar de paralelo (debug)
#
# Tiempo objetivo: <60s estado green con todos los manifests presentes.

set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

LOG_DIR="$PROJECT_DIR/.private/quality-gate"
mkdir -p "$LOG_DIR"

MODE="${1:-full}"
case "$MODE" in
  --quick) QUICK=1 ;;
  --serial) SERIAL=1; QUICK=0 ;;
  full|"") QUICK=0; SERIAL=0 ;;
  -h|--help)
    echo "Usage: bash scripts/quality-gate.sh [--quick|--serial|--help]"
    echo "  --quick    Solo content gates + secret scan + git staged check (no lint pesado)"
    echo "  --serial   Secuencial (debug — log más legible)"
    exit 0
    ;;
  *) echo "Unknown mode: $MODE" >&2; exit 2 ;;
esac
QUICK="${QUICK:-0}"
SERIAL="${SERIAL:-0}"

# ─── colores ────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_WARN=""; C_DIM=""; C_OFF=""
fi

GATE_RESULTS=()   # nombre|status|tiempo_ms|nota
GATE_PIDS=()      # PID por gate (modo paralelo)
GATE_NAMES=()     # nombre por PID

# ─── helpers ────────────────────────────────────────────────────────────────
now_ms() { python3 -c 'import time;print(int(time.time()*1000))' 2>/dev/null || date +%s000; }

record() {
  # record <name> <status> <duration_ms> <note>
  GATE_RESULTS+=("$1|$2|$3|$4")
}

run_gate() {
  # run_gate <name> <fn>
  # En paralelo: escribe resultado a $LOG_DIR/<name>.result (formato: status|duration_ms|note)
  local name="$1" fn="$2" t0 t1 dur status note logfile resultfile
  logfile="$LOG_DIR/${name}.log"
  resultfile="$LOG_DIR/${name}.result"
  : > "$logfile"
  t0=$(now_ms)
  if "$fn" >"$logfile" 2>&1; then
    status="OK"; note=""
  else
    rc=$?
    if [[ "$rc" -eq 78 ]]; then
      status="SKIP"; note="$(grep -m1 'SKIP_REASON:' "$logfile" | sed 's/SKIP_REASON: //')"
    else
      status="FAIL"; note="rc=$rc · log: $logfile"
    fi
  fi
  t1=$(now_ms)
  dur=$((t1 - t0))
  echo "${status}|${dur}|${note}" > "$resultfile"
  record "$name" "$status" "$dur" "$note"
}

skip_with() { echo "SKIP_REASON: $*"; return 78; }

# ─── content gates (grep, no tooling needed) ───────────────────────────────
gate_voseo() {
  # Voseo rioplatense PROHIBIDO en customer-facing strings.
  local targets=()
  for d in wp-content/plugins/akibara wp-content/plugins/akibara-reservas \
           wp-content/plugins/akibara-whatsapp wp-content/themes/akibara \
           wp-content/mu-plugins; do
    [[ -d "$d" ]] && targets+=("$d")
  done
  if [[ ${#targets[@]} -eq 0 ]]; then
    skip_with "no editable wp-content/ tree (workspace en modo audit only)"
    return
  fi
  # Patrón: imperativos voseo + pronombres rioplatenses
  local pattern='\b(confirmá|hacé|tenés|podés|querés|sentís|tomá|llegá|mirá|enviá|guardá|comprá|pagá|seleccioná|marcá|elegí|escribí|ingresá|continuá|aceptá|cancelá|sos|vos)\b'
  local hits
  hits=$(grep -rIE --include='*.php' --include='*.js' --include='*.html' --include='*.po' --include='*.mo' \
    "$pattern" "${targets[@]}" 2>/dev/null || true)
  if [[ -n "$hits" ]]; then
    echo "VOSEO PROHIBIDO encontrado:"
    echo "$hits"
    return 1
  fi
}

gate_claims() {
  # Claims sin evidencia (heurístico — warning, no falla)
  local targets=()
  for d in wp-content/plugins/akibara wp-content/themes/akibara wp-content/mu-plugins; do
    [[ -d "$d" ]] && targets+=("$d")
  done
  if [[ ${#targets[@]} -eq 0 ]]; then
    skip_with "no editable wp-content/ tree"
    return
  fi
  local pattern='\b(el mejor|líder en|garantizado|único en chile|exclusivo|100% original|certificado oficial)\b'
  local hits
  hits=$(grep -rIEi --include='*.php' --include='*.js' --include='*.html' \
    "$pattern" "${targets[@]}" 2>/dev/null || true)
  if [[ -n "$hits" ]]; then
    echo "CLAIMS sin evidencia detectados (revisar manualmente):"
    echo "$hits"
    # NO fail — solo warning. Return 0.
  fi
}

gate_secrets_staged() {
  # Solo escanea archivos staged en git (evita false positives en audit/ docs).
  if ! command -v git >/dev/null 2>&1; then
    skip_with "git no instalado"; return
  fi
  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    skip_with "no es repo git"; return
  fi
  local staged
  staged=$(git diff --cached --name-only --diff-filter=ACMR 2>/dev/null || true)
  if [[ -z "$staged" ]]; then
    skip_with "no hay archivos staged"; return
  fi
  local pattern='(AKB_[A-Z_]*_(API_KEY|TOKEN|SECRET|PASSWORD)\s*=\s*["'\''][^"'\''$]{12,}|aws_(secret|access)_key|-----BEGIN [A-Z ]*PRIVATE KEY-----|password\s*=\s*["'\''][^"'\''$]{8,})'
  local hits
  hits=$(echo "$staged" | xargs -I{} grep -HIE "$pattern" "{}" 2>/dev/null || true)
  if [[ -n "$hits" ]]; then
    echo "SECRETS detectados en archivos staged (NO commitear):"
    echo "$hits"
    return 1
  fi
}

gate_test_email_guard() {
  # Verifica que email_testing_guard sigue intacto (alejandro.fvaras@gmail.com only).
  local guard="wp-content/mu-plugins/akibara-email-testing-guard.php"
  if [[ ! -f "$guard" ]]; then
    skip_with "guard no presente en workspace (audit only o pre-extract)"; return
  fi
  if ! grep -q "alejandro.fvaras@gmail.com" "$guard"; then
    echo "FALTA whitelist alejandro.fvaras@gmail.com en $guard"
    return 1
  fi
}

# ─── PHP gates (composer-based, requieren plugin tree editable) ─────────────
gate_phpcs() {
  local plugin_dir="wp-content/plugins/akibara"
  if [[ ! -f "$plugin_dir/composer.json" ]]; then
    skip_with "no $plugin_dir/composer.json (workspace audit only)"; return
  fi
  if [[ ! -d "$plugin_dir/vendor" ]]; then
    skip_with "vendor/ no instalado — corre 'cd $plugin_dir && bin/composer install'"; return
  fi
  ( cd "$plugin_dir" && bin/php vendor/bin/phpcs --standard=WordPress --extensions=php --ignore=vendor,tests,coverage . )
}

gate_phpstan() {
  local plugin_dir="wp-content/plugins/akibara"
  if [[ ! -f "$plugin_dir/composer.json" ]]; then
    skip_with "no $plugin_dir/composer.json"; return
  fi
  if [[ ! -d "$plugin_dir/vendor" ]]; then
    skip_with "vendor/ no instalado"; return
  fi
  if [[ ! -f "$plugin_dir/phpstan.neon" && ! -f "$plugin_dir/phpstan.neon.dist" ]]; then
    skip_with "phpstan.neon no configurado en $plugin_dir"; return
  fi
  ( cd "$plugin_dir" && bin/php vendor/bin/phpstan analyse --no-progress )
}

gate_phpunit() {
  local plugin_dir="wp-content/plugins/akibara"
  if [[ ! -f "$plugin_dir/composer.json" ]]; then
    skip_with "no $plugin_dir/composer.json"; return
  fi
  if [[ ! -d "$plugin_dir/tests/phpunit" ]]; then
    skip_with "no $plugin_dir/tests/phpunit"; return
  fi
  if [[ ! -d "$plugin_dir/vendor" ]]; then
    skip_with "vendor/ no instalado"; return
  fi
  ( cd "$plugin_dir" && bin/php vendor/bin/phpunit --testsuite=unit --no-coverage )
}

gate_composer_audit() {
  local plugin_dir="wp-content/plugins/akibara"
  if [[ ! -f "$plugin_dir/composer.lock" ]]; then
    skip_with "no $plugin_dir/composer.lock"; return
  fi
  ( cd "$plugin_dir" && bin/composer audit --no-interaction --format=plain )
}

# ─── JS/CSS gates ──────────────────────────────────────────────────────────
gate_eslint() {
  if [[ ! -f "package.json" ]]; then
    skip_with "no package.json en root"; return
  fi
  if [[ ! -d "node_modules" ]]; then
    skip_with "node_modules/ no instalado — corre 'bin/npm ci'"; return
  fi
  bin/npx eslint "wp-content/themes/akibara/assets/**/*.{js,ts}" \
    "wp-content/plugins/akibara*/assets/**/*.{js,ts}" \
    --max-warnings=0
}

gate_stylelint() {
  if [[ ! -f "package.json" ]]; then
    skip_with "no package.json en root"; return
  fi
  if [[ ! -d "node_modules" ]]; then
    skip_with "node_modules/ no instalado"; return
  fi
  bin/npx stylelint "wp-content/themes/akibara/assets/**/*.css" \
    "wp-content/plugins/akibara*/assets/**/*.css"
}

gate_prettier() {
  if [[ ! -f "package.json" ]]; then
    skip_with "no package.json en root"; return
  fi
  if [[ ! -d "node_modules" ]]; then
    skip_with "node_modules/ no instalado"; return
  fi
  bin/npx prettier --check "wp-content/themes/akibara/assets/**/*.{js,ts,css}" 2>/dev/null
}

gate_knip() {
  if [[ ! -f "package.json" ]]; then
    skip_with "no package.json en root"; return
  fi
  if [[ ! -d "node_modules" ]]; then
    skip_with "node_modules/ no instalado"; return
  fi
  bin/npx knip
}

gate_purgecss() {
  if [[ ! -f "package.json" ]]; then
    skip_with "no package.json en root"; return
  fi
  if [[ ! -d "node_modules" ]]; then
    skip_with "node_modules/ no instalado"; return
  fi
  bin/npx purgecss --css "wp-content/themes/akibara/assets/css/*.css" \
    --content "wp-content/themes/akibara/**/*.{php,html,js}" \
    --output /tmp/purgecss-akibara/ \
    && echo "purgecss output written to /tmp/purgecss-akibara/ (review for unused selectors)"
}

gate_npm_audit() {
  if [[ ! -f "package-lock.json" && ! -f "npm-shrinkwrap.json" ]]; then
    skip_with "no package-lock.json"; return
  fi
  bin/npm audit --audit-level=high --omit=dev
}

# ─── Security scans (Docker run, no manifest needed) ────────────────────────
gate_trivy() {
  # Trivy filesystem scan via Docker. Sin necesidad de install host.
  # Falla solo si HIGH+CRITICAL en archivos versionados.
  if ! command -v docker >/dev/null 2>&1; then
    skip_with "docker no disponible"; return
  fi
  docker run --rm -v "$PROJECT_DIR:/scan:ro" aquasec/trivy:latest \
    fs --scanners vuln,secret \
    --severity HIGH,CRITICAL \
    --exit-code 1 \
    --skip-dirs ".git,node_modules,vendor,data,server-snapshot,.private,.staging-agents,snapshots,audit/raw" \
    /scan
}

gate_gitleaks() {
  # Solo escanea wp-content/ + scripts/ + bin/ (código deployable).
  # NO escanea audit/, .private/, server-snapshot/, .mcp.json, .claude/ — esos contienen
  # referencias legítimas a secrets (audit findings, config local) que ya están .gitignored
  # o son intencionalmente parte del workspace.
  if ! command -v docker >/dev/null 2>&1; then
    skip_with "docker no disponible"; return
  fi
  local has_target=0
  for d in wp-content scripts bin; do
    [[ -d "$PROJECT_DIR/$d" ]] && has_target=1
  done
  if [[ "$has_target" -eq 0 ]]; then
    skip_with "no targets editables (wp-content/scripts/bin)"; return
  fi
  # Crear un staging dir temporal con solo los paths a scanear
  local staging rc
  staging=$(mktemp -d -t gitleaks-staging-XXXXXX)
  for d in wp-content scripts bin; do
    if [[ -d "$PROJECT_DIR/$d" ]]; then
      cp -R "$PROJECT_DIR/$d" "$staging/$d"
    fi
  done
  docker run --rm -v "$staging:/scan:ro" zricethezav/gitleaks:latest \
    detect --source=/scan --no-banner --no-git --redact \
    --report-format=json --report-path=/dev/null
  rc=$?
  rm -rf "$staging"
  return "$rc"
}

# ─── runner ─────────────────────────────────────────────────────────────────
run_parallel() {
  local fn name pid
  for entry in "$@"; do
    name="${entry%:*}"; fn="${entry#*:}"
    ( run_gate "$name" "$fn" ) &
    GATE_PIDS+=("$!")
    GATE_NAMES+=("$name")
  done
  for pid in "${GATE_PIDS[@]}"; do
    wait "$pid" || true
  done
  # En modo paralelo, run_gate corre en subshell — los GATE_RESULTS no se propagan.
  # Reemitimos leyendo los .result files que escribió cada subshell.
  GATE_RESULTS=()
  for entry in "$@"; do
    name="${entry%:*}"
    local resultfile="$LOG_DIR/${name}.result"
    if [[ -f "$resultfile" ]]; then
      IFS='|' read -r status dur note <"$resultfile"
      record "$name" "$status" "$dur" "$note"
    else
      record "$name" "FAIL" "0" "no result file (subshell crashed?)"
    fi
  done
}

run_serial() {
  local fn name
  for entry in "$@"; do
    name="${entry%:*}"; fn="${entry#*:}"
    echo "${C_DIM}→ $name${C_OFF}"
    run_gate "$name" "$fn"
  done
}

# ─── registro de gates ──────────────────────────────────────────────────────
QUICK_GATES=(
  "voseo:gate_voseo"
  "claims:gate_claims"
  "secrets-staged:gate_secrets_staged"
  "email-guard:gate_test_email_guard"
)

FULL_GATES=(
  "voseo:gate_voseo"
  "claims:gate_claims"
  "secrets-staged:gate_secrets_staged"
  "email-guard:gate_test_email_guard"
  "phpcs:gate_phpcs"
  "phpstan:gate_phpstan"
  "phpunit:gate_phpunit"
  "composer-audit:gate_composer_audit"
  "eslint:gate_eslint"
  "stylelint:gate_stylelint"
  "prettier:gate_prettier"
  "knip:gate_knip"
  "purgecss:gate_purgecss"
  "npm-audit:gate_npm_audit"
  "trivy:gate_trivy"
  "gitleaks:gate_gitleaks"
)

# ─── ejecución ──────────────────────────────────────────────────────────────
START_MS=$(now_ms)
echo "🛡  Akibara quality-gate · mode=${MODE:-full} · serial=${SERIAL} · quick=${QUICK}"

if [[ "$QUICK" -eq 1 ]]; then
  GATES=("${QUICK_GATES[@]}")
else
  GATES=("${FULL_GATES[@]}")
fi

if [[ "$SERIAL" -eq 1 ]]; then
  run_serial "${GATES[@]}"
else
  run_parallel "${GATES[@]}"
fi

END_MS=$(now_ms)
TOTAL_MS=$((END_MS - START_MS))

# ─── reporte ────────────────────────────────────────────────────────────────
echo ""
echo "─── resultado ────────────────────────────────────────────────"
printf "%-20s %-6s %-8s %s\n" "GATE" "STATUS" "TIME" "NOTA"
echo "──────────────────────────────────────────────────────────────"

OK_COUNT=0; FAIL_COUNT=0; SKIP_COUNT=0
for r in "${GATE_RESULTS[@]}"; do
  IFS='|' read -r name status dur note <<<"$r"
  case "$status" in
    OK)   color="$C_OK";   ((OK_COUNT++)) ;;
    FAIL) color="$C_FAIL"; ((FAIL_COUNT++)) ;;
    SKIP) color="$C_WARN"; ((SKIP_COUNT++)) ;;
    *)    color="" ;;
  esac
  printf "%-20s ${color}%-6s${C_OFF} %-8s %s\n" "$name" "$status" "${dur}ms" "$note"
done

echo "──────────────────────────────────────────────────────────────"
printf "Total: ${C_OK}%d OK${C_OFF} · ${C_FAIL}%d FAIL${C_OFF} · ${C_WARN}%d SKIP${C_OFF} · %dms\n" \
  "$OK_COUNT" "$FAIL_COUNT" "$SKIP_COUNT" "$TOTAL_MS"

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  echo ""
  echo "${C_FAIL}❌ Quality gate FAILED${C_OFF} — revisa logs en $LOG_DIR/"
  exit "$FAIL_COUNT"
fi

if [[ "$SKIP_COUNT" -gt 0 ]] && [[ "$OK_COUNT" -eq 0 ]]; then
  echo ""
  echo "${C_WARN}⚠️  Todos los gates skipped${C_OFF} — workspace en modo audit-only o sin manifests instalados."
  echo "   Para activar gates: instala plugin tree editable + ejecuta 'cd wp-content/plugins/akibara && bin/composer install'."
  exit 0
fi

echo ""
echo "${C_OK}✅ Quality gate PASSED${C_OFF}"
exit 0
