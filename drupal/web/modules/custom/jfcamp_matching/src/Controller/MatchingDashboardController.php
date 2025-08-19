<?php

namespace Drupal\jfcamp_matching\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\jfcamp_matching\Service\MatchingClient;
use Drupal\jfcamp_matching\Batch\ApplyAssignmentsBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class MatchingDashboardController extends ControllerBase {

  public function __construct(private MatchingClient $client) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('jfcamp_matching.client'));
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
    $state = \Drupal::state();
    $last = $state->get('jfcamp_matching.last_dryrun') ?: NULL;

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-dashboard']],
      '#cache' => ['max-age' => 0],
    ];

    // --- ACTION BUTTONS ---
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline'], 'style' => 'margin-bottom:12px'],
    ];
    $build['actions']['dry'] = [
      '#type' => 'inline_template',
      '#template' => '<form action="{{ url }}" method="post" style="display:inline-block;margin-right:8px"><button class="button button--primary">{{ label }}</button></form>',
      '#context' => [
        'url' => Url::fromRoute('jfcamp_matching.action_dry_run')->toString(),
        'label' => $this->t('Dry‑Run ausführen'),
      ],
    ];
    $build['actions']['reset'] = [
      '#type' => 'inline_template',
      '#template' => '<form action="{{ url }}" method="post" style="display:inline-block;margin-right:8px"><button class="button button--danger">{{ label }}</button></form>',
      '#context' => [
        'url' => Url::fromRoute('jfcamp_matching.action_reset')->toString(),
        'label' => $this->t('Zuteilungen zurücksetzen'),
      ],
    ];
    if ($last) {
      $build['actions']['apply'] = [
        '#type' => 'inline_template',
        '#template' => '<form action="{{ url }}" method="post" style="display:inline-block"><button class="button">{{ label }}</button></form>',
        '#context' => [
          'url' => Url::fromRoute('jfcamp_matching.action_apply')->toString(),
          'label' => $this->t('Zuteilungen festschreiben'),
        ],
      ];
    }

    // --- CONFIG: aktuell veröffentlicht ---
    $currentCfg = $this->readMatchingConfigFromDrupal();
    if ($currentCfg) {
      $build['cfg_current_title'] = ['#markup' => '<h3>'.$this->t('Aktuelle Matching‑Config (veröffentlicht)').'</h3>'];
      $build['cfg_current_meta'] = [
        '#theme' => 'item_list',
        '#items' => array_filter([
          $currentCfg['label'] ? $this->t('Titel: @t', ['@t' => $currentCfg['label']]) : NULL,
          $currentCfg['uuid'] ? $this->t('UUID: @u', ['@u' => $currentCfg['uuid']]) : NULL,
          $currentCfg['changed'] ? $this->t('Geändert: @d', ['@d' => \Drupal::service('date.formatter')->format($currentCfg['changed'], 'short')]) : NULL,
        ]),
      ];
      $build['cfg_current_table'] = $this->renderConfigTable($currentCfg['values']);
    } else {
      $build['cfg_current_hint'] = ['#markup' => '<p><em>'.$this->t('Keine veröffentlichte matching_config gefunden.').'</em></p>'];
    }

    // --- LAST DRY-RUN (Summary + Config zum Zeitpunkt des Dry-Runs) ---
    if ($last && is_array($last)) {
      $sum = $last['summary'] ?? [];
      $per_priority = $sum['per_priority_fulfilled'] ?? [];
      $per_slot = $sum['per_slot_assigned_counts'] ?? [];

      $build['summary'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Ergebnis des letzten Dry‑Run'),
        '#items' => array_filter([
          $this->t('Teilnehmer: @v', ['@v' => $sum['participants_total'] ?? 0]),
          $this->t('Zuteilungen gesamt: @v', ['@v' => $sum['assignments_total'] ?? 0]),
          isset($sum['participants_no_wishes']) ? $this->t('Teilnehmer ohne Wünsche: @v', ['@v' => $sum['participants_no_wishes']]) : NULL,
          $this->t('Alle mit Slots gefüllt: @af', ['@af' => !empty($sum['all_filled_to_slots']) ? 'ja' : 'nein']),
          isset($sum['happy_index']) ? $this->t('Happy‑Index: @v', ['@v' => $sum['happy_index']]) : NULL,
          isset($sum['capacity_remaining_total']) ? $this->t('Restplätze gesamt: @v', ['@v' => $sum['capacity_remaining_total']]) : NULL,
        ]),
      ];

      if (!empty($last['config']) && is_array($last['config'])) {
        $build['cfg_last_title'] = ['#markup' => '<h3>'.$this->t('Config beim letzten Dry‑Run').'</h3>'];
        $build['cfg_last_table'] = $this->renderConfigTable($last['config']);
      }

      if ($per_priority) {
        $rows = [];
        foreach ($per_priority as $prio => $v) { $rows[] = [(string) $prio, (int) $v]; }
        $build['prio'] = [
          '#type' => 'table',
          '#caption' => $this->t('Erfüllte Wünsche nach Priorität'),
          '#header' => [$this->t('Priorität'), $this->t('Erfüllt')],
          '#rows' => $rows,
        ];
      }

      if ($per_slot) {
        $rows = [];
        foreach ($per_slot as $slot => $v) { $rows[] = [(string) $slot, (int) $v]; }
        $build['slot'] = [
          '#type' => 'table',
          '#caption' => $this->t('Zuteilungen nach Slot'),
          '#header' => [$this->t('Slot'), $this->t('Zuteilungen')],
          '#rows' => $rows,
        ];
      }
    } else {
      $build['hint'] = ['#markup' => '<p><em>'.$this->t('Noch kein Dry‑Run durchgeführt.').'</em></p>'];
    }

    // --- EXPORT BUTTONS ---
    $build['exports'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline'], 'style' => 'margin-top:12px'],
      'all' => Link::fromTextAndUrl($this->t('Alle Slots (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_all'))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--primary']]],
      's1' => Link::fromTextAndUrl($this->t('Slot 1 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 1]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
      's2' => Link::fromTextAndUrl($this->t('Slot 2 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 2]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
      's3' => Link::fromTextAndUrl($this->t('Slot 3 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 3]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
      'regions' => Link::fromTextAndUrl($this->t('Teilnehmer je Regionalverband (CSV)'), Url::fromRoute('jfcamp_matching.export_regions'))->toRenderable() + ['#attributes' => ['class' => ['button']]],
    ];

    return $build;
  }

  /** POST /dry-run → Snapshot im State speichern (inkl. Config), zurück aufs Dashboard */
  public function actionDryRun(Request $request): RedirectResponse {
    try {
      $res = $this->client->dryRun();

      // Zusätzlich die aktuell veröffentlichte Config mitschneiden,
      // damit im Dashboard klar ist, mit welchen Werten der Dry-Run erfolgte.
      $cfgValues = $this->readMatchingConfigValuesOnly();

      $snapshot = [
        'summary' => $res['summary'] ?? [],
        'by_participant' => $res['by_participant'] ?? [],
        'config' => $cfgValues,
      ];
      \Drupal::state()->set('jfcamp_matching.last_dryrun', $snapshot);

      $sum = $snapshot['summary'] ?? [];
      $this->messenger()->addStatus($this->t('Dry‑Run OK. Happy‑Index: @h, Zuteilungen: @a.', [
        '@h' => $sum['happy_index'] ?? 'n/a',
        '@a' => $sum['assignments_total'] ?? 0,
      ]));
    } catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Dry‑Run fehlgeschlagen: @m', ['@m' => $e->getMessage()]));
    }
    return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
  }

  /** POST /apply → Snapshot per Batch anwenden */
  public function actionApply(Request $request) {
    $snapshot = \Drupal::state()->get('jfcamp_matching.last_dryrun');
    if (!$snapshot || empty($snapshot['by_participant'])) {
      $this->messenger()->addError($this->t('Kein Dry‑Run‑Snapshot vorhanden. Bitte erst Dry‑Run ausführen.'));
      return new RedirectResponse(Url::fromRoute('jfcamp_matching.dashboard')->toString());
    }
    $config = $this->cfg();
    $batch = ApplyAssignmentsBatch::build($config, $snapshot['by_participant']);
    batch_set($batch);

    $sum = $snapshot['summary'] ?? [];
    $this->messenger()->addStatus($this->t(
      'Festschreiben gestartet: @p TN, @a Zuteilungen.',
      ['@p' => $sum['participants_total'] ?? 0, '@a' => $sum['assignments_total'] ?? 0]
    ));

    return batch_process(Url::fromRoute('jfcamp_matching.dashboard'));
  }

  /** POST /reset → Zuteilungen löschen (Batch) */
  public function actionReset(Request $request) {
    $config = $this->cfg();
    $batch = ApplyAssignmentsBatch::buildReset($config);
    batch_set($batch);

    $this->messenger()->addStatus($this->t('Zurücksetzen der Zuteilungen gestartet …'));
    return batch_process(Url::fromRoute('jfcamp_matching.dashboard'));
  }

  // ---------- Helpers: Config aus Drupal lesen & rendern ----------

  /** Liefert komplette veröffentlichte matching_config inkl. Meta, oder NULL. */
  private function readMatchingConfigFromDrupal(): ?array {
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) return NULL;

    /** @var \Drupal\node\Entity\Node $n */
    $n = Node::load((int) reset($ids));
    $values = $this->extractMatchingConfigValues($n);
    return [
      'label' => $n->label(),
      'uuid' => $n->uuid(),
      'changed' => (int) $n->getChangedTime(),
      'values' => $values,
    ];
  }

  /** Liefert nur die Werte der aktuellen Config (für Snapshot-Ablage). */
  private function readMatchingConfigValuesOnly(): array {
    $ids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) return [];
    $n = Node::load((int) reset($ids));
    return $this->extractMatchingConfigValues($n);
  }

  /** Werte aus einem matching_config-Node extrahieren (mit Fallbacks). */
  private function extractMatchingConfigValues(Node $n): array {
    $g = fn(string $f, $d = NULL) => $n->hasField($f) ? $n->get($f)->value : $d;

    // Felder & Fallbacks analog zu deinem matching_server.py
    $num_wishes = (int) ($g('field_num_wuensche', 5) ?: 5);
    $num_assign = (int) ($g('field_zuteilung', $g('field_num_zuteilung', 3)) ?: 3);
    $seed = (string) ($g('field_seed', '') ?: '');
    $topk_equals_slots = (bool) ($g('field_topk_equals_slots', 1));
    $slicing_mode = (string) ($g('field_slicing_mode', 'relative') ?: 'relative');
    $slicing_value = (int) ($g('field_slicing_value', 50) ?: 50);

    $weights = [
      1 => (float) ($g('field_weight_p1', 1.0) ?: 1.0),
      2 => (float) ($g('field_weight_p2', 0.8) ?: 0.8),
      3 => (float) ($g('field_weight_p3', 0.6) ?: 0.6),
      4 => (float) ($g('field_weight_p4', 0.4) ?: 0.4),
      5 => (float) ($g('field_weight_p5', 0.2) ?: 0.2),
    ];

    return [
      'num_wishes' => $num_wishes,
      'num_assign' => $num_assign,
      'seed' => $seed,
      'topk_equals_slots' => $topk_equals_slots,
      'slicing_mode' => $slicing_mode,
      'slicing_value' => $slicing_value,
      'weights' => $weights,
    ];
  }

  /** Rendert eine kompakte Tabelle für die Config-Werte. */
  private function renderConfigTable(array $cfg): array {
    $w = $cfg['weights'] ?? [];
    $rows = [
      [$this->t('Anzahl Wünsche'), (string) ($cfg['num_wishes'] ?? '—')],
      [$this->t('Zuteilungen je TN (Slots)'), (string) ($cfg['num_assign'] ?? '—')],
      [$this->t('Seed'), (string) (($cfg['seed'] ?? '') !== '' ? $cfg['seed'] : '—')],
      [$this->t('Top‑k = Slots'), !empty($cfg['topk_equals_slots']) ? 'ja' : 'nein'],
      [$this->t('Slicing‑Modus'), (string) ($cfg['slicing_mode'] ?? '—')],
      [$this->t('Slicing‑Wert'), (string) ($cfg['slicing_value'] ?? '—')],
      [$this->t('Gewichte P1..P5'),
        sprintf('P1=%.2f, P2=%.2f, P3=%.2f, P4=%.2f, P5=%.2f',
          (float) ($w[1] ?? 0), (float) ($w[2] ?? 0), (float) ($w[3] ?? 0),
          (float) ($w[4] ?? 0), (float) ($w[5] ?? 0)
        )
      ],
    ];
    return [
      '#type' => 'table',
      '#header' => [$this->t('Parameter'), $this->t('Wert')],
      '#rows' => $rows,
      '#attributes' => ['class' => ['jfcamp-config-table']],
    ];
  }
}
