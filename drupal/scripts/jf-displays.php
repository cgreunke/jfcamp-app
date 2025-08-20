<?php

/**
 * Repariert / setzt Form- & View-Displays für die JF‑Bundles.
 * Idempotent: setzt nur Komponenten für Felder, die existieren.
 *
 * Aufruf (im Container):
 *   drush scr /opt/drupal/scripts/jf-displays.php
 */

use Drupal\field\Entity\FieldConfig;

/**
 * Prüft, ob ein Feld im Bundle existiert.
 */
function jf_field_exists_for_bundle(string $bundle, string $field): bool {
  return FieldConfig::loadByName('node', $bundle, $field) !== NULL;
}

/**
 * Setzt Form- und View-Display‑Komponenten für ein Node‑Bundle.
 *
 * @param string $bundle
 *   Der Maschinenname des Bundles.
 * @param array $components
 *   Liste von [field_name, form_widget, view_formatter].
 */
function jf_apply_displays_for_bundle(string $bundle, array $components): void {
  /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $repo */
  $repo = \Drupal::service('entity_display.repository');

  // Form display
  $form = $repo->getFormDisplay('node', $bundle, 'default');
  $w = 0;
  foreach ($components as [$field, $widget, $formatter]) {
    if (jf_field_exists_for_bundle($bundle, $field)) {
      $form->setComponent($field, ['type' => $widget, 'weight' => $w++]);
    }
  }
  $form->save();

  // View display
  $view = $repo->getViewDisplay('node', $bundle, 'default');
  $w = 0;
  foreach ($components as [$field, $widget, $formatter]) {
    if (jf_field_exists_for_bundle($bundle, $field)) {
      $view->setComponent($field, [
        'type'   => $formatter,
        'label'  => 'above',
        'weight' => $w++,
      ]);
    }
  }
  $view->save();
}

// =================== Konfiguration der Bundles ===================

$bundles = [
  'workshop' => [
    ['field_maximale_plaetze', 'number',            'number_integer'],
    ['field_ext_id',           'string_textfield',  'string'],
  ],
  'teilnehmer' => [
    ['field_code',             'string_textfield',  'string'],
    ['field_vorname',          'string_textfield',  'string'],
    ['field_name',             'string_textfield',  'string'],
    ['field_regionalverband',  'string_textfield',  'string'],
    ['field_zugewiesen',       'entity_reference_autocomplete_tags', 'entity_reference_label'],
  ],
  'wunsch' => [
    ['field_teilnehmer',       'entity_reference_autocomplete',      'entity_reference_label'],
    ['field_wuensche',         'entity_reference_autocomplete_tags', 'entity_reference_label'],
  ],
  'matching_config' => [
    ['field_num_wuensche',       'number',            'number_integer'],
    ['field_num_zuteilung',      'number',            'number_integer'],
    ['field_seed',               'string_textfield',  'string'],
    ['field_slicing_mode',       'options_select',    'list_default'],
    ['field_slicing_value',      'number',            'number_integer'],
    ['field_topk_equals_slots',  'boolean_checkbox',  'boolean'],
    ['field_weight_p1',          'number',            'number_decimal'],
    ['field_weight_p2',          'number',            'number_decimal'],
    ['field_weight_p3',          'number',            'number_decimal'],
    ['field_weight_p4',          'number',            'number_decimal'],
    ['field_weight_p5',          'number',            'number_decimal'],
  ],
];

// Anwenden
foreach ($bundles as $bundle => $components) {
  jf_apply_displays_for_bundle($bundle, $components);
  print "Displays gesetzt für bundle: {$bundle}\n";
}

print "JF Displays – fertig.\n";
