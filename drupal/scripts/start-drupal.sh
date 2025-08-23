#!/usr/bin/env bash
# start-drupal.sh — Repro-sicherer Bootstrap für Drupal im Container
# DB-Wait → Composer → settings.php → (Erst)Install (--existing-config wenn möglich)
# → Upgrades → (optional) CIM → Cache → Apache
set -euo pipefail

# --- Pfade ---
PROJECT_ROOT="/opt/drupal"
WEB_ROOT="${PROJECT_ROOT}/web"
SITES_DIR="${WEB_ROOT}/sites/default"
SETTINGS_FILE="${SITES_DIR}/settings.php"
SETTINGS_ENV_FILE="${SITES_DIR}/settings.env.php"
DRUSH="${PROJECT_ROOT}/vendor/bin/drush"

# CONFIG_SYNC_DIR: ENV hat Vorrang; Default ist /opt/drupal/config/sync
CONFIG_SYNC_DIR_DEFAULT="${PROJECT_ROOT}/config/sync"
CONFIG_SYNC_DIR="${CONFIG_SYNC_DIRECTORY:-$CONFIG_SYNC_DIR_DEFAULT}"

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

AUTO_CIM="${DRUPAL_AUTO_IMPORT_ON_START:-0}"           # DEV: "1", PROD: "0"
INSTALL_PROFILE="${DRUPAL_INSTALL_PROFILE:-minimal}"   # Fallback ohne Config
AUTO_SEED_EMPTY_SYNC="${AUTO_SEED_EMPTY_SYNC:-1}"      # 1 = leeren Sync beim ersten Mal cex-seeden

have_cmd(){ command -v "$1" >/dev/null 2>&1; }

wait_for_db() {
  echo "[db] Warte auf ${DB_HOST}:${DB_PORT} ..."
  if have_cmd nc; then
    for _ in $(seq 1 120); do nc -z "${DB_HOST}" "${DB_PORT}" && { echo "[db] erreichbar"; return 0; }; sleep 1; done
  else
    for _ in $(seq 1 120); do (echo >"/dev/tcp/${DB_HOST}/${DB_PORT}") >/dev/null 2>&1 && { echo "[db] erreichbar"; return 0; }; sleep 1; done
  fi
  echo "[db] nicht erreichbar" >&2; exit 1
}

is_installed() {
  "${DRUSH}" status --fields=bootstrap --format=list 2>/dev/null | grep -q "Successful" || return 1
}

ensure_composer() {
  cd "${PROJECT_ROOT}"
  if ! have_cmd composer; then
    echo "[fatal] composer fehlt. Dockerfile: COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer" >&2
    exit 1
  fi
  if [ ! -f composer.json ]; then
    echo "[fatal] composer.json fehlt unter ${PROJECT_ROOT}. DEV-Mount richtig? (./drupal/composer.json → /opt/drupal/composer.json)" >&2
    exit 1
  fi

  echo "[composer] install (lock-basiert)"
  COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --prefer-dist

  if [ ! -x "${DRUSH}" ]; then
    echo "[warn] ${DRUSH} fehlt – installiere drush/drush on-the-fly"
    COMPOSER_MEMORY_LIMIT=-1 composer require drush/drush:^13.6 --no-interaction -W || true
  fi
  [ -x "${DRUSH}" ] || { echo "[fatal] Drush weiterhin nicht gefunden (composer.lock/DEV-Mount prüfen)"; exit 1; }
}

