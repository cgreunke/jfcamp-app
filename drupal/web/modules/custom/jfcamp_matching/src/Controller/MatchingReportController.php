<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

final class MatchingReportController extends ControllerBase {

  public function __construct(private MatchingClient $client) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_matching.client'));
  }

  public function report(): array {
    $build = ['#title' => $this->t('Matching – Report & Exporte'), '#cache' => ['max-age' => 0]];

    try {
      $stats = $this->client->stats();
      $cfg = $stats['config'] ?? [];
      $counts = $stats['counts'] ?? [];
      $wishes_hist = $stats['wishes_per_participant_histogram'] ?? [];
      $cap_fields = $stats['capacity_fields_used_histogram'] ?? [];
      $pop_preview = $stats['popularity_topk_preview'] ?? [];
      $capacity_preview = $stats['capacity_preview'] ?? [];

      $build['summary'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Stats'),
        '#items' => [
          $this->t('num_assign: @n', ['@n' => $cfg['num_assign'] ?? 'n/a']),
          $this->t('num_wishes: @n', ['@n' => $cfg['num_wishes'] ?? 'n/a']),
          $this->t('Teilnehmer gesehen: @n', ['@n' => $counts['teilnehmer_seen'] ?? 0]),
          $this->t('Workshops: @n', ['@n' => $counts['workshops'] ?? 0]),
        ],
      ];

      if ($wishes_hist) {
        $rows = [];
        foreach ($wishes_hist as $k => $v) { $rows[] = [$k, $v]; }
        $build['wishes_hist'] = [
          '#type' => 'table',
          '#caption' => $this->t('Wünsche pro Teilnehmer (Histogramm)'),
          '#header' => [$this->t('Anzahl Wünsche'), $this->t('Teilnehmer')],
          '#rows' => $rows,
        ];
      }

      if ($cap_fields) {
        $rows = [];
        foreach ($cap_fields as $k => $v) { $rows[] = [$k, $v]; }
        $build['cap_fields'] = [
          '#type' => 'table',
          '#caption' => $this->t('Verwendete Kapazitätsfelder'),
          '#header' => [$this->t('Feld'), $this->t('Anzahl Workshops')],
          '#rows' => $rows,
        ];
      }

      if ($pop_preview) {
        $rows = [];
        foreach ($pop_preview as $r) {
          $rows[] = [$r['id'] ?? '', $r['title'] ?? '', $r['topk_demand'] ?? 0];
        }
        $build['pop_preview'] = [
          '#type' => 'table',
          '#caption' => $this->t('Top‑Nachfrage (Top‑k) – Vorschau'),
          '#header' => ['ID', $this->t('Workshop'), $this->t('Top‑k‑Nachfrage')],
          '#rows' => $rows,
        ];
      }

      if ($capacity_preview) {
        $rows = [];
        foreach ($capacity_preview as $r) {
          $rows[] = [$r['id'] ?? '', $r['title'] ?? '', $r['capacity'] ?? 0];
        }
        $build['capacity_preview'] = [
          '#type' => 'table',
          '#caption' => $this->t('Kapazitäten (Vorschau)'),
          '#header' => ['ID', $this->t('Workshop'), $this->t('Kapazität')],
          '#rows' => $rows,
        ];
      }

      // Export-Buttons (Drupals eigene CSVs)
      $build['exports'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline', 'jfcamp-export-buttons']],
        'all' => Link::fromTextAndUrl($this->t('Alle Slots (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_all'))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--primary']]],
        's1'  => Link::fromTextAndUrl($this->t('Slot 1 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 1]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
        's2'  => Link::fromTextAndUrl($this->t('Slot 2 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 2]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
        's3'  => Link::fromTextAndUrl($this->t('Slot 3 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 3]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
        'regions' => Link::fromTextAndUrl($this->t('Teilnehmer je Regionalverband (CSV)'), Url::fromRoute('jfcamp_matching.export_regions'))->toRenderable() + ['#attributes' => ['class' => ['button']]],
        'overview'=> Link::fromTextAndUrl($this->t('Workshop‑Übersicht & Restplätze (CSV)'), Url::fromRoute('jfcamp_matching.export_overview'))->toRenderable() + ['#attributes' => ['class' => ['button']]],
      ];

    } catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Report fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }

    return $build;
  }

}
