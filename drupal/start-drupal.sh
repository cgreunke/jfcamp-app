#!/usr/bin/env bash
set -euo pipefail

# ====================================================================================
# Drupal Startscript (idempotent) – Projekt unter /opt/drupal mit web/ als Docroot
# Beinhaltet: Composer-Projekt-Bootstrap, Drush, Installation (DE), Bundles/Felder,
# Admin-UX (Gin, Admin Toolbar, r4032login), Rollen & Rechte, API-User, JSON:API etc.
# ====================================================================================

echo "[start] Drupal Startscript läuft…"

# ------------------------ Pfade & Defaults ------------------------------------------
PROJECT_ROOT="${DRUPAL_ROOT:-/opt/drupal}"
WEB_ROOT="${PROJECT_ROOT}/web"
cd "$PROJECT_ROOT"

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
API_MAIL="${DRUPAL_API_MAIL:-${API_USER}@example.com}"

# ------------------------ 0) Auf Postgres warten ------------------------------------
echo "[start] Warte auf Postgres ${DB_HOST}/${DB_NAME}…"
for i in {1..60}; do
  if PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -c "select 1" >/dev/null 2>&1; then
    echo "[start] Postgres ist erreichbar."
    break
  fi
  echo "[start] …noch nicht bereit (${i}/60)."
  sleep 2
done

# ------------------------ 1) Composer-Projekt sicherstellen -------------------------
if [ ! -f "composer.json" ]; then
  echo "[start] Kein composer.json gefunden – erstelle drupal/recommended-project…"
  composer create-project drupal/recommended-project:^11.0 "$PROJECT_ROOT"
fi

# Dependencies installieren (falls vendor fehlt/leer)
if [ ! -d "vendor" ] || [ -z "$(ls -A vendor 2>/dev/null || true)" ]; then
  echo "[start] Installiere Composer-Abhängigkeiten…"
  composer install --no-interaction --prefer-dist
fi

# Drush sicherstellen
if ! vendor/bin/drush --version >/dev/null 2>&1; then
  echo "[start] Installiere Drush…"
  composer require drush/drush:^13 --no-interaction
fi

# ------------------------ 2) settings.php & Dateien ---------------------------------
SETTINGS_DIR="${WEB_ROOT}/sites/default"
SETTINGS_FILE="${SETTINGS_DIR}/settings.php"
DEFAULT_SETTINGS="${SETTINGS_DIR}/default.settings.php"

if [ ! -f "$SETTINGS_FILE" ]; then
  echo "[start] Erzeuge settings.php…"
  cp "$DEFAULT_SETTINGS" "$SETTINGS_FILE"
  chmod 644 "$SETTINGS_FILE"
fi

# Trusted Hosts via ENV in settings.php schreiben (nur falls nicht bereits vorhanden)
if ! grep -q "trusted_host_patterns" "$SETTINGS_FILE"; then
  {
    echo ""
    echo "\$settings['trusted_host_patterns'] = ["
    IFS=',' read -ra HOSTS <<< "$TRUSTED"
    for h in "${HOSTS[@]}"; do echo "  '$h',"; done
    echo "];"
  } >> "$SETTINGS_FILE"
fi

# Files & private Pfad
mkdir -p "${SETTINGS_DIR}/files" "${PROJECT_ROOT}/private"
chown -R www-data:www-data "${SETTINGS_DIR}/files" "${PROJECT_ROOT}/private" || true
chmod -R 775 "${SETTINGS_DIR}/files" "${PROJECT_ROOT}/private" || true

if ! grep -q "file_private_path" "$SETTINGS_FILE"; then
  {
    echo "\$settings['file_private_path'] = '${PROJECT_ROOT}/private';"
    echo "\$settings['file_temp_path'] = '/tmp';"
  } >> "$SETTINGS_FILE"
fi

# settings.env.php & settings.local.php ggf. aus /opt/drupal/ befüllen (falls im Image hinterlegt)
if [ -f "/opt/drupal/settings.env.php" ] && [ ! -f "${SETTINGS_DIR}/settings.env.php" ]; then
  cp "/opt/drupal/settings.env.php" "${SETTINGS_DIR}/settings.env.php"
fi
if [ -f "/opt/drupal/settings.local.php" ] && [ ! -f "${SETTINGS_DIR}/settings.local.php" ]; then
  cp "/opt/drupal/settings.local.php" "${SETTINGS_DIR}/settings.local.php"
fi

