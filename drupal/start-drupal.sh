#!/usr/bin/env bash
set -euo pipefail

cd /opt/drupal
echo "[start] Drupal Startscript läuft…"

# ===== Env aus Compose =====
DB_HOST="${DRUPAL_DB_HOST:-postgres}"
DB_NAME="${DRUPAL_DB_NAME:-drupal}"
DB_USER="${DRUPAL_DB_USER:-drupal}"
DB_PASS="${DRUPAL_DB_PASS:-drupal}"
SITE_NAME="${DRUPAL_SITE_NAME:-JF Startercamp - App}"
ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
ADMIN_PASS="${DRUPAL_ADMIN_PASS:-admin}"
ADMIN_MAIL="${DRUPAL_ADMIN_MAIL:-admin@example.com}"
TRUSTED="${DRUPAL_TRUSTED_HOSTS:-^localhost$}"
INSTALL_MODE="${DRUPAL_INSTALL:-auto}"   # auto|skip

API_USER="${DRUPAL_API_USER:-apiuser}"
API_PASS="${DRUPAL_API_PASS:-apipassword}"
API_ROLE="${DRUPAL_API_ROLE:-api_writer}"

# ===== 0) Auf Postgres warten =====
echo "[start] Warte auf Postgres ${DB_HOST}/${DB_NAME}…"
for i in {1..60}; do
  if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "select 1" >/dev/null 2>&1; then
    echo "[start] Postgres ist erreichbar."
    break
  fi
  echo "[start] …noch nicht bereit (${i}/60)."
  sleep 2
done

# ===== 1) Composer-Projekt initialisieren =====
if [ ! -f "composer.json" ]; then
  echo "[start] Kein composer.json gefunden – erstelle drupal/recommended-project…"
  composer create-project drupal/recommended-project:^11.0 /opt/drupal
fi

# ===== 2) Dependencies installieren =====
if [ ! -d "vendor" ] || [ -z "$(ls -A vendor || true)" ]; then
  echo "[start] Installiere Composer-Abhängigkeiten…"
  composer install --no-interaction --prefer-dist
fi

# ===== 3) Drush sicherstellen =====
if ! vendor/bin/drush --version >/dev/null 2>&1; then
  echo "[start] Installiere Drush…"
  composer require drush/drush:^13 --no-interaction
fi

# ===== 4) settings.php vorbereiten =====
SETTINGS_DIR="web/sites/default"
SETTINGS_FILE="$SETTINGS_DIR/settings.php"
DEFAULT_SETTINGS="$SETTINGS_DIR/default.settings.php"

if [ ! -f "$SETTINGS_FILE" ]; then
  echo "[start] Erzeuge settings.php…"
  cp "$DEFAULT_SETTINGS" "$SETTINGS_FILE"
  chmod 644 "$SETTINGS_FILE"
fi

# Trusted Hosts
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

# Files & private Pfad
mkdir -p "$SETTINGS_DIR/files" /opt/drupal/private
chown -R www-data:www-data "$SETTINGS_DIR/files" /opt/drupal/private || true
chmod -R 775 "$SETTINGS_DIR/files" /opt/drupal/private || true

if ! grep -q "file_private_path" "$SETTINGS_FILE"; then
  echo "\$settings['file_private_path'] = '/opt/drupal/private';" >> "$SETTINGS_FILE"
  echo "\$settings['file_temp_path'] = '/tmp';" >> "$SETTINGS_FILE"
fi

