#!/usr/bin/env bash
# Slim Start für JF Camp: DB‑Wait → settings.env.php → (Erst)Install → (Auto)CIM → Cache → Apache

: "${DEBUG:=0}"                                   # DEBUG=1 für Trace/Traps
: "${DRUPAL_INSTALL_PROFILE:=minimal}"            # Profil steuerbar via ENV; Default: minimal
: "${DRUPAL_INSTALL_CONFIG_IMPORT:=1}"            # 1 = einmalig nach Erstinstallation cim
: "${DRUPAL_AUTO_IMPORT_ON_START:=0}"             # 1 = bei jedem Start cim (nur DEV!)

if [ "${DEBUG}" = "1" ]; then
  set -Eeuo pipefail
  set -x
  export PS4='+ [${LINENO}] ${BASH_SOURCE}:${FUNCNAME[0]:-main} > '
  trap 'code=$?; echo "[ERROR] exit=$code at line ${LINENO}: ${BASH_COMMAND}"; exit $code' ERR
else
  set -euo pipefail
fi

echo "[start] JF Startercamp: Slim Start (DEBUG=${DEBUG})"

# --------------------------------------------------------------------
# Pfade & Defaults
# --------------------------------------------------------------------
PROJECT_ROOT="/opt/drupal"
WEB_ROOT="${PROJECT_ROOT}/web"
SITES_DIR="${WEB_ROOT}/sites/default"
SETTINGS_FILE="${SITES_DIR}/settings.php"
SETTINGS_ENV_FILE="${SITES_DIR}/settings.env.php"

DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_PORT="${DRUPAL_DB_PORT:-5432}"

SITE_NAME="${DRUPAL_SITE_NAME:-Drupal}"
ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
ADMIN_PASS="${DRUPAL_ADMIN_PASS:-admin}"
ADMIN_MAIL="${DRUPAL_ADMIN_MAIL:-admin@example.com}"

CONFIG_SYNC_DIR="${CONFIG_SYNC_DIRECTORY:-/opt/drupal/config/sync}"
TRUSTED="${DRUPAL_TRUSTED_HOSTS:-^localhost$}"
DRUPAL_REVERSE_PROXY="${DRUPAL_REVERSE_PROXY:-0}"
DRUPAL_REVERSE_PROXY_ADDRESSES="${DRUPAL_REVERSE_PROXY_ADDRESSES:-}"
DRUPAL_REVERSE_PROXY_TRUSTED_HEADERS="${DRUPAL_REVERSE_PROXY_TRUSTED_HEADERS:-X_FORWARDED_FOR,X_FORWARDED_HOST,X_FORWARDED_PROTO,X_FORWARDED_PORT}"

MATCHING_BASE_URL="${MATCHING_BASE_URL:-http://matching:5001}"
DRUSH="${PROJECT_ROOT}/vendor/bin/drush"

echo "[probe] php: $(php -v | head -n1)"
echo "[probe] drush: $(${DRUSH} --version | head -n1 || echo 'NOT FOUND')"
ls -ld "${WEB_ROOT}" || true
ls -ld "${SITES_DIR}" || true

# --------------------------------------------------------------------
# 1) Auf DB warten
# --------------------------------------------------------------------
echo "[wait] Warte auf DB ${DB_HOST}:${DB_PORT}…"
for i in {1..60}; do
  if (exec 3<>"/dev/tcp/${DB_HOST}/${DB_PORT}") 2>/dev/null; then
    exec 3>&- 3<&-
    echo "[wait] DB erreichbar."
    break
  fi
  sleep 2
  if [[ $i -eq 60 ]]; then
    echo "[fatal] DB nicht erreichbar."
    exit 1
  fi
done

# --------------------------------------------------------------------
# 2) settings.env.php erzeugen + Verzeichnisse
# --------------------------------------------------------------------
echo "[step] settings.env.php + Verzeichnisse anlegen"
mkdir -p "${SITES_DIR}" "${CONFIG_SYNC_DIR}" "${PROJECT_ROOT}/private" "${SITES_DIR}/files"
chown -R www-data:www-data "${SITES_DIR}/files" "${PROJECT_ROOT}/private" || true
chmod -R 775 "${SITES_DIR}/files" "${PROJECT_ROOT}/private" || true