# ensure includes in settings.php (idempotent)
if ! grep -q "settings.env.php" "${SETTINGS_FILE}"; then
  cat >> "${SETTINGS_FILE}" <<'PHP'
if (file_exists(__DIR__ . '/settings.env.php')) {
  include __DIR__ . '/settings.env.php';
}
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
PHP
fi

# ------------------------ 3) Installation (DE) --------------------------------------
if [ "$INSTALL_MODE" != "skip" ]; then
  if ! vendor/bin/drush status --fields=bootstrap --format=list 2>/dev/null | grep -q "Successful"; then
    echo "[start] Drupal nicht installiert – führe Installation (deutsch) aus…"
    for attempt in 1 2 3; do
      if vendor/bin/drush site:install standard -y \
        --db-url="pgsql://${DRUPAL_DB_USER}:${DRUPAL_DB_PASS}@${DRUPAL_DB_HOST}:5432/${DRUPAL_DB_NAME}" \
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

# Sprache & Übersetzungen
vendor/bin/drush en language locale -y || true
vendor/bin/drush language:add de || true
vendor/bin/drush language:default de -y || true
vendor/bin/drush cset -y system.site default_langcode de
vendor/bin/drush cset -y system.site langcode de
vendor/bin/drush locale:check || true
vendor/bin/drush locale:update || true

# ------------------------ 4) Pflichtmodule & JSON:API -------------------------------
vendor/bin/drush en jsonapi dblog basic_auth field_ui -y || true
vendor/bin/drush cset -y jsonapi.settings read_only 0 || true

# ------------------------ 5) Admin-UX & Login (Gin, r4032login, Admin Toolbar) ------
if ! composer show drupal/gin >/dev/null 2>&1; then
  COMPOSER_MEMORY_LIMIT=-1 composer require \
    drupal/gin:^3 drupal/r4032login:^2 drupal/admin_toolbar:^3 -W --no-interaction
fi

vendor/bin/drush theme:enable gin -y || true
vendor/bin/drush en r4032login admin_toolbar admin_toolbar_tools -y || true
vendor/bin/drush cset system.theme admin gin -y || true
vendor/bin/drush cset system.site page.403 "/user/login" -y || true
vendor/bin/drush cset system.site page.front "/admin/content" -y || true

# ------------------------ 6) Custom-Module aktivieren -------------------------------
if [ -d "${WEB_ROOT}/modules/custom/jfcamp_api" ]; then
  echo "[start] Aktiviere Modul jfcamp_api…"
  vendor/bin/drush en jfcamp_api -y || true
fi
if [ -d "${WEB_ROOT}/modules/custom/jfcamp_matching" ]; then
  echo "[start] Aktiviere Modul jfcamp_matching…"
  vendor/bin/drush en jfcamp_matching -y || true
fi
if [ -d "${WEB_ROOT}/modules/custom/jfcamp_import" ]; then
  echo "[start] Aktiviere Modul jfcamp_import…"
  vendor/bin/drush en jfcamp_import -y || true
fi

# ------------------------ 7) Bundles, Felder, Default Matching-Config ----------------
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
      "field_name"=>$field_name,
      "entity_type"=>$entity,
      "type"=>$type,
      "settings"=>$settings,
      "cardinality"=>$cardinality,
      "translatable"=>$translatable,
    ])->save();
  }
}
function ensure_field($entity, $bundle, $field_name, $label, array $settings=[]) {
  if (!FieldConfig::loadByName($entity, $bundle, $field_name)) {
    FieldConfig::create([
      "field_name"=>$field_name,
      "entity_type"=>$entity,
      "bundle"=>$bundle,
      "label"=>$label,
      "settings"=>$settings,
    ])->save();
  }
}

/* Bundles */
ensure_bundle("workshop","Workshop");
ensure_bundle("teilnehmer","Teilnehmer");
ensure_bundle("wunsch","Wunsch");
ensure_bundle("matching_config","Matching Config");

/* Workshop: Plätze + ext_id */
ensure_field_storage("node","field_maximale_plaetze","integer",[],1,false);
ensure_field("node","workshop","field_maximale_plaetze","Maximale Plätze");

ensure_field_storage("node","field_ext_id","string",["max_length"=>128],1,false);
ensure_field("node","workshop","field_ext_id","Externe ID");

/* Teilnehmer: Stammdaten + Zuweisungen */
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
  "handler_settings"=>["target_bundles"=>["workshop"=>"workshop"]],
]);

