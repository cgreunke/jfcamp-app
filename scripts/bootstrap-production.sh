#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

ENV_FILE=".env.production"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "[bootstrap] ${ENV_FILE} missing." >&2
  echo "[bootstrap] Copy .env.production.example to .env.production and edit it first." >&2
  exit 1
fi

command -v docker >/dev/null 2>&1 || {
  echo "[bootstrap] required command 'docker' not found" >&2
  exit 1
}

docker compose version >/dev/null

COMPOSE=(
  docker compose
  --env-file "${ENV_FILE}"
  -f docker-compose.yml
  -f docker-compose.prod.yml
  -f docker-compose.domain.yml
)

echo "[bootstrap] reading Compose environment"
compose_environment="$("${COMPOSE[@]}" config --environment)"

get_env() {
  local name="$1"
  local line

  line="$(printf '%s\n' "${compose_environment}" | grep -m1 -E "^${name}=" || true)"
  if [[ -z "${line}" ]]; then
    printf ''
    return 0
  fi

  printf '%s' "${line#*=}"
}

required_vars=(
  APP_DOMAIN
  DRUPAL_DB_NAME
  DRUPAL_DB_USER
  DRUPAL_DB_PASS
  DRUPAL_SITE_NAME
  DRUPAL_ADMIN_USER
  DRUPAL_ADMIN_PASS
  DRUPAL_ADMIN_MAIL
  DRUPAL_HASH_SALT
  DRUPAL_TRUSTED_HOSTS
  DRUPAL_API_USER
  DRUPAL_API_PASS
  DRUPAL_API_ROLE
  DRUPAL_API_MAIL
)

for name in "${required_vars[@]}"; do
  value="$(get_env "${name}")"

  if [[ -z "${value}" ]]; then
    echo "[bootstrap] required variable ${name} is empty or missing" >&2
    exit 1
  fi

  if [[ "${value}" == PLEASE_CHANGE_ME* ]]; then
    echo "[bootstrap] required variable ${name} still contains a placeholder" >&2
    exit 1
  fi
done

APP_DOMAIN="$(get_env APP_DOMAIN)"
DRUPAL_DB_NAME="$(get_env DRUPAL_DB_NAME)"
DRUPAL_DB_USER="$(get_env DRUPAL_DB_USER)"
DRUPAL_SITE_NAME="$(get_env DRUPAL_SITE_NAME)"
DRUPAL_ADMIN_USER="$(get_env DRUPAL_ADMIN_USER)"
DRUPAL_ADMIN_PASS="$(get_env DRUPAL_ADMIN_PASS)"
DRUPAL_ADMIN_MAIL="$(get_env DRUPAL_ADMIN_MAIL)"

echo "[bootstrap] validating Compose configuration"
"${COMPOSE[@]}" config >/dev/null

echo "[bootstrap] building immutable application images"
"${COMPOSE[@]}" build

echo "[bootstrap] starting PostgreSQL"
"${COMPOSE[@]}" up -d postgres

echo "[bootstrap] waiting for PostgreSQL readiness"
db_ready=0
for _ in $(seq 1 120); do
  if "${COMPOSE[@]}" exec -T postgres \
    pg_isready -U "${DRUPAL_DB_USER}" -d "${DRUPAL_DB_NAME}" >/dev/null 2>&1; then
    db_ready=1
    break
  fi
  sleep 2
done

if [[ "${db_ready}" != "1" ]]; then
  echo "[bootstrap] PostgreSQL did not become ready" >&2
  exit 1
fi

drush_run() {
  "${COMPOSE[@]}" run --rm --no-deps \
    -e DRUPAL_REVERSE_PROXY=0 \
    drupal vendor/bin/drush "$@"
}

echo "[bootstrap] checking Drupal installation state"
set +e
bootstrap_status="$(drush_run status --fields=bootstrap --format=list)"
bootstrap_rc=$?
set -e

if [[ "${bootstrap_rc}" -ne 0 ]]; then
  echo "[bootstrap] unable to determine Drupal installation state" >&2
  exit 1
fi

bootstrap_status_trimmed="$(printf '%s' "${bootstrap_status}" | tr -d '[:space:]')"

if [[ "${bootstrap_status_trimmed}" == "Successful" ]]; then
  echo "[bootstrap] Drupal is already installed; skipping site:install"
elif [[ -z "${bootstrap_status_trimmed}" ]]; then
  echo "[bootstrap] installing Drupal strictly from config/sync"
  drush_run site:install --existing-config -y \
    --site-name="${DRUPAL_SITE_NAME}" \
    --account-name="${DRUPAL_ADMIN_USER}" \
    --account-pass="${DRUPAL_ADMIN_PASS}" \
    --account-mail="${DRUPAL_ADMIN_MAIL}"
else
  printf '%s\n' "${bootstrap_status}" >&2
  echo "[bootstrap] unexpected Drupal bootstrap status; refusing to install" >&2
  exit 1
fi

echo "[bootstrap] applying explicit database updates"
drush_run updatedb -y

echo "[bootstrap] ensuring matching API user"
"${COMPOSE[@]}" run --rm --no-deps \
  -e DRUPAL_REVERSE_PROXY=0 \
  drupal /opt/drupal/scripts/jf-roles.sh

echo "[bootstrap] rebuilding Drupal cache"
drush_run cache:rebuild -y

echo "[bootstrap] checking configuration status"
set +e
config_status="$(drush_run config:status)"
config_rc=$?
set -e

printf '%s\n' "${config_status}"

if [[ "${config_rc}" -ne 0 ]]; then
  echo "[bootstrap] unable to determine Drupal configuration status" >&2
  exit 1
fi

if echo "${config_status}" | grep -Eq 'Only in DB|Only in sync dir|Different'; then
  echo "[bootstrap] Drupal configuration differs from config/sync" >&2
  exit 1
fi

echo "[bootstrap] starting application stack"
"${COMPOSE[@]}" up -d

echo "[bootstrap] final service status"
"${COMPOSE[@]}" ps

echo
echo "[bootstrap] JF Camp production bootstrap completed."
echo "[bootstrap] URL: https://${APP_DOMAIN}"
