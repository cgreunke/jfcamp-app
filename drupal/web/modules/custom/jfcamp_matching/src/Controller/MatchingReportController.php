<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

final class MatchingReportController extends ControllerBase {

  public function __construct(private MatchingClient $client) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_matching.client'));
  }

  public function report(): array {
    $build = ['#title' => $this->t('Matching – Report & Exports')];

    try {
      $res = $this->client->dryRun();
      $sum = $res['summary'] ?? [];

      $per_priority = $sum['per_priority_fulfilled'] ?? [];
      $per_slot     = $sum['per_slot_assigned_counts'] ?? [];

      $build['summary'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Zusammenfassung (Simulation)'),
        '#items' => [
          $this->t('Teilnehmer: @v', ['@v' => $sum['participants_total'] ?? 0]),
          $this->t('Zuteilungen gesamt: @v', ['@v' => $sum['assignments_total'] ?? 0]),
          $this->t('Teilnehmer ohne Wünsche: @v', ['@v' => $sum['participants_no_wishes'] ?? 0]),
          $this->t('Alle mit Slots gefüllt: @af', ['@af' => !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein']),
          $this->t('Restplätze gesamt: @v', ['@v' => $sum['capacity_remaining_total'] ?? 0]),
          $this->t('Erfüllte pro Priorität: @v', ['@v' => json_encode($per_priority)]),
          $this->t('Erfüllte pro Slot: @v', ['@v' => json_encode($per_slot)]),
        ],
      ];

      // Export-Links
      $base = rtrim($this->client->exportUrl(''), '/');
      $build['exports'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline']],
        'all' => [
          '#type' => 'link',
          '#title' => $this->t('Alle Slots (CSV)'),
          '#url' => Url::fromUri($base . '/export/slots.csv'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'slot1' => [
          '#type' => 'link',
          '#title' => $this->t('Slot 1 (CSV)'),
          '#url' => Url::fromUri($base . '/export/slot/1.csv'),
          '#attributes' => ['class' => ['button']],
        ],
        'slot2' => [
          '#type' => 'link',
          '#title' => $this->t('Slot 2 (CSV)'),
          '#url' => Url::fromUri($base . '/export/slot/2.csv'),
          '#attributes' => ['class' => ['button']],
        ],
        'slot3' => [
          '#type' => 'link',
          '#title' => $this->t('Slot 3 (CSV)'),
          '#url' => Url::fromUri($base . '/export/slot/3.csv'),
          '#attributes' => ['class' => ['button']],
        ],
        'regions' => [
          '#type' => 'link',
          '#title' => $this->t('Teilnehmer je Regionalverband (CSV)'),
          '#url' => Url::fromUri($base . '/export/regions.csv'),
          '#attributes' => ['class' => ['button']],
        ],
        'overview' => [
          '#type' => 'link',
          '#title' => $this->t('Workshop-Übersicht & Restplätze (CSV)'),
          '#url' => Url::fromUri($base . '/export/overview.csv'),
          '#attributes' => ['class' => ['button']],
        ],
        'pending' => [
          '#type' => 'link',
          '#title' => $this->t('Ohne Wünsche (CSV)'),
          '#url' => Url::fromUri($base . '/export/pending.csv'),
          '#attributes' => ['class' => ['button']],
        ],
      ];

    } catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Report fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }

    return $build;
  }

}
