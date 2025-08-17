<?php

namespace Drupal\jfcamp_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class WunschController extends ControllerBase {

  public function teilnehmerId(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), true) ?: [];
    $code = trim((string)($data['code'] ?? ''));
    if ($code === '') {
      return new JsonResponse(['ok' => false, 'error' => 'Code erforderlich'], 400);
    }

    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('field_code', $code)
      ->range(0, 1)->execute();

    if (!$ids) {
      return new JsonResponse(['ok' => false, 'id' => null], 404);
    }

    $node = Node::load(reset($ids));
    return new JsonResponse(['ok' => true, 'id' => $node->uuid()]);
  }

  /** vormals submit(): jetzt wunsch(), passend zur Route */
  public function wunsch(Request $request): JsonResponse {
    $ip = $request->getClientIp() ?: '0.0.0.0';
    $flood = \Drupal::service('flood');
    $key = 'jfcamp_wunsch_submit_' . $ip;
    if (!$flood->isAllowed($key, 5, 60)) {
      return new JsonResponse(['ok' => false, 'error' => 'Zu viele Versuche. Bitte kurz warten.'], 429);
    }
    $flood->register($key);

    $data = json_decode($request->getContent(), true) ?: [];
    $code = isset($data['code']) ? trim((string) $data['code']) : '';
    $workshopIds = array_values(array_filter(array_map('strval', $data['workshop_ids'] ?? [])));

    if ($code === '' || empty($workshopIds)) {
      return new JsonResponse(['ok' => false, 'error' => 'Code und mindestens ein Workshop sind erforderlich.'], 400);
    }

    // Teilnehmer per Code finden
    $tn_ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('field_code', $code)
      ->range(0, 1)->execute();
    if (!$tn_ids) return new JsonResponse(['ok' => false, 'error' => 'Teilnehmercode ungültig.'], 403);
    $tn_id = (int) reset($tn_ids);

    // Konfig lesen: Anzahl Wünsche
    $num = 3;
    $cfg_q = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('changed', 'DESC')->range(0, 1)->execute();
    if ($cfg_q) {
      $cfg = Node::load((int) reset($cfg_q));
      if ($cfg && $cfg->hasField('field_num_wuensche')) {
        $num = max(1, (int) $cfg->get('field_num_wuensche')->value);
      }
    }

    // Workshops deduplizieren & auf max. Anzahl kürzen
    $workshopIds = array_slice(array_unique($workshopIds), 0, $num);

    // UUIDs → NIDs validieren
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $byUuid = $storage->loadByProperties(['uuid' => $workshopIds]);
    $validNids = [];
    foreach ($byUuid as $ws) if ($ws->bundle() === 'workshop') $validNids[] = $ws->id();
    if (count($validNids) !== count($workshopIds)) {
      return new JsonResponse(['ok' => false, 'error' => 'Ein oder mehrere Workshops ungültig.'], 400);
    }

    // Upsert: 1 Wunsch-Node pro Teilnehmer
    $wq = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'wunsch')
      ->condition('field_teilnehmer', $tn_id)
      ->range(0, 1)->execute();
    $wunsch = $wq ? Node::load((int) reset($wq)) : Node::create([
      'type' => 'wunsch',
      'title' => 'Wünsche ' . $code,
      'status' => 0,
      'uid' => 0,
    ]);
    if ($wunsch->hasField('field_teilnehmer')) {
      $wunsch->set('field_teilnehmer', ['target_id' => $tn_id]);
    } else {
      return new JsonResponse(['ok'=>false,'error'=>'Feld field_teilnehmer fehlt am Typ wunsch.'],500);
    }
    if ($wunsch->hasField('field_wuensche')) {
      $wunsch->set('field_wuensche', array_map(fn($nid)=>['target_id'=>$nid], $validNids));
    } else {
      return new JsonResponse(['ok'=>false,'error'=>'Feld field_wuensche fehlt am Typ wunsch.'],500);
    }
    $wunsch->save();

    return new JsonResponse(['ok' => true, 'wunsch_uuid' => $wunsch->uuid()], 200);
  }
}
