<?php

namespace Drupal\jfcamp_operations\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the current workshop/slot capacity picture from live assignments.
 */
final class WorkshopCapacityService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns workshop capacity and occupancy data for all current slots.
   */
  public function getOverview(): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $numSlots = $this->getAssignmentCount();

    $workshopIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'workshop')
      ->condition('status', 1)
      ->sort('title')
      ->execute();

    /** @var \Drupal\node\NodeInterface[] $workshops */
    $workshops = $workshopIds ? $nodeStorage->loadMultiple($workshopIds) : [];
    $occupancy = [];

    $participantIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->execute();

    foreach ($participantIds ? $nodeStorage->loadMultiple($participantIds) : [] as $participant) {
      if (!$participant instanceof NodeInterface || !$participant->hasField('field_zugewiesen')) {
        continue;
      }

      foreach ($participant->get('field_zugewiesen') as $delta => $item) {
        $slot = $delta + 1;
        $workshopId = (int) ($item->target_id ?? 0);
        if ($workshopId <= 0 || $slot < 1 || $slot > $numSlots) {
          continue;
        }
        $occupancy[$workshopId][$slot] = ($occupancy[$workshopId][$slot] ?? 0) + 1;
      }
    }

    $rows = [];
    $summary = [
      'workshops' => count($workshops),
      'slots' => $numSlots,
      'total_capacity' => 0,
      'occupied' => 0,
      'free' => 0,
      'full_slot_occurrences' => 0,
      'overbooked_slot_occurrences' => 0,
    ];

    foreach ($workshops as $workshop) {
      $capacity = $workshop->hasField('field_maximale_plaetze')
        ? max(0, (int) $workshop->get('field_maximale_plaetze')->value)
        : 0;
      $room = $workshop->hasField('field_room')
        ? trim((string) $workshop->get('field_room')->value)
        : '';

      $slotData = [];
      $workshopOccupied = 0;
      for ($slot = 1; $slot <= $numSlots; $slot++) {
        $occupied = (int) ($occupancy[(int) $workshop->id()][$slot] ?? 0);
        $free = max(0, $capacity - $occupied);
        $over = max(0, $occupied - $capacity);
        $isFull = $capacity > 0 && $occupied >= $capacity;
        $isOverbooked = $capacity > 0 && $occupied > $capacity;

        $slotData[$slot] = [
          'slot' => $slot,
          'occupied' => $occupied,
          'capacity' => $capacity,
          'free' => $free,
          'overbooked_by' => $over,
          'full' => $isFull,
          'overbooked' => $isOverbooked,
        ];

        $summary['total_capacity'] += $capacity;
        $summary['occupied'] += $occupied;
        $summary['free'] += $free;
        if ($isFull) {
          $summary['full_slot_occurrences']++;
        }
        if ($isOverbooked) {
          $summary['overbooked_slot_occurrences']++;
        }
        $workshopOccupied += $occupied;
      }

      $rows[] = [
        'workshop_id' => (int) $workshop->id(),
        'label' => $workshop->label(),
        'room' => $room,
        'capacity' => $capacity,
        'occupied_total' => $workshopOccupied,
        'slots' => $slotData,
      ];
    }

    usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

    return [
      'assignment_columns' => $numSlots,
      'rows' => $rows,
      'summary' => $summary,
    ];
  }

  /**
   * Reads the current number of assignment slots from matching_config.
   */
  private function getAssignmentCount(): int {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $ids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids) {
      $config = $nodeStorage->load(reset($ids));
      if ($config instanceof NodeInterface && $config->hasField('field_num_zuteilung')) {
        $value = (int) $config->get('field_num_zuteilung')->value;
        if ($value > 0) {
          return $value;
        }
      }
    }

    // Safe fallback for installations without a published matching config.
    $max = 0;
    $participantIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'teilnehmer')
      ->execute();
    foreach ($participantIds ? $nodeStorage->loadMultiple($participantIds) : [] as $participant) {
      if ($participant instanceof NodeInterface && $participant->hasField('field_zugewiesen')) {
        $max = max($max, $participant->get('field_zugewiesen')->count());
      }
    }

    return max(1, $max);
  }

}
