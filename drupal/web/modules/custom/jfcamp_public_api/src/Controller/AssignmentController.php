<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

class AssignmentController extends ControllerBase {

  /**
   * GET /api/zuweisungen?code=ABC123
   * Response: { ok: true, zuweisungen: [{ slot_index, workshop: { id, ext_id, title, room, description } }] }
   *
   * Liest den Teilnehmer über field_code und gibt die Workshop-Zuweisungen
   * aus dem Feld field_zugewiesen (Entity-Referenzen) in Delta-Reihenfolge zurück.
   * Räume kommen vom Workshop (field_room). Kurzbeschreibung aus field_kurzbeschreibung (falls vorhanden).
   */
  public function getByCode(Request $request): JsonResponse {
    $code = trim((string) $request->query->get('code', ''));
    if ($code === '') {
      return new JsonResponse(['ok' => false, 'error' => 'Code erforderlich'], 400);
    }

    // Teilnehmer laden
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('field_code', $code)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      // Lieber leere Liste als 404 für bessere UX
      return new JsonResponse(['ok' => true, 'zuweisungen' => []]);
    }

    /** @var \Drupal\node\Entity\Node $teilnehmer */
    $teilnehmer = Node::load(reset($ids));

    if (
      !$teilnehmer ||
      !$teilnehmer->hasField('field_zugewiesen') ||
      $teilnehmer->get('field_zugewiesen')->isEmpty()
    ) {
      return new JsonResponse(['ok' => true, 'zuweisungen' => []]);
    }

    $out = [];
    $refs = $teilnehmer->get('field_zugewiesen')->referencedEntities(); // Delta=Slotindex

    foreach ($refs as $delta => $workshop) {
      if ($workshop instanceof Node && $workshop->bundle() === 'workshop') {
        $room = '';
        if ($workshop->hasField('field_room') && !$workshop->get('field_room')->isEmpty()) {
          $room = (string) $workshop->get('field_room')->value;
        }

        $extId = '';
        if ($workshop->hasField('field_ext_id') && !$workshop->get('field_ext_id')->isEmpty()) {
          $extId = (string) $workshop->get('field_ext_id')->value;
        }

        $desc = '';
        if ($workshop->hasField('field_kurzbeschreibung') && !$workshop->get('field_kurzbeschreibung')->isEmpty()) {
          // Plaintext – einfache Variante: Markup entfernen
          $desc = strip_tags($workshop->get('field_kurzbeschreibung')->value ?? '');
        }

        $out[] = [
          'slot_index' => (int) $delta,
          'workshop' => [
            'id'          => $workshop->uuid(),
            'ext_id'      => $extId,
            'title'       => $workshop->label(),
            'room'        => $room,
            'description' => $desc,
          ],
        ];
      }
    }

    return new JsonResponse(['ok' => true, 'zuweisungen' => $out]);
  }
}
