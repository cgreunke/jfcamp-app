<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

final class MatchingRunStorage {

  private const TABLE = 'jfcamp_matching_run';
  public const APPLY_RECOVERY_AFTER_SECONDS = 1200;

  public function __construct(
    private Connection $database,
    private UuidInterface $uuid,
    private AccountProxyInterface $currentUser,
    private TimeInterface $time,
  ) {}

  /**
   * Creates a queued run inside the caller's start transaction.
   */
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

  /**
   * Atomically claims a queued run or recovers an abandoned running run.
   *
   * The returned started_at value is the processing ownership token. Any
   * completion/failure write must still match that exact value, otherwise a
   * stale worker could overwrite the result of a newer recovery attempt.
   */
  public function claimForProcessing(int $runId, int $recoveryAfterSeconds): ?array {
    if ($recoveryAfterSeconds < 1) {
      throw new \InvalidArgumentException('Recovery-Grenze muss größer als 0 Sekunden sein.');
    }

    $now = $this->time->getCurrentTime();

    $affected = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'running',
        'started_at' => $now,
        'finished_at' => NULL,
        'error_message' => NULL,
      ])
      ->condition('id', $runId)
      ->condition('status', 'queued')
      ->execute();

    if ($affected === 1) {
      return [
        'started_at' => $now,
        'recovered' => FALSE,
      ];
    }

    $cutoff = $now - $recoveryAfterSeconds;
    $affected = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'running',
        'started_at' => $now,
        'finished_at' => NULL,
        'error_message' => NULL,
      ])
      ->condition('id', $runId)
      ->condition('status', 'running')
      ->condition('started_at', $cutoff, '<=')
      ->execute();

    if ($affected === 1) {
      return [
        'started_at' => $now,
        'recovered' => TRUE,
      ];
    }

    return NULL;
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
    string $inputFingerprint,
    int $inputFingerprintVersion,
    int $expectedStartedAt,
  ): void {
    $snapshot = [
      'created_at' => $this->time->getCurrentTime(),
      'summary' => $summary,
      'by_participant' => $byParticipant,
      'assignment_details' => $assignmentDetails,
      'config' => $configSnapshot,
    ];

    $snapshotJson = $this->encode($snapshot);

    $affected = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'completed',
        'finished_at' => $this->time->getCurrentTime(),
        'config_json' => $this->encode($configSnapshot),
        'summary_json' => $this->encode($summary),
        'snapshot_json' => $snapshotJson,
        'result_hash' => hash('sha256', $snapshotJson),
        'input_fingerprint' => $inputFingerprint,
        'input_fingerprint_version' => $inputFingerprintVersion,
        'error_message' => NULL,
      ])
      ->condition('id', $runId)
      ->condition('status', 'running')
      ->condition('started_at', $expectedStartedAt)
      ->execute();

    if ($affected !== 1) {
      throw new \RuntimeException(sprintf(
        'Matching-Run #%d konnte nicht atomar als completed gespeichert werden.',
        $runId,
      ));
    }
  }

  public function markFailed(
    int $runId,
    string $message,
    ?int $expectedStartedAt = NULL,
  ): bool {
    $query = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'failed',
        'finished_at' => $this->time->getCurrentTime(),
        'error_message' => mb_substr($message, 0, 10000),
      ])
      ->condition('id', $runId);

    if ($expectedStartedAt !== NULL) {
      $query
        ->condition('status', 'running')
        ->condition('started_at', $expectedStartedAt);
    }

    return $query->execute() === 1;
  }


  public function loadApplicable(int $runId): array {
    $run = $this->load($runId);
    if (!$run) {
      throw new \RuntimeException(sprintf('Matching-Run #%d wurde nicht gefunden.', $runId));
    }
    if ($run['status'] !== 'completed') {
      throw new \RuntimeException(sprintf('Matching-Run #%d ist nicht abgeschlossen.', $runId));
    }
    if (empty($run['result_hash']) || strlen((string) $run['result_hash']) !== 64) {
      throw new \RuntimeException(sprintf('Matching-Run #%d besitzt keinen gültigen Snapshot-Hash.', $runId));
    }
    if (empty($run['input_fingerprint']) || !preg_match('/^[a-f0-9]{64}$/', (string) $run['input_fingerprint'])) {
      throw new \RuntimeException(sprintf(
        'Matching-Run #%d besitzt keinen verifizierbaren Eingabe-Fingerprint. Bitte ein neues Matching starten.',
        $runId,
      ));
    }
    if (empty($run['input_fingerprint_version']) || (int) $run['input_fingerprint_version'] < 1) {
      throw new \RuntimeException(sprintf(
        'Matching-Run #%d besitzt keine gültige Fingerprint-Version. Bitte ein neues Matching starten.',
        $runId,
      ));
    }
    if (empty($run['snapshot']) || empty($run['snapshot']['by_participant']) || !is_array($run['snapshot']['by_participant'])) {
      throw new \RuntimeException(sprintf('Matching-Run #%d besitzt keinen anwendbaren Snapshot.', $runId));
    }
    return $run;
  }

  public function markApplying(int $runId, string $expectedHash): int {
    $now = $this->time->getCurrentTime();

    $affected = $this->database->update(self::TABLE)
      ->fields([
        'apply_status' => 'applying',
        'apply_started_at' => $now,
        'apply_result_json' => NULL,
      ])
      ->condition('id', $runId)
      ->condition('status', 'completed')
      ->condition('result_hash', $expectedHash)
      ->condition('apply_status', ['not_applied', 'failed', 'superseded', 'reset'], 'IN')
      ->execute();

    if ($affected === 1) {
      return $now;
    }

    $cutoff = $now - self::APPLY_RECOVERY_AFTER_SECONDS;

    $staleClaim = $this->database->condition('OR')
      ->condition('apply_started_at', $cutoff, '<=')
      ->isNull('apply_started_at');

    $affected = $this->database->update(self::TABLE)
      ->fields([
        'apply_status' => 'applying',
        'apply_started_at' => $now,
        'apply_result_json' => NULL,
      ])
      ->condition('id', $runId)
      ->condition('status', 'completed')
      ->condition('result_hash', $expectedHash)
      ->condition('apply_status', 'applying')
      ->condition($staleClaim)
      ->execute();

    if ($affected === 1) {
      return $now;
    }

    throw new \RuntimeException(sprintf(
      'Matching-Run #%d konnte nicht für Apply reserviert werden. Ein anderer Apply-Versuch läuft möglicherweise noch.',
      $runId,
    ));
  }

  public function markApplied(int $runId, string $expectedHash, int $expectedApplyStartedAt, array $result): void {
    $transaction = $this->database->startTransaction();

    try {
      // There is exactly one currently applied matching state. Older applied
      // runs remain in history, but are explicitly marked as superseded.
      $this->database->update(self::TABLE)
        ->fields(['apply_status' => 'superseded'])
        ->condition('apply_status', 'applied')
        ->condition('id', $runId, '<>')
        ->execute();

      $affected = $this->database->update(self::TABLE)
        ->fields([
          'apply_status' => 'applied',
          'apply_started_at' => NULL,
          'applied_at' => $this->time->getCurrentTime(),
          'applied_uid' => (int) $this->currentUser->id(),
          'applied_hash' => $expectedHash,
          'apply_result_json' => $this->encode($result),
          'reset_at' => NULL,
          'reset_uid' => NULL,
        ])
        ->condition('id', $runId)
        ->condition('status', 'completed')
        ->condition('result_hash', $expectedHash)
        ->condition('apply_status', 'applying')
        ->condition('apply_started_at', $expectedApplyStartedAt)
        ->execute();

      if ($affected !== 1) {
        throw new \RuntimeException(sprintf(
          'Matching-Run #%d konnte nach Apply nicht bestätigt werden.',
          $runId,
        ));
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  public function markApplyFailed(int $runId, string $expectedHash, int $expectedApplyStartedAt, array $result): bool {
    return $this->database->update(self::TABLE)
      ->fields([
        'apply_status' => 'failed',
        'apply_started_at' => NULL,
        'apply_result_json' => $this->encode($result),
      ])
      ->condition('id', $runId)
      ->condition('result_hash', $expectedHash)
      ->condition('apply_status', 'applying')
      ->condition('apply_started_at', $expectedApplyStartedAt)
      ->execute() === 1;
  }


  public function currentApplied(): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->condition('apply_status', 'applied')
      ->orderBy('applied_at', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->normalizeRow($row) : NULL;
  }

  public function loadCurrentApplied(int $runId): array {
    $run = $this->load($runId);
    if (!$run) {
      throw new \RuntimeException(sprintf('Matching-Run #%d wurde nicht gefunden.', $runId));
    }
    if ($run['apply_status'] !== 'applied') {
      throw new \RuntimeException(sprintf(
        'Matching-Run #%d ist nicht der aktuell festgeschriebene Run.',
        $runId,
      ));
    }
    if (empty($run['applied_hash']) || empty($run['result_hash']) || !hash_equals(
      (string) $run['result_hash'],
      (string) $run['applied_hash'],
    )) {
      throw new \RuntimeException(sprintf(
        'Matching-Run #%d hat keinen konsistenten angewendeten Snapshot-Hash.',
        $runId,
      ));
    }

    return $run;
  }

  public function markReset(int $runId, string $expectedHash): void {
    $affected = $this->database->update(self::TABLE)
      ->fields([
        'apply_status' => 'reset',
        'reset_at' => $this->time->getCurrentTime(),
        'reset_uid' => (int) $this->currentUser->id(),
      ])
      ->condition('id', $runId)
      ->condition('apply_status', 'applied')
      ->condition('applied_hash', $expectedHash)
      ->execute();

    if ($affected !== 1) {
      throw new \RuntimeException(sprintf(
        'Matching-Run #%d konnte nach Reset nicht als zurückgesetzt markiert werden.',
        $runId,
      ));
    }
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
        'input_fingerprint',
        'input_fingerprint_version',
        'error_message',
        'apply_status',
        'apply_started_at',
        'applied_at',
        'applied_uid',
        'applied_hash',
        'reset_at',
        'reset_uid',
        'summary_json',
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
        'input_fingerprint' => $row->input_fingerprint !== NULL ? (string) $row->input_fingerprint : NULL,
        'input_fingerprint_version' => $row->input_fingerprint_version !== NULL ? (int) $row->input_fingerprint_version : NULL,
        'error_message' => $row->error_message !== NULL ? (string) $row->error_message : NULL,
        'apply_status' => $row->apply_status !== NULL ? (string) $row->apply_status : 'not_applied',
        'apply_started_at' => $row->apply_started_at !== NULL ? (int) $row->apply_started_at : NULL,
        'applied_at' => $row->applied_at !== NULL ? (int) $row->applied_at : NULL,
        'applied_uid' => $row->applied_uid !== NULL ? (int) $row->applied_uid : NULL,
        'applied_hash' => $row->applied_hash !== NULL ? (string) $row->applied_hash : NULL,
        'reset_at' => $row->reset_at !== NULL ? (int) $row->reset_at : NULL,
        'reset_uid' => $row->reset_uid !== NULL ? (int) $row->reset_uid : NULL,
        'summary' => !empty($row->summary_json) ? $this->decode((string) $row->summary_json) : [],
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
      'input_fingerprint' => isset($row['input_fingerprint']) && $row['input_fingerprint'] !== NULL
        ? (string) $row['input_fingerprint']
        : NULL,
      'input_fingerprint_version' => isset($row['input_fingerprint_version']) && $row['input_fingerprint_version'] !== NULL
        ? (int) $row['input_fingerprint_version']
        : NULL,
      'error_message' => $row['error_message'] !== NULL ? (string) $row['error_message'] : NULL,
      'apply_status' => isset($row['apply_status']) && $row['apply_status'] !== NULL
        ? (string) $row['apply_status']
        : 'not_applied',
      'apply_started_at' => isset($row['apply_started_at']) && $row['apply_started_at'] !== NULL
        ? (int) $row['apply_started_at']
        : NULL,
      'applied_at' => $row['applied_at'] !== NULL ? (int) $row['applied_at'] : NULL,
      'applied_uid' => isset($row['applied_uid']) && $row['applied_uid'] !== NULL
        ? (int) $row['applied_uid']
        : NULL,
      'applied_hash' => isset($row['applied_hash']) && $row['applied_hash'] !== NULL
        ? (string) $row['applied_hash']
        : NULL,
      'apply_result' => !empty($row['apply_result_json'])
        ? $this->decode((string) $row['apply_result_json'])
        : [],
      'reset_at' => isset($row['reset_at']) && $row['reset_at'] !== NULL
        ? (int) $row['reset_at']
        : NULL,
      'reset_uid' => isset($row['reset_uid']) && $row['reset_uid'] !== NULL
        ? (int) $row['reset_uid']
        : NULL,
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
