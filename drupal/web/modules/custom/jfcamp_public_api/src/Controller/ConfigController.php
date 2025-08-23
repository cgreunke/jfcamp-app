<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;

/**
 * Liefert die Matching-Konfiguration für das öffentliche Vue-Formular.
 *
 * Antwort-Format:
 * {
 *   "ok": true,
 *   "field_num_wuensche": 3,
 *   "max_wishes": 3,          // Alias für das Frontend (Kompatibilität)
 *   "workshops": [ { "id": "uuid", "ext_id": "W01", "title": "..." }, ... ]
 * }
 */
class ConfigController extends ControllerBase {

  /**
   * GET /api/config
   */
  public function getConfig(): JsonResponse {
    try {
      $num_wishes = $this->loadNumWuensche();
      $workshops  = $this->loadWorkshops();

      return new JsonResponse([
        'ok' => TRUE,
        'field_num_wuensche' => $num_wishes,
        'max_wishes' => $num_wishes, // FE liest wahlweise diesen Schlüssel
        'workshops' => $workshops,
      ], 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'Content-Type' => 'application/json; charset=UTF-8',
      ]);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Config konnte nicht geladen werden: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Liest die Anzahl der Wünsche aus dem zuletzt geänderten, veröffentlichten matching_config.
   */
  protected function loadNumWuensche(): int {
    $storage = $this->entityTypeManager()->getStorage('node');

    $ids = $storage->getQuery()
      ->condition('type', 'matching_config')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->accessCheck(TRUE)
      ->execute();

    if (!empty($ids)) {
      /** @var \Drupal\node\Entity\Node $cfg */
      $cfg = $storage->load(reset($ids));
      if ($cfg && $cfg->hasField('field_num_wuensche')) {
        $val = (int) $cfg->get('field_num_wuensche')->value;
        if ($val > 0) {
          return $val;
        }
      }
    }

    // Fallback (zur Sicherheit)
    return 3;
  }

  /**
   * Liefert veröffentlichte Workshops als [{ id, ext_id, title }].
   */
  protected function loadWorkshops(): array {
    $storage = $this->entityTypeManager()->getStorage('node');

    $ids = $storage->getQuery()
      ->condition('type', 'workshop')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('title', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadMultiple($ids);
    $out = [];

    foreach ($nodes as $node) {
      $ext = ($node->hasField('field_ext_id') && !$node->get('field_ext_id')->isEmpty())
        ? (string) $node->get('field_ext_id')->value
        : '';

      $out[] = [
        'id'     => $node->uuid(),
        'ext_id' => $ext,
        'title'  => $node->label() ?? '(ohne Titel)',
      ];
    }

    return $out;
  }

}
