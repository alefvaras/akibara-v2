#!/usr/bin/env bash
# Akibara — Emergency plugin disable.
#
# Use cuando un plugin Akibara tira fatal en activation y wp-cli no puede
# ejecutarse (porque WP no botea). Renombra el directorio del plugin a
# `<plugin>.DISABLED-<timestamp>` lo que hace que WP no lo cargue.
#
# Site recovers automáticamente — el plugin queda offline pero el resto del
# sitio sigue funcionando. Reversible: `mv` opuesto restaura.
#
# REQUIERE autorización explícita en .claude/settings.json (project allowlist).
#
# Uso:
#   bash bin/emergency-disable-plugin.sh akibara-preventas
#   bash bin/emergency-disable-plugin.sh akibara-marketing
#   bash bin/emergency-disable-plugin.sh --restore akibara-preventas  # mv opuesto
#
# Exit code:
#   0 = mv exitoso
#   1 = error

set -uo pipefail

PROD_HOST="akibara"
PROD_PLUGINS="/home/u888022333/domains/akibara.cl/public_html/wp-content/plugins"
DATE=$(date +%Y-%m-%d-%H%M%S)

if [[ -t 1 ]]; then
  C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_FAIL=""; C_WARN=""; C_DIM=""; C_OFF=""
fi

usage() {
  echo "Usage: bash bin/emergency-disable-plugin.sh <plugin-slug>"
  echo "       bash bin/emergency-disable-plugin.sh --restore <plugin-slug>"
  echo ""
  echo "Examples:"
  echo "  bash bin/emergency-disable-plugin.sh akibara-preventas"
  echo "  bash bin/emergency-disable-plugin.sh --restore akibara-preventas"
  exit 2
}

[[ $# -lt 1 ]] && usage

mode="disable"
plugin=""

if [[ "$1" == "--restore" ]]; then
  mode="restore"
  plugin="${2:-}"
elif [[ "$1" == "-h" || "$1" == "--help" ]]; then
  usage
else
  plugin="$1"
fi

[[ -z "$plugin" ]] && usage

# Whitelist: solo plugins akibara-* permitidos.
if [[ ! "$plugin" =~ ^akibara- ]]; then
  echo "${C_FAIL}❌ plugin must match akibara-*${C_OFF}"
  exit 1
fi

case "$mode" in
  disable)
    echo "${C_DIM}emergency disable: ${plugin} on ${PROD_HOST}${C_OFF}"

    # Verify plugin exists in prod
    if ! ssh "$PROD_HOST" "test -d ${PROD_PLUGINS}/${plugin}"; then
      echo "${C_FAIL}❌ ${PROD_PLUGINS}/${plugin} no existe en prod${C_OFF}"
      exit 1
    fi

    target="${PROD_PLUGINS}/${plugin}.DISABLED-${DATE}"
    echo "${C_DIM}  mv: ${PROD_PLUGINS}/${plugin} → ${target}${C_OFF}"

    if ssh "$PROD_HOST" "mv '${PROD_PLUGINS}/${plugin}' '${target}'"; then
      echo "${C_OK}✅ ${plugin} disabled (renamed to .DISABLED-${DATE})${C_OFF}"
      echo ""
      echo "${C_DIM}Para restore: bash bin/emergency-disable-plugin.sh --restore ${plugin}${C_OFF}"
      echo "${C_DIM}Smoke check:  bash scripts/smoke-prod.sh --quick${C_OFF}"
      exit 0
    else
      echo "${C_FAIL}❌ mv falló — verificar permisos SSH y path${C_OFF}"
      exit 1
    fi
    ;;

  restore)
    echo "${C_DIM}restore: ${plugin} on ${PROD_HOST}${C_OFF}"

    # Find most recent .DISABLED-* dir for this plugin
    disabled=$(ssh "$PROD_HOST" "ls -1d ${PROD_PLUGINS}/${plugin}.DISABLED-* 2>/dev/null | sort | tail -1")

    if [[ -z "$disabled" ]]; then
      echo "${C_FAIL}❌ no encontró ${PROD_PLUGINS}/${plugin}.DISABLED-* en prod${C_OFF}"
      exit 1
    fi

    echo "${C_DIM}  mv: ${disabled} → ${PROD_PLUGINS}/${plugin}${C_OFF}"

    if ssh "$PROD_HOST" "mv '${disabled}' '${PROD_PLUGINS}/${plugin}'"; then
      echo "${C_OK}✅ ${plugin} restored${C_OFF}"
      echo ""
      echo "${C_DIM}Para activar plugin: bin/wp-ssh plugin activate ${plugin}${C_OFF}"
      echo "${C_DIM}Smoke check:         bash scripts/smoke-prod.sh --quick${C_OFF}"
      exit 0
    else
      echo "${C_FAIL}❌ mv falló${C_OFF}"
      exit 1
    fi
    ;;
esac
