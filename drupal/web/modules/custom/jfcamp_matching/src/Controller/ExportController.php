<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends ControllerBase {

  private function cfg(): array {
    $c = \Drupal::config('jfcamp_matching.settings');
    return [
      'participant_bundle' => $c->get('participant_bundle') ?? 'teilnehmer',
      'workshop_bundle' => $c->get('workshop_bundle') ?? 'workshop',
      'assignment_mode' => $c->get('assignment_mode') ?? 'auto',
      'slot_fields_prefix' => $c->get('slot_fields_prefix') ?? 'field_slot_',
      'num_slots' => (int) ($c->get('num_slots') ?? 3),
      'assigned_field' => $c->get('assigned_field') ?? 'field_zugewiesen',
    ];
  }

  private function getAssigned($participant, array $cfg): array {
    $items = [];
    $mode = $cfg['assignment_mode'];
    if ($mode === 'auto') {
      $defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $cfg['participant_bundle']);
      $mode = isset($defs[$cfg['slot_fields_prefix'].'1']) ? 'per_slot' : 'multi';
    }
    if ($mode === 'per_slot') {
      for ($i = 1; $i <= $cfg['num_slots']; $i++) {
        $f = $cfg['slot_fields_prefix'].$i;
        if ($participant->hasField($f) && !$participant->get($f)->isEmpty()) {
          $items[] = ['slot' => $i, 'wid' => (int) $participant->get($f)->target_id];
        }
      }
    } else {
      $f = $cfg['assigned_field'];
      if ($participant->hasField($f) && !$participant->get($f)->isEmpty()) {
        $slot = 1;
        foreach ($participant->get($f) as $ref) {
          $items[] = ['slot' => $slot++, 'wid' => (int) $ref->target_id];
        }
      }
    }
    return $items;
  }

  /** Alle Slots – CSV */
  public function attendanceAll(): StreamedResponse {
    $cfg = $this->cfg();
    $headers = ['slot','workshop_title','workshop_id','teilnehmer_code','vorname','name','regionalverband'];

    $rows = $this->collectRowsFromDrupal($cfg);
    return $this->streamCsv('matching-slots.csv', $headers, $rows, sort: true);
  }

  /** Einzelner Slot – CSV */
  public function attendanceSlot(int $slot): StreamedResponse {
    $cfg = $this->cfg();
    $headers = ['slot','workshop_title','workshop_id','teilnehmer_code','vorname','name','regionalverband'];

    $rows = array_values(array_filter($this->collectRowsFromDrupal($cfg), fn($r) => (int)$r[0] === (int)$slot));
    return $this->streamCsv('matching-slot-'.$slot.'.csv', $headers, $rows, sort: true);
  }

  /** Regionen – CSV */
  public function regions(): StreamedResponse {
    $cfg = $this->cfg();
    // region, code, vorname, name, slot, ws_title, ws_id
    $rowsAll = $this->collectRowsFromDrupal($cfg);
    $rows = array_map(fn($r) => [$r[6], $r[3], $r[4], $r[5], $r[0], $r[1], $r[2]], $rowsAll);
    $headers = ['regionalverband','teilnehmer_code','vorname','name','slot','workshop_title','workshop_id'];
    return $this->streamCsv('matching-regions.csv', $headers, $rows, sort: true);
  }

  // ---- Helpers ----

  /** liefert [slot, ws_title, ws_id, code, first, last, region] */
  private function collectRowsFromDrupal(array $cfg): array {
    $nids = \Drupal::entityQuery('node')->condition('type', $cfg['participant_bundle'])->accessCheck(FALSE)->execute();
    if (!$nids) { return []; }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $participants = $storage->loadMultiple($nids);

    $rows = [];
    foreach ($participants as $p) {
      $first = $p->hasField('field_vorname') ? (string) $p->get('field_vorname')->value : '';
      $last  = $p->hasField('field_name') ? (string) $p->get('field_name')->value : '';
      $code  = $p->hasField('field_code') ? (string) $p->get('field_code')->value : '';
      $region= $p->hasField('field_regionalverband') ? (string) $p->get('field_regionalverband')->value : '';
      foreach ($this->getAssigned($p, $cfg) as $a) {
        $ws = $storage->load($a['wid']);
        if (!$ws) { continue; }
        $rows[] = [
          (int) $a['slot'],
          (string) $ws->label(),
          (string) $ws->id(),
          $code, $first, $last, $region,
        ];
      }
    }
    return $rows;
  }

  private function streamCsv(string $filename, array $headers, array $rows, bool $sort = false): StreamedResponse {
    if ($sort) {
      usort($rows, function($a, $b) {
        $slotA = (int) $a[0]; $slotB = (int) $b[0];
        if ($slotA !== $slotB) return $slotA <=> $slotB;
        $wa = (string) ($a[1] ?? ''); $wb = (string) ($b[1] ?? '');
        if ($wa !== $wb) return strcmp($wa, $wb);
        $la = (string) ($a[5] ?? ''); $lb = (string) ($b[5] ?? '');
        if ($la !== $lb) return strcmp($la, $lb);
        $fa = (string) ($a[4] ?? ''); $fb = (string) ($b[4] ?? '');
        return strcmp($fa, $fb);
      });
    }

    $response = new StreamedResponse(function() use ($headers, $rows) {
      $out = fopen('php://output', 'w');
      fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
      fputcsv($out, $headers);
      foreach ($rows as $r) { fputcsv($out, $r); }
      fclose($out);
    });
    $response->headers->set('Content-Type','text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition','attachment; filename="'.$filename.'"');
    $response->setPrivate();
    return $response;
  }
}
