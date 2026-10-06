<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

final class MatchingRunStorage {

  private const TABLE = 'jfcamp_matching_run';

  public function __construct(
    private Connection $database,
    private UuidInterface $uuid,
    private AccountProxyInterface $currentUser,
    private TimeInterface $time,
  ) {}

  public function createQueued(array $configSnapshot): int {
    $now = $this->time->getCurrentTime();

    $id = $this->database->insert(self::TABLE)
      ->fields([
        'uuid' => $this->uuid->generate(),
        'status' => 'queued',
        'created_at' => $now,
        'started_at' => NULL,
        'created_uid' => (int) $this->currentUser->id(),
        'config_json' => $this->encode($configSnapshot),
      ])
      ->execute();

    return (int) $id;
  }

  public function markRunning(int $runId): bool {
    $affected = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'running',
        'started_at' => $this->time->getCurrentTime(),
        'finished_at' => NULL,
        'error_message' => NULL,
      ])
      ->condition('id', $runId)
      ->condition('status', 'queued')
      ->execute();

    return $affected === 1;
  }

  public function hasActive(): bool {
    $count = $this->database->select(self::TABLE, 'r')
      ->condition('status', ['queued', 'running'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count > 0;
  }

  public function latestActive(): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->condition('status', ['queued', 'running'], 'IN')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->normalizeRow($row) : NULL;
  }

  public function markCompleted(
    int $runId,
    array $summary,
    array $byParticipant,
    array $assignmentDetails,
    array $configSnapshot,
  ): void {
    $snapshot = [
      'created_at' => $this->time->getCurrentTime(),
      'summary' => $summary,
      'by_participant' => $byParticipant,
      'assignment_details' => $assignmentDetails,
      'config' => $configSnapshot,
    ];

    $snapshotJson = $this->encode($snapshot);

    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'completed',
        'finished_at' => $this->time->getCurrentTime(),
        'summary_json' => $this->encode($summary),
        'snapshot_json' => $snapshotJson,
        'result_hash' => hash('sha256', $snapshotJson),
        'error_message' => NULL,
      ])
      ->condition('id', $runId)
      ->execute();
  }

  public function markFailed(int $runId, string $message): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'failed',
        'finished_at' => $this->time->getCurrentTime(),
        'error_message' => mb_substr($message, 0, 10000),
      ])
      ->condition('id', $runId)
      ->execute();
  }

  public function latest(): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->normalizeRow($row) : NULL;
  }

  public function load(int $runId): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->condition('id', $runId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->normalizeRow($row) : NULL;
  }

  public function recent(int $limit = 10): array {
    $limit = max(1, min(50, $limit));

    $result = $this->database->select(self::TABLE, 'r')
      ->fields('r', [
        'id',
        'uuid',
        'status',
        'created_at',
        'started_at',
        'finished_at',
        'created_uid',
        'result_hash',
        'error_message',
      ])
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();

    $rows = [];
    foreach ($result as $row) {
      $rows[] = [
        'id' => (int) $row->id,
        'uuid' => (string) $row->uuid,
        'status' => (string) $row->status,
        'created_at' => (int) $row->created_at,
        'started_at' => $row->started_at !== NULL ? (int) $row->started_at : NULL,
        'finished_at' => $row->finished_at !== NULL ? (int) $row->finished_at : NULL,
        'created_uid' => (int) $row->created_uid,
        'result_hash' => $row->result_hash !== NULL ? (string) $row->result_hash : NULL,
        'error_message' => $row->error_message !== NULL ? (string) $row->error_message : NULL,
      ];
    }

    return $rows;
  }

  private function normalizeRow(array $row): array {
    $snapshotJson = $row['snapshot_json'] ?? NULL;
    $snapshot = $snapshotJson ? $this->decode((string) $snapshotJson) : NULL;

    if ($snapshotJson && !empty($row['result_hash'])) {
      $actualHash = hash('sha256', (string) $snapshotJson);
      if (!hash_equals((string) $row['result_hash'], $actualHash)) {
        throw new \RuntimeException(sprintf(
          'Matching-Run #%d hat einen ungültigen Snapshot-Hash.',
          (int) $row['id'],
        ));
      }
    }

    return [
      'id' => (int) $row['id'],
      'uuid' => (string) $row['uuid'],
      'status' => (string) $row['status'],
      'created_at' => (int) $row['created_at'],
      'started_at' => $row['started_at'] !== NULL ? (int) $row['started_at'] : NULL,
      'finished_at' => $row['finished_at'] !== NULL ? (int) $row['finished_at'] : NULL,
      'created_uid' => (int) $row['created_uid'],
      'config' => !empty($row['config_json']) ? $this->decode((string) $row['config_json']) : [],
      'summary' => !empty($row['summary_json']) ? $this->decode((string) $row['summary_json']) : [],
      'snapshot' => $snapshot,
      'result_hash' => $row['result_hash'] !== NULL ? (string) $row['result_hash'] : NULL,
      'error_message' => $row['error_message'] !== NULL ? (string) $row['error_message'] : NULL,
      'applied_at' => $row['applied_at'] !== NULL ? (int) $row['applied_at'] : NULL,
    ];
  }

  private function encode(array $value): string {
    return json_encode(
      $value,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
  }

  private function decode(string $value): array {
    $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Persistierter Matching-Run enthält ungültiges JSON.');
    }
    return $decoded;
  }

}