# Hash‑Salt (persistieren)
if [[ -z "${DRUPAL_HASH_SALT:-}" ]]; then
  if [[ -f ${PROJECT_ROOT}/.hash_salt ]]; then
    DRUPAL_HASH_SALT="$(cat ${PROJECT_ROOT}/.hash_salt)"
  else
    DRUPAL_HASH_SALT="$(php -r 'echo bin2hex(random_bytes(32));')"
    echo -n "${DRUPAL_HASH_SALT}" > "${PROJECT_ROOT}/.hash_salt"
  fi
fi

# Trusted Hosts → PHP‑Array
PHP_TRUSTED=""
IFS=',' read -ra THP <<< "${TRUSTED}"
for th in "${THP[@]}"; do PHP_TRUSTED="${PHP_TRUSTED}'${th}',"; done

cat > "${SETTINGS_ENV_FILE}" <<PHP
<?php
// Auto-generated (slim)
\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['config_sync_directory'] = '${CONFIG_SYNC_DIR}';
\$settings['trusted_host_patterns'] = [ ${PHP_TRUSTED} ];

\$settings['reverse_proxy'] = ${DRUPAL_REVERSE_PROXY} ? TRUE : FALSE;
\$settings['reverse_proxy_addresses'] = array_filter(array_map('trim', explode(',', '${DRUPAL_REVERSE_PROXY_ADDRESSES}')));

\$__hdrs = array_map('trim', explode(',', '${DRUPAL_REVERSE_PROXY_TRUSTED_HEADERS}'));
\$__map = [
  'X_FORWARDED_FOR'  => \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR,
  'X_FORWARDED_HOST' => \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST,
  'X_FORWARDED_PROTO'=> \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO,
  'X_FORWARDED_PORT' => \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT,
  'FORWARDED'        => \Symfony\Component\HttpFoundation\Request::HEADER_FORWARDED,
];
\$settings['reverse_proxy_trusted_headers'] = array_reduce(\$__hdrs, fn(\$c,\$h)=> \$c | (\$__map[\$h] ?? 0), 0);

if (!isset(\$config)) { \$config = []; }
\$config['jfcamp.settings']['matching_base_url'] = getenv('MATCHING_BASE_URL') ?: '${MATCHING_BASE_URL}';
PHP

# settings.php Include sicherstellen
if ! grep -q "settings.env.php" "${SETTINGS_FILE}" 2>/dev/null; then
  cp "${SITES_DIR}/default.settings.php" "${SETTINGS_FILE}"
  cat >> "${SETTINGS_FILE}" <<'PHP'

/** Include environment-specific settings */
$env_settings = __DIR__ . '/settings.env.php';
if (file_exists($env_settings)) {
  include $env_settings;
}
PHP
fi

# --------------------------------------------------------------------
# 3) (Erst)Installation
# --------------------------------------------------------------------
echo "[step] Bootstrap‑Status prüfen…"
bootstrap_status="$(${DRUSH} core:status --fields=bootstrap --format=string 2>/dev/null || true)"
echo "[probe] bootstrap_status='${bootstrap_status}'"

if [[ "${bootstrap_status}" != "Successful" ]]; then
  echo "[step] site:install (${DRUPAL_INSTALL_PROFILE})…"
  ${DRUSH} site:install -y "${DRUPAL_INSTALL_PROFILE}" \
    --db-url="pgsql://${DRUPAL_DB_USER:-drupal}:${DRUPAL_DB_PASS:-drupal}@${DB_HOST}:${DB_PORT}/${DRUPAL_DB_NAME:-drupal}" \
    --account-name="${ADMIN_USER}" \
    --account-pass="${ADMIN_PASS}" \
    --account-mail="${ADMIN_MAIL}" \
    --site-name="${SITE_NAME}" \
    --locale=de

  if [[ "${DRUPAL_INSTALL_CONFIG_IMPORT}" == "1" ]]; then
    echo "[step] Erstimport: drush cim -y"
    ${DRUSH} cim -y || true
  fi
else
  echo "[step] Bereits installiert."
fi

# --------------------------------------------------------------------
# 4) Optional: Auto‑Import bei jedem Start (nur DEV!)
# --------------------------------------------------------------------
if [[ "${DRUPAL_AUTO_IMPORT_ON_START}" == "1" ]]; then
  echo "[step] Auto‑cim -y"
  ${DRUSH} cim -y || true
fi

# --------------------------------------------------------------------
# 5) Cache & Apache
# --------------------------------------------------------------------
echo "[step] drush cr -y"
${DRUSH} cr -y || true

echo "[start] Apache starten…"
exec apache2-foreground
