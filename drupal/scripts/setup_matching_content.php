<?php

/**
 * Drush-Skript: Erstellt Content-Types & Felder für das Matching
 *
 * Aufruf (im Container):
 *   cd /opt/drupal && vendor/bin/drush scr scripts/setup_matching_content.php
 *
 * Hinweis:
 *  - Standardmäßig werden 5 Wunsch-Felder (field_wunsch_1..3)
 *    und 3 Zuteilungs-Felder (field_workshop_1..2) angelegt.
 *  - Passe $NUM_WISHES / $NUM_ASSIGN unten bei Bedarf an.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;

$NUM_WISHES = 5;   // field_wunsch_1..3
$NUM_ASSIGN = 3;   // field_workshop_1..2

/** Helpers */
function ensure_module_enabled(array $modules) {
  /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
  $installer = \Drupal::service('module_installer');
  $to_install = [];
  foreach ($modules as $m) {
    if (!\Drupal::moduleHandler()->moduleExists($m)) {
      $to_install[] = $m;
    }
  }
  if ($to_install) {
    $installer->install($to_install);
    echo "Enabled modules: " . implode(', ', $to_install) . PHP_EOL;
  }
}

function ensure_node_type($type, $label, $description = '') {
  $exists = NodeType::load($type);
  if (!$exists) {
    $nt = NodeType::create([
      'type' => $type,
      'name' => $label,
      'description' => $description,
    ]);
    $nt->save();
    echo "Created node type: $type ($label)\n";
  } else {
    echo "Node type already exists: $type\n";
  }
}

function ensure_field_storage($entity_type, $field_name, $type, array $settings = [], $cardinality = 1) {
  $storage = FieldStorageConfig::loadByName($entity_type, $field_name);
  if (!$storage) {
    $storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
      'translatable' => FALSE,
    ]);
    $storage->save();
    echo "Created field storage: $entity_type.$field_name ($type)\n";
  } else {
    echo "Field storage already exists: $entity_type.$field_name\n";
  }
}

function ensure_field_instance($entity_type, $bundle, $field_name, $label, array $settings = [], array $widget_settings = []) {
  $config = FieldConfig::loadByName($entity_type, $bundle, $field_name);
  if (!$config) {
    $config = FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
      'settings' => $settings,
      'required' => FALSE,
    ]);
    $config->save();
    echo "Created field instance: $entity_type.$bundle.$field_name\n";
  } else {
    echo "Field instance already exists: $entity_type.$bundle.$field_name\n";
  }

  // Minimal Form- & View-Display sicherstellen
  /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form */
  $form = \Drupal::entityTypeManager()
    ->getStorage('entity_form_display')
    ->load("$entity_type.$bundle.default") ?? \Drupal::entityTypeManager()
    ->getStorage('entity_form_display')
    ->create(['targetEntityType' => $entity_type, 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE]);

  if ($form->getComponent($field_name) === NULL) {
    $form->setComponent($field_name, ['type' => 'default'] + $widget_settings);
    $form->save();
  }

  /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $view */
  $view = \Drupal::entityTypeManager()
    ->getStorage('entity_view_display')
    ->load("$entity_type.$bundle.default") ?? \Drupal::entityTypeManager()
    ->getStorage('entity_view_display')
    ->create(['targetEntityType' => $entity_type, 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE]);

  if ($view->getComponent($field_name) === NULL) {
    $view->setComponent($field_name, ['type' => 'entity_reference_label', 'label' => 'above']);
    $view->save();
  }
}

function ensure_api_role_permissions() {
  $role_id = 'api';
  $role = Role::load($role_id);
  if (!$role) {
    $role = Role::create(['id' => $role_id, 'label' => 'API']);
    $role->save();
    echo "Created role: api\n";
  }
  $perms = [
    'access content',
    // Anzeigen von publish. Inhalt
    'view published content',
    // Bearbeiten von Teilnehmer-Inhalten (für JSON:API PATCH):
    'edit any teilnehmer content',
  ];
  $granted = [];
  foreach ($perms as $p) {
    if (!$role->hasPermission($p)) {
      $role->grantPermission($p);
      $granted[] = $p;
    }
  }
  if ($granted) {
    $role->save();
    echo "Granted permissions to role 'api': " . implode(', ', $granted) . PHP_EOL;
  } else {
    echo "Role 'api' already had required permissions.\n";
  }
}

/** 1) Module aktivieren (JSON:API, Serialization, optional Basic Auth) */
ensure_module_enabled(['jsonapi', 'serialization', 'basic_auth']);