# ===== 5) Installation (deutsch) =====
if [ "$INSTALL_MODE" != "skip" ]; then
  if ! vendor/bin/drush status --fields=bootstrap --format=list 2>/dev/null | grep -q "Successful"; then
    echo "[start] Drupal nicht installiert – führe Installation (deutsch) aus…"
    for attempt in 1 2 3; do
      if vendor/bin/drush site:install standard -y \
        --db-url="pgsql://${DB_USER}:${DB_PASS}@${DB_HOST}:5432/${DB_NAME}" \
        --site-name="${SITE_NAME}" \
        --account-name="${ADMIN_USER}" \
        --account-pass="${ADMIN_PASS}" \
        --account-mail="${ADMIN_MAIL}" \
        --locale=de; then
        echo "[start] Installation erfolgreich."
        break
      else
        echo "[start] Installation fehlgeschlagen (Versuch ${attempt}/3)."
        sleep 3
      fi
    done
    if ! vendor/bin/drush status --fields=bootstrap --format=list 2>/dev/null | grep -q "Successful"; then
      echo "[start][FATAL] Drupal konnte nicht installiert werden. Prüfe DB & Logs."
      exit 1
    fi
  else
    echo "[start] Drupal bereits installiert."
  fi
else
  echo "[start] INSTALL_MODE=skip – Installation wird übersprungen."
fi

# ===== 6) Sprache hart auf Deutsch setzen & Übersetzungen laden =====
vendor/bin/drush en language locale -y || true
vendor/bin/drush language:add de || true
vendor/bin/drush language:default de || true
vendor/bin/drush cset -y system.site default_langcode de
vendor/bin/drush cset -y system.site langcode de
vendor/bin/drush locale:check || true
vendor/bin/drush locale:update || true

# ===== 7) Pflichtmodule & Custom-Modul aktivieren =====
vendor/bin/drush en jsonapi dblog basic_auth field_ui -y || true
vendor/bin/drush cset -y jsonapi.settings read_only 0 || true

if [ -d "web/modules/custom/jfcamp_api" ]; then
  echo "[start] jfcamp_api gefunden – aktiviere Modul…"
  vendor/bin/drush en jfcamp_api -y || true
fi

# ===== 8) Inhaltstypen + Felder idempotent anlegen =====
vendor/bin/drush ev '
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;

function ensure_bundle(string $type, string $label) {
  if (!NodeType::load($type)) {
    $nt = NodeType::create(["type"=>$type,"name"=>$label]);
    $nt->save();
  }
}
function ensure_field_storage($entity, $field_name, $type, array $settings=[], int $cardinality=-1, bool $translatable=false) {
  if (!FieldStorageConfig::loadByName($entity, $field_name)) {
    FieldStorageConfig::create([
      "field_name"=>$field_name,"entity_type"=>$entity,"type"=>$type,
      "settings"=>$settings,"cardinality"=>$cardinality,"translatable"=>$translatable,
    ])->save();
  }
}
function ensure_field($entity, $bundle, $field_name, $label, array $settings=[]) {
  if (!FieldConfig::loadByName($entity, $bundle, $field_name)) {
    FieldConfig::create([
      "field_name"=>$field_name,"entity_type"=>$entity,"bundle"=>$bundle,
      "label"=>$label,"settings"=>$settings,
    ])->save();
  }
}

/* Bundles */
ensure_bundle("workshop","Workshop");
ensure_bundle("teilnehmer","Teilnehmer");
ensure_bundle("wunsch","Wunsch");
ensure_bundle("matching_config","Matching Config");

/* Workshop: field_maximale_plaetze (Integer) + ext_id (optional) */
ensure_field_storage("node","field_maximale_plaetze","integer",[],1,false);
ensure_field("node","workshop","field_maximale_plaetze","Maximale Plätze");

ensure_field_storage("node","field_ext_id","string",["max_length"=>128],1,false);
ensure_field("node","workshop","field_ext_id","Externe ID");

/* Teilnehmer: Code/Vorname/Nachname/RV/Zuweisung */
ensure_field_storage("node","field_code","string",["max_length"=>128],1,false);
ensure_field("node","teilnehmer","field_code","Code");

ensure_field_storage("node","field_vorname","string",["max_length"=>128],1,false);
ensure_field("node","teilnehmer","field_vorname","Vorname");

