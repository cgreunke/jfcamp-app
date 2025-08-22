<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public API: Wünsche absenden & Zuweisungen abrufen.
 *
 * Routen (Beispiel):
 *  - POST /api/wunsch        -> submit()
 *  - GET  /api/zuweisungen   -> assignments()
 * Optional:
 *  - GET  /api/form-config   -> formConfig()   (max_wishes + workshops)
 */
class WunschController extends ControllerBase {

  protected EntityTypeManagerInterface $etm;

  public function __construct(EntityTypeManagerInterface $etm) {
    $this->etm = $etm;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * POST /api/wunsch
   * Body: {"code":"CODE100","wuensche":["Schauspiel","Social Media","Graffiti"]}
   */
  public function submit(Request $request): JsonResponse {
    $data = json_decode($request->getContent() ?: '[]', true);
    if (!is_array($data)) {
      return new JsonResponse(['ok' => false, 'error' => 'Ungültiger Body'], 400);
    }

    $code = isset($data['code']) ? trim((string) $data['code']) : '';
    $wishLabels = isset($data['wuensche']) && is_array($data['wuensche']) ? array_values($data['wuensche']) : [];

    if ($code === '') {
      return new JsonResponse(['ok' => false, 'error' => 'Code fehlt'], 400);
    }
    if (empty($wishLabels)) {
      return new JsonResponse(['ok' => false, 'error' => 'Mindestens ein Wunsch erforderlich'], 400);
    }

    // Konfiguration aus matching_config laden (max. erlaubte Wünsche + ggf. erlaubte Workshops)
    $config = $this->getFormConfig();
    $max = (int) ($config['max_wishes'] ?? 3);
    $allowed = (array) ($config['workshops'] ?? []);
    $max = max(1, $max);

    // Normalisieren
    $wishLabels = array_values(array_unique(array_map('strval', $wishLabels)));

    if (count($wishLabels) > $max) {
      return new JsonResponse(['ok' => false, 'error' => "Es sind maximal {$max} Wünsche erlaubt"], 400);
    }

    if (!empty($allowed)) {
      $illegal = array_diff($wishLabels, $allowed);
      if (!empty($illegal)) {
        return new JsonResponse(['ok' => false, 'error' => 'Ungültige Workshop-Auswahl'], 400);
      }
    }

    // Teilnehmer über Code finden (CT: teilnehmer, Feld: field_code)
    $teilnehmerNid = $this->loadTeilnehmerByCode($code);
    if (!$teilnehmerNid) {
      return new JsonResponse(['ok' => false, 'error' => 'Teilnehmer-Code unbekannt'], 404);
    }

    // Workshop-Labels -> Workshop-Node IDs (CT: workshop, Titel-Match)
    $workshopNids = $this->mapWorkshopLabelsToNids($wishLabels);
    if (count($workshopNids) !== count($wishLabels)) {
      return new JsonResponse(['ok' => false, 'error' => 'Ein oder mehrere Workshops wurden nicht gefunden'], 400);
    }

    // Wunsch-Node erstellen/aktualisieren (CT: wunsch)
    try {
      $this->createOrUpdateWunschNode($teilnehmerNid, $workshopNids);
    }
    catch (\Throwable $e) {
      $this->getLogger('jfcamp_public_api')->error('Wunsch speichern fehlgeschlagen: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['ok' => false, 'error' => 'Speichern nicht möglich'], 500);
    }

    return new JsonResponse(['ok' => true]);
  }

  /**
   * GET /api/zuweisungen?code=CODE100
   * Antwort: {"ok":true,"zuweisungen":[...] }
   *
   * Aktuell liefert Placeholders – hier kannst du später deine echte Quelle anschließen.
   */
  public function assignments(Request $request): JsonResponse {
    $code = trim((string) $request->query->get('code', ''));
    if ($code === '') {
      return new JsonResponse(['ok' => false, 'error' => 'Code fehlt'], 400);
    }

    try {
      $result = $this->fetchAssignmentsForCode($code);
    }
    catch (\Throwable $e) {
      $this->getLogger('jfcamp_public_api')->error('Zuweisungen fehlgeschlagen: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['ok' => false, 'error' => 'Abruf nicht möglich'], 500);
    }

    return new JsonResponse(['ok' => true, 'zuweisungen' => array_values($result)]);
  }

  /**
   * OPTIONAL: GET /api/form-config
   * Gibt "max_wishes" und "workshops" (Labels) zurück – praktisch fürs Vue-Formular.
   */
  public function formConfig(): JsonResponse {
    return new JsonResponse($this->getFormConfig() + ['ok' => true]);
  }

  // ----------------------------------------------------------------------------
  // Internals
  // ----------------------------------------------------------------------------

  /**
   * Konfiguration für das Formular.
   * - Content-Type: matching_config
   *   - Integer-Feld: field_max_wuensche
   * - Workshops: bevorzugt Content-Type "workshop" (Titel); Fallback: Taxonomy "workshops"
   */
  protected function getFormConfig(): array {
    $max = 3;
    $workshops = [];

    // max_wishes
    try {
      $ids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'matching_config')
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 1)
        ->execute();
      if ($ids) {
        /** @var \Drupal\node\Entity\Node $cfg */
        $cfg = Node::load(reset($ids));
        if ($cfg && $cfg->hasField('field_max_wuensche') && !$cfg->get('field_max_wuensche')->isEmpty()) {
          $max = (int) $cfg->get('field_max_wuensche')->value;
        }
      }
    } catch (\Throwable $e) {}

    // Workshops (CT: workshop)
    try {
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
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
    } catch (\Throwable $e) {}

    return [
      'max_wishes' => max(1, (int) $max),
      'workshops' => array_values(array_unique($workshops)),
    ];
  }

  /**
   * Teilnehmer-NID via Code (CT teilnehmer, Feld field_code).
   */
  protected function loadTeilnehmerByCode(string $code): ?int {
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('status', 1)
      ->condition('field_code', $code)
      ->range(0, 1)
      ->execute();

    return $nids ? (int) reset($nids) : null;
  }

  /**
   * Workshop-Labels -> Workshop-NIDs (Titel-Match, CT workshop).
   * Bewahrt die Reihenfolge der Eingabe (Priorität).
   */
  protected function mapWorkshopLabelsToNids(array $labels): array {
    if (empty($labels)) {
      return [];
    }

    $map = [];
    // Alle möglichen Treffer auf einmal laden (Case-sensitive Titel – bei Bedarf strtolower compare)
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'workshop')
      ->condition('status', 1)
      ->condition('title', $labels, 'IN')
      ->execute();

    if ($nids) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $map[$node->label()] = (int) $node->id();
      }
    }

    $ordered = [];
    foreach ($labels as $label) {
      if (isset($map[$label])) {
        $ordered[] = $map[$label];
      }
    }
    return $ordered;
  }

  /**
   * Erzeugt/aktualisiert den Wunsch-Node (CT wunsch).
   * - field_teilnehmer: EntityReference -> teilnehmer
   * - field_wuensche:   EntityReference (Mehrfach) -> workshop (Reihenfolge = Priorität)
   */
  protected function createOrUpdateWunschNode(int $teilnehmerNid, array $workshopNids): void {
    // Existierenden Wunsch-Node zu diesem Teilnehmer finden
    $wunschNid = null;
    $existing = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'wunsch')
      ->condition('field_teilnehmer', $teilnehmerNid)
      ->range(0, 1)
      ->execute();
    if ($existing) {
      $wunschNid = (int) reset($existing);
    }

    if ($wunschNid) {
      /** @var \Drupal\node\Entity\Node $wunsch */
      $wunsch = Node::load($wunschNid);
      if (!$wunsch) {
        throw new \RuntimeException('Wunsch konnte nicht geladen werden');
      }
    } else {
      $wunsch = Node::create([
        'type' => 'wunsch',
        'status' => 1,
        'title' => 'Wunsch von Teilnehmer #' . $teilnehmerNid,
      ]);
      $wunsch->setOwnerId(0); // Optional: Besitzer "Anonymous" oder API-User setzen
    }

    // Referenzen setzen
    $wunsch->set('field_teilnehmer', $teilnehmerNid);

    // field_wuensche als geordnete Referenzliste schreiben
    $refs = [];
    foreach ($workshopNids as $nid) {
      $refs[] = ['target_id' => (int) $nid];
    }
    $wunsch->set('field_wuensche', $refs);

    $wunsch->save();
  }

  /**
   * Zuweisungen für Code abrufen (Platzhalter).
   * -> Hier später deine echte Quelle andocken (z. B. Feld am Teilnehmer oder eigener CT).
   */
  protected function fetchAssignmentsForCode(string $code): array {
    // Beispiel: Wenn am Teilnehmer ein Feld "field_zuweisungen" (Ref auf workshop) existiert:
    // $tnid = $this->loadTeilnehmerByCode($code);
    // if ($tnid) {
    //   $t = Node::load($tnid);
    //   if ($t && $t->hasField('field_zuweisungen') && !$t->get('field_zuweisungen')->isEmpty()) {
    //     $ids = array_map(fn($i) => $i['target_id'], $t->get('field_zuweisungen')->getValue());
    //     $nodes = Node::loadMultiple($ids);
    //     return array_map(fn($n) => $n->label(), $nodes);
    //   }
    // }
    return [];
  }

}
