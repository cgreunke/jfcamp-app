#!/usr/bin/env bash
# init-drupal.sh – einmalig nach der Erstinstallation
set -euo pipefail
cd /opt/drupal
DRUSH=vendor/bin/drush

echo "[init] Composer-Pakete für Admin-UX installieren"
COMPOSER_MEMORY_LIMIT=-1 composer require \
  drupal/gin:^3 \
  drupal/gin_toolbar:^1 \
  drupal/r4032login:^2 \
  drupal/admin_toolbar:^3 \
  -W --no-interaction

echo "[init] Core-Module aktivieren (JSON:API, Auth, UI)"
$DRUSH en -y jsonapi basic_auth dblog field_ui options

echo "[init] Admin-UX & Login konfigurieren"
# Wichtig: gin ist ein THEME, gin_toolbar ist ein MODUL
$DRUSH en -y r4032login admin_toolbar admin_toolbar_tools gin_toolbar
$DRUSH theme:enable gin -y
$DRUSH cset -y system.theme admin gin
$DRUSH cset -y system.site page.403 "/user/login"
$DRUSH cset -y system.site page.front "/admin/content"

echo "[init] Custom-Module aktivieren"
for M in jfcamp_api jfcamp_matching; do
  if [ -d "web/modules/custom/$M" ]; then
    $DRUSH en -y "$M"
  fi
done

echo "[init] Bundles/Felder/Displays anlegen (ensure-bundles.php)"
$DRUSH php:script /opt/drupal/scripts/ensure-bundles.php

echo "[init] Rollen & Rechte (inkl. API-User aus ENV)"
bash /opt/drupal/scripts/jf-roles.sh

echo "[init] Config exportieren"
$DRUSH cex -y

echo "[init] Cache leeren"
$DRUSH cr -y

echo "[done] Init abgeschlossen – danach nur noch Config Mgmt (cim/cex)."
