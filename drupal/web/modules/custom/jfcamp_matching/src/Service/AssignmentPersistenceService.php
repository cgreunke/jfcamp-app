<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Atomically persists and verifies matching assignments.
 */
final class AssignmentPersistenceService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
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
    $applyStartedAt = $this->runStorage->markApplying($runId, $resultHash);
    $transaction = $this->database->startTransaction();

    try {
      foreach ($plan['participants'] as $participantPlan) {
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

      $this->runStorage->markApplied(
        $runId,
        $resultHash,
        $applyStartedAt,
        $result,
      );
      return $result;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $result['error'] = $e->getMessage();
      try {
        $this->runStorage->markApplyFailed(
          $runId,
          $resultHash,
          $applyStartedAt,
          $result,
        );
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
    $assignedField = (string) ($config['assigned_field'] ?? 'field_zugewiesen');

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
      if (!$participant->hasField($assignedField)) {
        throw new \RuntimeException(sprintf('Teilnehmer %s besitzt das Zuteilungsfeld %s nicht.', $participantUuid, $assignedField));
      }

      $normalizedSlots = [];
      foreach ($slotMap as $slotRaw => $workshopUuid) {
        $slot = (int) $slotRaw;
        if ($slot < 1 || (string) $workshopUuid === '') {
          throw new \RuntimeException(sprintf('Teilnehmer %s enthält eine ungültige Slot-Zuteilung.', $participantUuid));
        }
        if (isset($normalizedSlots[$slot])) {
          throw new \RuntimeException(sprintf('Teilnehmer %s enthält Slot %d mehrfach.', $participantUuid, $slot));
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
      'assigned_field' => $assignedField,
      'participants' => $participants,
      'participant_ids' => $participantIds,
      'workshops' => $workshops,
      'expected_assignments' => $expectedAssignments,
    ];
  }

  private function buildResetPlan(array $config): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $participantBundle = (string) ($config['participant_bundle'] ?? 'teilnehmer');
    $assignedField = (string) ($config['assigned_field'] ?? 'field_zugewiesen');

    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $participantBundle)
      ->execute();
    $participants = $ids ? $nodeStorage->loadMultiple($ids) : [];

    foreach ($participants as $participant) {
      if (!$participant->hasField($assignedField)) {
        throw new \RuntimeException(sprintf('Teilnehmer %s besitzt das Zuteilungsfeld %s nicht.', $participant->uuid(), $assignedField));
      }
    }

    return [
      'assigned_field' => $assignedField,
      'participants' => $participants,
      'participant_ids' => array_map('intval', array_keys($participants)),
    ];
  }

  private function writeParticipant(NodeInterface $participant, array $plan, array $slots): void {
    $items = [];
    foreach ($slots as $workshopUuid) {
      $items[] = ['target_id' => $plan['workshops'][$workshopUuid]->id()];
    }
    $participant->set($plan['assigned_field'], $items);
  }

  private function clearParticipant(NodeInterface $participant, array $plan): void {
    $participant->set($plan['assigned_field'], []);
  }

  private function verifyAppliedState(array $plan): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    foreach ($plan['participants'] as $participantUuid => $participantPlan) {
      /** @var \Drupal\node\NodeInterface|null $participant */
      $participant = $nodeStorage->load($participantPlan['node']->id());
      if (!$participant instanceof NodeInterface) {
        throw new \RuntimeException(sprintf('Teilnehmer %s konnte nach Apply nicht erneut geladen werden.', $participantUuid));
      }

      $actual = array_map(
        static fn(array $item): int => (int) $item['target_id'],
        $participant->get($plan['assigned_field'])->getValue(),
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
      if (!$participant->get($plan['assigned_field'])->isEmpty()) {
        throw new \RuntimeException(sprintf('Zuteilungen von Teilnehmer %s sind nach Reset nicht leer.', $participant->uuid()));
      }
    }
  }

}
