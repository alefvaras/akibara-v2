#!/usr/bin/env bash
# Akibara — Voseo rioplatense detector.
#
# Checks customer-facing strings for Argentine voseo verbs (PROHIBIDO en Akibara
# per memoria feedback. Chile usa tuteo neutro: "confirma", "haz", "tienes", "puedes").
#
# Patterns prohibidos:
#   confirmá, hacé, tenés, podés, vos sos, tomá, mirá, andá, dale,
#   sabés, querés, vení, estás (when followed by Argentine context)
#
# Scope: customer-facing files only (themes/, plugins/akibara*, mu-plugins/).
# Excluye docs/, audit/, .git/, vendor/, node_modules/, tests/.
#
# Uso:
#   bash bin/grep-voseo.sh                    # scan all customer-facing
#   bash bin/grep-voseo.sh path/to/file.php   # scan specific file
#   bash bin/grep-voseo.sh --staged           # solo files staged en git (pre-commit)
#
# Exit code:
#   0 = no voseo detectado
#   1 = voseo detectado (rompe build)

set -uo pipefail

# Force UTF-8 locale para grep case-folding correcto (BSD grep en macOS y GNU grep en CI).
# Sin esto, "Confirmá" no matchea pattern lowercase "confirmá" porque LC_CTYPE=C.
export LC_ALL="${LC_ALL:-en_US.UTF-8}"
export LANG="${LANG:-en_US.UTF-8}"

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_DIM=""; C_OFF=""
fi

# Patterns voseo Argentina (case-insensitive). Boundaries con \b para evitar
# false positives ("confirmás" matches "confirma" pero "confirmamos" no).
VOSEO_PATTERN='\b(confirmá|hacé|tenés|podés|tomá|mirá|andá|sabés|querés|vení|escribí|llamá|aprovechá|disfrutá|elegí|comprá|ingresá|seguí|completá|enviá|llegá|dame|prestá|mostrá|revisá)\b'

# Excludes globales
EXCLUDES=(
  --exclude-dir=.git
  --exclude-dir=.claude
  --exclude-dir=.github
  --exclude-dir=node_modules
  --exclude-dir=vendor
  --exclude-dir=.private
  --exclude-dir=audit
  --exclude-dir=docs
  --exclude-dir=tests
  --exclude-dir=test-results
  --exclude-dir=playwright-report
  --exclude-dir=coverage
  --exclude-dir=server-snapshot
  --exclude-dir=data
  --exclude-dir=snapshots
  --exclude-dir='.staging-agents'
  --exclude='*.log'
  --exclude='*.min.*'
  --exclude='*-baseline*'
)

# Post-filter: skip matches en archivos doc/dev (md/sh/yml/json/gitignore).
# El voseo gate aplica SOLO a customer-facing strings (php/js/html/po/pot).
# Los .md/.sh/.yml describen el rule, no son customer-facing.
post_filter_doc_files() {
  grep -Ev "\.(md|sh|txt|yml|yaml|json|gitignore|gitleaksignore|toml|lock)(\..+)?:[0-9]" || true
}

# Files a scanear: PHP, JS, JSX, TS, HTML, PO/MO (Akibara customer-facing)
INCLUDE_PATTERN='*.{php,js,jsx,ts,tsx,html,po,pot,json}'

# Mode: --staged usa git diff names; default scanea filesystem
mode="${1:-full}"

if [[ "$mode" == "--staged" ]]; then
  files=$(git diff --cached --name-only --diff-filter=AM \
    | grep -E '\.(php|js|jsx|ts|tsx|html|po|pot)$' || true)
  if [[ -z "$files" ]]; then
    echo "${C_DIM}voseo: no staged files relevantes${C_OFF}"
    exit 0
  fi
  matches=$(echo "$files" | xargs -I {} grep -EinH "$VOSEO_PATTERN" {} 2>/dev/null | post_filter_doc_files || true)
elif [[ -f "$mode" ]]; then
  matches=$(grep -EinH "$VOSEO_PATTERN" "$mode" 2>/dev/null | post_filter_doc_files || true)
else
  matches=$(grep -EirnH "$VOSEO_PATTERN" \
    "${EXCLUDES[@]}" \
    --include="*.php" --include="*.js" --include="*.jsx" \
    --include="*.ts" --include="*.tsx" --include="*.html" \
    --include="*.po" --include="*.pot" \
    "$PROJECT_DIR" 2>/dev/null | post_filter_doc_files || true)
fi

if [[ -z "$matches" ]]; then
  echo "${C_OK}✓ grep-voseo: no voseo rioplatense detectado${C_OFF}"
  exit 0
fi

echo "${C_FAIL}❌ grep-voseo: voseo rioplatense detectado (Akibara usa tuteo chileno neutro):${C_OFF}"
echo "$matches"
echo ""
echo "${C_DIM}   Reemplazos sugeridos:${C_OFF}"
echo "${C_DIM}   confirmá → confirma | hacé → haz | tenés → tienes | podés → puedes${C_OFF}"
echo "${C_DIM}   tomá → toma | andá → ve | vení → ven | querés → quieres${C_OFF}"
exit 1
