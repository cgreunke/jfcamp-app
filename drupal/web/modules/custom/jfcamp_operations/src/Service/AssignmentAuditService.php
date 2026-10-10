<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Persists an immutable audit trail for manual assignment changes.
 */
final class AssignmentAuditService {

  public const TABLE = 'jfcamp_assignment_audit';

  public function __construct(
    private Connection $database,
    private AccountProxyInterface $currentUser,
    private TimeInterface $time,
  ) {}

  /**
   * Records one manual assignment change in the caller's DB transaction.
   */
  public function recordChange(
    NodeInterface $participant,
    int $slot,
    NodeInterface $oldWorkshop,
    NodeInterface $newWorkshop,
  ): int {
    if ($slot < 1) {
      throw new \InvalidArgumentException('Audit-Slot muss größer als 0 sein.');
    }

    $base = $this->currentAppliedRun();

    $id = $this->database->insert(self::TABLE)
      ->fields([
        'created_at' => $this->time->getCurrentTime(),
        'uid' => (int) $this->currentUser->id(),
        'participant_id' => (int) $participant->id(),
        'participant_uuid' => $participant->uuid(),
        'participant_code' => $this->fieldValue($participant, 'field_code'),
        'slot' => $slot,
        'old_workshop_id' => (int) $oldWorkshop->id(),
        'old_workshop_uuid' => $oldWorkshop->uuid(),
        'old_workshop_label' => $oldWorkshop->label(),
        'new_workshop_id' => (int) $newWorkshop->id(),
        'new_workshop_uuid' => $newWorkshop->uuid(),
        'new_workshop_label' => $newWorkshop->label(),
        'base_matching_run_id' => $base['id'] ?? NULL,
        'base_matching_hash' => $base['hash'] ?? NULL,
      ])
      ->execute();

    return (int) $id;
  }

  /**
   * Returns the most recent audit rows for one participant.
   */
  public function recentForParticipant(int $participantId, int $limit = 20): array {
    $limit = max(1, min(100, $limit));
    $result = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->condition('participant_id', $participantId)
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();

    return array_map(
      static fn (object $row): array => (array) $row,
      iterator_to_array($result),
    );
  }


  /**
   * Returns the current assignment base and number of later manual changes.
   */
  public function getCurrentAssignmentStatus(): array {
    $base = $this->currentAppliedRun();

    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a', ['id']);

    if ($base) {
      $query->condition('base_matching_run_id', $base['id']);
      if (!empty($base['hash'])) {
        $query->condition('base_matching_hash', $base['hash']);
      }
    }
    else {
      $query->isNull('base_matching_run_id');
    }

    return [
      'base_run_id' => $base['id'] ?? NULL,
      'base_hash' => $base['hash'] ?? NULL,
      'manual_changes' => (int) $query->countQuery()->execute()->fetchField(),
    ];
  }

  /**
   * Returns recent audit rows across all participants.
   */
  public function recent(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $result = $this->database->select(self::TABLE, 'a')
      ->fields('a')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();

    return array_map(
      static fn (object $row): array => (array) $row,
      iterator_to_array($result),
    );
  }

  /**
   * Finds the currently applied matching run without mutating matching state.
   */
  private function currentAppliedRun(): ?array {
    if (!$this->database->schema()->tableExists('jfcamp_matching_run')) {
      return NULL;
    }

    $row = $this->database->select('jfcamp_matching_run', 'r')
      ->fields('r', ['id', 'applied_hash'])
      ->condition('apply_status', 'applied')
      ->orderBy('applied_at', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$row) {
      return NULL;
    }

    $hash = isset($row['applied_hash']) ? (string) $row['applied_hash'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
      $hash = '';
    }

    return [
      'id' => (int) $row['id'],
      'hash' => $hash !== '' ? $hash : NULL,
    ];
  }

  private function fieldValue(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $node->get($field)->value);
  }

}
