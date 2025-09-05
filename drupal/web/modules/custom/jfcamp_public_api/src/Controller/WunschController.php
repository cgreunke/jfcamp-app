<?php

namespace Drupal\jfcamp_public_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

/**
 * Wunsch-API: Speichern und Lesen von priorisierten Workshop-Wünschen.
 */
class WunschController extends ControllerBase {

  /**
   * POST /api/wunsch
   * Body: { "code": "ABC123", "wuensche": ["<uuid oder titel>", ...] }
   */
  public function submit(Request $request): JsonResponse {
    // Flood/Rate-Limit: max 10 Events pro 5s pro IP → 429 + Retry-After=3
    $ip = \Drupal::request()->getClientIp();
    $flood = \Drupal::flood();
    $key = 'jfcamp_wunsch_post_' . $ip;
    $window = 5;  // Sekunden
    $limit  = 10; // max 10 Ereignisse pro 5s/IP
    if (!$flood->isAllowed($key, $limit, $window)) {
      return new JsonResponse(
        ['error' => 'Zu viele Anfragen. Bitte kurz warten und erneut versuchen.'],
        429,
        ['Retry-After' => '3']
      );
    }
    $flood->register($key, $window);
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

    // Konfiguration aus matching_config laden (max. erlaubte Wünsche + optional Whitelist).
    $config = $this->getFormConfig();
    $max = (int) ($config['max_wishes'] ?? 3);
    $allowed = (array) ($config['workshops'] ?? []);
    $max = max(1, $max);

    // Normalisieren (Strings, Duplikate entfernen, Reihenfolge beibehalten).
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

    // Werte können Titel ODER UUIDs sein → erst UUIDs mappen, dann Fallback Titel.
    $workshopNids = $this->mapWorkshopUuidsToNids($wishLabels);
    if (count($workshopNids) !== count($wishLabels)) {
      $workshopNids = $this->mapWorkshopLabelsToNids($wishLabels);
    }

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

    return new JsonResponse(['ok' => true, 'message' => 'Wünsche gespeichert. Danke!']);
  }

