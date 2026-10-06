<?php

namespace Drupal\jfcamp_matching\Service;

use Psr\Log\LoggerInterface;

final class MatchingRunProcessor {

  public function __construct(
    private MatchingClient $client,
    private MatchingRunStorage $runStorage,
    private LoggerInterface $logger,
  ) {}

  public function process(int $runId): void {
    $run = $this->runStorage->load($runId);
    if (!$run) {
      $this->logger->error('Matching-Run #@run existiert nicht.', ['@run' => $runId]);
      return;
    }

    if ($run['status'] !== 'queued') {
      return;
    }

    if (!$this->runStorage->markRunning($runId)) {
      $this->logger->notice(
        'Matching-Run #@run wurde bereits von einem anderen Worker übernommen.',
        ['@run' => $runId],
      );
      return;
    }

    try {
      $res = $this->client->dryRun();
      $byParticipant = $res['by_participant'] ?? [];
      if (!is_array($byParticipant)) {
        throw new \RuntimeException('Matching-Service lieferte kein gültiges by_participant-Ergebnis.');
      }

      $summary = is_array($res['summary'] ?? NULL) ? $res['summary'] : [];
      $assignmentDetails = is_array($res['assignment_details'] ?? NULL)
        ? $res['assignment_details']
        : [];

      $this->runStorage->markCompleted(
        $runId,
        $summary,
        $byParticipant,
        $assignmentDetails,
        $run['config'],
      );

      $this->logger->notice(
        'Matching-Run #@run abgeschlossen: @assignments Zuteilungen.',
        [
          '@run' => $runId,
          '@assignments' => $summary['assignments_total'] ?? 0,
        ],
      );
    }
    catch (\Throwable $e) {
      try {
        $this->runStorage->markFailed($runId, $e->getMessage());
      }
      catch (\Throwable $storageError) {
        $this->logger->error(
          'Matching-Run #@run konnte nach Fehler nicht als failed gespeichert werden: @message',
          ['@run' => $runId, '@message' => $storageError->getMessage()],
        );
      }

      $this->logger->error(
        'Matching-Run #@run fehlgeschlagen: @message',
        ['@run' => $runId, '@message' => $e->getMessage()],
      );
    }
  }

}
