<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the read-only participant overview from the canonical Drupal data.
 */
final class ParticipantOverviewService {

  private const PARTICIPANT_BUNDLE = 'teilnehmer';
  private const WORKSHOP_BUNDLE = 'workshop';
  private const WISH_BUNDLE = 'wunsch';
  private const MATCHING_CONFIG_BUNDLE = 'matching_config';

  private const FIELD_CODE = 'field_code';
  private const FIELD_FIRST_NAME = 'field_vorname';
  private const FIELD_LAST_NAME = 'field_name';
  private const FIELD_REGION = 'field_regionalverband';
  private const FIELD_ASSIGNMENTS = 'field_zugewiesen';
  private const FIELD_WISH_PARTICIPANT = 'field_teilnehmer';
  private const FIELD_WISHES = 'field_wuensche';
  private const FIELD_NUM_ASSIGNMENTS = 'field_num_zuteilung';

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns one paged, read-only participant overview.
   *
   * @return array{
   *   rows: array<int, array<string, mixed>>,
   *   total: int,
   *   assignment_columns: int
   * }
   */
  public function getPage(int $pageSize = 50): array {
    if ($pageSize < 1) {
      throw new \InvalidArgumentException('Die Seitengröße muss größer als 0 sein.');
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $total = (int) $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::PARTICIPANT_BUNDLE)
      ->count()
      ->execute();

    $participantIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::PARTICIPANT_BUNDLE)
      ->sort(self::FIELD_REGION, 'ASC')
      ->sort(self::FIELD_LAST_NAME, 'ASC')
      ->sort(self::FIELD_FIRST_NAME, 'ASC')
      ->sort('nid', 'ASC')
      ->pager($pageSize)
      ->execute();

    if (!$participantIds) {
      return [
        'rows' => [],
        'total' => $total,
        'assignment_columns' => max(1, $this->readConfiguredAssignmentCount()),
      ];
    }

    $participants = $nodeStorage->loadMultiple($participantIds);
    $wishCounts = $this->loadWishCounts(array_map('intval', $participantIds));

    $workshopIds = [];
    $participantAssignments = [];
    $maxAssignmentsInPage = 0;

    foreach ($participantIds as $participantId) {
      $participant = $participants[$participantId] ?? NULL;
      if (!$participant instanceof NodeInterface) {
        continue;
      }

      $assignmentIds = [];
      if ($participant->hasField(self::FIELD_ASSIGNMENTS)) {
        foreach ($participant->get(self::FIELD_ASSIGNMENTS) as $item) {
          $workshopId = (int) ($item->target_id ?? 0);
          if ($workshopId > 0) {
            $assignmentIds[] = $workshopId;
            $workshopIds[$workshopId] = $workshopId;
          }
        }
      }

      $participantAssignments[(int) $participantId] = $assignmentIds;
      $maxAssignmentsInPage = max($maxAssignmentsInPage, count($assignmentIds));
    }

    $workshopLabels = [];
    if ($workshopIds) {
      foreach ($nodeStorage->loadMultiple(array_values($workshopIds)) as $workshop) {
        if ($workshop instanceof NodeInterface && $workshop->bundle() === self::WORKSHOP_BUNDLE) {
          $workshopLabels[(int) $workshop->id()] = $workshop->label();
        }
      }
    }

    $rows = [];
    foreach ($participantIds as $participantId) {
      $participant = $participants[$participantId] ?? NULL;
      if (!$participant instanceof NodeInterface) {
        continue;
      }

      $assignmentLabels = [];
      foreach ($participantAssignments[(int) $participantId] ?? [] as $workshopId) {
        $assignmentLabels[] = $workshopLabels[$workshopId] ?? sprintf('#%d', $workshopId);
      }

      $rows[] = [
        'participant_id' => (int) $participant->id(),
        'code' => $this->fieldValue($participant, self::FIELD_CODE),
        'first_name' => $this->fieldValue($participant, self::FIELD_FIRST_NAME),
        'last_name' => $this->fieldValue($participant, self::FIELD_LAST_NAME),
        'region' => $this->fieldValue($participant, self::FIELD_REGION),
        'wish_count' => (int) ($wishCounts[(int) $participant->id()] ?? 0),
        'assignments' => $assignmentLabels,
      ];
    }

    return [
      'rows' => $rows,
      'total' => $total,
      'assignment_columns' => max(
        1,
        $this->readConfiguredAssignmentCount(),
        $maxAssignmentsInPage,
      ),
    ];
  }

  /**
   * Loads the latest published wish node per participant and counts wishes.
   *
   * @param int[] $participantIds
   *   Participant node IDs from the current page.
   *
   * @return array<int, int>
   *   Wish count keyed by participant node ID.
   */
  private function loadWishCounts(array $participantIds): array {
    if (!$participantIds) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $wishIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::WISH_BUNDLE)
      ->condition('status', 1)
      ->condition(self::FIELD_WISH_PARTICIPANT . '.target_id', $participantIds, 'IN')
      ->sort('changed', 'ASC')
      ->sort('nid', 'ASC')
      ->execute();

    if (!$wishIds) {
      return [];
    }

    $counts = [];
    foreach ($nodeStorage->loadMultiple($wishIds) as $wishNode) {
      if (!$wishNode instanceof NodeInterface || $wishNode->bundle() !== self::WISH_BUNDLE) {
        continue;
      }
      if (
        !$wishNode->hasField(self::FIELD_WISH_PARTICIPANT)
        || $wishNode->get(self::FIELD_WISH_PARTICIPANT)->isEmpty()
      ) {
        continue;
      }

      $participantId = (int) $wishNode->get(self::FIELD_WISH_PARTICIPANT)->target_id;
      if ($participantId <= 0) {
        continue;
      }

      $wishCount = 0;
      if ($wishNode->hasField(self::FIELD_WISHES)) {
        foreach ($wishNode->get(self::FIELD_WISHES) as $item) {
          if (!empty($item->target_id)) {
            $wishCount++;
          }
        }
      }

      // Queries are sorted oldest -> newest. Overwriting therefore leaves the
      // latest published wish node as the operative state for this participant.
      $counts[$participantId] = $wishCount;
    }

    return $counts;
  }

  /**
   * Reads the current published matching configuration for column rendering.
   */
  private function readConfiguredAssignmentCount(): int {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::MATCHING_CONFIG_BUNDLE)
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return 0;
    }

    $config = $nodeStorage->load((int) reset($ids));
    if (
      !$config instanceof NodeInterface
      || !$config->hasField(self::FIELD_NUM_ASSIGNMENTS)
      || $config->get(self::FIELD_NUM_ASSIGNMENTS)->isEmpty()
    ) {
      return 0;
    }

    return max(0, (int) $config->get(self::FIELD_NUM_ASSIGNMENTS)->value);
  }

  private function fieldValue(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }

    return trim((string) $node->get($fieldName)->value);
  }

}
