#!/usr/bin/env bash
# start-drupal.sh — Repro-sicherer Bootstrap für Drupal im Container
# Aufgaben: DB-Wait → Composer → settings.php → (Erst)Install (--existing-config wenn möglich)
#           → Upgrades → (optional) CIM → Cache → Apache
set -euo pipefail

PROJECT_ROOT="/opt/drupal"
WEB_ROOT="${PROJECT_ROOT}/web"
SITES_DIR="${WEB_ROOT}/sites/default"
SETTINGS_FILE="${SITES_DIR}/settings.php"
SETTINGS_ENV_FILE="${SITES_DIR}/settings.env.php"
DRUSH="${PROJECT_ROOT}/vendor/bin/drush"
CONFIG_SYNC_DIR="${PROJECT_ROOT}/config/sync"

# --- ENV Defaults ---
DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_PORT="${DRUPAL_DB_PORT:-5432}"
DB_NAME="${DRUPAL_DB_NAME:-drupal}"
DB_USER="${DRUPAL_DB_USER:-drupal}"
DB_PASS="${DRUPAL_DB_PASS:-drupal}"

SITE_NAME="${DRUPAL_SITE_NAME:-JF Startercamp}"
ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
ADMIN_PASS="${DRUPAL_ADMIN_PASS:-admin}"
ADMIN_MAIL="${DRUPAL_ADMIN_MAIL:-admin@example.com}"

AUTO_CIM="${DRUPAL_AUTO_IMPORT_ON_START:-0}"    # DEV: 1, PROD: 0
INSTALL_PROFILE="${DRUPAL_INSTALL_PROFILE:-minimal}" # Fallback, wenn keine Config im Repo

wait_for_db() {
  echo "[db] Warte auf ${DB_HOST}:${DB_PORT} ..."
  for i in $(seq 1 120); do
    (echo > /dev/tcp/${DB_HOST}/${DB_PORT}) >/dev/null 2>&1 && { echo "[db] erreichbar"; return 0; }
    sleep 1
  done
  echo "[db] nicht erreichbar" >&2
  exit 1
}

is_installed() {
  ${DRUSH} status --fields=bootstrap --format=list 2>/dev/null | grep -q "Successful" || return 1
}

ensure_composer() {
  cd "${PROJECT_ROOT}"
  if [ ! -d "vendor" ]; then
    echo "[composer] install"
    COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --prefer-dist
  else
    echo "[composer] install (update lock-basiert)"
    COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --prefer-dist
  fi
}

ensure_settings() {
  mkdir -p "${SITES_DIR}"
  if [ ! -f "${SETTINGS_FILE}" ]; then
    echo "[settings] Erzeuge settings.php"
    cp "${WEB_ROOT}/sites/default/default.settings.php" "${SETTINGS_FILE}"
    chmod 644 "${SETTINGS_FILE}"
    echo "\$settings['config_sync_directory'] = '${CONFIG_SYNC_DIR}';" >> "${SETTINGS_FILE}"
    echo "\$databases['default']['default'] = [" > "${SETTINGS_ENV_FILE}"
    echo "  'database' => '${DB_NAME}'," >> "${SETTINGS_ENV_FILE}"
    echo "  'username' => '${DB_USER}'," >> "${SETTINGS_ENV_FILE}"
    echo "  'password' => '${DB_PASS}'," >> "${SETTINGS_ENV_FILE}"
    echo "  'prefix'   => ''," >> "${SETTINGS_ENV_FILE}"
    echo "  'host'     => '${DB_HOST}'," >> "${SETTINGS_ENV_FILE}"
    echo "  'port'     => '${DB_PORT}'," >> "${SETTINGS_ENV_FILE}"
    echo "  'namespace'=> 'Drupal\\\\Core\\\\Database\\\\Driver\\\\pgsql'," >> "${SETTINGS_ENV_FILE}"
    echo "  'driver'   => 'pgsql'," >> "${SETTINGS_ENV_FILE}"
    echo "];" >> "${SETTINGS_ENV_FILE}"
    echo "require_once '${SETTINGS_ENV_FILE}';" >> "${SETTINGS_FILE}"
  fi
}

install_site_if_needed() {
  if is_installed; then
    echo "[install] Drupal bereits installiert."
    return
  fi

  cd "${PROJECT_ROOT}"
  if [ -f "${CONFIG_SYNC_DIR}/core.extension.yml" ]; then
    echo "[install] Site-Install mit bestehender Config"
    ${DRUSH} si --existing-config -y \
      --site-name="${SITE_NAME}" \
      --account-name="${ADMIN_USER}" \
      --account-pass="${ADMIN_PASS}" \
      --account-mail="${ADMIN_MAIL}"
  else
    echo "[install] Site-Install (${INSTALL_PROFILE}) (ohne vorhandene Config)"
    ${DRUSH} si -y "${INSTALL_PROFILE}" \
      --site-name="${SITE_NAME}" \
      --account-name="${ADMIN_USER}" \
      --account-pass="${ADMIN_PASS}" \
      --account-mail="${ADMIN_MAIL}"

    # Schnellstart/Seed (nur wenn keine Config im Repo vorhanden):
    # - Theme/Admin-UX, - Custom-Module, - Bundles/Fields, - Rollen
    echo "[install] Aktiviere Pflicht-Module/Theme"
    ${DRUSH} en -y jsonapi basic_auth dblog
    # Gin + Admin-Toolbar + r4032login, falls via Composer vorhanden
    ${DRUSH} en -y gin r4032login || true
    ${DRUSH} en -y gin_toolbar admin_toolbar admin_toolbar_tools || true
    ${DRUSH} theme:enable gin -y || true
    ${DRUSH} cset -y system.theme admin gin || true
    ${DRUSH} cset -y system.site page.403 "/user/login" || true
    ${DRUSH} cset -y system.site page.front "/admin/content" || true

    echo "[install] Aktiviere Custom-Module (falls vorhanden)"
    for M in jfcamp_api jfcamp_public_api jfcamp_matching; do
      if [ -d "${WEB_ROOT}/modules/custom/${M}" ]; then
        ${DRUSH} en -y "${M}"
      fi
    done

    # Seed-Helfer (idempotent, gut für DEV):
    if [ -f "/opt/drupal/scripts/ensure-bundles.php" ]; then
      ${DRUSH} php:script /opt/drupal/scripts/ensure-bundles.php || true
    fi
    if [ -f "/opt/drupal/scripts/jf-roles.sh" ]; then
      bash /opt/drupal/scripts/jf-roles.sh || true
    fi

    # Danach den erzeugten Zustand versionieren:
    echo "[install] Exportiere Seed-Config (einmalig sinnvoll)"
    ${DRUSH} cex -y || true
  fi
}

post_bootstrap_maintenance() {
  echo "[update] Datenbank-Updates (drush updb -y)"
  ${DRUSH} updb -y || true

  if [[ "${AUTO_CIM}" == "1" ]]; then
    echo "[config] drush cim -y (AUTO)"
    ${DRUSH} cim -y || true
  fi

  echo "[cache] drush cr -y"
  ${DRUSH} cr -y || true
}

start_apache() {
  echo "[start] Apache"
  exec apache2-foreground
}

# --- Ablauf ---
wait_for_db
ensure_composer
ensure_settings
install_site_if_needed
post_bootstrap_maintenance
start_apache