ensure_settings() {
  mkdir -p "${SITES_DIR}"
  if [ ! -f "${SETTINGS_FILE}" ]; then
    echo "[settings] Erzeuge settings.php"
    cp "${WEB_ROOT}/sites/default/default.settings.php" "${SETTINGS_FILE}"
    chmod 644 "${SETTINGS_FILE}"
    {
      echo "<?php"
      echo "\$settings['config_sync_directory'] = '${CONFIG_SYNC_DIR}';"
      echo "\$databases['default']['default'] = ["
      echo "  'database' => '${DB_NAME}',"
      echo "  'username' => '${DB_USER}',"
      echo "  'password' => '${DB_PASS}',"
      echo "  'prefix'   => '',"
      echo "  'host'     => '${DB_HOST}',"
      echo "  'port'     => '${DB_PORT}',"
      echo "  'namespace'=> 'Drupal\\\\Core\\\\Database\\\\Driver\\\\pgsql',"
      echo "  'driver'   => 'pgsql',"
      echo "];"
    } > "${SETTINGS_ENV_FILE}"
    echo "require_once '${SETTINGS_ENV_FILE}';" >> "${SETTINGS_FILE}"
  fi
}

debug_config() {
  echo "[debug] CONFIG_SYNC_DIR=${CONFIG_SYNC_DIR}"
  if [ -d "${CONFIG_SYNC_DIR}" ]; then
    echo "[debug] Inhalt ${CONFIG_SYNC_DIR}:"
    ls -la "${CONFIG_SYNC_DIR}" | sed -n '1,80p'
  else
    echo "[debug] Verzeichnis ${CONFIG_SYNC_DIR} existiert NICHT."
  fi
}

sync_has_files() {
  find "${CONFIG_SYNC_DIR}" -maxdepth 1 -type f -name '*.yml' 2>/dev/null | read -r _ && return 0 || return 1
}

ensure_config_dir_for_existing() {
  debug_config
  if [ ! -d "${CONFIG_SYNC_DIR}" ]; then
    echo "[fatal] Config-Verzeichnis fehlt: ${CONFIG_SYNC_DIR}. In DEV muss ./drupal/config → /opt/drupal/config gemountet sein." >&2
    exit 1
  fi
  if [ ! -f "${CONFIG_SYNC_DIR}/core.extension.yml" ]; then
    echo "[fatal] core.extension.yml fehlt in ${CONFIG_SYNC_DIR}. Ohne diese Datei ist --existing-config nicht möglich." >&2
    exit 1
  fi
}

# --- Helfer: UUID aus Sync lesen & im aktiven System setzen ---
align_site_uuid_with_sync() {
  local sys="${CONFIG_SYNC_DIR}/system.site.yml"
  if [ -f "${sys}" ]; then
    local uuid
    uuid="$(sed -n 's/^uuid:[[:space:]]*"\{0,1\}\([0-9a-f-]\{36\}\)"\{0,1\}.*/\1/p' "${sys}" | head -n1 || true)"
    if [ -n "${uuid:-}" ]; then
      echo "[uuid] Übernehme Site-UUID aus Sync: ${uuid}"
      "${DRUSH}" cset -y system.site uuid "${uuid}" >/dev/null
    else
      echo "[uuid] Konnte UUID in system.site.yml nicht finden – überspringe."
    fi
  else
    echo "[uuid] Keine system.site.yml im Sync – überspringe."
  fi
}

# --- Helfer: core.extension.yml so patchen, dass 'pgsql' garantiert installiert bleibt ---
ensure_pgsql_in_core_extension() {
  local ce="${CONFIG_SYNC_DIR}/core.extension.yml"
  [ -f "${ce}" ] || return 0
  if grep -qE '^[[:space:]]*pgsql:' "${ce}"; then
    echo "[pgsql] Eintrag in core.extension.yml vorhanden."
    return 0
  fi
  echo "[pgsql] Ergänze 'pgsql: 0' in core.extension.yml (unter module:)."
  # Insert '  pgsql: 0' innerhalb des 'module:'-Blocks falls vorhanden, sonst am Ende des Files.
  awk '
    BEGIN{inmod=0; done=0}
    /^module:[[:space:]]*$/ { print; inmod=1; next }
    inmod==1 && /^[^[:space:]]/ { if(done==0){ print "  pgsql: 0"; done=1 } ; inmod=0 }
    { print }
    END { if(inmod==1 && done==0) print "  pgsql: 0" }
  ' "${ce}" > "${ce}.tmp" && mv "${ce}.tmp" "${ce}"
}

