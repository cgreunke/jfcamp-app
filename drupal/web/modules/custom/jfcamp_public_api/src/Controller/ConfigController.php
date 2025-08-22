<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Return public form config for the Vue app.
 */
class ConfigController extends ControllerBase {

  public function get(): JsonResponse {
    $max = 3; // Fallback
    // 1) max_wishes aus letztem veröffentlichten matching_config Node lesen
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids) {
      /** @var \Drupal\node\Entity\Node $cfg */
      $cfg = Node::load(reset($ids));
      // Passe Feldnamen an DEINE Felder an:
      // Beispiel: field_max_wuensche (Integer)
      if ($cfg->hasField('field_max_wuensche') && !$cfg->get('field_max_wuensche')->isEmpty()) {
        $max = (int) $cfg->get('field_max_wuensche')->value;
      }
    }

    // 2) Workshops-Quelle:
    //   a) Taxonomie "workshops" (Beispiel)
    //   b) oder Node-Type "workshop" -> Titel
    $workshops = [];

    // a) Taxonomy
    $tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
      ->condition('vid', 'workshops')
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC')
      ->execute();

    if ($tids) {
      $terms = \Drupal\taxonomy\Entity\Term::loadMultiple($tids);
      foreach ($terms as $term) {
        $workshops[] = $term->label();
      }
    }
    // b) Fallback auf Node-Type "workshop", falls Taxonomie leer:
    if (!$workshops) {
      $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)
        ->condition('type', 'workshop')
        ->condition('status', 1)
        ->sort('title', 'ASC')
        ->execute();
      if ($nids) {
        $nodes = Node::loadMultiple($nids);
        foreach ($nodes as $n) {
          $workshops[] = $n->label();
        }
      }
    }

    return new JsonResponse([
      'ok' => true,
      'max_wishes' => max(1, $max),
      'workshops' => array_values(array_unique($workshops)),
    ]);
  }
}
