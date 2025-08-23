<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class SlotsController extends ControllerBase {

  /**
   * GET /api/slots
   * Response: { num_zuweisungen: int, slots: [{ index, start, end }] }
   */
  public function list(): JsonResponse {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    $num = 0;
    $starts = [];
    $ends = [];

    if ($ids) {
      $cfg = $storage->load(reset($ids));

      // Kandidaten für die drei Felder (inkl. deines field_num_zuteilung)
      $numCandidates   = ['field_num_zuteilung', 'field_num_zuweisungen', 'field_anzahl_zuteilungen', 'field_anzahl_slots'];
      $startCandidates = ['field_slot_start', 'field_startzeit_slot', 'field_startzeit', 'field_slot_startzeit'];
      $endCandidates   = ['field_slot_end',   'field_endzeit_slot',   'field_endzeit',   'field_slot_endzeit'];

      // Anzahl lesen
      foreach ($numCandidates as $n) {
        if ($cfg->hasField($n) && !$cfg->get($n)->isEmpty()) {
          $num = (int) $cfg->get($n)->value;
          break;
        }
      }

      // Start/Ende lesen (erstes vorhandenes Feld je Gruppe)
      $starts = $this->readMultiText($cfg, $startCandidates);
      $ends   = $this->readMultiText($cfg, $endCandidates);

      // Anzahl robust ableiten (falls num leer)
      $num = max($num, count($starts), count($ends));

      // Zeiten auf HH:MM normalisieren
      $starts = array_map([$this, 'normalizeTime'], $starts);
      $ends   = array_map([$this, 'normalizeTime'], $ends);
    }

    // Slots bauen
    $slots = [];
    for ($i = 0; $i < $num; $i++) {
      $slots[] = [
        'index' => $i,
        'start' => $starts[$i] ?? '',
        'end'   => $ends[$i]   ?? '',
      ];
    }

    return new JsonResponse([
      'num_zuweisungen' => $num,
      'slots' => $slots,
    ]);
  }

  /** Liest das erste vorhandene mehrwertige Textfeld aus Kandidaten. */
  protected function readMultiText($cfg, array $candidates): array {
    foreach ($candidates as $name) {
      if ($cfg->hasField($name) && !$cfg->get($name)->isEmpty()) {
        $out = [];
        foreach ($cfg->get($name)->getValue() as $v) {
          $val = trim((string) ($v['value'] ?? ''));
          if ($val !== '') { $out[] = $val; }
        }
        if (!empty($out)) return $out;
      }
    }
    return [];
  }

  /** Normalisiert auf HH:MM. */
  protected function normalizeTime(string $s): string {
    $s = trim($s);
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $s)) {
      return substr($s, 0, 5);
    }
    $s = preg_replace('/[^\d:]/', '', $s);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
      return sprintf('%02d:%s', (int)$m[1], $m[2]);
    }
    return '';
  }
}
