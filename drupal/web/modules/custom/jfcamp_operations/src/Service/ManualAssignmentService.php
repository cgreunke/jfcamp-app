<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\node\NodeInterface;

/**
 * Safely replaces one participant assignment while preserving slot order.
 */
final class ManualAssignmentService {

  private const PARTICIPANT_BUNDLE = 'teilnehmer';
  private const WORKSHOP_BUNDLE = 'workshop';
  private const MATCHING_CONFIG_BUNDLE = 'matching_config';
  private const FIELD_ASSIGNMENTS = 'field_zugewiesen';
  private const FIELD_CAPACITY = 'field_maximale_plaetze';
  private const FIELD_NUM_ASSIGNMENTS = 'field_num_zuteilung';
  private const LOCK_NAME = 'jfcamp_operations_manual_assignment';

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private Connection $database,
    private LockBackendInterface $lock,
    private AssignmentAuditService $assignmentAudit,
  ) {}

  /**
   * Returns participant state used by the edit form.
   */
  public function getParticipantState(int $participantId): array {
    $participant = $this->loadParticipant($participantId);
    $assignmentCount = $this->configuredAssignmentCount();
    $assignmentIds = $this->assignmentIds($participant);
    $labels = $this->workshopLabels($assignmentIds);

    return [
      'participant_id' => (int) $participant->id(),
      'code' => $this->fieldValue($participant, 'field_code'),
      'first_name' => $this->fieldValue($participant, 'field_vorname'),
      'last_name' => $this->fieldValue($participant, 'field_name'),
      'region' => $this->fieldValue($participant, 'field_regionalverband'),
      'assignment_count' => $assignmentCount,
      'assignment_ids' => $assignmentIds,
      'assignments' => array_map(
        static fn (int $id): string => $labels[$id] ?? sprintf('#%d', $id),
        $assignmentIds,
      ),
    ];
  }

  /**
   * Returns valid published workshop choices for one slot.
   *
   * Workshops already assigned in another slot are omitted. Full workshops are
   * omitted as well, except for the participant's current workshop in this slot.
   *
   * @return array<int, string>
   */
  public function getWorkshopChoices(int $participantId, int $slot): array {
    $state = $this->getParticipantState($participantId);
    $this->assertSlot($slot, $state['assignment_count']);

    if (count($state['assignment_ids']) !== $state['assignment_count']) {
      throw new \RuntimeException('Manuelle Änderungen sind nur bei vollständig zugeteilten Teilnehmenden möglich.');
    }

    $currentId = (int) ($state['assignment_ids'][$slot - 1] ?? 0);
    $otherAssignments = $state['assignment_ids'];
    unset($otherAssignments[$slot - 1]);
    $otherAssignments = array_map('intval', array_values($otherAssignments));

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::WORKSHOP_BUNDLE)
      ->condition('status', 1)
      ->sort('title', 'ASC')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($ids) as $workshop) {
      if (!$workshop instanceof NodeInterface) {
        continue;
      }

      $workshopId = (int) $workshop->id();
      if ($workshopId !== $currentId && in_array($workshopId, $otherAssignments, TRUE)) {
        continue;
      }

      $capacity = $this->capacity($workshop);
      $occupancy = $this->slotOccupancy($slot, $workshopId);
      $free = max(0, $capacity - $occupancy);

      if ($workshopId !== $currentId && $free < 1) {
        continue;
      }

      $suffix = sprintf(' (%d/%d belegt, %d frei)', $occupancy, $capacity, $free);
      $options[$workshopId] = $workshop->label() . $suffix;
    }

    uasort($options, 'strnatcasecmp');
    return $options;
  }

  /**
   * Atomically replaces one slot assignment and verifies the persisted state.
   */
  public function changeAssignment(int $participantId, int $slot, int $workshopId): array {
    if (!$this->lock->acquire(self::LOCK_NAME, 10.0)) {
      throw new \RuntimeException('Eine andere manuelle Zuteilungsänderung läuft gerade. Bitte erneut versuchen.');
    }

    try {
      $participant = $this->loadParticipant($participantId);
      $configuredCount = $this->configuredAssignmentCount();
      $this->assertSlot($slot, $configuredCount);

      $assignmentIds = $this->assignmentIds($participant);
      if (count($assignmentIds) !== $configuredCount) {
        throw new \RuntimeException(sprintf(
          'Teilnehmer %d besitzt %d statt %d Zuteilungen. Die manuelle Änderung wurde nicht ausgeführt.',
          $participantId,
          count($assignmentIds),
          $configuredCount,
        ));
      }

      $workshop = $this->loadWorkshop($workshopId);
      $oldWorkshopId = (int) ($assignmentIds[$slot - 1] ?? 0);
      $oldWorkshop = $this->loadAssignedWorkshop($oldWorkshopId);

      if ($oldWorkshopId === $workshopId) {
        return [
          'changed' => FALSE,
          'participant_id' => $participantId,
          'slot' => $slot,
          'old_workshop_id' => $oldWorkshopId,
          'new_workshop_id' => $workshopId,
          'new_workshop_label' => $workshop->label(),
        ];
      }

      $transaction = $this->database->startTransaction();
      try {
        foreach ($assignmentIds as $index => $assignedWorkshopId) {
          if ($index !== $slot - 1 && (int) $assignedWorkshopId === $workshopId) {
            throw new \RuntimeException('Dieser Workshop ist dem Teilnehmer bereits in einem anderen Slot zugeteilt.');
          }
        }

        $capacity = $this->capacity($workshop);
        $occupancy = $this->slotOccupancy($slot, $workshopId);
        if ($occupancy >= $capacity) {
          throw new \RuntimeException(sprintf(
            'Der Workshop "%s" ist in Slot %d bereits voll (%d/%d).',
            $workshop->label(),
            $slot,
            $occupancy,
            $capacity,
          ));
        }

        $assignmentIds[$slot - 1] = $workshopId;
        $participant->set(
          self::FIELD_ASSIGNMENTS,
          array_map(static fn (int $id): array => ['target_id' => $id], $assignmentIds),
        );
        $participant->save();

        $this->entityTypeManager->getStorage('node')->resetCache([$participantId]);
        $reloaded = $this->loadParticipant($participantId);
        $persisted = $this->assignmentIds($reloaded);
        if ($persisted !== array_values($assignmentIds)) {
          throw new \RuntimeException('Die gespeicherte Zuteilung konnte nach dem Schreiben nicht verifiziert werden.');
        }

        // The audit insert uses the same database connection and therefore the
        // same transaction: assignment and audit either both persist or both
        // roll back.
        $auditId = $this->assignmentAudit->recordChange(
          $reloaded,
          $slot,
          $oldWorkshop,
          $workshop,
        );

        // Commit before releasing the cross-request manual-assignment lock.
        unset($transaction);

        return [
          'changed' => TRUE,
          'participant_id' => $participantId,
          'slot' => $slot,
          'old_workshop_id' => $oldWorkshopId,
          'new_workshop_id' => $workshopId,
          'new_workshop_label' => $workshop->label(),
          'slot_occupancy_after' => $occupancy + 1,
          'slot_capacity' => $capacity,
          'audit_id' => $auditId,
        ];
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  private function loadParticipant(int $participantId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($participantId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::PARTICIPANT_BUNDLE) {
      throw new \RuntimeException('Teilnehmer wurde nicht gefunden.');
    }
    if (!$node->hasField(self::FIELD_ASSIGNMENTS)) {
      throw new \RuntimeException('Teilnehmer besitzt kein Zuteilungsfeld.');
    }
    return $node;
  }

  private function loadAssignedWorkshop(int $workshopId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($workshopId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::WORKSHOP_BUNDLE) {
      throw new \RuntimeException('Die bisherige Workshop-Zuteilung ist nicht mehr gültig.');
    }
    return $node;
  }

  private function loadWorkshop(int $workshopId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($workshopId);
    if (
      !$node instanceof NodeInterface
      || $node->bundle() !== self::WORKSHOP_BUNDLE
      || !$node->isPublished()
    ) {
      throw new \RuntimeException('Der gewählte Workshop ist nicht gültig oder nicht veröffentlicht.');
    }
    return $node;
  }

  /** @return int[] */
  private function assignmentIds(NodeInterface $participant): array {
    $ids = [];
    foreach ($participant->get(self::FIELD_ASSIGNMENTS) as $item) {
      $id = (int) ($item->target_id ?? 0);
      if ($id > 0) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  /** @return array<int, string> */
  private function workshopLabels(array $ids): array {
    if (!$ids) {
      return [];
    }
    $labels = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      if ($node instanceof NodeInterface && $node->bundle() === self::WORKSHOP_BUNDLE) {
        $labels[(int) $node->id()] = $node->label();
      }
    }
    return $labels;
  }

  private function slotOccupancy(int $slot, int $workshopId): int {
    $query = $this->database->select('node__' . self::FIELD_ASSIGNMENTS, 'a');
    $query->addExpression('COUNT(*)');
    $query->condition('a.deleted', 0);
    $query->condition('a.delta', $slot - 1);
    $query->condition('a.' . self::FIELD_ASSIGNMENTS . '_target_id', $workshopId);
    return (int) $query->execute()->fetchField();
  }

  private function capacity(NodeInterface $workshop): int {
    if (!$workshop->hasField(self::FIELD_CAPACITY) || $workshop->get(self::FIELD_CAPACITY)->isEmpty()) {
      throw new \RuntimeException(sprintf('Workshop "%s" hat keine Kapazität.', $workshop->label()));
    }
    $capacity = (int) $workshop->get(self::FIELD_CAPACITY)->value;
    if ($capacity < 1) {
      throw new \RuntimeException(sprintf('Workshop "%s" hat eine ungültige Kapazität.', $workshop->label()));
    }
    return $capacity;
  }

  private function configuredAssignmentCount(): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::MATCHING_CONFIG_BUNDLE)
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      throw new \RuntimeException('Keine veröffentlichte Matching-Konfiguration vorhanden.');
    }

    $config = $storage->load((int) reset($ids));
    if (
      !$config instanceof NodeInterface
      || !$config->hasField(self::FIELD_NUM_ASSIGNMENTS)
      || $config->get(self::FIELD_NUM_ASSIGNMENTS)->isEmpty()
    ) {
      throw new \RuntimeException('Matching-Konfiguration enthält keine gültige Anzahl Zuteilungen.');
    }

    $count = (int) $config->get(self::FIELD_NUM_ASSIGNMENTS)->value;
    if ($count < 1) {
      throw new \RuntimeException('Die konfigurierte Anzahl Zuteilungen ist ungültig.');
    }
    return $count;
  }

  private function assertSlot(int $slot, int $assignmentCount): void {
    if ($slot < 1 || $slot > $assignmentCount) {
      throw new \RuntimeException(sprintf('Slot %d ist ungültig.', $slot));
    }
  }

  private function fieldValue(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $node->get($field)->value);
  }

}
