<?php

namespace Drupal\jfcamp_matching\Commands;

use Drush\Commands\DrushCommands;
use Drupal\jfcamp_matching\Service\MatchingClient;

final class JfcampMatchingCommands extends DrushCommands {

  public function __construct(private MatchingClient $client) {
    parent::__construct();
  }

  /**
   * Matching ausführen (POST /matching/run).
   *
   * @command jfcamp:matching-run
   * @aliases jcmr
   */
  public function matchingRun(): int {
    try {
      $res = $this->client->run();
      $sum = $res['summary'] ?? [];
      $patched = (int) ($res['patched'] ?? 0);
      $this->io()->success(sprintf(
        'OK: %d TN, %d Zuteilungen, geändert: %d, alle gefüllt: %s. Seed: %s.',
        $sum['participants_total'] ?? 0,
        $sum['assignments_total'] ?? 0,
        $patched,
        !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein',
        $sum['seed'] ?? 'n/a'
      ));
      if (!empty($res['patch_errors'])) {
        $this->io()->warning(sprintf('%d Patch-Fehler. Details im Log.', count($res['patch_errors'])));
      }
      return 0;
    } catch (\Throwable $e) {
      $this->io()->error('Fehler: ' . $e->getMessage());
      return 1;
    }
  }

  /**
   * Dry-Run (POST /matching/dry-run).
   *
   * @command jfcamp:matching-dry
   * @aliases jcmd
   */
  public function matchingDry(): int {
    try {
      $res = $this->client->dryRun();
      $sum = $res['summary'] ?? [];
      $this->io()->success(sprintf(
        'Dry-Run: %d TN, %d Zuteilungen, ohne Wünsche: %d, alle gefüllt: %s. Seed: %s.',
        $sum['participants_total'] ?? 0,
        $sum['assignments_total'] ?? 0,
        $sum['participants_no_wishes'] ?? 0,
        !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein',
        $sum['seed'] ?? 'n/a'
      ));
      return 0;
    } catch (\Throwable $e) {
      $this->io()->error('Fehler: ' . $e->getMessage());
      return 1;
    }
  }

}
