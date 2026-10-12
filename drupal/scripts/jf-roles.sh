#!/usr/bin/env bash
set -euo pipefail

cd /opt/drupal
DRUSH="vendor/bin/drush"

API_USER="${DRUPAL_API_USER:-apiuser}"
API_PASS="${DRUPAL_API_PASS:-}"
API_MAIL="${DRUPAL_API_MAIL:-${API_USER}@example.com}"
API_ROLE="${DRUPAL_API_ROLE:-api_writer}"

ROLE_EXISTS="$(${DRUSH} php:eval '
  $role_id = getenv("DRUPAL_API_ROLE") ?: "api_writer";
  print \Drupal\user\Entity\Role::load($role_id) ? "yes" : "no";
')"

if [[ "${ROLE_EXISTS}" != "yes" ]]; then
  echo "[jf-roles] required API role '${API_ROLE}' is missing from imported Drupal config" >&2
  exit 1
fi

if ! ${DRUSH} user:information "${API_USER}" >/dev/null 2>&1; then
  if [[ -z "${API_PASS}" ]]; then
    echo "[jf-roles] DRUPAL_API_PASS must be set when creating the API user" >&2
    exit 1
  fi

  ${DRUSH} user:create "${API_USER}" \
    --mail="${API_MAIL}" \
    --password="${API_PASS}"
elif [[ -n "${API_PASS}" ]]; then
  ${DRUSH} user:password "${API_USER}" "${API_PASS}"
fi

${DRUSH} user:role:add "${API_ROLE}" "${API_USER}" >/dev/null 2>&1 || true

echo "[jf-roles] API user '${API_USER}' is ready with role '${API_ROLE}'"
