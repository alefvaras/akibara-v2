#!/usr/bin/env bash
# Akibara — Secrets pattern detector.
#
# Detecta secrets potencialmente accidentales en código (API keys, tokens,
# passwords, hardcoded). Defensa adicional a gitleaks (que también corre en
# pipeline). Esto cubre patterns customizados Akibara (AKB_*_API_KEY).
#
# Patterns:
#   - AKB_*_API_KEY, AKB_*_SECRET, AKB_*_TOKEN, AKB_*_PASSWORD (define con value real)
#   - xkeysib-* (Brevo API key prefix) NO seguido por staging key conocida
#   - sk_live_*, sk_test_* (Stripe-style)
#   - secret_, password = '<value>' patterns con value real
#
# Uso:
#   bash bin/grep-secrets.sh           # scan all
#   bash bin/grep-secrets.sh --staged  # solo staged en git
#
# Exit code:
#   0 = no secrets detectados
#   1 = posibles secrets detectados (rompe build por default)

set -uo pipefail

# Force UTF-8 locale para grep case-folding correcto.
export LC_ALL="${LC_ALL:-en_US.UTF-8}"
export LANG="${LANG:-en_US.UTF-8}"

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_DIM=""; C_OFF=""
fi

# Patterns sospechosos (NOT placeholder values like 'XXX', 'YOUR_KEY', '***')
# AKB_*_API|SECRET|TOKEN|PASSWORD con value de >8 chars que no es placeholder
SECRETS_PATTERN_1="define\\(\\s*['\"]AKB_[A-Z_]+_(API_KEY|SECRET|TOKEN|PASSWORD)['\"][^,]*,\\s*['\"][a-zA-Z0-9+/_=.-]{12,}['\"]"

# Brevo API key real (xkeysib-<long_hash>)
SECRETS_PATTERN_2="xkeysib-[a-f0-9]{60,}"

# Stripe-style keys
SECRETS_PATTERN_3="(sk_live_|sk_test_|pk_live_)[a-zA-Z0-9]{20,}"

# Generic password = '...' o secret = '...' patterns
SECRETS_PATTERN_4="(password|secret|api_key|token|apikey)\\s*[=:]\\s*['\"][a-zA-Z0-9+/_=.-]{12,}['\"]"

mode="${1:-full}"

scan_pattern() {
  local pattern="$1"
  local label="$2"
  local files_arg=""
  local result=""

  if [[ "$mode" == "--staged" ]]; then
    files=$(git diff --cached --name-only --diff-filter=AM \
      | grep -E '\.(php|js|jsx|ts|tsx|json|yml|yaml|env)$' || true)
    [[ -z "$files" ]] && return 0
    result=$(echo "$files" | xargs -I {} grep -EinH "$pattern" {} 2>/dev/null || true)
  else
    result=$(grep -EirnH "$pattern" \
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
      --include="*.ts" --include="*.tsx" --include="*.json" \
      --include="*.yml" --include="*.yaml" --include="*.env" \
      "$PROJECT_DIR" 2>/dev/null || true)
  fi

  # Filter false positives:
  # - placeholder values (XXX, YOUR_KEY, REPLACE_ME, ***, sandbox-*, test-*, fake-*)
  # - option name strings (lowercase + underscore only, ej "akb_12horas_api_key")
  # - .gitignore'd patterns (env.example)
  # - documentation strings (comments, README)
  filtered=$(echo "$result" | grep -Ev "(YOUR_|REPLACE_|XXXXX|sandbox-staging|fake-|test-key|example|placeholder|EXAMPLE|\\*\\*\\*)" || true)
  # Excluir option NAME strings (todos lowercase + _, sin uppercase ni digits).
  # Real secrets típicamente tienen mixed-case + digits + special chars.
  filtered=$(echo "$filtered" | awk -F"['\"]" '
    {
      # Extraer el value entre quotes (segundo o cuarto field típicamente)
      for (i=2; i<=NF; i+=2) {
        v = $i
        if (length(v) >= 12) {
          # Si value es solo lowercase + _ + digits → probable option name, skip
          if (v ~ /^[a-z0-9_]+$/) next
          # Si tiene mixed-case O special chars (+/=.-) → probable real secret
          if (v ~ /[A-Z]/ || v ~ /[+\/=.\\-]/) { print; break }
          # Default conservative: si hay digits y >12 chars, flag
          if (v ~ /[0-9]/ && length(v) >= 16) { print; break }
        }
      }
    }
  ')

  if [[ -n "$filtered" ]]; then
    echo "${C_FAIL}❌ grep-secrets ($label):${C_OFF}"
    echo "$filtered"
    return 1
  fi
  return 0
}

failures=0
scan_pattern "$SECRETS_PATTERN_1" "AKB_*_API_KEY/SECRET/TOKEN/PASSWORD" || failures=$((failures + 1))
scan_pattern "$SECRETS_PATTERN_2" "Brevo xkeysib- API key" || failures=$((failures + 1))
scan_pattern "$SECRETS_PATTERN_3" "Stripe-style sk_live/sk_test/pk_live" || failures=$((failures + 1))
scan_pattern "$SECRETS_PATTERN_4" "Generic password/secret/token assignments" || failures=$((failures + 1))

if [[ $failures -eq 0 ]]; then
  echo "${C_OK}✓ grep-secrets: no secrets detectados (gitleaks corre en CI como segunda capa)${C_OFF}"
  exit 0
fi

echo ""
echo "${C_DIM}Move secrets to wp-config-private.php (chmod 600) o GitHub secrets en CI.${C_OFF}"
echo "${C_DIM}Reference: project_no_key_rotation_policy.md memoria.${C_OFF}"
exit 1