/* Wunsch: Teilnehmer (single), Wünsche (multi, Reihenfolge) */
ensure_field_storage("node","field_teilnehmer","entity_reference",["target_type"=>"node"],1,false);
ensure_field("node","wunsch","field_teilnehmer","Teilnehmer",[
  "handler"=>"default",
  "handler_settings"=>["target_bundles"=>["teilnehmer"=>"teilnehmer"]],
]);

ensure_field_storage("node","field_wuensche","entity_reference",["target_type"=>"node"],-1,false);
ensure_field("node","wunsch","field_wuensche","Wünsche",[
  "handler"=>"default",
  "handler_settings"=>["target_bundles"=>["workshop"=>"workshop"]],
]);

/* Matching Config: Grund- & erweiterte Settings */
ensure_field_storage("node","field_num_wuensche","integer",[],1,false);
ensure_field("node","matching_config","field_num_wuensche","Anzahl Wünsche");

ensure_field_storage("node","field_num_zuteilung","integer",[],1,false);
ensure_field("node","matching_config","field_num_zuteilung","Anzahl Zuteilungen (Slots)");

ensure_field_storage("node","field_seed","string",["max_length"=>32],1,false);
ensure_field("node","matching_config","field_seed","Seed (optional, leer = Zufall)");

/* Slicing-Mode */
$allowed = [
  "off" => "Off (kein Slicing)",
  "relative" => "Relative (z.B. 50% pro Slot)",
  "fixed" => "Fixed (absolute Deckel pro Slot)",
];

$fs = FieldStorageConfig::loadByName("node","field_slicing_mode");
if (!$fs) {
  ensure_field_storage("node","field_slicing_mode","list_string",["allowed_values"=>$allowed],1,false);
} else {
  $current = $fs->getSetting("allowed_values");
  $numericKeys = array_keys($current ?? []);
  if (!$current || (count($numericKeys) && is_int($numericKeys[0]))) {
    $fs->setSetting("allowed_values", $allowed);
    $fs->save();
  }
}
ensure_field("node","matching_config","field_slicing_mode","Slicing Mode");

ensure_field_storage("node","field_slicing_value","integer",[],1,false);
ensure_field("node","matching_config","field_slicing_value","Slicing Value (relativ: Prozent, fixed: Anzahl)");

ensure_field_storage("node","field_topk_equals_slots","boolean",[],1,false);
ensure_field("node","matching_config","field_topk_equals_slots","Top-K = Slots (1=yes, 0=no)");

/* Gewichtungen (Float) */
ensure_field_storage("node","field_weight_p1","float",[],1,false);
ensure_field("node","matching_config","field_weight_p1","Gewicht Prio 1");
ensure_field_storage("node","field_weight_p2","float",[],1,false);
ensure_field("node","matching_config","field_weight_p2","Gewicht Prio 2");
ensure_field_storage("node","field_weight_p3","float",[],1,false);
ensure_field("node","matching_config","field_weight_p3","Gewicht Prio 3");
ensure_field_storage("node","field_weight_p4","float",[],1,false);
ensure_field("node","matching_config","field_weight_p4","Gewicht Prio 4");
ensure_field_storage("node","field_weight_p5","float",[],1,false);
ensure_field("node","matching_config","field_weight_p5","Gewicht Prio 5");

/* Mindestens eine veröffentlichte Matching-Konfiguration */
$ids = \Drupal::entityQuery("node")->accessCheck(FALSE)
  ->condition("type","matching_config")
  ->condition("status",1)
  ->range(0,1)
  ->execute();
if (empty($ids)) {
  $n = Node::create([
    "type"=>"matching_config",
    "title"=>"Standard Matching-Konfiguration",
    "status"=>1,
  ]);
  $n->set("field_num_wuensche", 5);
  $n->set("field_num_zuteilung", 3);
  $n->set("field_topk_equals_slots", 1);
  $n->set("field_slicing_mode", "relative");
  $n->set("field_slicing_value", 50);
  $n->set("field_weight_p1", 1.0);
  $n->set("field_weight_p2", 0.8);
  $n->set("field_weight_p3", 0.6);
  $n->set("field_weight_p4", 0.4);
  $n->set("field_weight_p5", 0.2);
  $n->save();
}
'

# ------------------------ 8) Displays (Form & View) ---------------------------------
vendor/bin/drush ev '
use Drupal\field\Entity\FieldConfig;

