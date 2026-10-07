<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Atomically persists and verifies matching assignments.
 */
final class AssignmentPersistenceService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityFieldManagerInterface $entityFieldManager,
    private Connection $database,
    private MatchingRunStorage $runStorage,
  ) {}

  /**
   * Applies one completed run atomically from its persisted snapshot.
   */
  public function apply(
    array $config,
    array $byParticipant,
    int $runId,
    string $resultHash,
  ): array {
    if ($runId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $resultHash)) {
      throw new \InvalidArgumentException('Apply enthält keine gültige Run-/Hash-Referenz.');
    }
    if (!$byParticipant) {
      throw new \RuntimeException('Der Matching-Snapshot enthält keine Teilnehmer-Zuteilungen.');
    }

    $plan = $this->buildApplyPlan($config, $byParticipant);
    $result = [
      'expected_participants' => count($plan['participants']),
      'expected_assignments' => $plan['expected_assignments'],
      'updated_participants' => 0,
      'written_assignments' => 0,
      'verified' => FALSE,
    ];

    // Reserve only after the full preflight passed. From this point on, any
    // write error is recorded as failed after the transaction was rolled back.
    $this->runStorage->markApplying($runId, $resultHash);
    $transaction = $this->database->startTransaction();

    try {
      foreach ($plan['participants'] as $participantUuid => $participantPlan) {
        /** @var \Drupal\node\NodeInterface $participant */
        $participant = $participantPlan['node'];
        $this->writeParticipant($participant, $plan, $participantPlan['slots']);
        $participant->save();

        $result['updated_participants']++;
        $result['written_assignments'] += count($participantPlan['slots']);

      }

      $this->entityTypeManager->getStorage('node')->resetCache($plan['participant_ids']);
      $this->verifyAppliedState($plan);
      $result['verified'] = TRUE;

      if (
        $result['updated_participants'] !== $result['expected_participants']
        || $result['written_assignments'] !== $result['expected_assignments']
      ) {
        throw new \RuntimeException('Apply-Zählwerte stimmen nach dem Schreiben nicht mit dem Snapshot überein.');
      }

      $this->runStorage->markApplied($runId, $resultHash, $result);
      return $result;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $result['error'] = $e->getMessage();
      try {
        $this->runStorage->markApplyFailed($runId, $resultHash, $result);
      }
      catch (\Throwable) {
        // Preserve the original exception. A separate storage failure is
        // intentionally not allowed to hide the persistence failure.
      }
      throw $e;
    }
  }

  /**
   * Clears the currently applied assignment state atomically.
   */
  public function reset(array $config, int $runId, string $appliedHash): array {
    if ($runId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $appliedHash)) {
      throw new \InvalidArgumentException('Reset enthält keine gültige Run-/Hash-Referenz.');
    }

    $plan = $this->buildResetPlan($config);
    $transaction = $this->database->startTransaction();

    try {
      foreach ($plan['participants'] as $participant) {
        $this->clearParticipant($participant, $plan);
        $participant->save();
      }

      $this->entityTypeManager->getStorage('node')->resetCache($plan['participant_ids']);
      $this->verifyResetState($plan);
      $this->runStorage->markReset($runId, $appliedHash);

      return [
        'reset_participants' => count($plan['participants']),
        'verified' => TRUE,
      ];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Performs all Apply validation before the first node is saved.
   */
  private function buildApplyPlan(array $config, array $byParticipant): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $participantBundle = (string) ($config['participant_bundle'] ?? 'teilnehmer');
    $workshopBundle = (string) ($config['workshop_bundle'] ?? 'workshop');
    $mode = $this->resolveMode($config, $participantBundle);
    $prefix = (string) ($config['slot_fields_prefix'] ?? 'field_slot_');
    $numSlots = (int) ($config['num_slots'] ?? 3);
    $multiField = (string) ($config['assigned_field'] ?? 'field_zugewiesen');

    if ($numSlots <= 0) {
      throw new \RuntimeException('Die konfigurierte Slot-Anzahl ist ungültig.');
    }

    $participants = [];
    $participantIds = [];
    $workshopUuids = [];
    $expectedAssignments = 0;

    foreach ($byParticipant as $participantUuid => $slotMap) {
      if (!is_string($participantUuid) || $participantUuid === '' || !is_array($slotMap)) {
        throw new \RuntimeException('Der Matching-Snapshot enthält ungültige Teilnehmerdaten.');
      }

      $matches = $nodeStorage->loadByProperties(['uuid' => $participantUuid]);
      $participant = $matches ? reset($matches) : NULL;
      if (!$participant instanceof NodeInterface || $participant->bundle() !== $participantBundle) {
        throw new \RuntimeException(sprintf('Teilnehmer %s ist nicht mehr gültig.', $participantUuid));
      }

      if ($mode === 'multi' && !$participant->hasField($multiField)) {
        throw new \RuntimeException(sprintf('Teilnehmer %s besitzt das Zuteilungsfeld %s nicht.', $participantUuid, $multiField));
      }

      $normalizedSlots = [];
      foreach ($slotMap as $slotRaw => $workshopUuid) {
        $slot = (int) $slotRaw;
        if (
          $slot < 1
          || ($mode === 'per_slot' && $slot > $numSlots)
          || (string) $workshopUuid === ''
        ) {
          throw new \RuntimeException(sprintf('Teilnehmer %s enthält eine ungültige Slot-Zuteilung.', $participantUuid));
        }
        if (isset($normalizedSlots[$slot])) {
          throw new \RuntimeException(sprintf('Teilnehmer %s enthält Slot %d mehrfach.', $participantUuid, $slot));
        }
        if ($mode === 'per_slot' && !$participant->hasField($prefix . $slot)) {
          throw new \RuntimeException(sprintf('Teilnehmer %s besitzt das Slot-Feld %s nicht.', $participantUuid, $prefix . $slot));
        }
        $normalizedSlots[$slot] = (string) $workshopUuid;
        $workshopUuids[(string) $workshopUuid] = TRUE;
      }
      ksort($normalizedSlots, SORT_NUMERIC);

      $participants[$participantUuid] = [
        'node' => $participant,
        'slots' => $normalizedSlots,
      ];
      $participantIds[] = (int) $participant->id();
      $expectedAssignments += count($normalizedSlots);
    }

    $workshops = [];
    foreach (array_keys($workshopUuids) as $workshopUuid) {
      $matches = $nodeStorage->loadByProperties(['uuid' => $workshopUuid]);
      $workshop = $matches ? reset($matches) : NULL;
      if (!$workshop instanceof NodeInterface || $workshop->bundle() !== $workshopBundle) {
        throw new \RuntimeException(sprintf('Workshop %s ist nicht mehr gültig.', $workshopUuid));
      }
      $workshops[$workshopUuid] = $workshop;
    }

    return [
      'mode' => $mode,
      'prefix' => $prefix,
      'num_slots' => $numSlots,
      'multi_field' => $multiField,
      'participants' => $participants,
      'participant_ids' => $participantIds,
      'workshops' => $workshops,
      'expected_assignments' => $expectedAssignments,
    ];
  }

  private function buildResetPlan(array $config): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $participantBundle = (string) ($config['participant_bundle'] ?? 'teilnehmer');
    $mode = $this->resolveMode($config, $participantBundle);
    $prefix = (string) ($config['slot_fields_prefix'] ?? 'field_slot_');
    $numSlots = (int) ($config['num_slots'] ?? 3);
    $multiField = (string) ($config['assigned_field'] ?? 'field_zugewiesen');

    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $participantBundle)
      ->execute();
    $participants = $ids ? $nodeStorage->loadMultiple($ids) : [];

    foreach ($participants as $participant) {
      if ($mode === 'multi' && !$participant->hasField($multiField)) {
        throw new \RuntimeException(sprintf('Teilnehmer %s besitzt das Zuteilungsfeld %s nicht.', $participant->uuid(), $multiField));
      }
      if ($mode === 'per_slot') {
        for ($slot = 1; $slot <= $numSlots; $slot++) {
          if (!$participant->hasField($prefix . $slot)) {
            throw new \RuntimeException(sprintf('Teilnehmer %s besitzt das Slot-Feld %s nicht.', $participant->uuid(), $prefix . $slot));
          }
        }
      }
    }

    return [
      'mode' => $mode,
      'prefix' => $prefix,
      'num_slots' => $numSlots,
      'multi_field' => $multiField,
      'participants' => $participants,
      'participant_ids' => array_map('intval', array_keys($participants)),
    ];
  }

  private function writeParticipant(NodeInterface $participant, array $plan, array $slots): void {
    if ($plan['mode'] === 'per_slot') {
      for ($slot = 1; $slot <= $plan['num_slots']; $slot++) {
        $participant->set($plan['prefix'] . $slot, NULL);
      }
      foreach ($slots as $slot => $workshopUuid) {
        $participant->set($plan['prefix'] . $slot, $plan['workshops'][$workshopUuid]->id());
      }
      return;
    }

    $items = [];
    foreach ($slots as $workshopUuid) {
      $items[] = ['target_id' => $plan['workshops'][$workshopUuid]->id()];
    }
    $participant->set($plan['multi_field'], $items);
  }

  private function clearParticipant(NodeInterface $participant, array $plan): void {
    if ($plan['mode'] === 'per_slot') {
      for ($slot = 1; $slot <= $plan['num_slots']; $slot++) {
        $participant->set($plan['prefix'] . $slot, NULL);
      }
      return;
    }
    $participant->set($plan['multi_field'], []);
  }

  private function verifyAppliedState(array $plan): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($plan['participants'] as $participantUuid => $participantPlan) {
      /** @var \Drupal\node\NodeInterface|null $participant */
      $participant = $nodeStorage->load($participantPlan['node']->id());
      if (!$participant instanceof NodeInterface) {
        throw new \RuntimeException(sprintf('Teilnehmer %s konnte nach Apply nicht erneut geladen werden.', $participantUuid));
      }

      if ($plan['mode'] === 'per_slot') {
        for ($slot = 1; $slot <= $plan['num_slots']; $slot++) {
          $field = $plan['prefix'] . $slot;
          $actualTarget = $participant->get($field)->target_id;
          $expectedUuid = $participantPlan['slots'][$slot] ?? NULL;
          $expectedTarget = $expectedUuid !== NULL ? (int) $plan['workshops'][$expectedUuid]->id() : NULL;
          if (($actualTarget !== NULL ? (int) $actualTarget : NULL) !== $expectedTarget) {
            throw new \RuntimeException(sprintf('Persistierte Zuteilung von Teilnehmer %s stimmt in Slot %d nicht mit dem Snapshot überein.', $participantUuid, $slot));
          }
        }
        continue;
      }

      $actual = array_map(
        static fn(array $item): int => (int) $item['target_id'],
        $participant->get($plan['multi_field'])->getValue(),
      );
      $expected = array_map(
        fn(string $workshopUuid): int => (int) $plan['workshops'][$workshopUuid]->id(),
        array_values($participantPlan['slots']),
      );
      if ($actual !== $expected) {
        throw new \RuntimeException(sprintf('Persistierte Zuteilungen von Teilnehmer %s stimmen nicht mit dem Snapshot überein.', $participantUuid));
      }
    }
  }

  private function verifyResetState(array $plan): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($plan['participants'] as $original) {
      /** @var \Drupal\node\NodeInterface|null $participant */
      $participant = $nodeStorage->load($original->id());
      if (!$participant instanceof NodeInterface) {
        throw new \RuntimeException(sprintf('Teilnehmer %s konnte nach Reset nicht erneut geladen werden.', $original->uuid()));
      }

      if ($plan['mode'] === 'per_slot') {
        for ($slot = 1; $slot <= $plan['num_slots']; $slot++) {
          if (!$participant->get($plan['prefix'] . $slot)->isEmpty()) {
            throw new \RuntimeException(sprintf('Zuteilung von Teilnehmer %s ist nach Reset nicht leer.', $participant->uuid()));
          }
        }
        continue;
      }

      if (!$participant->get($plan['multi_field'])->isEmpty()) {
        throw new \RuntimeException(sprintf('Zuteilungen von Teilnehmer %s sind nach Reset nicht leer.', $participant->uuid()));
      }
    }
  }

  private function resolveMode(array $config, string $participantBundle): string {
    $mode = (string) ($config['assignment_mode'] ?? 'auto');
    if ($mode === 'auto') {
      $prefix = (string) ($config['slot_fields_prefix'] ?? 'field_slot_');
      $defs = $this->entityFieldManager->getFieldDefinitions('node', $participantBundle);
      $mode = isset($defs[$prefix . '1']) ? 'per_slot' : 'multi';
    }
    if (!in_array($mode, ['per_slot', 'multi'], TRUE)) {
      throw new \RuntimeException(sprintf('Unbekannter Zuteilungsmodus: %s', $mode));
    }
    return $mode;
  }

}
