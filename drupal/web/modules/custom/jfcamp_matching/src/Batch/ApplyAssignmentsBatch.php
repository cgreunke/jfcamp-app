<?php

namespace Drupal\jfcamp_matching\Batch;

use Drupal\node\Entity\Node;

/**
 * Apply / Reset von Zuteilungen auf Teilnehmer-Nodes.
 *
 * Erwartete Struktur (Apply):
 *   by_participant: { "<teilnehmer-uuid>": { "1": "<workshop-uuid>", "2": "<workshop-uuid>", ... } }
 *
 * Modi:
 *  - per_slot: schreibt in field_slot_{slot}
 *  - multi:    schreibt Reihenfolge in ein Mehrfach-Referenzfeld (assigned_field)
 *  - auto:     erkennt per_slot, wenn field_slot_1 existiert, sonst multi
 */
final class ApplyAssignmentsBatch {

  /** Batch-Definition für Apply */
  public static function build(array $config, array $by_participant): array {
    $ops = [];
    $chunks = array_chunk($by_participant, 150, TRUE);
    foreach ($chunks as $chunk) {
      $ops[] = [[static::class, 'applyChunk'], [$config, $chunk]];
    }
    return [
      'title' => t('Zuteilungen werden angewendet …'),
      'operations' => $ops,
      'finished' => [static::class, 'finishApply'],
      'init_message' => t('Starte Batch …'),
      'progress_message' => t('Verarbeite @current von @total'),
      'error_message' => t('Fehler beim Anwenden der Zuteilungen.'),
    ];
  }

  /** Batch-Definition für Reset */
  public static function buildReset(array $config): array {
    return [
      'title' => t('Zuteilungen werden zurückgesetzt …'),
      'operations' => [
        [[static::class, 'resetChunk'], [$config, NULL]],
      ],
      'finished' => [static::class, 'finishReset'],
      'init_message' => t('Starte Zurücksetzen …'),
      'progress_message' => t('Lösche Zuweisungen …'),
      'error_message' => t('Fehler beim Zurücksetzen der Zuteilungen.'),
    ];
  }

  public static function applyChunk(array $config, array $chunk, array &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $p_bundle = (string) ($config['participant_bundle'] ?? 'teilnehmer');
    $w_bundle = (string) ($config['workshop_bundle'] ?? 'workshop');
    $mode = (string) ($config['assignment_mode'] ?? 'auto');
    $prefix = (string) ($config['slot_fields_prefix'] ?? 'field_slot_');
    $numSlots = (int) ($config['num_slots'] ?? 3);
    $multiField = (string) ($config['assigned_field'] ?? 'field_zugewiesen');

    // auto erkennen
    if ($mode === 'auto') {
      $defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $p_bundle);
      $mode = isset($defs[$prefix . '1']) ? 'per_slot' : 'multi';
    }

    foreach ($chunk as $participantUuid => $slotMap) {
      $tns = $storage->loadByProperties(['uuid' => $participantUuid]);
      /** @var \Drupal\node\Entity\Node|null $p */
      $p = $tns ? reset($tns) : NULL;
      if (!$p || $p->bundle() !== $p_bundle) {
        continue;
      }

      if ($mode === 'per_slot') {
        // leeren
        for ($s = 1; $s <= $numSlots; $s++) {
          $field = $prefix . $s;
          if ($p->hasField($field)) {
            $p->set($field, NULL);
          }
        }
        // schreiben
        foreach ($slotMap as $slotStr => $workshopUuid) {
          $s = (int) $slotStr;
          if ($s < 1 || $s > $numSlots) { continue; }
          $wNodes = $storage->loadByProperties(['uuid' => $workshopUuid]);
          /** @var \Drupal\node\Entity\Node|null $w */
          $w = $wNodes ? reset($wNodes) : NULL;
          if (!$w || $w->bundle() !== $w_bundle) { continue; }
          $field = $prefix . $s;
          if ($p->hasField($field)) {
            $p->set($field, $w->id());
          }
        }
      }
      else { // multi
        if ($p->hasField($multiField)) {
          $items = [];
          ksort($slotMap, SORT_NUMERIC);
          foreach ($slotMap as $slotStr => $workshopUuid) {
            $wNodes = $storage->loadByProperties(['uuid' => $workshopUuid]);
            $w = $wNodes ? reset($wNodes) : NULL;
            if ($w && $w->bundle() === $w_bundle) {
              $items[] = ['target_id' => $w->id()];
            }
          }
          $p->set($multiField, $items);
        }
      }

      $p->save();
      $context['results']['updated'] = ($context['results']['updated'] ?? 0) + 1;
    }
  }

  public static function resetChunk(array $config, $unused, array &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $p_bundle = (string) ($config['participant_bundle'] ?? 'teilnehmer');
    $mode = (string) ($config['assignment_mode'] ?? 'auto');
    $prefix = (string) ($config['slot_fields_prefix'] ?? 'field_slot_');
    $numSlots = (int) ($config['num_slots'] ?? 3);
    $multiField = (string) ($config['assigned_field'] ?? 'field_zugewiesen');

    if ($mode === 'auto') {
      $defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $p_bundle);
      $mode = isset($defs[$prefix . '1']) ? 'per_slot' : 'multi';
    }

    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', $p_bundle)->execute();
    if (!$ids) { return; }
    $nodes = $storage->loadMultiple($ids);

    foreach ($nodes as $n) {
      if ($mode === 'per_slot') {
        for ($s = 1; $s <= $numSlots; $s++) {
          $field = $prefix . $s;
          if ($n->hasField($field)) {
            $n->set($field, NULL);
          }
        }
      } else {
        if ($n->hasField($multiField)) {
          $n->set($multiField, []);
        }
      }
      $n->save();
      $context['results']['reset'] = ($context['results']['reset'] ?? 0) + 1;
    }
  }

  public static function finishApply($success, array $results, array $operations): void {
    if ($success) {
      $n = (int) ($results['updated'] ?? 0);
      \Drupal::messenger()->addStatus(t('Zuweisungen angewendet: @n Teilnehmer aktualisiert.', ['@n' => $n]));
    } else {
      \Drupal::messenger()->addError(t('Batch ist mit Fehlern beendet.'));
    }
  }

  public static function finishReset($success, array $results, array $operations): void {
    if ($success) {
      $n = (int) ($results['reset'] ?? 0);
      \Drupal::messenger()->addStatus(t('Zuweisungen zurückgesetzt: @n Teilnehmer aktualisiert.', ['@n' => $n]));
    } else {
      \Drupal::messenger()->addError(t('Zurücksetzen mit Fehlern beendet.'));
    }
  }
}
