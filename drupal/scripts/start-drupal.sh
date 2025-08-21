#!/usr/bin/env bash
# start-drupal.sh (Slim + Composer-Bootstrap)
# Aufgaben: DB-Wait → Composer-Bootstrap → settings.php → (Erst)Install → (optional) CIM → Cache → Apache
set -euo pipefail

PROJECT_ROOT="/opt/drupal"
WEB_ROOT="${PROJECT_ROOT}/web"
SITES_DIR="${WEB_ROOT}/sites/default"
SETTINGS_FILE="${SITES_DIR}/settings.php"
SETTINGS_ENV_FILE="${SITES_DIR}/settings.env.php"
DRUSH="${PROJECT_ROOT}/vendor/bin/drush"

DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_PORT="${DRUPAL_DB_PORT:-5432}"

SITE_NAME="${DRUPAL_SITE_NAME:-JF Startercamp}"
ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
ADMIN_PASS="${DRUPAL_ADMIN_PASS:-admin}"
ADMIN_MAIL="${DRUPAL_ADMIN_MAIL:-admin@example.com}"
PROFILE="${DRUPAL_INSTALL_PROFILE:-minimal}"

CONFIG_SYNC_DIR="${CONFIG_SYNC_DIRECTORY:-/opt/drupal/config/sync}"

echo "[start] Slim Drupal Start"

# 0) Composer-Bootstrap (falls Volume leer / kein Projekt vorhanden)
if [ ! -f "${PROJECT_ROOT}/composer.json" ]; then
  echo "[bootstrap] Kein composer.json → drupal/recommended-project…"
  composer create-project drupal/recommended-project:^11.0 "${PROJECT_ROOT}"
fi
if [ ! -x "${DRUSH}" ]; then
  echo "[bootstrap] vendor/bin/drush fehlt → composer install + drush…"
  ( cd "${PROJECT_ROOT}" && composer install --no-interaction --prefer-dist )
  ( cd "${PROJECT_ROOT}" && composer require drush/drush:^13 --no-interaction )
fi

# 1) DB-Wait
for i in {1..60}; do
  if (exec 3<>"/dev/tcp/${DB_HOST}/${DB_PORT}") 2>/dev/null; then
    echo "[ok] DB erreichbar."
    break
  fi
  echo "[wait] warte auf DB… (${i}/60)"
  sleep 2
  [[ $i -eq 60 ]] && { echo "[fatal] DB nicht erreichbar"; exit 1; }
done

# 2) settings.env.php / Verzeichnisse
mkdir -p "${SITES_DIR}" "${CONFIG_SYNC_DIR}" "${PROJECT_ROOT}/private" "${SITES_DIR}/files"
if [[ ! -f "${SETTINGS_FILE}" ]]; then
  cp "${SITES_DIR}/default.settings.php" "${SETTINGS_FILE}"
  cat >> "${SETTINGS_FILE}" <<'PHP'

/** Include environment-specific settings */
if (file_exists(__DIR__ . '/settings.env.php')) {
  include __DIR__ . '/settings.env.php';
}
PHP
fi

# Hash salt einmal persistent
if [[ -z "${DRUPAL_HASH_SALT:-}" ]]; then
  if [[ -f ${PROJECT_ROOT}/.hash_salt ]]; then
    DRUPAL_HASH_SALT="$(cat ${PROJECT_ROOT}/.hash_salt)"
  else
    DRUPAL_HASH_SALT="$(php -r 'echo bin2hex(random_bytes(32));')"
    echo -n "${DRUPAL_HASH_SALT}" > "${PROJECT_ROOT}/.hash_salt"
  fi
fi

cat > "${SETTINGS_ENV_FILE}" <<PHP
<?php
\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['config_sync_directory'] = '${CONFIG_SYNC_DIR}';
\$settings['file_private_path'] = '${PROJECT_ROOT}/private';
PHP

chown -R www-data:www-data "${SITES_DIR}/files" "${PROJECT_ROOT}/private" || true
chmod -R 775 "${SITES_DIR}/files" "${PROJECT_ROOT}/private" || true

# 3) Installation falls nötig
if ! ${DRUSH} status bootstrap --format=list 2>/dev/null | grep -q Successful; then
  echo "[install] Drupal installieren…"
  ${DRUSH} site:install -y "${PROFILE}" \
    --db-url="pgsql://${DRUPAL_DB_USER:-drupal}:${DRUPAL_DB_PASS:-drupal}@${DB_HOST}:${DB_PORT}/${DRUPAL_DB_NAME:-drupal}" \
    --account-name="${ADMIN_USER}" \
    --account-pass="${ADMIN_PASS}" \
    --account-mail="${ADMIN_MAIL}" \
    --site-name="${SITE_NAME}" \
    --locale=de
else
  echo "[skip] Drupal ist bereits installiert."
fi

# 4) Optional: Config-Import (DEV: via env DRUPAL_AUTO_IMPORT_ON_START=1)
if [[ "${DRUPAL_AUTO_IMPORT_ON_START:-0}" == "1" ]]; then
  echo "[config] drush cim -y"
  ${DRUSH} cim -y || true
fi

# 5) Cache & Apache
${DRUSH} cr -y || true
echo "[start] Apache…"
exec apache2-foreground
