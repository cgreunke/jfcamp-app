<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\node\NodeInterface;

/**
 * Provides capacity-safe 1:1 assignment swaps for one slot.
 */
final class AssignmentSwapService {

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
   * Returns all selectable published workshops, including full workshops.
   *
   * @return array{options: array<int, string>, full: array<int, bool>}
   */
  public function getWorkshopChoices(int $participantId, int $slot): array {
    $participant = $this->loadParticipant($participantId);
    $assignmentCount = $this->configuredAssignmentCount();
    $this->assertSlot($slot, $assignmentCount);

    $assignmentIds = $this->assignmentIds($participant);
    if (count($assignmentIds) !== $assignmentCount) {
      throw new \RuntimeException('Manuelle Änderungen sind nur bei vollständig zugeteilten Teilnehmenden möglich.');
    }

    $currentId = (int) ($assignmentIds[$slot - 1] ?? 0);
    $otherAssignments = $assignmentIds;
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
    $full = [];
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
      $isFull = $workshopId !== $currentId && $occupancy >= $capacity;
      $full[$workshopId] = $isFull;

      $suffix = $isFull
        ? sprintf(' (%d/%d belegt – voll, Tausch erforderlich)', $occupancy, $capacity)
        : sprintf(' (%d/%d belegt, %d frei)', $occupancy, $capacity, $free);
      $options[$workshopId] = $workshop->label() . $suffix;
    }

