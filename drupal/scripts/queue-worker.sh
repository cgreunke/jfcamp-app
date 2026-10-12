#!/usr/bin/env bash
set -euo pipefail

cd /opt/drupal

DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_PORT="${DRUPAL_DB_PORT:-5432}"

echo "[queue] waiting for database ${DB_HOST}:${DB_PORT}"

db_ready=0
for _ in $(seq 1 120); do
  if (echo >"/dev/tcp/${DB_HOST}/${DB_PORT}") >/dev/null 2>&1; then
    db_ready=1
    break
  fi
  sleep 1
done

if [[ "${db_ready}" != "1" ]]; then
  echo "[queue] database not reachable after timeout" >&2
  exit 1
fi

echo "[queue] jfcamp matching worker started"

while true; do
  vendor/bin/drush queue:run jfcamp_matching_runs --lease-time=900 || true
  sleep 10
done
