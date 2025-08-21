<?php
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;

/**
 * Einrichtung der Content-Types, Felder, Displays und einer Default-Matching-Konfiguration.
 * Idempotent: mehrfach ausführbar ohne Duplikate.
 */

/* ------------------------- Helper ------------------------- */
function ensure_bundle(string $type, string $label): void {
  if (!NodeType::load($type)) {
    $nt = NodeType::create(["type"=>$type,"name"=>$label]);
    $nt->save();
  }
}

function ensure_field_storage(
  string $entity,
  string $field_name,
  string $type,
  array $settings = [],
  int $cardinality = -1,
  bool $translatable = false
): void {
  if (!FieldStorageConfig::loadByName($entity, $field_name)) {
    FieldStorageConfig::create([
      "field_name"   => $field_name,
      "entity_type"  => $entity,
      "type"         => $type,
      "settings"     => $settings,
      "cardinality"  => $cardinality,
      "translatable" => $translatable,
    ])->save();
  }
}

function ensure_field(
  string $entity,
  string $bundle,
  string $field_name,
  string $label,
  array $settings = []
): void {
  if (!FieldConfig::loadByName($entity, $bundle, $field_name)) {
    FieldConfig::create([
      "field_name"  => $field_name,
      "entity_type" => $entity,
      "bundle"      => $bundle,
      "label"       => $label,
      "settings"    => $settings,
    ])->save();
  }
}

function field_exists_for_bundle(string $bundle, string $field): bool {
  return FieldConfig::loadByName("node", $bundle, $field) !== NULL;
}

/* ------------------------- Bundles ------------------------- */
ensure_bundle("workshop","Workshop");
ensure_bundle("teilnehmer","Teilnehmer");
ensure_bundle("wunsch","Wunsch");
ensure_bundle("matching_config","Matching Config");

/* ------------------------- Felder -------------------------- */
/* Workshop */
ensure_field_storage("node","field_maximale_plaetze","integer",[],1,false);
ensure_field("node","workshop","field_maximale_plaetze","Maximale Plätze");

ensure_field_storage("node","field_ext_id","string",["max_length"=>128],1,false);
ensure_field("node","workshop","field_ext_id","Externe ID");

/* Teilnehmer */
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

/* Wunsch */
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

/* Matching Config – Grundsettings */
ensure_field_storage("node","field_num_wuensche","integer",[],1,false);
ensure_field("node","matching_config","field_num_wuensche","Anzahl Wünsche");

ensure_field_storage("node","field_num_zuteilung","integer",[],1,false);
ensure_field("node","matching_config","field_num_zuteilung","Anzahl Zuteilungen (Slots)");

ensure_field_storage("node","field_seed","string",["max_length"=>32],1,false);
ensure_field("node","matching_config","field_seed","Seed (optional, leer = Zufall)");

/* Matching Config – Slicing */
$allowed = [
  "off"      => "Off (kein Slicing)",
  "relative" => "Relative (z.B. 50% pro Slot)",
  "fixed"    => "Fixed (absolute Deckel pro Slot)",
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
ensure_field("node","matching_config","field_slicing_value","Slicing Value");

ensure_field_storage("node","field_topk_equals_slots","boolean",[],1,false);
ensure_field("node","matching_config","field_topk_equals_slots","Top-K = Slots");

/* Matching Config – Gewichtungen (float) */
foreach ([1,2,3,4,5] as $i) {
  ensure_field_storage("node","field_weight_p{$i}","float",[],1,false);
  ensure_field("node","matching_config","field_weight_p{$i}","Gewicht Prio {$i}");
}

/* ---------------------- Default Matching-Node ---------------------- */
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

/* ------------------------- Displays (Form & View) ------------------------- */
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

print "ensure-bundles.php: done\n";
