#!/usr/bin/env bash
set -euo pipefail

cd /opt/drupal
echo "[start] Drupal Startscript läuft…"

# 1) Composer-Projekt initialisieren, wenn leer
if [ ! -f "composer.json" ]; then
  echo "[start] Kein composer.json gefunden – erstelle drupal/recommended-project…"
  composer create-project drupal/recommended-project:^11.0 /opt/drupal
fi

# 2) Dependencies installieren, wenn vendor fehlt/leer
if [ ! -d "vendor" ] || [ -z "$(ls -A vendor || true)" ]; then
  echo "[start] Installiere Composer-Abhängigkeiten…"
  composer install --no-interaction --prefer-dist
fi

# 3) Drush sicherstellen
if ! vendor/bin/drush --version >/dev/null 2>&1; then
  echo "[start] Installiere Drush…"
  composer require drush/drush:^13 --no-interaction
fi

# 4) settings.php erzeugen/ergänzen
SETTINGS_DIR="web/sites/default"
SETTINGS_FILE="$SETTINGS_DIR/settings.php"
DEFAULT_SETTINGS="$SETTINGS_DIR/default.settings.php"

if [ ! -f "$SETTINGS_FILE" ]; then
  echo "[start] Erzeuge settings.php…"
  cp "$DEFAULT_SETTINGS" "$SETTINGS_FILE"
  chmod 644 "$SETTINGS_FILE"
fi

# Trusted Hosts
TRUSTED="${DRUPAL_TRUSTED_HOSTS:-^localhost$}"
if ! grep -q "trusted_host_patterns" "$SETTINGS_FILE"; then
  {
    echo ""
    echo "\$settings['trusted_host_patterns'] = ["
    IFS=',' read -ra HOSTS <<< "$TRUSTED"
    for h in "${HOSTS[@]}"; do echo "  '$h',"; done
    echo "];"
  } >> "$SETTINGS_FILE"
fi

# DB-Config
DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_NAME="${DRUPAL_DB_NAME:-drupal}"
DB_USER="${DRUPAL_DB_USER:-drupal}"
DB_PASS="${DRUPAL_DB_PASS:-drupal}"

if ! grep -q "databases\['default']\['default']" "$SETTINGS_FILE"; then
  cat <<PHP >> "$SETTINGS_FILE"

\$databases['default']['default'] = [
  'database' => '$DB_NAME',
  'username' => '$DB_USER',
  'password' => '$DB_PASS',
  'host' => '$DB_HOST',
  'port' => '5432',
  'driver' => 'pgsql',
  'prefix' => '',
];
PHP
fi

# Files-Verzeichnis
mkdir -p "$SETTINGS_DIR/files"
chown -R www-data:www-data "$SETTINGS_DIR/files"
chmod -R 775 "$SETTINGS_DIR/files"

# 5) Erstinstallation falls nötig
if ! vendor/bin/drush status --fields=bootstrap --format=list 2>/dev/null | grep -q "Successful"; then
  echo "[start] Drupal scheint nicht installiert – führe Installation aus…"
  SITE_NAME="${DRUPAL_SITE_NAME:-Drupal Site}"
  ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
  ADMIN_PASS="${DRUPAL_ADMIN_PASS:-admin}"
  ADMIN_MAIL="${DRUPAL_ADMIN_MAIL:-admin@example.com}"

  vendor/bin/drush site:install standard -y \
    --db-url="pgsql://${DB_USER}:${DB_PASS}@${DB_HOST}:5432/${DB_NAME}" \
    --site-name="${SITE_NAME}" \
    --account-name="${ADMIN_USER}" \
    --account-pass="${ADMIN_PASS}" \
    --account-mail="${ADMIN_MAIL}"

  # JSON:API direkt aktivieren (wird vom Matching benötigt)
  vendor/bin/drush en jsonapi -y
else
  echo "[start] Drupal bereits installiert."
fi

# Optional: Config Sync Pfad setzen (wenn du ./drupal/config nutzt)
if ! grep -q "config_sync_directory" "$SETTINGS_FILE"; then
  echo "\$settings['config_sync_directory'] = '/opt/drupal/config';" >> "$SETTINGS_FILE"
fi

echo "[start] Starte Apache…"
exec apache2-foreground