/** 2) Node-Types anlegen */
ensure_node_type('workshop', 'Workshop', 'Angebotener Workshop inkl. Maximalplätze');
ensure_node_type('teilnehmer', 'Teilnehmer', 'Person, die Workshops zugeteilt bekommt');
ensure_node_type('wunsch', 'Wunsch', 'Wunschliste eines Teilnehmers');
ensure_node_type('matching_config', 'Matching Config', 'Steuert Anzahl Wünsche/Zuteilungen');

/** 3) Felder definieren */
// 3a) Workshop: field_maximale_plaetze (integer)
ensure_field_storage('node', 'field_maximale_plaetze', 'integer', ['min' => 0], 1);
ensure_field_instance('node', 'workshop', 'field_maximale_plaetze', 'Maximale Plätze');

// 3b) Teilnehmer: field_workshop_1..N (entity_reference -> node:workshop)
for ($i = 1; $i <= $NUM_ASSIGN; $i++) {
  $fname = "field_workshop_$i";
  ensure_field_storage('node', $fname, 'entity_reference', [
    'target_type' => 'node',
  ], 1);
  // Target bundle einschränken auf workshop (Handler-Settings)
  $settings = [
    'handler' => 'default:node',
    'handler_settings' => [
      'target_bundles' => ['workshop' => 'workshop'],
      'auto_create' => FALSE,
    ],
  ];
  // Nach create müssen wir die Settings auf der FieldConfig setzen:
  ensure_field_instance('node', 'teilnehmer', $fname, "Workshop $i", $settings);
  // FieldConfig neu laden & speichern, falls settings beim ersten Mal nicht komplett kamen:
  $fc = FieldConfig::loadByName('node', 'teilnehmer', $fname);
  if ($fc) {
    $fc->setSetting('handler', 'default:node');
    $fc->setSetting('handler_settings', [
      'target_bundles' => ['workshop' => 'workshop'],
      'auto_create' => FALSE,
    ]);
    $fc->save();
  }
}

// 3c) Wunsch:
// - field_teilnehmer (ref -> teilnehmer)
ensure_field_storage('node', 'field_teilnehmer', 'entity_reference', [
  'target_type' => 'node',
], 1);
$settings_tn = [
  'handler' => 'default:node',
  'handler_settings' => [
    'target_bundles' => ['teilnehmer' => 'teilnehmer'],
    'auto_create' => FALSE,
  ],
];
ensure_field_instance('node', 'wunsch', 'field_teilnehmer', 'Teilnehmer', $settings_tn);
$fc = FieldConfig::loadByName('node', 'wunsch', 'field_teilnehmer');
if ($fc) {
  $fc->setSetting('handler', 'default:node');
  $fc->setSetting('handler_settings', [
    'target_bundles' => ['teilnehmer' => 'teilnehmer'],
    'auto_create' => FALSE,
  ]);
  $fc->save();
}

// - field_wunsch_1..N (ref -> workshop)
for ($i = 1; $i <= $NUM_WISHES; $i++) {
  $fname = "field_wunsch_$i";
  ensure_field_storage('node', $fname, 'entity_reference', [
    'target_type' => 'node',
  ], 1);
  $settings_ws = [
    'handler' => 'default:node',
    'handler_settings' => [
      'target_bundles' => ['workshop' => 'workshop'],
      'auto_create' => FALSE,
    ],
  ];
  ensure_field_instance('node', 'wunsch', $fname, "Wunsch $i", $settings_ws);
  $fc = FieldConfig::loadByName('node', 'wunsch', $fname);
  if ($fc) {
    $fc->setSetting('handler', 'default:node');
    $fc->setSetting('handler_settings', [
      'target_bundles' => ['workshop' => 'workshop'],
      'auto_create' => FALSE,
    ]);
    $fc->save();
  }
}

// 3d) Matching Config: field_num_wuensche, field_num_zuteilung (integer)
ensure_field_storage('node', 'field_num_wuensche', 'integer', ['min' => 1], 1);
ensure_field_instance('node', 'matching_config', 'field_num_wuensche', 'Anzahl Wünsche');

ensure_field_storage('node', 'field_num_zuteilung', 'integer', ['min' => 1], 1);
ensure_field_instance('node', 'matching_config', 'field_num_zuteilung', 'Anzahl Zuteilungen');

/** 4) API-Rolle & Rechte */
ensure_api_role_permissions();

echo "Done.\n";
