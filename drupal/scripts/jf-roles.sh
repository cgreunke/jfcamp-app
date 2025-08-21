#!/usr/bin/env bash
# jf-roles.sh — Rollen, Rechte & API-User für JF Camp
# Idempotent, mehrfach ausführbar

set -euo pipefail
cd /opt/drupal

DRUSH="vendor/bin/drush"

# ===== ENV / Defaults =========================================================
API_USER="${DRUPAL_API_USER:-apiuser}"
API_PASS="${DRUPAL_API_PASS:-apipassword}"
API_MAIL="${DRUPAL_API_MAIL:-${API_USER}@example.com}"

TEAM_ROLE="${TEAM_ROLE:-team}"
API_ROLE="${DRUPAL_API_ROLE:-api_writer}"

# Node Bundles in deinem Projekt:
BUNDLES=("workshop" "teilnehmer" "wunsch")

# Deine beiden Custom-Permissions:
PERM_IMPORT="import jfcamp csv"
PERM_MATCHING="run jfcamp matching"

# ===== Helper ================================================================
role_exists () {
  # nutzt string/list-Format und greppt den Rollennamen exakt
  ${DRUSH} role:list --format=string 2>/dev/null | grep -qx "$1"
}

user_exists () {
  ${DRUSH} user:information "$1" >/dev/null 2>&1
}

add_perm () {
  local role="$1"; shift
  for perm in "$@"; do
    ${DRUSH} role:perm:add "$role" "$perm" >/dev/null 2>&1 || true
  done
}

ensure_role () {
  local rid="$1" ; local label="$2"
  if ! role_exists "$rid"; then
    ${DRUSH} role:create "$rid" "$label" || true
  fi
}

# ===== 1) Rollen anlegen =====================================================
ensure_role "$TEAM_ROLE" "Team"
ensure_role "$API_ROLE" "API Writer"

# ===== 2) Basisrechte vergeben ===============================================
# Team: Admin-Zugänge & generische Leserechte
add_perm "$TEAM_ROLE" \
  "access administration pages" \
  "access toolbar" \
  "access content overview" \
  "access site reports" \
  "view published content"

# CRUD für Bundles
for B in "${BUNDLES[@]}"; do
  add_perm "$TEAM_ROLE" \
    "create ${B} content" \
    "edit own ${B} content" \
    "edit any ${B} content" \
    "delete own ${B} content" \
    "delete any ${B} content" \
    "view ${B} revisions"
done

# Deine beiden Custom-Permissions → Team + Administrator
add_perm "$TEAM_ROLE" "$PERM_IMPORT" "$PERM_MATCHING"
add_perm "administrator" "$PERM_IMPORT" "$PERM_MATCHING"

# ===== 3) API Writer (für Matching-Service; KEIN Matching-Trigger) ===========
# Minimale Setups für JSON:API + gezielte Schreibrechte auf Teilnehmer
add_perm "$API_ROLE" \
  "access content" \
  "access user profiles" \
  "edit any teilnehmer content"

# Optional, falls der Service Teilnehmer anlegen/entfernen soll:
# add_perm "$API_ROLE" "create teilnehmer content" "delete any teilnehmer content"

# ===== 4) API-User anlegen & Rolle zuweisen ==================================
if ! user_exists "$API_USER"; then
  ${DRUSH} user:create "$API_USER" --mail="$API_MAIL" --password="$API_PASS"
else
  # Passwort optional aktualisieren, falls gesetzt
  if [[ -n "${API_PASS}" ]]; then
    ${DRUSH} user:password "$API_USER" "$API_PASS" >/dev/null 2>&1 || true
  fi
fi

${DRUSH} user:role:add "$API_ROLE" "$API_USER" >/dev/null 2>&1 || true

echo "[jf-roles] Rollen & Rechte gesetzt. API-User: ${API_USER} (Rolle: ${API_ROLE})"
