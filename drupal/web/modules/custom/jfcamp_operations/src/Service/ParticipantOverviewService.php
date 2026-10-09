<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the participant overview and export data from canonical Drupal data.
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
  private const FIELD_NUM_WISHES = 'field_num_wuensche';

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the complete filtered overview.
   *
   * Pagination deliberately happens in the controller so table and CSV share
   * exactly the same result set and filtering rules.
   *
   * @return array{
   *   rows: array<int, array<string, mixed>>,
   *   total: int,
   *   assignment_columns: int,
   *   wish_columns: int,
   *   regions: string[],
   *   workshops: array<int, string>
   * }
   */
  public function getOverview(array $filters = [], ?array $allowedRegions = NULL): array {
    $filters = $this->normalizeFilters($filters);
    $allowedRegions = $this->normalizeAllowedRegions($allowedRegions);
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $participantQuery = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::PARTICIPANT_BUNDLE);

    if ($allowedRegions !== NULL) {
      if (!$allowedRegions) {
        $participantIds = [];
      }
      else {
        $participantIds = $participantQuery
          ->condition(self::FIELD_REGION, $allowedRegions, 'IN')
          ->execute();
      }
    }
    else {
      $participantIds = $participantQuery->execute();
    }

    $assignmentColumns = max(1, $this->readConfiguredAssignmentCount());
    $wishColumns = max(1, $this->readConfiguredWishCount());

    if (!$participantIds) {
      return [
        'rows' => [],
        'total' => 0,
        'assignment_columns' => $assignmentColumns,
        'wish_columns' => $wishColumns,
        'regions' => [],
        'workshops' => $this->loadWorkshopOptions(),
      ];
    }

    $participantIds = array_map('intval', array_values($participantIds));
    $participants = $nodeStorage->loadMultiple($participantIds);
    $wishData = $this->loadWishData($participantIds);

    $referencedWorkshopIds = [];
    $participantAssignments = [];
    $maxAssignments = 0;

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
            $referencedWorkshopIds[$workshopId] = $workshopId;
          }
        }
      }

      foreach ($wishData[$participantId]['wish_ids'] ?? [] as $workshopId) {
        $referencedWorkshopIds[$workshopId] = $workshopId;
      }

      $participantAssignments[$participantId] = $assignmentIds;
      $maxAssignments = max($maxAssignments, count($assignmentIds));
    }

    $workshopLabels = $this->loadWorkshopLabels(array_values($referencedWorkshopIds));
    $allWorkshopOptions = $this->loadWorkshopOptions();

    $rows = [];
    $regions = [];

    foreach ($participantIds as $participantId) {
      $participant = $participants[$participantId] ?? NULL;
      if (!$participant instanceof NodeInterface) {
        continue;
      }

      $region = $this->fieldValue($participant, self::FIELD_REGION);
      if ($region !== '') {
        $regions[$region] = $region;
      }

      $assignmentIds = $participantAssignments[$participantId] ?? [];
      $assignmentLabels = array_map(
        static fn (int $workshopId): string => $workshopLabels[$workshopId] ?? sprintf('#%d', $workshopId),
        $assignmentIds,
      );

      $wishIds = $wishData[$participantId]['wish_ids'] ?? [];
      $wishLabels = array_map(
        static fn (int $workshopId): string => $workshopLabels[$workshopId] ?? sprintf('#%d', $workshopId),
        $wishIds,
      );

      $row = [
        'participant_id' => (int) $participant->id(),
        'code' => $this->fieldValue($participant, self::FIELD_CODE),
        'first_name' => $this->fieldValue($participant, self::FIELD_FIRST_NAME),
        'last_name' => $this->fieldValue($participant, self::FIELD_LAST_NAME),
        'region' => $region,
        'wish_count' => count($wishIds),
        'wish_ids' => $wishIds,
        'wishes' => $wishLabels,
        'assignment_ids' => $assignmentIds,
        'assignments' => $assignmentLabels,
        'fully_assigned' => count($assignmentIds) >= $assignmentColumns,
      ];

      if ($this->matchesFilters($row, $filters)) {
        $rows[] = $row;
      }
    }

    usort($rows, static function (array $a, array $b): int {
      foreach (['region', 'last_name', 'first_name', 'code'] as $key) {
        $cmp = strnatcasecmp((string) $a[$key], (string) $b[$key]);
        if ($cmp !== 0) {
          return $cmp;
        }
      }
      return $a['participant_id'] <=> $b['participant_id'];
    });

    ksort($regions, SORT_NATURAL | SORT_FLAG_CASE);

    return [
      'rows' => $rows,
      'total' => count($rows),
      'assignment_columns' => max($assignmentColumns, $maxAssignments),
      'wish_columns' => $wishColumns,
      'regions' => array_values($regions),
      'workshops' => $allWorkshopOptions,
    ];
  }

  /**
   * Returns global operational counts independent of the active list filters.
   *
   * The summary deliberately derives from the same canonical overview data as
   * the table and CSV export, so the dashboard counts cannot drift from the
   * underlying participant list.
   *
   * @return array{
   *   total: int,
   *   with_wishes: int,
   *   without_wishes: int,
   *   fully_assigned: int,
   *   incomplete: int
   * }
   */
  public function getSummary(?array $allowedRegions = NULL): array {
    $overview = $this->getOverview([], $allowedRegions);

    $withWishes = 0;
    $fullyAssigned = 0;

    foreach ($overview['rows'] as $row) {
      if ((int) ($row['wish_count'] ?? 0) > 0) {
        $withWishes++;
      }
      if (!empty($row['fully_assigned'])) {
        $fullyAssigned++;
      }
    }

    $total = (int) $overview['total'];

    return [
      'total' => $total,
      'with_wishes' => $withWishes,
      'without_wishes' => max(0, $total - $withWishes),
      'fully_assigned' => $fullyAssigned,
      'incomplete' => max(0, $total - $fullyAssigned),
    ];
  }

  /**
   * Returns selectable export columns keyed by stable query identifier.
   */
  public function getExportColumns(int $assignmentColumns, int $wishColumns): array {
    $columns = [
      'code' => 'Code',
      'first_name' => 'Vorname',
      'last_name' => 'Nachname',
      'region' => 'Regionalverband',
      'wish_count' => 'Anzahl Wünsche',
    ];

    for ($rank = 1; $rank <= $wishColumns; $rank++) {
      $columns['wish_' . $rank] = 'Wunsch ' . $rank;
    }

    for ($slot = 1; $slot <= $assignmentColumns; $slot++) {
      $columns['assignment_' . $slot] = 'Zuteilung ' . $slot;
    }

    return $columns;
  }

  /**
   * Default export mirrors the useful operational columns of the table.
   */
  public function getDefaultExportColumns(int $assignmentColumns): array {
    $columns = ['code', 'first_name', 'last_name', 'region', 'wish_count'];
    for ($slot = 1; $slot <= $assignmentColumns; $slot++) {
      $columns[] = 'assignment_' . $slot;
    }
    return $columns;
  }

  /**
   * Converts one overview row to one scalar export row.
   */
  public function buildExportRow(array $row, array $columnKeys): array {
    $values = [];

    foreach ($columnKeys as $key) {
      if (preg_match('/^wish_(\d+)$/', $key, $match)) {
        $index = ((int) $match[1]) - 1;
        $values[] = (string) ($row['wishes'][$index] ?? '');
        continue;
      }

      if (preg_match('/^assignment_(\d+)$/', $key, $match)) {
        $index = ((int) $match[1]) - 1;
        $values[] = (string) ($row['assignments'][$index] ?? '');
        continue;
      }

      $values[] = match ($key) {
        'code' => (string) ($row['code'] ?? ''),
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'region' => (string) ($row['region'] ?? ''),
        'wish_count' => (string) ($row['wish_count'] ?? 0),
        default => '',
      };
    }

    return $values;
  }


  /**
   * Normalizes the server-side Regionalverband access scope.
   *
   * NULL means unrestricted access. An empty array means no regions.
   *
   * @return string[]|null
   */
  private function normalizeAllowedRegions(?array $allowedRegions): ?array {
    if ($allowedRegions === NULL) {
      return NULL;
    }

    $normalized = [];
    foreach ($allowedRegions as $region) {
      $region = strtoupper(trim((string) $region));
      if ($region !== '') {
        $normalized[$region] = $region;
      }
    }

    ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($normalized);
  }

  private function normalizeFilters(array $filters): array {
    return [
      'q' => trim((string) ($filters['q'] ?? '')),
      'region' => trim((string) ($filters['region'] ?? '')),
      'wishes' => in_array(($filters['wishes'] ?? ''), ['yes', 'no'], TRUE)
        ? (string) $filters['wishes']
        : '',
      'complete' => in_array(($filters['complete'] ?? ''), ['yes', 'no'], TRUE)
        ? (string) $filters['complete']
        : '',
      'workshop' => max(0, (int) ($filters['workshop'] ?? 0)),
      'slot' => max(0, (int) ($filters['slot'] ?? 0)),
    ];
  }

  private function matchesFilters(array $row, array $filters): bool {
    if ($filters['region'] !== '' && $row['region'] !== $filters['region']) {
      return FALSE;
    }

    if ($filters['wishes'] === 'yes' && (int) $row['wish_count'] < 1) {
      return FALSE;
    }
    if ($filters['wishes'] === 'no' && (int) $row['wish_count'] > 0) {
      return FALSE;
    }

    if ($filters['complete'] === 'yes' && empty($row['fully_assigned'])) {
      return FALSE;
    }
    if ($filters['complete'] === 'no' && !empty($row['fully_assigned'])) {
      return FALSE;
    }

    if ($filters['workshop'] > 0) {
      if ($filters['slot'] > 0) {
        $slotIndex = $filters['slot'] - 1;
        if ((int) ($row['assignment_ids'][$slotIndex] ?? 0) !== $filters['workshop']) {
          return FALSE;
        }
      }
      elseif (!in_array($filters['workshop'], $row['assignment_ids'], TRUE)) {
        return FALSE;
      }
    }

    if ($filters['q'] !== '') {
      $needle = mb_strtolower($filters['q']);
      $haystack = mb_strtolower(implode(' ', [
        $row['code'],
        $row['first_name'],
        $row['last_name'],
      ]));
      if (!str_contains($haystack, $needle)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Loads the latest published wish node per participant.
   *
   * @return array<int, array{wish_ids: int[]}>
   */
  private function loadWishData(array $participantIds): array {
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

    $data = [];
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

      $participantWishIds = [];
      if ($wishNode->hasField(self::FIELD_WISHES)) {
        foreach ($wishNode->get(self::FIELD_WISHES) as $item) {
          $workshopId = (int) ($item->target_id ?? 0);
          if ($workshopId > 0) {
            $participantWishIds[] = $workshopId;
          }
        }
      }

      // Oldest -> newest: the last published node becomes operative.
      $data[$participantId] = ['wish_ids' => $participantWishIds];
    }

    return $data;
  }

  private function loadWorkshopLabels(array $workshopIds): array {
    if (!$workshopIds) {
      return [];
    }

    $labels = [];
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    foreach ($nodeStorage->loadMultiple($workshopIds) as $workshop) {
      if ($workshop instanceof NodeInterface && $workshop->bundle() === self::WORKSHOP_BUNDLE) {
        $labels[(int) $workshop->id()] = $workshop->label();
      }
    }
    return $labels;
  }

  /**
   * @return array<int, string>
   */
  private function loadWorkshopOptions(): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::WORKSHOP_BUNDLE)
      ->condition('status', 1)
      ->sort('title', 'ASC')
      ->execute();

    $options = [];
    foreach ($nodeStorage->loadMultiple($ids) as $workshop) {
      if ($workshop instanceof NodeInterface && $workshop->bundle() === self::WORKSHOP_BUNDLE) {
        $options[(int) $workshop->id()] = $workshop->label();
      }
    }

    uasort($options, 'strnatcasecmp');
    return $options;
  }

  private function readConfiguredAssignmentCount(): int {
    return $this->readMatchingConfigInteger(self::FIELD_NUM_ASSIGNMENTS);
  }

  private function readConfiguredWishCount(): int {
    return $this->readMatchingConfigInteger(self::FIELD_NUM_WISHES);
  }

  private function readMatchingConfigInteger(string $fieldName): int {
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
      || !$config->hasField($fieldName)
      || $config->get($fieldName)->isEmpty()
    ) {
      return 0;
    }

    return max(0, (int) $config->get($fieldName)->value);
  }

  private function fieldValue(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }

    return trim((string) $node->get($fieldName)->value);
  }

}