sanitize_core_extension_basics() {
  local ce="${CONFIG_SYNC_DIR}/core.extension.yml"
  [ -f "${ce}" ] || return 0
  # Profil sicherstellen
  if ! grep -qE "^profile:[[:space:]]*[A-Za-z0-9_-]+" "${ce}"; then
    echo "[core.extension] Kein 'profile:' gefunden – setze 'minimal'."
    printf "\nprofile: minimal\n" >> "${ce}"
  fi
  ensure_pgsql_in_core_extension
}

install_site_if_needed() {
  if is_installed; then
    echo "[install] Drupal bereits installiert."
    return
  fi
  cd "${PROJECT_ROOT}"

  if [ -f "${CONFIG_SYNC_DIR}/core.extension.yml" ]; then
    ensure_config_dir_for_existing
    echo "[install] Site-Install mit bestehender Config (drush si --existing-config)"
    sanitize_core_extension_basics
    if "${DRUSH}" si --existing-config -y \
        --config-dir="${CONFIG_SYNC_DIR}" \
        --site-name="${SITE_NAME}" \
        --account-name="${ADMIN_USER}" \
        --account-pass="${ADMIN_PASS}" \
        --account-mail="${ADMIN_MAIL}"; then
      echo "[install] Existing-config erfolgreich."
      return
    else
      echo "[warn] Existing-config fehlgeschlagen – Fallback auf Minimal-Install"
    fi
  fi

  # Fallback: Minimal-Install + UUID angleichen + Import
  "${DRUSH}" -y site:install minimal \
    --db-url="pgsql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}" \
    --site-name="${SITE_NAME}" \
    --account-name="${ADMIN_USER}" \
    --account-pass="${ADMIN_PASS}" \
    --account-mail="${ADMIN_MAIL}"

  # Import nur, wenn auch Dateien im Sync vorhanden
  if sync_has_files; then
    echo "[config] Vorbereitung für Import (UUID angleichen, core.extension patchen)"
    align_site_uuid_with_sync
    sanitize_core_extension_basics
    echo "[config] Importiere Config aus ${CONFIG_SYNC_DIR}"
    if ! "${DRUSH}" cim -y --source="${CONFIG_SYNC_DIR}"; then
      echo "[config] Import schlug fehl – versuche einmaligen Fix (UUID+pgsql) und Retry."
      align_site_uuid_with_sync
      sanitize_core_extension_basics
      "${DRUSH}" cim -y --source="${CONFIG_SYNC_DIR}" || echo "[config] Import weiterhin fehlgeschlagen – bitte Logs prüfen."
    fi
  else
    echo "[config] Sync-Ordner ist leer → überspringe 'cim'."
    if [[ "${AUTO_SEED_EMPTY_SYNC}" == "1" ]]; then
      echo "[config] Seede leeren Sync-Ordner einmalig mit aktuellem Stand (drush cex -y)"
      mkdir -p "${CONFIG_SYNC_DIR}"
      "${DRUSH}" cex -y || true
    fi
  fi
}

post_bootstrap_maintenance() {
  echo "[update] drush updb -y"
  "${DRUSH}" updb -y || true
  if [[ "${AUTO_CIM}" == "1" ]] && sync_has_files; then
    echo "[config] drush cim -y (AUTO)"
    align_site_uuid_with_sync
    sanitize_core_extension_basics
    "${DRUSH}" cim -y --source="${CONFIG_SYNC_DIR}" || true
  fi
  echo "[cache] drush cr -y"
  "${DRUSH}" cr -y || true
}

start_apache() { echo "[start] Apache"; exec apache2-foreground; }

# --- Ablauf ---
wait_for_db
ensure_composer
ensure_settings
install_site_if_needed
post_bootstrap_maintenance
start_apache
