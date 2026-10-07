<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\jfcamp_matching\Service\AssignmentPersistenceService;
use Drupal\jfcamp_matching\Service\MatchingRunStorage;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class MatchingDashboardController extends ControllerBase {

  public function __construct(
    private MatchingRunStorage $runStorage,
    private QueueFactory $queueFactory,
    private MatchingClient $client,
    private AssignmentPersistenceService $assignmentPersistence,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_matching.run_storage'),
      $container->get('queue'),
      $container->get('jfcamp_matching.client'),
      $container->get('jfcamp_matching.assignment_persistence'),
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
    $activeRun = $this->runStorage->latestActive();
    $currentApplied = $this->runStorage->currentApplied();
    $matchingConfig = $this->readMatchingConfigFromDrupal();
    $latestFreshness = (
      $latestRun
      && $latestRun['status'] === 'completed'
      && $latestRun['apply_status'] !== 'applied'
    ) ? $this->freshnessForRun($latestRun) : NULL;

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

    if ($matchingConfig && !$activeRun) {
      $build['run_action'] = [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ url }}" method="post"><button class="button button--primary">{{ label }}</button></form>',
        '#context' => [
          'url' => Url::fromRoute('jfcamp_matching.action_dry_run')->toString(),
          'label' => $this->t('Matching im Hintergrund starten'),
        ],
      ];
      $build['run_hint'] = [
        '#markup' => '<p><small>' . $this->t(
          'Der Lauf wird in die Matching-Warteschlange gestellt. Die Seite muss während der Berechnung nicht geöffnet bleiben.'
        ) . '</small></p>',
      ];
    }
    elseif ($activeRun) {
      $statusUrl = Url::fromRoute(
        'jfcamp_matching.run_status',
        ['run_id' => $activeRun['id']],
      )->toString();

      $build['run_active'] = [
        '#type' => 'inline_template',
        '#template' => '
          <div class="jfcamp-matching-live"
               id="jfcamp-matching-live-status"
               data-status-url="{{ status_url }}"
               data-run-id="{{ run_id }}">
            <p><strong>Run #{{ run_id }} läuft im Hintergrund.</strong></p>
            <p>
              <span data-jfcamp-status>Wird gestartet …</span><br>
              <span data-jfcamp-phase>Fortschritt wird geladen …</span><br>
              <small>Laufzeit: <span data-jfcamp-elapsed>0:00</span></small>
            </p>
            <progress data-jfcamp-progress value="0" max="7" style="width:min(560px,100%);"></progress>
            <p><small data-jfcamp-detail>Die Seite bleibt benutzbar. Nur diese Statusanzeige wird aktualisiert.</small></p>
          </div>',
        '#context' => [
          'status_url' => $statusUrl,
          'run_id' => $activeRun['id'],
        ],
      ];
      $build['#attached']['library'][] = 'jfcamp_matching/status_polling';
    }

    $build['applied_title'] = [
      '#markup' => '<h2>' . $this->t('Aktuell festgeschriebener Stand') . '</h2>',
    ];

    if ($currentApplied) {
      $appliedSummary = is_array($currentApplied['summary'] ?? NULL)
        ? $currentApplied['summary']
        : [];
      $build['applied_state'] = [
        '#type' => 'table',
        '#header' => [$this->t('Merkmal'), $this->t('Wert')],
        '#rows' => [
          [$this->t('Run'), '#' . $currentApplied['id']],
          [$this->t('Festgeschrieben'), $this->formatTimestamp($currentApplied['applied_at'])],
          [$this->t('Snapshot'), substr((string) $currentApplied['applied_hash'], 0, 16) . '…'],
          [$this->t('Zuteilungen'), (string) ($appliedSummary['assignments_total'] ?? 'n/a')],
          [$this->t('Wunsch-Zuteilungen'), (string) ($appliedSummary['wish_assignments'] ?? 'n/a')],
          [$this->t('Auffüllungen'), (string) ($appliedSummary['filler_assignments'] ?? 'n/a')],
        ],
      ];
    }
    else {
      $build['applied_empty'] = [
        '#markup' => '<p><em>' . $this->t(
          'Aktuell ist kein Matching-Run als festgeschriebener Stand markiert.'
        ) . '</em></p>',
      ];
    }

    $build['result_title'] = [
      '#markup' => '<h2>' . $this->t('Letzter Matching-Lauf') . '</h2>',
    ];

    if ($latestRun) {
      $build += $this->buildRunResult($latestRun, $latestFreshness);
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
      && $latestRun['apply_status'] !== 'applied'
      && ($latestFreshness['state'] ?? '') === 'current'
    ) {
      $build['actions']['apply'] = [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ url }}" method="post" style="display:inline-block;margin-right:8px"><button class="button button--primary">{{ label }}</button></form>',
        '#context' => [
          'url' => Url::fromRoute(
            'jfcamp_matching.action_apply',
            ['run_id' => $latestRun['id']],
          )->toString(),
          'label' => $this->t('Run #@id festschreiben', ['@id' => $latestRun['id']]),
        ],
      ];
    }

    elseif (
      $latestRun
      && $latestRun['status'] === 'completed'
      && !empty($latestRun['snapshot']['by_participant'])
      && $latestRun['apply_status'] !== 'applied'
    ) {
      $build['actions']['apply_blocked'] = [
        '#markup' => '<p><strong>' . $this->t('Festschreiben blockiert:') . '</strong> '
          . $this->escape((string) ($latestFreshness['message'] ?? $this->t('Der Eingabestand konnte nicht verifiziert werden.')))
          . '</p>',
      ];
    }

    if ($currentApplied) {
      $build['actions']['reset'] = [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ url }}" method="post" style="display:inline-block"><button class="button button--danger">{{ label }}</button></form>',
        '#context' => [
          'url' => Url::fromRoute(
            'jfcamp_matching.action_reset',
            ['run_id' => $currentApplied['id']],
          )->toString(),
          'label' => $this->t('Run #@id zurücksetzen', ['@id' => $currentApplied['id']]),
        ],
      ];
    }

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
   * GET /run/{run_id}/status -> kompakter Polling-Endpunkt fürs Dashboard.
   */
  public function runStatus(int $run_id): JsonResponse {
    $run = $this->runStorage->load($run_id);
    if (!$run) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'Run nicht gefunden.',
      ], 404);
    }

    $now = time();
    $startedAt = $run['started_at'] ?? NULL;
    $elapsed = $startedAt ? max(0, $now - (int) $startedAt) : 0;

    $progress = [];
    if ($run['status'] === 'running') {
      $progress = $this->client->progress($run_id);
    }

    $phase = (string) ($progress['phase'] ?? (
      $run['status'] === 'queued' ? 'queued' : $run['status']
    ));

    $labels = [
      'queued' => $this->t('Wartet auf den Matching-Worker'),
      'loading_data' => $this->t('Drupal-Daten und Matching-Konfiguration werden geladen'),
      'priority_1' => $this->t('Priorität 1/5: möglichst viele Wunsch-Zuteilungen'),
      'priority_2' => $this->t('Priorität 2/5: möglichst viele vollständig über Wünsche versorgen'),
      'priority_3' => $this->t('Priorität 3/5: Top-k-Wünsche maximieren'),
      'priority_4' => $this->t('Priorität 4/5: Wunschqualität optimieren'),
      'priority_5' => $this->t('Priorität 5/5: Fairness verbessern'),
      'completion' => $this->t('Offene Plätze werden aufgefüllt'),
      'slot' => $this->t('Workshops werden auf Slots verteilt'),
      'finalizing' => $this->t('Ergebnis wird vorbereitet'),
      'completed' => $this->t('Matching abgeschlossen'),
      'failed' => $this->t('Matching fehlgeschlagen'),
      'running' => $this->t('Matching wird berechnet'),
      'unknown' => $this->t('Matching wird berechnet'),
    ];

    $current = (int) ($progress['current'] ?? 0);
    $total = max(1, (int) ($progress['total'] ?? 7));

    return new JsonResponse([
      'ok' => TRUE,
      'run_id' => $run['id'],
      'status' => $run['status'],
      'status_label' => match ($run['status']) {
        'queued' => (string) $this->t('Warteschlange'),
        'running' => (string) $this->t('Läuft'),
        'completed' => (string) $this->t('Abgeschlossen'),
        'failed' => (string) $this->t('Fehlgeschlagen'),
        default => $run['status'],
      },
      'phase' => $phase,
      'phase_label' => (string) ($labels[$phase] ?? $labels['unknown']),
      'progress_current' => min($current, $total),
      'progress_total' => $total,
      'elapsed_seconds' => $elapsed,
      'updated_at' => $progress['updated_at'] ?? NULL,
      'detail' => is_array($progress['detail'] ?? NULL) ? $progress['detail'] : [],
      'error_message' => $run['error_message'],
      'finished_at' => $run['finished_at'],
      'terminal' => in_array($run['status'], ['completed', 'failed'], TRUE),
    ], 200, [
      'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
  }


  /**
   * POST /dry-run -> legt einen persistenten Run an und reiht ihn ein.
   *
   * Der HTTP-Request kehrt sofort zum Dashboard zurück. Die eigentliche
   * Berechnung übernimmt der Queue-Worker außerhalb des Browser-Requests.
   */
  public function actionDryRun(Request $request): RedirectResponse {
    try {
      if ($this->runStorage->hasActive()) {
        $active = $this->runStorage->latestActive();
        $this->messenger()->addWarning($this->t(
          'Es läuft bereits Matching-Run #@run. Bitte warten, bis dieser beendet ist.',
          ['@run' => $active['id'] ?? '?'],
        ));
        return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
      }

      $matchingConfig = $this->readMatchingConfigFromDrupal();
      if (!$matchingConfig) {
        throw new \RuntimeException('Keine veröffentlichte matching_config gefunden.');
      }

      $runId = $this->runStorage->createQueued($matchingConfig);
      $queue = $this->queueFactory->get('jfcamp_matching_runs');
      $queue->createItem(['run_id' => $runId]);

      $this->messenger()->addStatus($this->t(
        'Matching-Run #@run wurde gestartet und läuft im Hintergrund.',
        ['@run' => $runId],
      ));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Matching konnte nicht gestartet werden: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
  }

  /**
   * POST /run/{run_id}/apply -> wendet ausschließlich genau diesen Snapshot an.
   *
   * Es wird kein Matching-Service aufgerufen und nichts neu berechnet.
   */
  public function actionApply(int $run_id, Request $request) {
    try {
      $run = $this->runStorage->loadApplicable($run_id);
      $freshness = $this->freshnessForRun($run);
      if (($freshness['state'] ?? '') !== 'current') {
        throw new \RuntimeException((string) (
          $freshness['message']
          ?? 'Der Matching-Eingabestand konnte nicht als aktuell verifiziert werden.'
        ));
      }

      $snapshot = $run['snapshot'];
      $resultHash = (string) $run['result_hash'];
      $result = $this->assignmentPersistence->apply(
        $this->cfg(),
        $snapshot['by_participant'],
        $run_id,
        $resultHash,
      );

      $this->messenger()->addStatus($this->t(
        'Run #@run wurde atomar aus Snapshot @hash festgeschrieben: @participants Teilnehmer, @assignments Zuweisungen verifiziert.',
        [
          '@run' => $run_id,
          '@hash' => substr($resultHash, 0, 16) . '…',
          '@participants' => $result['updated_participants'] ?? 0,
          '@assignments' => $result['written_assignments'] ?? 0,
        ],
      ));

      return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t(
        'Run #@run konnte nicht angewendet werden: @message',
        [
          '@run' => $run_id,
          '@message' => $e->getMessage(),
        ],
      ));
      return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
    }
  }

  /**
   * POST /run/{run_id}/reset -> setzt genau den aktuell angewendeten Run zurück.
   */
  public function actionReset(int $run_id, Request $request) {
    try {
      $run = $this->runStorage->loadCurrentApplied($run_id);
      $appliedHash = (string) $run['applied_hash'];

      $result = $this->assignmentPersistence->reset(
        $this->cfg(),
        $run_id,
        $appliedHash,
      );

      $this->messenger()->addStatus($this->t(
        'Run #@run wurde atomar zurückgesetzt: @participants Teilnehmer verifiziert.',
        [
          '@run' => $run_id,
          '@participants' => $result['reset_participants'] ?? 0,
        ],
      ));
      return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t(
        'Run #@run konnte nicht zurückgesetzt werden: @message',
        [
          '@run' => $run_id,
          '@message' => $e->getMessage(),
        ],
      ));
      return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
    }
  }

  /**
   * Prüft, ob der beim Run verwendete Matching-Input noch dem aktuellen
   * normalisierten Eingabestand des Matching-Service entspricht.
   */
  private function freshnessForRun(?array $run): array {
    if (!$run || ($run['status'] ?? '') !== 'completed') {
      return [
        'state' => 'not_applicable',
        'message' => '',
      ];
    }

    $storedFingerprint = strtolower(trim((string) ($run['input_fingerprint'] ?? '')));
    $storedVersion = (int) ($run['input_fingerprint_version'] ?? 0);
    if (!preg_match('/^[a-f0-9]{64}$/', $storedFingerprint) || $storedVersion < 1) {
      return [
        'state' => 'unverifiable',
        'message' => (string) $this->t(
          'Dieser Lauf wurde ohne verifizierbaren Eingabe-Fingerprint gespeichert. Bitte ein neues Matching starten.'
        ),
      ];
    }

    try {
      $current = $this->client->inputFingerprint();
    }
    catch (\Throwable $e) {
      return [
        'state' => 'check_failed',
        'message' => (string) $this->t(
          'Der aktuelle Matching-Eingabestand konnte nicht geprüft werden. Apply bleibt sicherheitshalber blockiert: @message',
          ['@message' => $e->getMessage()],
        ),
      ];
    }

    $currentFingerprint = strtolower((string) $current['input_fingerprint']);
    $currentVersion = (int) $current['input_fingerprint_version'];
    if ($currentVersion !== $storedVersion) {
      return [
        'state' => 'stale',
        'message' => (string) $this->t(
          'Die Fingerprint-Version des aktuellen Eingabestands unterscheidet sich von diesem Run. Bitte ein neues Matching starten.'
        ),
      ];
    }

    if (!hash_equals($storedFingerprint, $currentFingerprint)) {
      return [
        'state' => 'stale',
        'message' => (string) $this->t(
          'Teilnehmer, Wünsche, Workshops, Kapazitäten oder die Matching-Konfiguration haben sich seit der Berechnung geändert. Bitte ein neues Matching starten.'
        ),
      ];
    }

    return [
      'state' => 'current',
      'message' => (string) $this->t('Der Matching-Eingabestand ist unverändert.'),
    ];
  }

  private function buildRunResult(array $run, ?array $freshness = NULL): array {
    $build = [];

    $statusLabels = [
      'queued' => $this->t('wartet'),
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

    if (!empty($run['input_fingerprint'])) {
      $metaRows[] = [
        $this->t('Input-Fingerprint'),
        substr((string) $run['input_fingerprint'], 0, 16) . '…'
          . ' (v' . (int) ($run['input_fingerprint_version'] ?? 0) . ')',
      ];
    }

    if ($run['status'] === 'completed' && $freshness !== NULL) {
      $freshnessLabels = [
        'current' => $this->t('aktuell – Apply möglich'),
        'stale' => $this->t('VERALTET – Apply blockiert'),
        'unverifiable' => $this->t('nicht verifizierbar – Apply blockiert'),
        'check_failed' => $this->t('Prüfung fehlgeschlagen – Apply blockiert'),
      ];
      $metaRows[] = [
        $this->t('Eingabestand'),
        (string) ($freshnessLabels[$freshness['state'] ?? ''] ?? ($freshness['state'] ?? 'unbekannt')),
      ];
    }

    $applyStatusLabels = [
      'not_applied' => $this->t('noch nicht festgeschrieben'),
      'applying' => $this->t('wird festgeschrieben'),
      'applied' => $this->t('aktuell festgeschrieben'),
      'superseded' => $this->t('durch neueren Run ersetzt'),
      'reset' => $this->t('zurückgesetzt'),
      'failed' => $this->t('Festschreiben fehlgeschlagen'),
    ];
    $metaRows[] = [
      $this->t('Apply-Status'),
      (string) ($applyStatusLabels[$run['apply_status']] ?? $run['apply_status']),
    ];
    if (!empty($run['applied_at'])) {
      $metaRows[] = [$this->t('Festgeschrieben'), $this->formatTimestamp($run['applied_at'])];
    }
    if (!empty($run['applied_hash'])) {
      $metaRows[] = [$this->t('Angewendeter Hash'), substr($run['applied_hash'], 0, 16) . '…'];
    }
    if (!empty($run['reset_at'])) {
      $metaRows[] = [$this->t('Zurückgesetzt'), $this->formatTimestamp($run['reset_at'])];
    }

    $build['run_meta'] = [
      '#type' => 'table',
      '#header' => [$this->t('Run-Metadatum'), $this->t('Wert')],
      '#rows' => $metaRows,
    ];

    if (
      $run['status'] === 'completed'
      && $freshness !== NULL
      && ($freshness['state'] ?? '') !== 'current'
    ) {
      $build['run_freshness_warning'] = [
        '#markup' => '<p><strong>' . $this->t('Apply nicht möglich:') . '</strong> '
          . $this->escape((string) ($freshness['message'] ?? $this->t('Der Eingabestand konnte nicht verifiziert werden.')))
          . '</p>',
      ];
    }

    if ($run['status'] === 'failed') {
      $build['run_error'] = [
        '#markup' => '<p><strong>' . $this->t('Fehler:') . '</strong> '
          . $this->escape((string) ($run['error_message'] ?? 'Unbekannter Fehler'))
          . '</p>',
      ];
      return $build;
    }

    if ($run['status'] === 'queued' || $run['status'] === 'running') {
      $build['run_running'] = [
        '#markup' => '<p><em>' . ($run['status'] === 'queued'
          ? $this->t('Dieser Run wartet auf den Matching-Worker.')
          : $this->t('Dieser Run wird gerade im Hintergrund berechnet.')) . '</em></p>',
      ];
      return $build;
    }

    if (!empty($run['snapshot']) && is_array($run['snapshot'])) {
      $build += $this->buildSnapshotResult($run['snapshot']);
    }

    return $build;
  }

  private function buildRunHistory(): array {
    $runs = $this->runStorage->recent(15);
    if (!$runs) {
      return [
        '#markup' => '<p><em>' . $this->t('Noch keine Matching-Läufe vorhanden.') . '</em></p>',
      ];
    }

    $runStatusLabels = [
      'queued' => $this->t('wartet'),
      'running' => $this->t('läuft'),
      'completed' => $this->t('abgeschlossen'),
      'failed' => $this->t('fehlgeschlagen'),
    ];
    $applyStatusLabels = [
      'not_applied' => $this->t('nicht angewendet'),
      'applying' => $this->t('wird angewendet'),
      'applied' => $this->t('AKTUELL ANGEWENDET'),
      'superseded' => $this->t('ersetzt'),
      'reset' => $this->t('zurückgesetzt'),
      'failed' => $this->t('Apply fehlgeschlagen'),
    ];

    $rows = [];
    foreach ($runs as $run) {
      $sum = is_array($run['summary'] ?? NULL) ? $run['summary'] : [];
      $quality = (string) ($sum['matching_quality_status'] ?? $sum['run_quality'] ?? '–');

      $rows[] = [
        '#' . $run['id'],
        (string) ($runStatusLabels[$run['status']] ?? $run['status']),
        (string) ($applyStatusLabels[$run['apply_status']] ?? $run['apply_status']),
        $quality,
        isset($sum['wish_assignments']) ? (string) $sum['wish_assignments'] : '–',
        isset($sum['filler_assignments']) ? (string) $sum['filler_assignments'] : '–',
        $this->formatTimestamp($run['created_at']),
        $run['applied_at'] ? $this->formatTimestamp($run['applied_at']) : '–',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Run'),
        $this->t('Berechnung'),
        $this->t('Festschreibung'),
        $this->t('Qualität'),
        $this->t('Wünsche'),
        $this->t('Filler'),
        $this->t('Erstellt'),
        $this->t('Angewendet'),
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
