<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Safely edits participant wishes and records an immutable audit trail.
 */
final class WishManagementService {

  public const TABLE = 'jfcamp_wish_audit';
  public const MAX_WISHES = 5;

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private Connection $database,
    private LockBackendInterface $lock,
    private AccountProxyInterface $currentUser,
    private TimeInterface $time,
  ) {}

  /**
   * Returns the current participant and ordered wish state.
   */
  public function getState(int $participantId): array {
    $participant = $this->loadParticipant($participantId);
    $wish = $this->loadWish($participantId);

    $wishIds = [];
    $wishes = [];
    if ($wish && $wish->hasField('field_wuensche')) {
      foreach ($wish->get('field_wuensche') as $item) {
        if (!$item->target_id) {
          continue;
        }
        $workshop = $item->entity;
        if (!$workshop instanceof NodeInterface) {
          continue;
        }
        $wishIds[] = (int) $workshop->id();
        $wishes[] = [
          'id' => (int) $workshop->id(),
          'label' => $workshop->label(),
        ];
      }
    }

    return [
      'participant' => $participant,
      'wish' => $wish,
      'wish_ids' => $wishIds,
      'wishes' => $wishes,
      'max_wishes' => self::MAX_WISHES,
    ];
  }

  /**
   * Returns published workshops as select options.
   */
  public function getWorkshopOptions(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'workshop')
      ->condition('status', 1)
      ->sort('title')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($ids) as $workshop) {
      if ($workshop instanceof NodeInterface) {
        $options[(int) $workshop->id()] = $workshop->label();
      }
    }
    return $options;
  }

  /**
   * Saves the ordered wish list atomically and returns change metadata.
   */
  public function saveWishes(int $participantId, array $workshopIds): array {
    $workshopIds = array_values(array_map('intval', array_filter(
      $workshopIds,
      static fn ($value): bool => (int) $value > 0,
    )));

    if (count($workshopIds) > self::MAX_WISHES) {
      throw new \InvalidArgumentException(sprintf('Es sind höchstens %d Wünsche erlaubt.', self::MAX_WISHES));
    }
    if (count($workshopIds) !== count(array_unique($workshopIds))) {
      throw new \InvalidArgumentException('Ein Workshop darf nur einmal in der Wunschliste vorkommen.');
    }

    $lockName = 'jfcamp_operations:wishes:' . $participantId;
    if (!$this->lock->acquire($lockName, 30.0)) {
      throw new \RuntimeException('Die Wünsche werden gerade an anderer Stelle geändert. Bitte erneut versuchen.');
    }

    try {
      $participant = $this->loadParticipant($participantId);
      $state = $this->getState($participantId);
      $oldIds = $state['wish_ids'];

      if ($oldIds === $workshopIds) {
        return [
          'changed' => FALSE,
          'audit_id' => NULL,
          'wish_id' => $state['wish']?->id(),
        ];
      }

      $workshops = $this->loadValidWorkshops($workshopIds);
      $transaction = $this->database->startTransaction();

      try {
        $wish = $state['wish'];
        if (!$wish instanceof NodeInterface) {
          $code = $participant->hasField('field_code')
            ? trim((string) $participant->get('field_code')->value)
            : (string) $participant->id();
          $wish = $this->entityTypeManager->getStorage('node')->create([
            'type' => 'wunsch',
            'title' => 'Wünsche ' . $code,
            'status' => 1,
            'field_teilnehmer' => ['target_id' => (int) $participant->id()],
          ]);
        }

        $items = array_map(
          static fn (int $id): array => ['target_id' => $id],
          $workshopIds,
        );
        $wish->set('field_wuensche', $items);
        $wish->setPublished(TRUE);
        $wish->save();

        $oldSnapshot = $this->snapshot($oldIds);
        $newSnapshot = $this->snapshotFromLoaded($workshopIds, $workshops);

        $auditId = (int) $this->database->insert(self::TABLE)
          ->fields([
            'created_at' => $this->time->getCurrentTime(),
            'uid' => (int) $this->currentUser->id(),
            'participant_id' => (int) $participant->id(),
            'participant_uuid' => $participant->uuid(),
            'participant_code' => $participant->hasField('field_code')
              ? trim((string) $participant->get('field_code')->value)
              : '',
            'wish_node_id' => (int) $wish->id(),
            'old_wishes_json' => json_encode($oldSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'new_wishes_json' => json_encode($newSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
          ])
          ->execute();

        return [
          'changed' => TRUE,
          'audit_id' => $auditId,
          'wish_id' => (int) $wish->id(),
        ];
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Returns recent immutable wish audit rows.
   */
  public function recentAudit(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }

    $result = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();

    $rows = [];
    foreach ($result as $row) {
      $item = (array) $row;
      $item['old_wishes'] = $this->decodeSnapshot((string) $item['old_wishes_json']);
      $item['new_wishes'] = $this->decodeSnapshot((string) $item['new_wishes_json']);
      $rows[] = $item;
    }
    return $rows;
  }

  private function loadParticipant(int $participantId): NodeInterface {
    $participant = $this->entityTypeManager->getStorage('node')->load($participantId);
    if (!$participant instanceof NodeInterface || $participant->bundle() !== 'teilnehmer') {
      throw new \InvalidArgumentException('Teilnehmer wurde nicht gefunden.');
    }
    return $participant;
  }

  private function loadWish(int $participantId): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wunsch')
      ->condition('status', 1)
      ->condition('field_teilnehmer.target_id', $participantId)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }
    $wish = $storage->load(reset($ids));
    return $wish instanceof NodeInterface ? $wish : NULL;
  }

  /** @return array<int, NodeInterface> */
  private function loadValidWorkshops(array $workshopIds): array {
    if (!$workshopIds) {
      return [];
    }
    $loaded = $this->entityTypeManager->getStorage('node')->loadMultiple($workshopIds);
    $result = [];
    foreach ($workshopIds as $id) {
      $workshop = $loaded[$id] ?? NULL;
      if (!$workshop instanceof NodeInterface || $workshop->bundle() !== 'workshop' || !$workshop->isPublished()) {
        throw new \InvalidArgumentException(sprintf('Workshop #%d ist nicht verfügbar.', $id));
      }
      $result[$id] = $workshop;
    }
    return $result;
  }

  private function snapshot(array $ids): array {
    if (!$ids) {
      return [];
    }
    $loaded = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    return $this->snapshotFromLoaded($ids, $loaded);
  }

  private function snapshotFromLoaded(array $ids, array $loaded): array {
    $snapshot = [];
    foreach ($ids as $id) {
      $workshop = $loaded[$id] ?? NULL;
      if (!$workshop instanceof NodeInterface) {
        continue;
      }
      $snapshot[] = [
        'id' => (int) $workshop->id(),
        'uuid' => $workshop->uuid(),
        'label' => $workshop->label(),
      ];
    }
    return $snapshot;
  }

  private function decodeSnapshot(string $json): array {
    if ($json === '') {
      return [];
    }
    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\JsonException) {
      return [];
    }
  }

}
