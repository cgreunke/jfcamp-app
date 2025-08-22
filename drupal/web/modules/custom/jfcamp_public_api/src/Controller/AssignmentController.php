<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

class AssignmentController extends ControllerBase {

  /**
   * GET /api/zuweisungen?code=ABC123
   * Antwort: { ok: true, zuweisungen: [...] }
   */
  public function getByCode(Request $request): JsonResponse {
    $code = trim((string)$request->query->get('code', ''));
    if ($code === '') {
      return new JsonResponse(['ok' => false, 'error' => 'Code erforderlich'], 400);
    }

    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('field_code', $code)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return new JsonResponse(['ok' => false, 'error' => 'Nicht gefunden'], 404);
    }

    /** @var \Drupal\node\Entity\Node $teilnehmer */
    $teilnehmer = Node::load(reset($ids));

    // TODO: Feldnamen anpassen (z. B. field_zugewiesen als JSON/Entity-Refs)
    $zuweisungen = [];
    if ($teilnehmer->hasField('field_zugewiesen') && !$teilnehmer->get('field_zugewiesen')->isEmpty()) {
      // Beispiel: Freitext/JSON
      $raw = (string)$teilnehmer->get('field_zugewiesen')->value;
      $zuweisungen = json_decode($raw, true) ?: [$raw];
    }

    return new JsonResponse(['ok' => true, 'zuweisungen' => $zuweisungen]);
  }
}
