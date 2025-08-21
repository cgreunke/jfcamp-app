<?php
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/** Helpers */
function ensure_bundle(string $type, string $label): void {
  if (!NodeType::load($type)) {
    NodeType::create(['type'=>$type,'name'=>$label])->save();
  }
}
function ensure_field_storage($entity, $field_name, $type, array $settings=[], int $cardinality=-1, bool $translatable=false): void {
  if (!FieldStorageConfig::loadByName($entity, $field_name)) {
    FieldStorageConfig::create([
      'field_name'=>$field_name,
      'entity_type'=>$entity,
      'type'=>$type,
      'settings'=>$settings,
      'cardinality'=>$cardinality,
      'translatable'=>$translatable,
    ])->save();
  }
}
function ensure_field($entity, $bundle, $field_name, $label, array $settings=[]): void {
  if (!FieldConfig::loadByName($entity, $bundle, $field_name)) {
    FieldConfig::create([
      'field_name'=>$field_name,
      'entity_type'=>$entity,
      'bundle'=>$bundle,
      'label'=>$label,
      'settings'=>$settings,
    ])->save();
  }
}

/* Bundles */
ensure_bundle('workshop','Workshop');
ensure_bundle('teilnehmer','Teilnehmer');
ensure_bundle('wunsch','Wunsch');
ensure_bundle('matching_config','Matching Config');

/* Workshop */
ensure_field_storage('node','field_maximale_plaetze','integer',[],1,false);
ensure_field('node','workshop','field_maximale_plaetze','Maximale Plätze');
ensure_field_storage('node','field_ext_id','string',['max_length'=>128],1,false);
ensure_field('node','workshop','field_ext_id','Externe ID');

/* Teilnehmer */
ensure_field_storage('node','field_code','string',['max_length'=>128],1,false);
ensure_field('node','teilnehmer','field_code','Code');
ensure_field_storage('node','field_vorname','string',['max_length'=>128],1,false);
ensure_field('node','teilnehmer','field_vorname','Vorname');
ensure_field_storage('node','field_name','string',['max_length'=>128],1,false);
ensure_field('node','teilnehmer','field_name','Nachname');
ensure_field_storage('node','field_regionalverband','string',['max_length'=>128],1,false);
ensure_field('node','teilnehmer','field_regionalverband','Regionalverband');
ensure_field_storage('node','field_zugewiesen','entity_reference',['target_type'=>'node'],-1,false);
ensure_field('node','teilnehmer','field_zugewiesen','Zugewiesene Workshops',[
  'handler'=>'default',
  'handler_settings'=>['target_bundles'=>['workshop'=>'workshop']],
]);

/* Wunsch */
ensure_field_storage('node','field_teilnehmer','entity_reference',['target_type'=>'node'],1,false);
ensure_field('node','wunsch','field_teilnehmer','Teilnehmer',[
  'handler'=>'default',
  'handler_settings'=>['target_bundles'=>['teilnehmer'=>'teilnehmer']],
]);
ensure_field_storage('node','field_wuensche','entity_reference',['target_type'=>'node'],-1,false);
ensure_field('node','wunsch','field_wuensche','Wünsche',[
  'handler'=>'default',
  'handler_settings'=>['target_bundles'=>['workshop'=>'workshop']],
]);

/* Matching Config */
$allowed = [
  'off' => 'Off (kein Slicing)',
  'relative' => 'Relative (z.B. 50% pro Slot)',
  'fixed' => 'Fixed (absolute Deckel pro Slot)',
];
$fs = FieldStorageConfig::loadByName('node','field_slicing_mode');
if (!$fs) {
  ensure_field_storage('node','field_slicing_mode','list_string',['allowed_values'=>$allowed],1,false);
} else {
  $fs->setSetting('allowed_values', $allowed);
  $fs->save();
}
ensure_field('node','matching_config','field_slicing_mode','Slicing Mode');

ensure_field_storage('node','field_slicing_value','integer',[],1,false);
ensure_field('node','matching_config','field_slicing_value','Slicing Value');
ensure_field_storage('node','field_topk_equals_slots','boolean',[],1,false);
ensure_field('node','matching_config','field_topk_equals_slots','Top-K = Slots');

foreach (['p1','p2','p3','p4','p5'] as $p) {
  ensure_field_storage('node',"field_weight_$p",'float',[],1,false);
  ensure_field('node','matching_config',"field_weight_$p","Gewicht Prio ".strtoupper($p));
}
echo "OK\n";
