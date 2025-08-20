#!/usr/bin/env bash
set -euo pipefail

DRUSH="/opt/drupal/vendor/bin/drush"

API_USER="${DRUPAL_API_USER:-apiuser}"
API_PASS="${DRUPAL_API_PASS:-apipassword}"
API_MAIL="${DRUPAL_API_MAIL:-apiuser@example.com}"

role_ensure () {
  local mach="$1" ; local label="$2"
  if ! "$DRUSH" role:list --format=list | grep -Fxq "$mach"; then
    "$DRUSH" role:create "$mach" "$label"
  fi
}

perm_add () {
  local role="$1"; shift
  # Jede Permission einzeln hinzufügen, Fehler (falls nicht vorhanden) ignorieren.
  for p in "$@"; do
    "$DRUSH" role:perm:add "$role" "$p" >/dev/null 2>&1 || true
  done
}

echo "[roles] Sicherstellen: Rollen"
role_ensure team        "Team"
role_ensure api_writer  "API Writer"

echo "[roles] Team‑Rechte (allgemein)"
perm_add team \
  "access administration pages" \
  "access toolbar" \
  "access content overview" \
  "view published content"

echo "[roles] Team‑Rechte (pro Bundle)"
for B in workshop teilnehmer wunsch; do
  perm_add team \
    "create ${B} content" \
    "edit own ${B} content" \
    "edit any ${B} content" \
    "delete own ${B} content" \
    "delete any ${B} content" \
    "view ${B} revisions"
done

echo "[roles] Team/Administrator – Custom‑Permissions (falls Module aktiv)"
perm_add team          "import jfcamp csv" "run jfcamp matching"
perm_add administrator "import jfcamp csv" "run jfcamp matching"

echo "[roles] API‑Rolle Rechte"
perm_add api_writer \
  "access content" \
  "access user profiles" \
  "edit any teilnehmer content"

echo "[roles] API‑User anlegen/zuordnen"
if ! "$DRUSH" user:information "$API_USER" >/dev/null 2>&1; then
  "$DRUSH" user:create "$API_USER" --mail="$API_MAIL" --password="$API_PASS"
fi
"$DRUSH" user:role:add api_writer "$API_USER" >/dev/null 2>&1 || true

echo "[roles] Cache leeren"
"$DRUSH" cr -y || true

echo "[roles] Fertig."
