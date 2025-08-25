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
    $lastParams = $state->get('jfcamp_matching.last_params') ?: [];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-dashboard']],
      '#cache' => ['max-age' => 0],
    ];

    // --- PARAMETER-FORM (kompakt + Hilfetexte/Tooltips) ---
    $defaults = array_merge([
      'strategy' => 'fair',
      'objective' => 'fair_maxmin',
      'seed' => '',
      'seeds' => 12,
      'round_cap_pct' => 50,
      'alpha_fairness' => 0.35,
      'topk_equals_slots' => 1,
      'weights_mode' => '',
      'weights_base' => 0.8,
      'linear_min' => 0.2,
      'weights_json' => '',
      'num_wishes' => '',
      'num_assign' => '',
    ], is_array($lastParams) ? $lastParams : []);

    $helpStyle = 'color:#666;font-size:12px;margin-left:6px';
    $labelW = 'display:inline-block;width:220px';
    $ctlW = 'width:100px';

    $tooltip = function (string $text): string {
      $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      return '<span title="'.$safe.'" aria-label="'.$safe.'" style="cursor:help;color:#666;margin-left:4px">ⓘ</span>';
    };

    // Build HTML with inline tooltips
    $html = <<<HTML
<form action="%{action_dry}" method="post" style="padding:12px;border:1px solid #ddd;margin-bottom:12px">
  <div style="margin-bottom:4px"><strong>Einstellungen (nur für diesen Dry‑Run)</strong></div>

  <div style="margin:8px 0">
    <label style="$labelW">Strategie</label>
    <select name="strategy">
      <option value="fair"   %{sel_fair}>fair</option>
      <option value="solver" %{sel_solver}>solver (leximin)</option>
      <option value="greedy" %{sel_greedy}>greedy</option>
    </select>
    <span style="$helpStyle">fair = ausgewogen, solver = stärkste Fairness, greedy = schnell aber evtl. ungleich</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">Objective (Seed‑Auswahl)</label>
    <select name="objective">
      <option value="fair_maxmin" %{sel_obj_fmm}>fair_maxmin</option>
      <option value="happy_mean"  %{sel_obj_hm}>happy_mean</option>
      <option value="leximin"     %{sel_obj_lex}>leximin</option>
    </select>
    <span style="$helpStyle">Wie wird aus mehreren Seeds gewählt? min↑ / Ø↑ / leximin</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">Seed</label>
    <input type="text" name="seed" value="%{seed}" style="width:180px">
    <span style="$helpStyle">Fixiert die Reihenfolge für Reproduzierbarkeit</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">Seeds (nur fair)</label>
    <input type="number" name="seeds" step="1" min="1" value="%{seeds}" style="$ctlW">
    <span style="$helpStyle">Anzahl Varianten; bestes Ergebnis nach Objective wird gewählt</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">Round Cap % (fair)</label>
    <input type="number" name="round_cap_pct" min="0" max="100" step="1" value="%{round_cap_pct}" style="$ctlW">
    <span style="$helpStyle">Deckel für Renner in Runde 1 (Empfehlung: 50–60)</span>{$tooltip('Begrenzt pro Slot, wie stark die meistgewünschten Workshops (Top ~20%) in Runde 1 gefüllt werden. Niedriger = mehr Umverteilung, schützt „schwächere“ TN.')}
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">Alpha Fairness (fair)</label>
    <input type="number" name="alpha_fairness" min="0" max="1" step="0.01" value="%{alpha_fairness}" style="$ctlW">
    <span style="$helpStyle">Gewichtung Benachteiligter (Empfehlung: 0.35–0.45)</span>{$tooltip('Runde 2 priorisiert TN mit wenig Treffern/Slots. Höher = stärkerer Ausgleich (ggf. etwas geringerer Durchschnitt).')}
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">Top‑k = Slots</label>
    <input type="checkbox" name="topk_equals_slots" value="1" %{chk_topk}>
    <span style="$helpStyle">Top‑k entspricht Anzahl Slots (empfohlen)</span>{$tooltip('Bewertung misst Treffer in den ersten k Wünschen; k = Slots. Beispiel: 3 Slots → Top‑3‑Treffer.')}
  </div>

  <hr style="margin:12px 0">

  <div style="margin:8px 0">
    <label style="$labelW">Gewichte – Modus</label>
    <select name="weights_mode">
      <option value="" %{sel_wm_none}>(manuell/Default)</option>
      <option value="linear" %{sel_wm_lin}>linear</option>
      <option value="geometric" %{sel_wm_geo}>geometric</option>
    </select>
    <span style="$helpStyle">Automatisch erzeugen, falls unten kein JSON angegeben</span>{$tooltip('linear: von 1.0 gleichmäßig bis linear_min; geometric: w_r = w_{r-1} × base. Passt sich automatisch an num_wishes an.')}
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">base (geometric)</label>
    <input type="number" step="0.01" min="0.1" max="0.99" name="weights_base" value="%{weights_base}" style="$ctlW">
    <span style="$helpStyle">0.75–0.85 üblich (0.8 = Standard)</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">linear_min (linear)</label>
    <input type="number" step="0.01" min="0" max="1" name="linear_min" value="%{linear_min}" style="$ctlW">
    <span style="$helpStyle">Unterer Zielwert (z. B. 0.2)</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW;vertical-align:top">Gewichte – JSON</label>
    <textarea name="weights_json" rows="3" style="width:460px" placeholder='{"1":1.0,"2":0.8,"3":0.6}'>%{weights_json}</textarea>
    <div style="$helpStyle">Überschreibt Modus/Defaults. Keys „1..N“. Fehlende Ränge werden mit dem letzten Gewicht verlängert.</div>
  </div>

  <hr style="margin:12px 0">

  <div style="margin:8px 0">
    <label style="$labelW">num_wishes (Override)</label>
    <input type="number" name="num_wishes" min="1" step="1" value="%{num_wishes}" style="$ctlW">
    <span style="$helpStyle">Nur für diesen Lauf; Node‑Wert bleibt unverändert</span>
  </div>

  <div style="margin:8px 0">
    <label style="$labelW">num_assign (Override)</label>
    <input type="number" name="num_assign" min="1" step="1" value="%{num_assign}" style="$ctlW">
    <span style="$helpStyle">Nur für diesen Lauf; Node‑Wert bleibt unverändert</span>
  </div>

  <div style="margin-top:14px">
    <button class="button button--primary">Dry‑Run ausführen</button>
  </div>
