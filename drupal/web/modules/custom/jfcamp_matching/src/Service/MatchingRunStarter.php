<?php

namespace Drupal\jfcamp_matching\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Starts matching runs without leaving duplicate or orphaned queued runs.
 */
final class MatchingRunStarter {

  private const START_LOCK = 'jfcamp_matching.create_run';
  private const QUEUE_ID = 'jfcamp_matching_runs';

  public function __construct(
    private Connection $database,
    private LockBackendInterface $lock,
    private QueueFactory $queueFactory,
    private MatchingRunStorage $runStorage,
  ) {}

  /**
   * Creates and enqueues one run atomically when no active run exists.
   */
  public function startIfNoneActive(array $configSnapshot): ?int {
    $this->acquireStartLock();
    $transaction = NULL;

    try {
      $transaction = $this->database->startTransaction();
      if ($this->runStorage->hasActive()) {
        // End the empty transaction before the start lock is released.
        unset($transaction);
        return NULL;
      }

      $runId = $this->runStorage->createQueued($configSnapshot);
      $itemId = $this->queueFactory
        ->get(self::QUEUE_ID)
        ->createItem(['run_id' => $runId]);

      if ($itemId === FALSE) {
        throw new \RuntimeException('Matching-Run konnte nicht in die Warteschlange gestellt werden.');
      }

      // Drupal commits when the transaction object leaves scope. Commit while
      // the start lock is still held so a concurrent starter can only observe
      // the fully committed run + queue item pair.
      unset($transaction);

      return $runId;
    }
    catch (\Throwable $e) {
      if ($transaction !== NULL) {
        $transaction->rollBack();
      }
      throw $e;
    }
    finally {
      $this->lock->release(self::START_LOCK);
    }
  }

  /**
   * Acquires the same exclusive start lock used for all matching starts.
   */
  private function acquireStartLock(): void {
    if ($this->lock->acquire(self::START_LOCK, 10.0)) {
      return;
    }

    $this->lock->wait(self::START_LOCK, 5);
    if (!$this->lock->acquire(self::START_LOCK, 10.0)) {
      throw new \RuntimeException('Matching-Start konnte nicht exklusiv reserviert werden. Bitte erneut versuchen.');
    }
  }

}
