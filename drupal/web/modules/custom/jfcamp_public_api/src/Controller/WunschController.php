<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

class WunschController extends ControllerBase {

  /**
   * POST /api/wunsch
   * Body: { "code": "ABC123", "wuensche": ["workshop_a","workshop_b","workshop_c"] }
   */
  public function submit(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), true) ?: [];

    $code = trim((string)($data['code'] ?? ''));
    $wuensche = $data['wuensche'] ?? null;
    if ($code === '' || !is_array($wuensche) || count($wuensche) === 0) {
      return new JsonResponse(['ok' => false, 'error' => 'Code und mindestens ein Wunsch sind erforderlich'], 400);
    }

    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('field_code', $code)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      // einheitliche Fehlermeldung (kein Timing-/Existenz-Leak)
      return new JsonResponse(['ok' => false, 'error' => 'Ungültige Kombination'], 404);
    }

    /** @var \Drupal\node\Entity\Node $teilnehmer */
    $teilnehmer = Node::load(reset($ids));

    // TODO: Passe Feldnamen an deine tatsächlichen Felder an.
    // Beispiel 1: String-JSON in ein freies Textfeld
    if ($teilnehmer->hasField('field_wuensche')) {
      $teilnehmer->set('field_wuensche', json_encode(array_values($wuensche), JSON_UNESCAPED_UNICODE));
    }

    // Beispiel 2: Statusfeld
    if ($teilnehmer->hasField('field_wunsch_abgegeben')) {
      $teilnehmer->set('field_wunsch_abgegeben', 1);
    }

    $teilnehmer->save();

    return new JsonResponse(['ok' => true]);
  }
}
