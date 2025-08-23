#!/usr/bin/env bash
# init-drupal.sh — einmalig/manuell für DEV-Seeds
set -euo pipefail

cd /opt/drupal
DRUSH=vendor/bin/drush

echo "[init] Stelle sicher, dass Site installiert ist"
$DRUSH status --fields=bootstrap --format=list | grep -q "Successful" || {
  echo "[init] Drupal ist nicht installiert. Bitte erst start-drupal.sh laufen lassen."
  exit 1
}

echo "[init] Admin-UX via Composer sicherstellen (nur falls fehlen)"
composer show drupal/gin_toolbar >/dev/null 2>&1 || COMPOSER_MEMORY_LIMIT=-1 composer require drupal/gin_toolbar:^1 -W --no-interaction
composer show drupal/admin_toolbar >/dev/null 2>&1 || COMPOSER_MEMORY_LIMIT=-1 composer require drupal/admin_toolbar:^3 -W --no-interaction

echo "[init] Core/UX-Module aktivieren"
$DRUSH en -y jsonapi basic_auth dblog gin gin_toolbar admin_toolbar admin_toolbar_tools r4032login || true
$DRUSH theme:enable gin -y || true
$DRUSH cset -y system.theme admin gin || true
$DRUSH cset -y system.site page.403 "/user/login" || true
$DRUSH cset -y system.site page.front "/admin/content" || true

echo "[init] Custom-Module aktivieren"
for M in jfcamp_api jfcamp_public_api jfcamp_matching; do
  [ -d "web/modules/custom/$M" ] && $DRUSH en -y "$M"
done

echo "[init] Bundles/Fields/Displays (Seed)"
[ -f "/opt/drupal/scripts/ensure-bundles.php" ] && $DRUSH php:script /opt/drupal/scripts/ensure-bundles.php || true

echo "[init] Rollen & Rechte (API-User etc.)"
[ -f "/opt/drupal/scripts/jf-roles.sh" ] && bash /opt/drupal/scripts/jf-roles.sh || true

echo "[init] Config exportieren"
$DRUSH cex -y

echo "[init] Cache leeren"
$DRUSH cr -y

echo "[done] Init abgeschlossen."