  /**
   * GET /api/wunsch?code=ABC123
   * Gibt die gespeicherten Wünsche (priorisierte Liste) des Teilnehmers zurück.
   * Response: { ok: true, wuensche: [{ id, ext_id, title }] }
   */
  public function read(Request $request): JsonResponse {
    $code = trim((string) $request->query->get('code', ''));
    if ($code === '') {
      return new JsonResponse(['ok' => false, 'error' => 'Code fehlt'], 400);
    }

    $teilnehmerNid = $this->loadTeilnehmerByCode($code);
    if (!$teilnehmerNid) {
      return new JsonResponse(['ok' => true, 'wuensche' => []]);
    }

    // Wunsch-Node zu diesem Teilnehmer holen
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'wunsch')
      ->condition('field_teilnehmer', $teilnehmerNid)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return new JsonResponse(['ok' => true, 'wuensche' => []]);
    }

    /** @var \Drupal\node\Entity\Node $node */
    $node = Node::load(reset($ids));
    $out = [];

    // Helper zum Pushen inkl. ext_id
    $pushWorkshop = function (Node $w) use (&$out) {
      if ($w->bundle() !== 'workshop') { return; }
      $ext = ($w->hasField('field_ext_id') && !$w->get('field_ext_id')->isEmpty())
        ? (string) $w->get('field_ext_id')->value
        : '';
      $out[] = [
        'id'     => $w->uuid(),
        'ext_id' => $ext,
        'title'  => $w->label(),
      ];
    };

    if ($node->hasField('field_wuensche') && !$node->get('field_wuensche')->isEmpty()) {
      foreach ($node->get('field_wuensche')->referencedEntities() as $w) {
        if ($w instanceof Node) { $pushWorkshop($w); }
      }
    }
    elseif ($node->hasField('field_wuensche_json') && !empty($node->get('field_wuensche_json')->value)) {
      $nids = json_decode((string) $node->get('field_wuensche_json')->value, true) ?: [];
      if (!empty($nids)) {
        $workshops = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
        $byId = [];
        foreach ($workshops as $w) {
          if ($w instanceof Node && $w->bundle() === 'workshop') {
            $byId[$w->id()] = $w;
          }
        }
        foreach ($nids as $nid) {
          if (isset($byId[$nid])) {
            $pushWorkshop($byId[$nid]);
          }
        }
      }
    }

    return new JsonResponse(['ok' => true, 'wuensche' => $out]);
  }

  /**
   * Lädt die aktuellste matching_config und gibt max_wishes + optional Workshop-Whitelist zurück.
   */
  protected function getFormConfig(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    $out = [
      'max_wishes' => 3,
      'workshops' => [],
    ];

    if ($ids) {
      /** @var \Drupal\node\Entity\Node $cfg */
      $cfg = $storage->load(reset($ids));
      if ($cfg) {
        if ($cfg->hasField('field_num_wuensche') && !$cfg->get('field_num_wuensche')->isEmpty()) {
          $out['max_wishes'] = (int) $cfg->get('field_num_wuensche')->value;
        }
        // Optional: Whitelist als UUIDs (falls Feld existiert).
        if ($cfg->hasField('field_allowed_workshops') && !$cfg->get('field_allowed_workshops')->isEmpty()) {
          $vals = [];
          foreach ($cfg->get('field_allowed_workshops')->referencedEntities() as $n) {
            if ($n instanceof Node && $n->bundle() === 'workshop') {
              $vals[] = $n->uuid();
            }
          }
          $out['workshops'] = $vals;
        }
      }
    }

    return $out;
  }

  /** Workshop-UUIDs -> NIDs */
  protected function mapWorkshopUuidsToNids(array $uuids): array {
    if (empty($uuids)) {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = $storage->loadByProperties(['uuid' => $uuids]);
    $byUuid = [];
    foreach ($nodes as $node) {
      if ($node instanceof Node && $node->bundle() === 'workshop') {
        $byUuid[$node->uuid()] = (int) $node->id();
      }
    }
    $ordered = [];
    foreach ($uuids as $u) {
      if (isset($byUuid[$u])) {
        $ordered[] = $byUuid[$u];
      }
    }
    return $ordered;
  }

  /** Workshop-Titel -> NIDs */
  protected function mapWorkshopLabelsToNids(array $labels): array {
    if (empty($labels)) {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'workshop')
      ->execute();
    if (empty($ids)) {
      return [];
    }
    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadMultiple($ids);
    $byTitle = [];
    foreach ($nodes as $n) {
      $t = trim($n->label());
      if ($t !== '') {
        $byTitle[$t] = (int) $n->id();
      }
    }
    $out = [];
    foreach ($labels as $t) {
      $t = trim((string) $t);
      if ($t !== '' && isset($byTitle[$t])) {
        $out[] = $byTitle[$t];
      }
    }
    return $out;
  }

  /** Teilnehmer über field_code finden. */
  protected function loadTeilnehmerByCode(string $code): ?int {
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->condition('field_code', $code)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return null;
    }
    return (int) reset($ids);
  }

  /**
   * Wunsch-Node anlegen oder aktualisieren.
   *
   * Speichert die Reihenfolge der Wünsche in einem Feld, z.B. field_wuensche (Entity Reference)
   * oder als JSON in field_wuensche_json – passe ggf. an dein Content-Model an.
   */
  protected function createOrUpdateWunschNode(int $teilnehmerNid, array $workshopNids): void {
    // Bestehenden Wunsch zu Teilnehmer suchen:
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'wunsch')
      ->condition('field_teilnehmer', $teilnehmerNid)
      ->range(0, 1)
      ->execute();

    if ($ids) {
      /** @var \Drupal\node\Entity\Node $node */
      $node = Node::load(reset($ids));
    } else {
      // Teilnehmer-Name für den Titel laden; Fallback auf NID.
      $teilnehmerNode = Node::load($teilnehmerNid);
      $teilnehmerName = $teilnehmerNode ? $teilnehmerNode->label() : $teilnehmerNid;

      $node = Node::create([
        'type' => 'wunsch',
        'title' => 'Wunsch ' . $teilnehmerName,
        'field_teilnehmer' => $teilnehmerNid,
      ]);
    }

    // Beispiel: Referenzfeld field_wuensche (mehrwertig) in priorisierter Reihenfolge
    if ($node->hasField('field_wuensche')) {
      $node->set('field_wuensche', array_map(fn($nid) => ['target_id' => $nid], $workshopNids));
    }
    elseif ($node->hasField('field_wuensche_json')) {
      $node->set('field_wuensche_json', json_encode($workshopNids));
    }

    $node->save();
  }

}
