#!/usr/bin/env bash
# Akibara — Claims sin evidencia detector.
#
# Detecta claims absolutos en customer-facing copy ("100% garantizado", "el mejor",
# "siempre", "nunca") que pueden violar Sernac / Ley del Consumidor Chile sin
# respaldo objetivo verificable.
#
# Pattern: word-boundary case-insensitive matches en strings dentro de PHP/JS/HTML.
#
# Uso:
#   bash bin/grep-claims.sh           # scan all customer-facing
#   bash bin/grep-claims.sh --staged  # solo staged
#
# Exit code:
#   0 = no claims problemáticos
#   1 = claims detectados (warning, no rompe build por default — review manual)

set -uo pipefail

# Force UTF-8 locale para grep case-folding correcto (BSD grep en macOS).
export LC_ALL="${LC_ALL:-en_US.UTF-8}"
export LANG="${LANG:-en_US.UTF-8}"

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_DIM=""; C_OFF=""
fi

# Claims absolutos a flagear (case-insensitive, word-boundaries).
# Solo en strings customer-facing (quoted strings PHP/JS), NO comments code.
# Excluye contextos legítimos: "100% original" para products is OK pero "100% garantizado" no.
CLAIMS_PATTERN='\b(100% garantizado|garantía total|el mejor (precio|servicio|producto)|la mejor (calidad|tienda)|siempre disponible|nunca falla|sin excepción|garantizamos satisfacción|libre de defectos|perfecta calidad|garantizado al 100%)\b'

mode="${1:-full}"

if [[ "$mode" == "--staged" ]]; then
  files=$(git diff --cached --name-only --diff-filter=AM \
    | grep -E '\.(php|js|jsx|ts|tsx|html|po|pot)$' || true)
  [[ -z "$files" ]] && { echo "${C_DIM}claims: no staged files relevantes${C_OFF}"; exit 0; }
  matches=$(echo "$files" | xargs -I {} grep -EinH "$CLAIMS_PATTERN" {} 2>/dev/null || true)
else
  matches=$(grep -EirnH "$CLAIMS_PATTERN" \
    --exclude-dir=.git \
    --exclude-dir=node_modules \
    --exclude-dir=vendor \
    --exclude-dir=.private \
    --exclude-dir=audit \
    --exclude-dir=docs \
    --exclude-dir=tests \
    --exclude-dir=test-results \
    --exclude-dir=playwright-report \
    --exclude-dir=coverage \
    --exclude-dir=server-snapshot \
    --exclude-dir=data \
    --exclude='*.log' --exclude='*.min.*' --exclude='*-baseline*' \
    --include="*.php" --include="*.js" --include="*.jsx" \
    --include="*.ts" --include="*.tsx" --include="*.html" \
    --include="*.po" --include="*.pot" \
    "$PROJECT_DIR" 2>/dev/null || true)
fi

if [[ -z "$matches" ]]; then
  echo "${C_OK}✓ grep-claims: no claims absolutos detectados${C_OFF}"
  exit 0
fi

echo "${C_WARN}⚠️  grep-claims: claims posiblemente sin evidencia detectados:${C_OFF}"
echo "$matches"
echo ""
echo "${C_DIM}   Sernac/Ley 19.496 desincentiva claims absolutos sin respaldo verificable.${C_OFF}"
echo "${C_DIM}   Suaviza con: 'amplia variedad', 'envío rápido', 'productos originales' (objetivos).${C_OFF}"
exit 1