    uasort($options, 'strnatcasecmp');
    return ['options' => $options, 'full' => $full];
  }

  /**
   * Returns TRUE when the selected workshop currently has no free place.
   */
  public function requiresSwap(int $participantId, int $slot, int $workshopId): bool {
    $participant = $this->loadParticipant($participantId);
    $assignmentCount = $this->configuredAssignmentCount();
    $this->assertSlot($slot, $assignmentCount);
    $assignmentIds = $this->assignmentIds($participant);

    if (count($assignmentIds) !== $assignmentCount) {
      throw new \RuntimeException('Manuelle Änderungen sind nur bei vollständig zugeteilten Teilnehmenden möglich.');
    }
    if (in_array($workshopId, array_values(array_filter(
      $assignmentIds,
      static fn (int $id, int $index): bool => $index !== $slot - 1,
      ARRAY_FILTER_USE_BOTH,
    )), TRUE)) {
      throw new \RuntimeException('Dieser Workshop ist dem Teilnehmer bereits in einem anderen Slot zugeteilt.');
    }

    $currentId = (int) ($assignmentIds[$slot - 1] ?? 0);
    if ($currentId === $workshopId) {
      return FALSE;
    }

    $workshop = $this->loadWorkshop($workshopId);
    return $this->slotOccupancy($slot, $workshopId) >= $this->capacity($workshop);
  }

  /**
   * Builds the context and compatible swap partners for the swap form.
   */
  public function getSwapContext(int $participantId, int $slot, int $targetWorkshopId): array {
    $participant = $this->loadParticipant($participantId);
    $assignmentCount = $this->configuredAssignmentCount();
    $this->assertSlot($slot, $assignmentCount);
    $assignmentIds = $this->assignmentIds($participant);
    if (count($assignmentIds) !== $assignmentCount) {
      throw new \RuntimeException('Der Teilnehmer besitzt keinen vollständigen Zuteilungsstand.');
    }

    $oldWorkshopId = (int) ($assignmentIds[$slot - 1] ?? 0);
    if ($oldWorkshopId === $targetWorkshopId) {
      throw new \RuntimeException('Der Zielworkshop entspricht bereits der aktuellen Zuteilung.');
    }
    if ($this->containsWorkshopOutsideSlot($assignmentIds, $slot, $targetWorkshopId)) {
      throw new \RuntimeException('Der Zielworkshop ist dem Teilnehmer bereits in einem anderen Slot zugeteilt.');
    }

    $oldWorkshop = $this->loadWorkshop($oldWorkshopId);
    $targetWorkshop = $this->loadWorkshop($targetWorkshopId);
    $targetCapacity = $this->capacity($targetWorkshop);
    $targetOccupancy = $this->slotOccupancy($slot, $targetWorkshopId);
    if ($targetOccupancy < $targetCapacity) {
      throw new \RuntimeException('Der Zielworkshop ist nicht mehr voll. Bitte die normale Umbuchung erneut öffnen.');
    }
    if ($targetOccupancy > $targetCapacity) {
      throw new \RuntimeException('Der Zielworkshop ist bereits überbucht. Ein Tausch wird aus Sicherheitsgründen nicht ausgeführt.');
    }

    $candidateIds = $this->participantIdsInWorkshop($slot, $targetWorkshopId);
    $candidates = [];
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($storage->loadMultiple($candidateIds) as $candidate) {
      if (!$candidate instanceof NodeInterface || $candidate->bundle() !== self::PARTICIPANT_BUNDLE) {
        continue;
      }
      if ((int) $candidate->id() === $participantId) {
        continue;
      }

      $candidateAssignments = $this->assignmentIds($candidate);
      if (count($candidateAssignments) !== $assignmentCount) {
        continue;
      }
      if ((int) ($candidateAssignments[$slot - 1] ?? 0) !== $targetWorkshopId) {
        continue;
      }
      if ($this->containsWorkshopOutsideSlot($candidateAssignments, $slot, $oldWorkshopId)) {
        continue;
      }

      $name = trim($this->fieldValue($candidate, 'field_vorname') . ' ' . $this->fieldValue($candidate, 'field_name'));
      $code = $this->fieldValue($candidate, 'field_code');
      $region = $this->fieldValue($candidate, 'field_regionalverband');
      if ($name === '') {
        $name = $code !== '' ? $code : '#' . $candidate->id();
      }
      $label = $name;
      if ($code !== '') {
        $label .= ' · ' . $code;
      }
      if ($region !== '') {
        $label .= ' · ' . $region;
      }
      $candidates[(int) $candidate->id()] = $label;
    }
    uasort($candidates, 'strnatcasecmp');

    return [
      'participant_id' => $participantId,
      'participant_name' => $this->participantLabel($participant),
      'participant_code' => $this->fieldValue($participant, 'field_code'),
      'slot' => $slot,
      'old_workshop_id' => $oldWorkshopId,
      'old_workshop_label' => $oldWorkshop->label(),
      'target_workshop_id' => $targetWorkshopId,
      'target_workshop_label' => $targetWorkshop->label(),
      'target_occupancy' => $targetOccupancy,
      'target_capacity' => $targetCapacity,
      'candidates' => $candidates,
    ];
  }

  /**
   * Atomically swaps two participants between two workshops in the same slot.
   */
  public function swap(int $participantId, int $slot, int $targetWorkshopId, int $partnerId): array {
    if ($participantId === $partnerId) {
      throw new \RuntimeException('Für einen Tausch muss eine andere Person ausgewählt werden.');
    }
    if (!$this->lock->acquire(self::LOCK_NAME, 10.0)) {
      throw new \RuntimeException('Eine andere manuelle Zuteilungsänderung läuft gerade. Bitte erneut versuchen.');
    }

    try {
      $assignmentCount = $this->configuredAssignmentCount();
      $this->assertSlot($slot, $assignmentCount);

      $participant = $this->loadParticipant($participantId);
      $partner = $this->loadParticipant($partnerId);
      $participantAssignments = $this->assignmentIds($participant);
      $partnerAssignments = $this->assignmentIds($partner);
      if (count($participantAssignments) !== $assignmentCount || count($partnerAssignments) !== $assignmentCount) {
        throw new \RuntimeException('Der Tausch ist nur bei zwei vollständig zugeteilten Teilnehmenden möglich.');
      }

      $oldWorkshopId = (int) ($participantAssignments[$slot - 1] ?? 0);
      $partnerWorkshopId = (int) ($partnerAssignments[$slot - 1] ?? 0);
      if ($partnerWorkshopId !== $targetWorkshopId) {
        throw new \RuntimeException('Die Zuteilung der ausgewählten Tauschperson hat sich inzwischen geändert. Bitte neu auswählen.');
      }
      if ($oldWorkshopId === $targetWorkshopId) {
        throw new \RuntimeException('Ein Tausch ist nicht erforderlich.');
      }
      if ($this->containsWorkshopOutsideSlot($participantAssignments, $slot, $targetWorkshopId)) {
        throw new \RuntimeException('Der Zielworkshop ist der ersten Person bereits in einem anderen Slot zugeteilt.');
      }
      if ($this->containsWorkshopOutsideSlot($partnerAssignments, $slot, $oldWorkshopId)) {
        throw new \RuntimeException('Die bisherige Zuteilung der ersten Person ist der Tauschperson bereits in einem anderen Slot zugeteilt.');
      }

      $oldWorkshop = $this->loadWorkshop($oldWorkshopId);
      $targetWorkshop = $this->loadWorkshop($targetWorkshopId);
      $targetCapacity = $this->capacity($targetWorkshop);
      $oldCapacity = $this->capacity($oldWorkshop);
      $targetOccupancyBefore = $this->slotOccupancy($slot, $targetWorkshopId);
      $oldOccupancyBefore = $this->slotOccupancy($slot, $oldWorkshopId);

      if ($targetOccupancyBefore !== $targetCapacity) {
        throw new \RuntimeException('Der Zielworkshop ist nicht mehr exakt voll. Bitte den Vorgang neu starten.');
      }
      if ($oldOccupancyBefore > $oldCapacity) {
        throw new \RuntimeException('Der bisherige Workshop ist bereits überbucht. Ein Tausch wird aus Sicherheitsgründen nicht ausgeführt.');
      }

      $transaction = $this->database->startTransaction();
      try {
        $participantAssignments[$slot - 1] = $targetWorkshopId;
        $partnerAssignments[$slot - 1] = $oldWorkshopId;

        $participant->set(self::FIELD_ASSIGNMENTS, $this->referenceValues($participantAssignments));
        $partner->set(self::FIELD_ASSIGNMENTS, $this->referenceValues($partnerAssignments));
        $participant->save();
        $partner->save();

        $storage = $this->entityTypeManager->getStorage('node');
        $storage->resetCache([$participantId, $partnerId]);
        $reloadedParticipant = $this->loadParticipant($participantId);
        $reloadedPartner = $this->loadParticipant($partnerId);
        if ($this->assignmentIds($reloadedParticipant) !== array_values($participantAssignments)) {
          throw new \RuntimeException('Die neue Zuteilung der ersten Person konnte nicht verifiziert werden.');
        }
        if ($this->assignmentIds($reloadedPartner) !== array_values($partnerAssignments)) {
          throw new \RuntimeException('Die neue Zuteilung der Tauschperson konnte nicht verifiziert werden.');
        }

        $targetOccupancyAfter = $this->slotOccupancy($slot, $targetWorkshopId);
        $oldOccupancyAfter = $this->slotOccupancy($slot, $oldWorkshopId);
        if ($targetOccupancyAfter > $targetCapacity || $oldOccupancyAfter > $oldCapacity) {
          throw new \RuntimeException('Der Tausch würde eine Workshop-Kapazität überschreiten und wurde zurückgerollt.');
        }
        if ($targetOccupancyAfter !== $targetOccupancyBefore || $oldOccupancyAfter !== $oldOccupancyBefore) {
          throw new \RuntimeException('Die Belegungszahlen haben sich beim Tausch unerwartet verändert. Der Vorgang wurde zurückgerollt.');
        }

        $auditA = $this->assignmentAudit->recordChange(
          $reloadedParticipant,
          $slot,
          $oldWorkshop,
          $targetWorkshop,
        );
        $auditB = $this->assignmentAudit->recordChange(
          $reloadedPartner,
          $slot,
          $targetWorkshop,
          $oldWorkshop,
        );

        unset($transaction);

        return [
          'participant_id' => $participantId,
          'participant_name' => $this->participantLabel($reloadedParticipant),
          'partner_id' => $partnerId,
          'partner_name' => $this->participantLabel($reloadedPartner),
          'slot' => $slot,
          'old_workshop_label' => $oldWorkshop->label(),
          'target_workshop_label' => $targetWorkshop->label(),
          'audit_ids' => [$auditA, $auditB],
        ];
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        $this->entityTypeManager->getStorage('node')->resetCache([$participantId, $partnerId]);
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

  private function loadWorkshop(int $workshopId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($workshopId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::WORKSHOP_BUNDLE || !$node->isPublished()) {
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

  /** @return array<int, array{target_id: int}> */
  private function referenceValues(array $ids): array {
    return array_map(static fn (int $id): array => ['target_id' => $id], array_values($ids));
  }

  private function containsWorkshopOutsideSlot(array $assignmentIds, int $slot, int $workshopId): bool {
    foreach ($assignmentIds as $index => $assignedWorkshopId) {
      if ($index !== $slot - 1 && (int) $assignedWorkshopId === $workshopId) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /** @return int[] */
  private function participantIdsInWorkshop(int $slot, int $workshopId): array {
    $query = $this->database->select('node__' . self::FIELD_ASSIGNMENTS, 'a');
    $query->addField('a', 'entity_id');
    $query->condition('a.deleted', 0);
    $query->condition('a.delta', $slot - 1);
    $query->condition('a.' . self::FIELD_ASSIGNMENTS . '_target_id', $workshopId);
    return array_map('intval', $query->execute()->fetchCol());
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
      throw new \RuntimeException(sprintf('Workshop "%s" besitzt keine gültige Kapazität.', $workshop->label()));
    }
    $capacity = (int) $workshop->get(self::FIELD_CAPACITY)->value;
    if ($capacity < 1) {
      throw new \RuntimeException(sprintf('Workshop "%s" besitzt keine gültige Kapazität.', $workshop->label()));
    }
    return $capacity;
  }

  private function configuredAssignmentCount(): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::MATCHING_CONFIG_BUNDLE)
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      throw new \RuntimeException('Keine Matching-Konfiguration gefunden.');
    }
    $config = $storage->load(reset($ids));
    if (!$config instanceof NodeInterface || !$config->hasField(self::FIELD_NUM_ASSIGNMENTS) || $config->get(self::FIELD_NUM_ASSIGNMENTS)->isEmpty()) {
      throw new \RuntimeException('Die Anzahl der Zuteilungen ist nicht konfiguriert.');
    }
    $count = (int) $config->get(self::FIELD_NUM_ASSIGNMENTS)->value;
    if ($count < 1) {
      throw new \RuntimeException('Die konfigurierte Anzahl der Zuteilungen ist ungültig.');
    }
    return $count;
  }

  private function assertSlot(int $slot, int $assignmentCount): void {
    if ($slot < 1 || $slot > $assignmentCount) {
      throw new \InvalidArgumentException('Ungültiger Zuteilungs-Slot.');
    }
  }

  private function fieldValue(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $node->get($field)->value);
  }

  private function participantLabel(NodeInterface $participant): string {
    $name = trim($this->fieldValue($participant, 'field_vorname') . ' ' . $this->fieldValue($participant, 'field_name'));
    if ($name !== '') {
      return $name;
    }
    $code = $this->fieldValue($participant, 'field_code');
    return $code !== '' ? $code : '#' . $participant->id();
  }

}
