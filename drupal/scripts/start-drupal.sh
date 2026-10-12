#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_PORT="${DRUPAL_DB_PORT:-5432}"

wait_for_db() {
  echo "[drupal] waiting for database ${DB_HOST}:${DB_PORT}"
  for _ in $(seq 1 120); do
    if (echo >"/dev/tcp/${DB_HOST}/${DB_PORT}") >/dev/null 2>&1; then
      echo "[drupal] database reachable"
      return 0
    fi
    sleep 1
  done

  echo "[drupal] database not reachable" >&2
  return 1
}

prepare_runtime_dirs() {
  mkdir -p /opt/drupal/web/sites/default/files /opt/drupal/private
  chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private
}

wait_for_db
prepare_runtime_dirs

echo "[drupal] starting Apache"
exec apache2-foreground
