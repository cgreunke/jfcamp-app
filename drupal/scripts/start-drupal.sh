#!/usr/bin/env bash
# Debugâ€‘freundliche Slimâ€‘Variante: DBâ€‘wait â†’ settings.env.php â†’ (Erst)Install â†’ (Auto)CIM â†’ Cache â†’ Apache
# Setze DEBUG=1 in der ENV (oder hier unten), um Trace/Traps zu aktivieren.

: "${DEBUG:=1}"

if [ "${DEBUG}" = "1" ]; then
  set -Eeuo pipefail
  set -x
  export PS4='+ [${LINENO}] ${BASH_SOURCE}:${FUNCNAME[0]:-main} > '
  trap 'code=$?; echo "[ERROR] exit=$code at line ${LINENO}: ${BASH_COMMAND}"; exit $code' ERR
else
  set -euo pipefail
fi

echo "[start] JF Startercamp: Slim Start (DEBUG=${DEBUG})"

PROJECT_ROOT="/opt/drupal"
WEB_ROOT="${PROJECT_ROOT}/web"
SITES_DIR="${WEB_ROOT}/sites/default"
SETTINGS_ENV_FILE="${SITES_DIR}/settings.env.php"
SETTINGS_FILE="${SITES_DIR}/settings.php"

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

INSTALL_IMPORT_ONCE="${DRUPAL_INSTALL_CONFIG_IMPORT:-1}"
AUTO_IMPORT_ON_START="${DRUPAL_AUTO_IMPORT_ON_START:-0}"

DRUSH="${PROJECT_ROOT}/vendor/bin/drush"

echo "[probe] php: $(php -v | head -n1)"
echo "[probe] drush: $(${DRUSH} --version | head -n1 || echo 'NOT FOUND')"
echo "[probe] webroot exists? ${WEB_ROOT} ; sites dir? ${SITES_DIR}"
ls -ld "${WEB_ROOT}" || true
ls -ld "${SITES_DIR}" || true

# 1) DBâ€‘Wait
echo "[start] Warte auf DB ${DB_HOST}:${DB_PORT}â€¦"
for i in {1..60}; do
  if (exec 3<>"/dev/tcp/${DB_HOST}/${DB_PORT}") 2>/dev/null; then
    exec 3>&- 3<&-
    echo "[start] DB erreichbar."
    break
  fi
  echo "[wait] â€¦ ${i}/60"
  sleep 2
  if [[ $i -eq 60 ]]; then
    echo "[fatal] DB nicht erreichbar."
    exit 1
  fi
done

# 2) settings.env.php erzeugen
echo "[step] settings.env.php schreiben + Verzeichnisse anlegen"
mkdir -p "${SITES_DIR}" "${CONFIG_SYNC_DIR}" "${PROJECT_ROOT}/private" "${SITES_DIR}/files"
chown -R www-data:www-data "${SITES_DIR}/files" "${PROJECT_ROOT}/private" || true
chmod -R 775 "${SITES_DIR}/files" "${PROJECT_ROOT}/private" || true

# Hash‑Salt (persistiert)
if [[ -z "${DRUPAL_HASH_SALT:-}" ]]; then
  if [[ -f ${PROJECT_ROOT}/.hash_salt ]]; then
    DRUPAL_HASH_SALT="$(cat ${PROJECT_ROOT}/.hash_salt)"
  else
    DRUPAL_HASH_SALT="$(php -r 'echo bin2hex(random_bytes(32));')"
    echo -n "${DRUPAL_HASH_SALT}" > "${PROJECT_ROOT}/.hash_salt"
  fi
fi


# Trusted Host Patterns â†’ PHPâ€‘Array
PHP_TRUSTED_HOSTS_ARRAY=""
IFS=',' read -ra THP <<< "${TRUSTED}"
for th in "${THP[@]}"; do PHP_TRUSTED_HOSTS_ARRAY="${PHP_TRUSTED_HOSTS_ARRAY}'${th}',"; done

cat > "${SETTINGS_ENV_FILE}" <<PHP
<?php
// Auto-generated (slim)
\$settings['hash_salt'] = '${DRUPAL_HASH_SALT}';
\$settings['config_sync_directory'] = '${CONFIG_SYNC_DIR}';
\$settings['trusted_host_patterns'] = [ ${PHP_TRUSTED_HOSTS_ARRAY} ];

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

# settings.php include sicherstellen
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

echo "[probe] settings.env.php head:"
head -n 10 "${SETTINGS_ENV_FILE}" || true

# 3) (Erst-)Installation
echo "[step] Bootstrapâ€‘Status prÃ¼fenâ€¦"
bootstrap_status="$(${DRUSH} core:status --fields=bootstrap --format=string 2>/dev/null || true)"
echo "[probe] bootstrap_status='${bootstrap_status}'"
if [[ "${bootstrap_status}" != "Successful" ]]; then
  echo "[step] site:install â€¦"
  ${DRUSH} site:install -y \
    --db-url="pgsql://${DRUPAL_DB_USER:-drupal}:${DRUPAL_DB_PASS:-drupal}@${DB_HOST}:${DB_PORT}/${DRUPAL_DB_NAME:-drupal}" \
    --account-name="${ADMIN_USER}" \
    --account-pass="${ADMIN_PASS}" \
    --account-mail="${ADMIN_MAIL}" \
    --site-name="${SITE_NAME}"
  if [[ "${INSTALL_IMPORT_ONCE}" == "1" ]]; then
    echo "[step] cim (Erstimport)â€¦"
    ${DRUSH} cim -y || true
  fi
else
  echo "[step] Bereits installiert."
fi

# 4) Optionaler Autoâ€‘Import bei jedem Start
if [[ "${AUTO_IMPORT_ON_START}" == "1" ]]; then
  echo "[step] Autoâ€‘cimâ€¦"
  ${DRUSH} cim -y || true
fi

# 5) Cache
echo "[step] drush crâ€¦"
${DRUSH} cr -y || true

# 6) Apache
echo "[start] Apache startenâ€¦"
exec apache2-foreground