ensure_field_storage("node","field_name","string",["max_length"=>128],1,false);
ensure_field("node","teilnehmer","field_name","Nachname");

ensure_field_storage("node","field_regionalverband","string",["max_length"=>128],1,false);
ensure_field("node","teilnehmer","field_regionalverband","Regionalverband");

ensure_field_storage("node","field_zugewiesen","entity_reference",["target_type"=>"node"],-1,false);
ensure_field("node","teilnehmer","field_zugewiesen","Zugewiesene Workshops",[
  "handler"=>"default",
  "handler_settings"=>["target_bundles"=>["workshop"=>"workshop"]]
]);

/* Wunsch: Teilnehmer (einfach), Wünsche (mehrfach, Reihenfolge) */
ensure_field_storage("node","field_teilnehmer","entity_reference",["target_type"=>"node"],1,false);
ensure_field("node","wunsch","field_teilnehmer","Teilnehmer",[
  "handler"=>"default",
  "handler_settings"=>["target_bundles"=>["teilnehmer"=>"teilnehmer"]]
]);

ensure_field_storage("node","field_wuensche","entity_reference",["target_type"=>"node"],-1,false);
ensure_field("node","wunsch","field_wuensche","Wünsche",[
  "handler"=>"default",
  "handler_settings"=>["target_bundles"=>["workshop"=>"workshop"]]
]);

/* Matching Config: Anzahl Wünsche & Zuteilungen */
ensure_field_storage("node","field_num_wuensche","integer",[],1,false);
ensure_field("node","matching_config","field_num_wuensche","Anzahl Wünsche");

ensure_field_storage("node","field_num_zuteilung","integer",[],1,false);
ensure_field("node","matching_config","field_num_zuteilung","Anzahl Zuteilungen");

/* Mindestens eine veröffentlichte Matching-Konfiguration */
$ids = \Drupal::entityQuery("node")->accessCheck(FALSE)
  ->condition("type","matching_config")->condition("status",1)->range(0,1)->execute();
if (empty($ids)) {
  $n = Node::create(["type"=>"matching_config","title"=>"Standard Matching-Konfiguration","status"=>1]);
  $n->set("field_num_wuensche", 5);
  $n->set("field_num_zuteilung", 3);
  $n->save();
}
';

# ===== 9) Displays (Form & View) sicherstellen – Skript schreiben & ausführen =====

vendor/bin/drush php:script web/modules/custom/jfcamp_api/scripts/ensure_displays.php || true

# ===== 10) API-Rolle + User für Matching =====
# Rolle sicherstellen und echte Permissions vergeben (ohne fiktive 'access jsonapi resources')
vendor/bin/drush ev '
use Drupal\user\Entity\Role;
$rid = getenv("DRUPAL_API_ROLE") ?: "api_writer";
$role = Role::load($rid);
if (!$role) { $role = Role::create(["id"=>$rid,"label"=>"API Writer"]); $role->save(); }
$perms = [
  "access content",                // veröffentlichte Nodes lesen
  "edit any teilnehmer content",   // Zuweisungen schreiben
  "access user profiles",          // optional
];
foreach ($perms as $p) { $role->grantPermission($p); }
$role->save();
'

# User anlegen/aktualisieren & Rolle zuweisen
if ! vendor/bin/drush user:information "$API_USER" >/dev/null 2>&1; then
  vendor/bin/drush user:create "$API_USER" --mail="${API_USER}@example.com" --password="$API_PASS"
fi
vendor/bin/drush user:role:add "$API_ROLE" "$API_USER" || true

# CSV-Import-Recht auf Rollen geben (Admin + API-Rolle)
vendor/bin/drush role:perm:add "administrator" "import jfcamp csv" || true
vendor/bin/drush role:perm:add "$API_ROLE" "import jfcamp csv" || true

# ===== 11) Caches leeren & Apache starten =====
vendor/bin/drush cr || true
echo "[start] Starte Apache…"
exec apache2-foreground