</form>
HTML;

    $repl = [
      '%{action_dry}' => $this->escape(Url::fromRoute('jfcamp_matching.action_dry_run')->toString()),
      '%{sel_fair}' => $defaults['strategy']==='fair'?'selected':'',
      '%{sel_solver}' => $defaults['strategy']==='solver'?'selected':'',
      '%{sel_greedy}' => $defaults['strategy']==='greedy'?'selected':'',
      '%{sel_obj_fmm}' => $defaults['objective']==='fair_maxmin'?'selected':'',
      '%{sel_obj_hm}'  => $defaults['objective']==='happy_mean'?'selected':'',
      '%{sel_obj_lex}' => $defaults['objective']==='leximin'?'selected':'',
      '%{seed}' => htmlspecialchars((string) $defaults['seed']),
      '%{seeds}' => (int) $defaults['seeds'],
      '%{round_cap_pct}' => (int) $defaults['round_cap_pct'],
      '%{alpha_fairness}' => (float) $defaults['alpha_fairness'],
      '%{chk_topk}' => !empty($defaults['topk_equals_slots']) ? 'checked' : '',
      '%{sel_wm_none}' => empty($defaults['weights_mode']) ? 'selected' : '',
      '%{sel_wm_lin}' => $defaults['weights_mode']==='linear'?'selected':'',
      '%{sel_wm_geo}' => $defaults['weights_mode']==='geometric'?'selected':'',
      '%{weights_base}' => (float) $defaults['weights_base'],
      '%{linear_min}' => (float) $defaults['linear_min'],
      '%{weights_json}' => htmlspecialchars(is_array($defaults['weights_json']) ? json_encode($defaults['weights_json']) : (string) $defaults['weights_json']),
      '%{num_wishes}' => $defaults['num_wishes'] !== '' ? (int) $defaults['num_wishes'] : '',
      '%{num_assign}' => $defaults['num_assign'] !== '' ? (int) $defaults['num_assign'] : '',
    ];
    $html = strtr($html, $repl);

    $build['params'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-bottom:12px'],
      'form' => ['#type' => 'inline_template', '#template' => $html],
    ];

    // --- ACTION BUTTONS (Reset/Apply) ---
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline'], 'style' => 'margin-bottom:12px'],
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

    // --- CONFIG: aktuell veröffentlichte Node‑Basics ---
    $currentCfg = $this->readMatchingConfigFromDrupal();
    if ($currentCfg) {
      $build['cfg_current_title'] = ['#markup' => '<h3>'.$this->t('Aktuelle Matching‑Config (veröffentlicht)').'</h3>'];
      $build['cfg_current_table'] = $this->renderConfigTable($currentCfg['values']);
    } else {
      $build['cfg_current_hint'] = ['#markup' => '<p><em>'.$this->t('Keine veröffentlichte matching_config gefunden.').'</em></p>'];
    }

    // --- LAST DRY‑RUN (Summary + letzte Parameter) ---
    if ($last && is_array($last)) {
      $sum = $last['summary'] ?? [];
      $per_priority = $sum['per_priority_fulfilled'] ?? [];
      $per_slot = $sum['per_slot_assigned_counts'] ?? [];

      $build['last_title'] = ['#markup' => '<h3>'.$this->t('Letzter Dry‑Run – Zusammenfassung').'</h3>'];
      $items = [
        $this->t('Happy‑Index: @v', ['@v' => $sum['happy_index'] ?? 'n/a']),
        $this->t('min_user_happy: @v', ['@v' => $sum['min_user_happy'] ?? 'n/a']),
        $this->t('Median: @v', ['@v' => $sum['median_user_happy'] ?? 'n/a']),
        $this->t('Gini (Unzufriedenheit): @v', ['@v' => $sum['gini_dissatisfaction'] ?? 'n/a']),
        $this->t('Jain‑Index: @v', ['@v' => $sum['jain_index'] ?? 'n/a']),
        $this->t('Top‑1‑Coverage: @v', ['@v' => $sum['top1_coverage'] ?? 'n/a']),
        $this->t('No‑Top‑k‑Rate: @v', ['@v' => $sum['no_topk_rate'] ?? 'n/a']),
        $this->t('Top‑k‑Coverage: @v', ['@v' => json_encode($sum['topk_coverage_hist'] ?? [])]),
      ];
      $build['last_list'] = ['#theme' => 'item_list', '#items' => $items];

      if ($per_priority) {
        $rows = [];
        foreach ($per_priority as $k => $v) { $rows[] = [$k, $v]; }
        $build['prio'] = [
          '#type' => 'table',
          '#caption' => $this->t('Treffer je Priorität'),
          '#header' => [$this->t('Prio'), $this->t('Anzahl')],
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

    // --- Exporte (aus Drupal-Daten) ---
    $build['exports'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline'], 'style' => 'margin-top:12px'],
      'all' => Link::fromTextAndUrl($this->t('Alle Slots (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_all'))->toRenderable() + ['#attributes' => ['class' => ['button', 'button--primary']]],
      's1' => Link::fromTextAndUrl($this->t('Slot 1 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 1]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
      's2' => Link::fromTextAndUrl($this->t('Slot 2 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 2]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
      's3' => Link::fromTextAndUrl($this->t('Slot 3 (CSV)'), Url::fromRoute('jfcamp_matching.export_attendance_slot', ['slot' => 3]))->toRenderable() + ['#attributes' => ['class' => ['button']]],
    ];

    return $build;
  }

  /** POST /dry-run → Snapshot (mit Config + Params) */
  public function actionDryRun(Request $request): RedirectResponse {
    try {
      $payload = $this->buildPayloadFromRequest($request);
      // Speichere letzte Parameter im State (für Vorbelegung/Repro)
      \Drupal::state()->set('jfcamp_matching.last_params', $payload);

      $res = $this->client->dryRun($payload);

      // Zusätzlich aktuelle Node‑Basics mitschneiden (Dokumentation)
      $cfgValues = $this->readMatchingConfigValuesOnly();

      $snapshot = [
        'summary' => $res['summary'] ?? [],
        'by_participant' => $res['by_participant'] ?? [],
        'config' => $cfgValues,
        'params' => $payload,
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

  // ---------- Helpers ----------

  private function buildPayloadFromRequest(Request $r): array {
    $p = $r->request;
    $payload = [];

    foreach (['strategy','objective','seed','weights_mode'] as $k) {
      $v = trim((string) $p->get($k, ''));
      if ($v !== '') $payload[$k] = $v;
    }
    foreach (['seeds','round_cap_pct','num_wishes','num_assign'] as $k) {
      $vv = $p->get($k, '');
      if ($vv !== '' && is_numeric($vv)) $payload[$k] = (int) $vv;
    }
    foreach (['alpha_fairness','weights_base','linear_min'] as $k) {
      $vv = $p->get($k, '');
      if ($vv !== '' && is_numeric($vv)) $payload[$k] = (float) $vv;
    }
    if ($p->get('topk_equals_slots', '') !== '') {
      $payload['topk_equals_slots'] = (bool) $p->get('topk_equals_slots');
    }
    $wj = trim((string) $p->get('weights_json', ''));
    if ($wj !== '') {
      try {
        $w = json_decode($wj, TRUE, 512, JSON_THROW_ON_ERROR);
        if (is_array($w)) {
          $payload['weights'] = $w;
        }
      } catch (\Throwable $e) {
        $this->messenger()->addWarning($this->t('Gewichte‑JSON konnte nicht gelesen werden. Benutze Default/Mode.'));
      }
    }

    return $payload;
  }

  private function readMatchingConfigFromDrupal(): ?array {
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) return NULL;
    $n = Node::load((int) reset($ids));
    return [
      'label' => $n->label(),
      'uuid' => $n->uuid(),
      'changed' => (int) $n->getChangedTime(),
      'values' => $this->extractMatchingConfigValues($n),
    ];
  }

  private function readMatchingConfigValuesOnly(): array {
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'matching_config')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) return [];
    $n = Node::load((int) reset($ids));
    return $this->extractMatchingConfigValues($n);
  }

  private function extractMatchingConfigValues(Node $n): array {
    $g = fn(string $f, $d = NULL) => $n->hasField($f) ? $n->get($f)->value : $d;
    return [
      'field_num_wuensche' => (int) ($g('field_num_wuensche', 5) ?: 5),
      'field_num_zuteilung' => (int) ($g('field_zuteilung', $g('field_num_zuteilung', 3)) ?: 3),
      'field_slot_start' => (string) ($g('field_slot_start', '') ?: ''),
      'field_slot_end' => (string) ($g('field_slot_end', '') ?: ''),
    ];
  }

  private function renderConfigTable(array $values): array {
    $rows = [];
    foreach ($values as $k => $v) { $rows[] = [$k, (string) $v]; }
    return [
      '#type' => 'table',
      '#header' => [$this->t('Feld'), $this->t('Wert')],
      '#rows' => $rows,
    ];
  }

  private function escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