function field_exists_for_bundle(string $bundle, string $field): bool {
  return FieldConfig::loadByName("node", $bundle, $field) !== NULL;
}

$bundles = [
  "workshop" => [
    ["field_maximale_plaetze", "number",            "number_integer"],
    ["field_ext_id",           "string_textfield",  "string"],
  ],
  "teilnehmer" => [
    ["field_code",             "string_textfield",  "string"],
    ["field_vorname",          "string_textfield",  "string"],
    ["field_name",             "string_textfield",  "string"],
    ["field_regionalverband",  "string_textfield",  "string"],
    ["field_zugewiesen",       "entity_reference_autocomplete_tags", "entity_reference_label"],
  ],
  "wunsch" => [
    ["field_teilnehmer",       "entity_reference_autocomplete",      "entity_reference_label"],
    ["field_wuensche",         "entity_reference_autocomplete_tags", "entity_reference_label"],
  ],
  "matching_config" => [
    ["field_num_wuensche",       "number",            "number_integer"],
    ["field_num_zuteilung",      "number",            "number_integer"],
    ["field_seed",               "string_textfield",  "string"],
    ["field_slicing_mode",       "options_select",    "list_default"],
    ["field_slicing_value",      "number",            "number_integer"],
    ["field_topk_equals_slots",  "boolean_checkbox",  "boolean"],
    ["field_weight_p1",          "number",            "number_decimal"],
    ["field_weight_p2",          "number",            "number_decimal"],
    ["field_weight_p3",          "number",            "number_decimal"],
    ["field_weight_p4",          "number",            "number_decimal"],
    ["field_weight_p5",          "number",            "number_decimal"],
  ],
];

$repo = \Drupal::service("entity_display.repository");

foreach ($bundles as $bundle => $components) {
  $form = $repo->getFormDisplay("node", $bundle, "default");
  $w = 0;
  foreach ($components as [$field, $widget, $formatter]) {
    if (field_exists_for_bundle($bundle, $field)) {
      $form->setComponent($field, ["type" => $widget, "weight" => $w++]);
    }
  }
  $form->save();

  $view = $repo->getViewDisplay("node", $bundle, "default");
  $w = 0;
  foreach ($components as [$field, $widget, $formatter]) {
    if (field_exists_for_bundle($bundle, $field)) {
      $view->setComponent($field, [
        "type"   => $formatter,
        "label"  => "above",
        "weight" => $w++,
      ]);
    }
  }
  $view->save();
}

print "Displays repariert (konsistent zu Schritt 7)\n";
' || true

# ------------------------ 9) Rollen, Rechte, API-User -------------------------------
vendor/bin/drush role:create team "Team" || true
vendor/bin/drush role:create "$API_ROLE" "API Writer" || true

vendor/bin/drush role:perm:add team "access administration pages" || true
vendor/bin/drush role:perm:add team "access toolbar" || true
vendor/bin/drush role:perm:add team "access content overview" || true
vendor/bin/drush role:perm:add team "view published content" || true

for B in workshop teilnehmer wunsch; do
  vendor/bin/drush role:perm:add team "create ${B} content" || true
  vendor/bin/drush role:perm:add team "edit own ${B} content" || true
  vendor/bin/drush role:perm:add team "edit any ${B} content" || true
  vendor/bin/drush role:perm:add team "delete own ${B} content" || true
  vendor/bin/drush role:perm:add team "delete any ${B} content" || true
  vendor/bin/drush role:perm:add team "view ${B} revisions" || true
done

vendor/bin/drush role:perm:add team "import jfcamp csv" || true
vendor/bin/drush role:perm:add team "run jfcamp matching" || true
vendor/bin/drush role:perm:add administrator "import jfcamp csv" || true
vendor/bin/drush role:perm:add administrator "run jfcamp matching" || true

vendor/bin/drush role:perm:add "$API_ROLE" "access content" || true
vendor/bin/drush role:perm:add "$API_ROLE" "access user profiles" || true
vendor/bin/drush role:perm:add "$API_ROLE" "edit any teilnehmer content" || true

if ! vendor/bin/drush user:information "$API_USER" >/dev/null 2>&1; then
  vendor/bin/drush user:create "$API_USER" --mail="$API_MAIL" --password="$API_PASS"
fi
vendor/bin/drush user:role:add "$API_ROLE" "$API_USER" || true

# ------------------------ 10) Cache & Apache ---------------------------------------
vendor/bin/drush cr || true
echo "[start] Starte Apache…"
exec apache2-foreground
