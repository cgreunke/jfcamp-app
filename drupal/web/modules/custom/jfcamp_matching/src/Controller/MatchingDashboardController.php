<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\jfcamp_matching\Batch\ApplyAssignmentsBatch;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Drupal\jfcamp_matching\Service\MatchingRunStorage;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class MatchingDashboardController extends ControllerBase {

  public function __construct(
    private MatchingClient $client,
    private MatchingRunStorage $runStorage,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_matching.client'),
      $container->get('jfcamp_matching.run_storage'),
    );
  }

  private function cfg(): array {
    $c = \Drupal::config('jfcamp_matching.settings');
    return [
      'participant_bundle' => $c->get('participant_bundle') ?? 'teilnehmer',
      'workshop_bundle' => $c->get('workshop_bundle') ?? 'workshop',
      'assignment_mode' => $c->get('assignment_mode') ?? 'auto',
      'slot_fields_prefix' => $c->get('slot_fields_prefix') ?? 'field_slot_',
      'num_slots' => (int) ($c->get('num_slots') ?? 3),
      'assigned_field' => $c->get('assigned_field') ?? 'field_zugewiesen',
    ];
  }

  public function dashboard(): array {
    $latestRun = $this->runStorage->latest();
    $matchingConfig = $this->readMatchingConfigFromDrupal();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-matching-dashboard']],
      '#cache' => ['max-age' => 0],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Das Matching verwendet genau eine feste CP-SAT-Strategie. Wünsche und Anzahl der Zuteilungen werden aus der veröffentlichten Matching-Konfiguration gelesen.'
      ) . '</p>',
    ];

    $build['config_title'] = [
      '#markup' => '<h2>' . $this->t('Aktuelle Matching-Konfiguration') . '</h2>',
    ];

    if ($matchingConfig) {
      $build['config_meta'] = [
        '#markup' => '<p><strong>' . $this->escape($matchingConfig['label']) . '</strong><br>'
          . $this->t('Zuletzt geändert: @date', [
            '@date' => \Drupal::service('date.formatter')->format($matchingConfig['changed'], 'short'),
          ]) . '</p>',
      ];
      $build['config_table'] = $this->renderConfigTable($matchingConfig['values']);
    }
    else {
      $build['config_missing'] = [
        '#markup' => '<p><strong>' . $this->t('Keine veröffentlichte matching_config gefunden.') . '</strong></p>',
      ];
    }

    $build['run_title'] = [
      '#markup' => '<h2>' . $this->t('Matching berechnen') . '</h2>',
    ];

    if ($matchingConfig) {
      $build['run_action'] = [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ url }}" method="post"><button class="button button--primary">{{ label }}</button></form>',
        '#context' => [
          'url' => Url::fromRoute('jfcamp_matching.action_dry_run')->toString(),
          'label' => $this->t('Matching berechnen'),
        ],
      ];
      $build['run_hint'] = [
        '#markup' => '<p><small>' . $this->t(
          'Phase 3B speichert jeden Lauf bereits dauerhaft. Die Berechnung selbst läuft noch synchron; echte Hintergrundverarbeitung und Live-Fortschritt folgen in Phase 3C.'
        ) . '</small></p>',
      ];
    }

    $build['result_title'] = [
      '#markup' => '<h2>' . $this->t('Letzter Matching-Lauf') . '</h2>',
    ];

    if ($latestRun) {
      $build += $this->buildRunResult($latestRun);
    }
    else {
      $build['result_empty'] = [
        '#markup' => '<p><em>' . $this->t('Noch kein persistenter Matching-Lauf vorhanden.') . '</em></p>',
      ];
    }

    $build['history_title'] = [
      '#markup' => '<h2>' . $this->t('Letzte Matching-Läufe') . '</h2>',
    ];
    $build['history'] = $this->buildRunHistory();

    $build['actions_title'] = [
      '#markup' => '<h2>' . $this->t('Aktionen') . '</h2>',
    ];
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    if (
      $latestRun
      && $latestRun['status'] === 'completed'
      && !empty($latestRun['snapshot']['by_participant'])
    ) {
      $build['actions']['apply'] = [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ url }}" method="post" style="display:inline-block;margin-right:8px"><button class="button button--primary">{{ label }}</button></form>',
        '#context' => [
          'url' => Url::fromRoute('jfcamp_matching.action_apply')->toString(),
          'label' => $this->t('Run #@id festschreiben', ['@id' => $latestRun['id']]),
        ],
      ];
    }

    $build['actions']['reset'] = [
      '#type' => 'inline_template',
      '#template' => '<form action="{{ url }}" method="post" style="display:inline-block"><button class="button button--danger">{{ label }}</button></form>',
      '#context' => [
        'url' => Url::fromRoute('jfcamp_matching.action_reset')->toString(),
        'label' => $this->t('Festgeschriebene Zuteilungen zurücksetzen'),
      ],
    ];

    $build['exports_title'] = [
      '#markup' => '<h2>' . $this->t('Exporte aus Drupal') . '</h2>',
    ];
    $build['exports_hint'] = [
      '#markup' => '<p><small>' . $this->t(
        'Diese Exporte enthalten die aktuell in Drupal festgeschriebenen Zuteilungen, nicht automatisch den letzten Matching-Run.'
      ) . '</small></p>',
    ];
    $build['exports'] = $this->buildExportLinks($matchingConfig);

    return $build;
  }

  /**
   * POST /dry-run -> persistenter Run mit unveränderlichem Ergebnis-Snapshot.
   *
   * Die Berechnung läuft in Phase 3B noch synchron. Der Run wird aber bereits
   * vor dem Service-Aufruf als "running" angelegt und danach auf "completed"
   * oder "failed" gesetzt. Phase 3C kann darauf die Async-Verarbeitung bauen.
   */
  public function actionDryRun(Request $request): RedirectResponse {
    $runId = NULL;

    try {
      $matchingConfig = $this->readMatchingConfigFromDrupal();
      if (!$matchingConfig) {
        throw new \RuntimeException('Keine veröffentlichte matching_config gefunden.');
      }

      $runId = $this->runStorage->createRunning($matchingConfig);

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
        $matchingConfig,
      );

      $this->messenger()->addStatus($this->t(
        'Run #@run abgeschlossen: @participants Teilnehmer, @assignments Zuteilungen, davon @wishes Wünsche und @fillers Auffüllungen.',
        [
          '@run' => $runId,
          '@participants' => $summary['participants_total'] ?? count($byParticipant),
          '@assignments' => $summary['assignments_total'] ?? 0,
          '@wishes' => $summary['wish_assignments'] ?? 0,
          '@fillers' => $summary['filler_assignments'] ?? 0,
        ]
      ));
    }
    catch (\Throwable $e) {
      if ($runId !== NULL) {
        try {
          $this->runStorage->markFailed($runId, $e->getMessage());
        }
        catch (\Throwable $storageError) {
          \Drupal::logger('jfcamp_matching')->error(
            'Run #@run konnte nach Fehler nicht als failed gespeichert werden: @message',
            ['@run' => $runId, '@message' => $storageError->getMessage()],
          );
        }
      }

      $this->messenger()->addError($this->t('Matching fehlgeschlagen: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
  }

  /**
   * POST /apply -> wendet ausschließlich den Snapshot des neuesten Runs an.
   *
   * Ein fehlgeschlagener oder laufender neuer Run blockiert damit bewusst den
   * Apply-Button für einen älteren Run. Eine gezielte Auswahl historischer Runs
   * folgt zusammen mit Review/Apply in einer späteren Phase.
   */
  public function actionApply(Request $request) {
    $run = $this->runStorage->latest();
    $snapshot = $run['snapshot'] ?? NULL;

    if (
      !$run
      || $run['status'] !== 'completed'
      || !$snapshot
      || empty($snapshot['by_participant'])
      || !is_array($snapshot['by_participant'])
    ) {
      $this->messenger()->addError($this->t(
        'Der neueste Matching-Run ist nicht anwendbar. Bitte zuerst einen erfolgreichen Matching-Lauf abschließen.'
      ));
      return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
    }

    $config = $this->cfg();
    $batch = ApplyAssignmentsBatch::build($config, $snapshot['by_participant']);
    batch_set($batch);

    $sum = $snapshot['summary'] ?? [];
    $this->messenger()->addStatus($this->t(
      'Festschreiben von Run #@run gestartet: @participants Teilnehmer, @assignments Zuteilungen. Das Matching wird dabei nicht neu berechnet.',
      [
        '@run' => $run['id'],
        '@participants' => $sum['participants_total'] ?? count($snapshot['by_participant']),
        '@assignments' => $sum['assignments_total'] ?? 0,
      ]
    ));

    return batch_process(Url::fromRoute('jfcamp_matching.dashboard'));
  }

  /** POST /reset -> löscht die in Drupal festgeschriebenen Zuteilungen. */
  public function actionReset(Request $request) {
    $config = $this->cfg();
    $batch = ApplyAssignmentsBatch::buildReset($config);
    batch_set($batch);

    $this->messenger()->addStatus($this->t('Zurücksetzen der Zuteilungen gestartet …'));
    return batch_process(Url::fromRoute('jfcamp_matching.dashboard'));
  }

  private function buildRunResult(array $run): array {
    $build = [];

    $statusLabels = [
      'running' => $this->t('läuft'),
      'completed' => $this->t('abgeschlossen'),
      'failed' => $this->t('fehlgeschlagen'),
    ];

    $metaRows = [
      [$this->t('Run'), '#' . $run['id']],
      [$this->t('Status'), (string) ($statusLabels[$run['status']] ?? $run['status'])],
      [$this->t('Erstellt'), $this->formatTimestamp($run['created_at'])],
      [$this->t('Gestartet'), $this->formatTimestamp($run['started_at'])],
      [$this->t('Beendet'), $this->formatTimestamp($run['finished_at'])],
    ];

    if (!empty($run['result_hash'])) {
      $metaRows[] = [$this->t('Snapshot-Hash'), substr($run['result_hash'], 0, 16) . '…'];
    }

    $build['run_meta'] = [
      '#type' => 'table',
      '#header' => [$this->t('Run-Metadatum'), $this->t('Wert')],
      '#rows' => $metaRows,
    ];

    if ($run['status'] === 'failed') {
      $build['run_error'] = [
        '#markup' => '<p><strong>' . $this->t('Fehler:') . '</strong> '
          . $this->escape((string) ($run['error_message'] ?? 'Unbekannter Fehler'))
          . '</p>',
      ];
      return $build;
    }

    if ($run['status'] === 'running') {
      $build['run_running'] = [
        '#markup' => '<p><em>' . $this->t(
          'Dieser Run ist als laufend gespeichert. In Phase 3B blockiert der synchrone Request die Oberfläche noch; Phase 3C übernimmt die Hintergrundverarbeitung.'
        ) . '</em></p>',
      ];
      return $build;
    }

    if (!empty($run['snapshot']) && is_array($run['snapshot'])) {
      $build += $this->buildSnapshotResult($run['snapshot']);
    }

    return $build;
  }

  private function buildRunHistory(): array {
    $runs = $this->runStorage->recent(10);
    if (!$runs) {
      return [
        '#markup' => '<p><em>' . $this->t('Noch keine Matching-Läufe vorhanden.') . '</em></p>',
      ];
    }

    $rows = [];
    foreach ($runs as $run) {
      $rows[] = [
        '#' . $run['id'],
        $run['status'],
        $this->formatTimestamp($run['created_at']),
        $this->formatTimestamp($run['finished_at']),
        $run['result_hash'] ? substr($run['result_hash'], 0, 12) . '…' : '–',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Run'),
        $this->t('Status'),
        $this->t('Erstellt'),
        $this->t('Beendet'),
        $this->t('Snapshot'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Noch keine Matching-Läufe vorhanden.'),
    ];
  }

  private function buildSnapshotResult(array $snapshot): array {
    $sum = is_array($snapshot['summary'] ?? NULL) ? $snapshot['summary'] : [];
    $build = [];

    if (!empty($snapshot['created_at'])) {
      $build['result_time'] = [
        '#markup' => '<p>' . $this->t('Ergebnis gespeichert am: @date', [
          '@date' => \Drupal::service('date.formatter')->format((int) $snapshot['created_at'], 'short'),
        ]) . '</p>',
      ];
    }

    $status = (string) ($sum['matching_quality_status'] ?? $sum['run_quality'] ?? 'unbekannt');
    $rows = [
      [$this->t('Qualitätsstatus'), $status],
      [$this->t('Teilnehmer'), (string) ($sum['participants_total'] ?? count($snapshot['by_participant'] ?? []))],
      [$this->t('Vollständig zugeteilt'), (string) ($sum['participants_fully_assigned'] ?? 'n/a')],
      [$this->t('Zuteilungen gesamt'), $this->fraction($sum['assignments_total'] ?? NULL, $sum['target_assignments_total'] ?? NULL)],
      [$this->t('Wunsch-Zuteilungen'), (string) ($sum['wish_assignments'] ?? 'n/a')],
      [$this->t('Auffüllungen / Nicht-Wünsche'), (string) ($sum['filler_assignments'] ?? 'n/a')],
      [$this->t('Top-k-Treffer'), (string) ($sum['topk_hits'] ?? 'n/a')],
      [$this->t('Happy-Index'), $this->formatNumber($sum['happy_index'] ?? NULL)],
      [$this->t('Schlechtester Happy-Wert'), $this->formatNumber($sum['min_user_happy'] ?? NULL)],
      [$this->t('Median Happy-Wert'), $this->formatNumber($sum['median_user_happy'] ?? NULL)],
      [$this->t('Solver-Laufzeit'), isset($sum['total_solver_seconds']) ? $this->formatNumber($sum['total_solver_seconds'], 3) . ' s' : 'n/a'],
    ];

    $build['result_summary'] = [
      '#type' => 'table',
      '#header' => [$this->t('Kennzahl'), $this->t('Wert')],
      '#rows' => $rows,
    ];

    $warnings = [];
    if (isset($sum['all_filled_to_slots']) && !$sum['all_filled_to_slots']) {
      $warnings[] = $this->t('Nicht alle Teilnehmer sind vollständig zugeteilt.');
    }
    if ((int) ($sum['filler_assignments'] ?? 0) > 0) {
      $warnings[] = $this->t('@count Zuteilungen sind Auffüllungen und keine Wünsche.', [
        '@count' => (int) $sum['filler_assignments'],
      ]);
    }
    if ((int) ($sum['warning_capacity_deficit'] ?? 0) > 0) {
      $warnings[] = $this->t('Es fehlen rechnerisch @count Plätze in der Gesamtkapazität.', [
        '@count' => (int) $sum['warning_capacity_deficit'],
      ]);
    }
    if ($status !== 'optimal') {
      $warnings[] = $this->t('Der Solver meldet keinen vollständig bewiesenen Optimalstatus: @status.', [
        '@status' => $status,
      ]);
    }

    if ($warnings) {
      $build['result_warnings'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Hinweise'),
        '#items' => $warnings,
      ];
    }

    $perPriority = $sum['per_priority_fulfilled'] ?? [];
    if (is_array($perPriority) && $perPriority) {
      ksort($perPriority, SORT_NUMERIC);
      $priorityRows = [];
      foreach ($perPriority as $rank => $count) {
        $priorityRows[] = [$this->t('Wunsch @rank', ['@rank' => $rank]), (int) $count];
      }
      $build['priority_table'] = [
        '#type' => 'table',
        '#caption' => $this->t('Erfüllte Wünsche nach Rang'),
        '#header' => [$this->t('Rang'), $this->t('Anzahl')],
        '#rows' => $priorityRows,
      ];
    }

    $perSlot = $sum['per_slot_assigned_counts'] ?? [];
    if (is_array($perSlot) && $perSlot) {
      ksort($perSlot, SORT_NUMERIC);
      $slotRows = [];
      foreach ($perSlot as $slot => $count) {
        $slotRows[] = [(string) $slot, (int) $count];
      }
      $build['slot_table'] = [
        '#type' => 'table',
        '#caption' => $this->t('Zuteilungen je Slot'),
        '#header' => [$this->t('Slot'), $this->t('Zuteilungen')],
        '#rows' => $slotRows,
      ];
    }

    return $build;
  }

  private function buildExportLinks(?array $matchingConfig): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $build['all'] = Link::fromTextAndUrl(
      $this->t('Alle Slots (CSV)'),
      Url::fromRoute('jfcamp_matching.export_attendance_all')
    )->toRenderable() + ['#attributes' => ['class' => ['button', 'button--primary']]];

    $numSlots = 0;
    if ($matchingConfig) {
      $numSlots = (int) ($matchingConfig['values']['field_num_zuteilung'] ?? 0);
    }
    if ($numSlots <= 0) {
      $numSlots = max(1, (int) ($this->cfg()['num_slots'] ?? 1));
    }

    for ($slot = 1; $slot <= $numSlots; $slot++) {
      $build['slot_' . $slot] = Link::fromTextAndUrl(
        $this->t('Slot @slot (CSV)', ['@slot' => $slot]),
        Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => $slot])
      )->toRenderable() + ['#attributes' => ['class' => ['button']]];
    }

    $build['regions'] = Link::fromTextAndUrl(
      $this->t('Regionalverbände (CSV)'),
      Url::fromRoute('jfcamp_matching.export_regions')
    )->toRenderable() + ['#attributes' => ['class' => ['button']]];

    return $build;
  }

  private function readMatchingConfigFromDrupal(): ?array {
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $node = Node::load((int) reset($ids));
    if (!$node) {
      return NULL;
    }

    return [
      'label' => $node->label(),
      'uuid' => $node->uuid(),
      'changed' => (int) $node->getChangedTime(),
      'values' => $this->extractMatchingConfigValues($node),
    ];
  }

  private function extractMatchingConfigValues(Node $node): array {
    $get = static fn(string $field, $default = NULL) => $node->hasField($field)
      ? $node->get($field)->value
      : $default;

    return [
      'field_num_wuensche' => (int) ($get('field_num_wuensche', 5) ?: 5),
      'field_num_zuteilung' => (int) ($get('field_num_zuteilung', 4) ?: 4),
      'field_slot_start' => (string) ($get('field_slot_start', '') ?: ''),
      'field_slot_end' => (string) ($get('field_slot_end', '') ?: ''),
    ];
  }

  private function renderConfigTable(array $values): array {
    $labels = [
      'field_num_wuensche' => $this->t('Anzahl Wünsche'),
      'field_num_zuteilung' => $this->t('Anzahl Zuteilungen / Slots'),
      'field_slot_start' => $this->t('Slot-Start'),
      'field_slot_end' => $this->t('Slot-Ende'),
    ];

    $rows = [];
    foreach ($values as $key => $value) {
      $rows[] = [
        (string) ($labels[$key] ?? $key),
        $value === '' ? '–' : (string) $value,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Einstellung'), $this->t('Wert')],
      '#rows' => $rows,
    ];
  }

  private function fraction(mixed $value, mixed $target): string {
    if ($value === NULL) {
      return 'n/a';
    }
    if ($target === NULL) {
      return (string) $value;
    }
    return $value . ' / ' . $target;
  }

  private function formatNumber(mixed $value, int $decimals = 3): string {
    if ($value === NULL || $value === '') {
      return 'n/a';
    }
    if (!is_numeric($value)) {
      return (string) $value;
    }
    return number_format((float) $value, $decimals, ',', '.');
  }

  private function formatTimestamp(?int $timestamp): string {
    if (!$timestamp) {
      return '–';
    }
    return \Drupal::service('date.formatter')->format($timestamp, 'short');
  }

  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
