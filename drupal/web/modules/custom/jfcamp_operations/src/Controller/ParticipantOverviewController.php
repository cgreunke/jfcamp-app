<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\jfcamp_operations\Service\AssignmentAuditService;
use Drupal\jfcamp_operations\Service\ParticipantAccessService;
use Drupal\jfcamp_operations\Service\ParticipantOverviewService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operational overview of participants and current assignments.
 */
final class ParticipantOverviewController extends ControllerBase {

  private const PAGE_SIZE = 50;

  public function __construct(
    private ParticipantOverviewService $participantOverview,
    private ParticipantAccessService $participantAccess,
    private AssignmentAuditService $assignmentAudit,
    private PagerManagerInterface $pagerManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.participant_overview'),
      $container->get('jfcamp_operations.participant_access'),
      $container->get('jfcamp_operations.assignment_audit'),
      $container->get('pager.manager'),
    );
  }

  public function overview(Request $request): array {
    $filters = $this->filtersFromRequest($request);
    $allowedRegions = $this->participantAccess->getAllowedRegions();
    $data = $this->participantOverview->getOverview($filters, $allowedRegions);
    $summary = $this->participantOverview->getSummary($allowedRegions);
    $assignmentStatus = $this->assignmentAudit->getCurrentAssignmentStatus();

    $pager = $this->pagerManager->createPager($data['total'], self::PAGE_SIZE);
    $offset = $pager->getCurrentPage() * self::PAGE_SIZE;
    $pageRows = array_slice($data['rows'], $offset, self::PAGE_SIZE);

    $availableColumns = $this->participantOverview->getExportColumns(
      $data['assignment_columns'],
      $data['wish_columns'],
    );
    $selectedColumns = $this->selectedColumnsFromRequest(
      $request,
      $availableColumns,
      $this->participantOverview->getDefaultExportColumns($data['assignment_columns']),
    );

    $canEditAssignments = $this->currentUser()->hasPermission('edit jfcamp participant assignments');

    $header = [
      $this->t('Teilnehmer*in'),
      $this->t('Regionalverband'),
      $this->t('Wünsche'),
    ];

    for ($slot = 1; $slot <= $data['assignment_columns']; $slot++) {
      $header[] = $this->t('Slot @slot', ['@slot' => $slot]);
    }

    if ($canEditAssignments) {
      $header[] = $this->t('Aktionen');
    }

    $rows = [];
    foreach ($pageRows as $participant) {
      $participantName = trim($participant['first_name'] . ' ' . $participant['last_name']);
      if ($participantName === '') {
        $participantName = $participant['code'] !== '' ? $participant['code'] : (string) $this->t('Teilnehmer*in #@id', [
          '@id' => $participant['participant_id'],
        ]);
      }

      $participantUrl = Url::fromRoute('entity.node.canonical', [
        'node' => $participant['participant_id'],
      ]);
      $participantLink = Link::fromTextAndUrl($participantName, $participantUrl)->toRenderable();
      $participantLink['#attributes']['class'][] = 'jfcamp-participant-overview__participant-link';

      $participantCell = [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-participant-overview__participant']],
        'name' => $participantLink,
      ];
      if ($participant['code'] !== '') {
        $codeLink = Link::fromTextAndUrl($participant['code'], $participantUrl)->toRenderable();
        $codeLink['#attributes']['class'][] = 'jfcamp-participant-overview__code-link';
        $participantCell['code'] = $codeLink;
      }

      $row = [
        ['data' => $participantCell],
        [
          'data' => $participant['region'] !== '' ? $participant['region'] : '—',
          'class' => ['jfcamp-participant-overview__region'],
        ],
        [
          'data' => (string) $participant['wish_count'],
          'class' => ['jfcamp-participant-overview__wish-count'],
        ],
      ];

      for ($slot = 0; $slot < $data['assignment_columns']; $slot++) {
        $row[] = [
          'data' => $participant['assignments'][$slot] ?? '—',
          'class' => ['jfcamp-participant-overview__assignment'],
        ];
      }

      if ($canEditAssignments) {
        $actionItems = [
          '#type' => 'container',
          '#attributes' => ['class' => ['jfcamp-assignment-actions']],
        ];
        for ($slot = 1; $slot <= $data['assignment_columns']; $slot++) {
          $action = Link::fromTextAndUrl(
            $this->t('Slot @slot', ['@slot' => $slot]),
            Url::fromRoute('jfcamp_operations.assignment_edit', [
              'participant_id' => $participant['participant_id'],
              'slot' => $slot,
            ]),
          )->toRenderable();
          $action['#attributes']['class'] = ['button', 'button--small', 'jfcamp-assignment-actions__link'];
          $action['#attributes']['title'] = (string) $this->t('Zuteilung in Slot @slot ändern', ['@slot' => $slot]);
          $actionItems['slot_' . $slot] = $action;
        }
        $row[] = [
          'data' => $actionItems,
          'class' => ['jfcamp-participant-overview__actions'],
        ];
      }

      $rows[] = $row;
    }

    $exportQuery = $filters;
    $exportQuery['columns'] = $selectedColumns;

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operations']],
      '#attached' => [
        'library' => ['jfcamp_operations/admin'],
      ],
      '#cache' => ['max-age' => 0],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Teilnehmende, Wunschabgaben und aktueller Zuteilungsstand. Filter wirken identisch auf Tabelle und CSV-Export.'
      ) . '</p>',
    ];

    $baseText = $assignmentStatus['base_run_id'] !== NULL
      ? (string) $this->t('Matching Run #@run', ['@run' => $assignmentStatus['base_run_id']])
      : (string) $this->t('Bestehender Zuteilungsstand – kein Matching-Run angewendet');

    $build['assignment_status'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['messages', $assignmentStatus['base_run_id'] !== NULL ? 'messages--status' : 'messages--warning'],
      ],
      'content' => [
        '#markup' => '<strong>' . $this->t('Zuteilungsbasis:') . '</strong> ' . $baseText
          . '<br><strong>' . $this->t('Manuelle Änderungen seit dieser Basis:') . '</strong> '
          . $assignmentStatus['manual_changes'],
      ],
    ];

    if ($allowedRegions !== NULL) {
      if ($allowedRegions) {
        $build['access_scope'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--status']],
          'content' => [
            '#markup' => '<strong>' . $this->t('Regionalverbands-Sicht:') . '</strong> '
              . Html::escape(implode(', ', $allowedRegions))
              . '<br><small>'
              . $this->t('Tabelle, Kennzahlen und CSV-Export sind serverseitig auf diese Regionalverbände beschränkt.')
              . '</small>',
          ],
        ];
      }
      else {
        $build['access_scope'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--warning']],
          'content' => [
            '#markup' => '<strong>' . $this->t('Keine Regionalverbände zugeordnet.') . '</strong> '
              . $this->t('Für dieses Benutzerkonto sind derzeit keine Teilnehmenden freigegeben.'),
          ],
        ];
      }
    }

    $build['operational_summary'] = [
      '#type' => 'inline_template',
      '#template' => $this->summaryTemplate(),
      '#context' => [
        'total' => $summary['total'],
        'with_wishes' => $summary['with_wishes'],
        'without_wishes' => $summary['without_wishes'],
        'fully_assigned' => $summary['fully_assigned'],
        'incomplete' => $summary['incomplete'],
        'all_url' => Url::fromRoute('jfcamp_operations.participants')->toString(),
        'with_wishes_url' => Url::fromRoute('jfcamp_operations.participants', [], [
          'query' => ['wishes' => 'yes'],
        ])->toString(),
        'without_wishes_url' => Url::fromRoute('jfcamp_operations.participants', [], [
          'query' => ['wishes' => 'no'],
        ])->toString(),
        'incomplete_url' => Url::fromRoute('jfcamp_operations.participants', [], [
          'query' => ['complete' => 'no'],
        ])->toString(),
      ],
    ];

    $build['filters'] = [
      '#type' => 'inline_template',
      '#template' => $this->filterTemplate(),
      '#context' => [
        'action' => Url::fromRoute('jfcamp_operations.participants')->toString(),
        'reset_url' => Url::fromRoute('jfcamp_operations.participants')->toString(),
        'export_url' => Url::fromRoute('jfcamp_operations.participants_export', [], [
          'query' => $exportQuery,
        ])->toString(),
        'filters' => $filters,
        'regions' => array_map(
          fn (string $region): array => [
            'value' => $region,
            'label' => $region,
            'selected' => $filters['region'] === $region,
          ],
          $data['regions'],
        ),
        'workshops' => array_map(
          fn (int $id, string $label): array => [
            'value' => (string) $id,
            'label' => $label,
            'selected' => (int) $filters['workshop'] === $id,
          ],
          array_keys($data['workshops']),
          array_values($data['workshops']),
        ),
        'slots' => array_map(
          fn (int $slot): array => [
            'value' => (string) $slot,
            'label' => (string) $this->t('Slot @slot', ['@slot' => $slot]),
            'selected' => (int) $filters['slot'] === $slot,
          ],
          range(1, $data['assignment_columns']),
        ),
        'columns' => array_map(
          fn (string $key, string $label): array => [
            'key' => $key,
            'label' => $label,
            'checked' => in_array($key, $selectedColumns, TRUE),
          ],
          array_keys($availableColumns),
          array_values($availableColumns),
        ),
      ],
    ];

    $build['summary'] = [
      '#markup' => '<p><strong>' . $this->t('@count Teilnehmende gefunden', [
        '@count' => $data['total'],
      ]) . '</strong></p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('Keine Teilnehmenden für die gewählten Filter gefunden.'),
      '#sticky' => TRUE,
      '#attributes' => [
        'class' => ['jfcamp-participant-overview'],
      ],
    ];

    $build['pager'] = [
      '#type' => 'pager',
      '#parameters' => array_filter($filters, static fn ($value): bool => $value !== '' && $value !== 0),
    ];

    return $build;
  }

  public function export(Request $request): StreamedResponse {
    $filters = $this->filtersFromRequest($request);
    $allowedRegions = $this->participantAccess->getAllowedRegions();
    $data = $this->participantOverview->getOverview($filters, $allowedRegions);
    $availableColumns = $this->participantOverview->getExportColumns(
      $data['assignment_columns'],
      $data['wish_columns'],
    );
    $columnKeys = $this->selectedColumnsFromRequest(
      $request,
      $availableColumns,
      $this->participantOverview->getDefaultExportColumns($data['assignment_columns']),
    );

    $headers = array_map(
      static fn (string $key): string => $availableColumns[$key],
      $columnKeys,
    );

    $response = new StreamedResponse(function () use ($data, $columnKeys, $headers): void {
      $out = fopen('php://output', 'wb');
      if ($out === FALSE) {
        return;
      }

      // UTF-8 BOM improves direct opening in German Excel installations.
      fwrite($out, "\xEF\xBB\xBF");
      fputcsv($out, $headers, ';', '"', '\\');

      foreach ($data['rows'] as $row) {
        fputcsv(
          $out,
          $this->participantOverview->buildExportRow($row, $columnKeys),
          ';',
          '"',
          '\\',
        );
      }

      fclose($out);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="' . $this->exportFilename($filters, $data['workshops']) . '"',
    );
    $response->headers->set('Cache-Control', 'no-store, private');

    return $response;
  }

  private function filtersFromRequest(Request $request): array {
    return [
      'q' => trim((string) $request->query->get('q', '')),
      'region' => trim((string) $request->query->get('region', '')),
      'wishes' => trim((string) $request->query->get('wishes', '')),
      'complete' => trim((string) $request->query->get('complete', '')),
      'workshop' => max(0, (int) $request->query->get('workshop', 0)),
      'slot' => max(0, (int) $request->query->get('slot', 0)),
    ];
  }

  private function selectedColumnsFromRequest(
    Request $request,
    array $availableColumns,
    array $defaults,
  ): array {
    $raw = $request->query->all('columns');
    if (!$raw) {
      return $defaults;
    }

    $selected = [];
    foreach ($raw as $key) {
      $key = (string) $key;
      if (isset($availableColumns[$key]) && !in_array($key, $selected, TRUE)) {
        $selected[] = $key;
      }
    }

    return $selected ?: $defaults;
  }

  private function exportFilename(array $filters, array $workshops): string {
    $parts = ['jfcamp'];

    $workshopId = (int) ($filters['workshop'] ?? 0);
    if ($workshopId > 0 && isset($workshops[$workshopId])) {
      $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $workshops[$workshopId]));
      $slug = trim((string) $slug, '-');
      $parts[] = $slug !== '' ? $slug : 'workshop-' . $workshopId;
    }
    else {
      $parts[] = 'teilnehmende';
    }

    $slot = (int) ($filters['slot'] ?? 0);
    if ($slot > 0) {
      $parts[] = 'slot-' . $slot;
    }

    $parts[] = date('Y-m-d-His');
    return implode('-', $parts) . '.csv';
  }

  private function summaryTemplate(): string {
    return <<<'TWIG'
<div class="jfcamp-operations__summary" role="list" aria-label="Übersicht">
  <a class="jfcamp-operations__stat" href="{{ all_url }}" role="listitem">
    <strong class="jfcamp-operations__stat-value">{{ total }}</strong>
    <span class="jfcamp-operations__stat-label">Teilnehmende gesamt</span>
  </a>

  <a class="jfcamp-operations__stat" href="{{ with_wishes_url }}" role="listitem">
    <strong class="jfcamp-operations__stat-value">{{ with_wishes }}</strong>
    <span class="jfcamp-operations__stat-label">Wünsche abgegeben</span>
  </a>

  <a class="jfcamp-operations__stat jfcamp-operations__stat--warning" href="{{ without_wishes_url }}" role="listitem">
    <strong class="jfcamp-operations__stat-value">{{ without_wishes }}</strong>
    <span class="jfcamp-operations__stat-label">Ohne Wünsche</span>
  </a>

  {% if incomplete > 0 %}
    <a class="jfcamp-operations__stat jfcamp-operations__stat--warning" href="{{ incomplete_url }}" role="listitem">
      <strong class="jfcamp-operations__stat-value">{{ incomplete }}</strong>
      <span class="jfcamp-operations__stat-label">Unvollständig zugeteilt</span>
    </a>
  {% else %}
    <div class="jfcamp-operations__stat jfcamp-operations__stat--static" role="listitem">
      <strong class="jfcamp-operations__stat-value">{{ fully_assigned }}</strong>
      <span class="jfcamp-operations__stat-label">Vollständig zugeteilt</span>
    </div>
  {% endif %}
</div>
<p class="jfcamp-operations__help">„Ohne Wünsche“ zeigt Teilnehmende ohne verwertbare veröffentlichte Wunschliste. Ein Klick öffnet direkt die entsprechend gefilterte Liste; sie kann anschließend mit dem vorhandenen CSV-Export ausgegeben werden.</p>
TWIG;
  }

  private function filterTemplate(): string {
    return <<<'TWIG'
<form method="get" action="{{ action }}" class="jfcamp-operations__filter-form">
  <div class="jfcamp-operations__filters-grid">
    <label class="jfcamp-operations__field">
      <span class="jfcamp-operations__label">Name / Code</span>
      <input class="form-search" type="search" name="q" value="{{ filters.q|e }}" placeholder="Suchen …">
    </label>

    <label class="jfcamp-operations__field">
      <span class="jfcamp-operations__label">Regionalverband</span>
      <select class="form-select" name="region">
        <option value="">Alle</option>
        {% for option in regions %}
          <option value="{{ option.value|e }}"{% if option.selected %} selected{% endif %}>{{ option.label }}</option>
        {% endfor %}
      </select>
    </label>

    <label class="jfcamp-operations__field">
      <span class="jfcamp-operations__label">Wünsche</span>
      <select class="form-select" name="wishes">
        <option value="">Alle</option>
        <option value="yes"{% if filters.wishes == 'yes' %} selected{% endif %}>vorhanden</option>
        <option value="no"{% if filters.wishes == 'no' %} selected{% endif %}>keine</option>
      </select>
    </label>

    <label class="jfcamp-operations__field">
      <span class="jfcamp-operations__label">Zuteilung</span>
      <select class="form-select" name="complete">
        <option value="">Alle</option>
        <option value="yes"{% if filters.complete == 'yes' %} selected{% endif %}>vollständig</option>
        <option value="no"{% if filters.complete == 'no' %} selected{% endif %}>unvollständig</option>
      </select>
    </label>

    <label class="jfcamp-operations__field jfcamp-operations__field--wide">
      <span class="jfcamp-operations__label">Workshop</span>
      <select class="form-select" name="workshop">
        <option value="">Alle</option>
        {% for option in workshops %}
          <option value="{{ option.value|e }}"{% if option.selected %} selected{% endif %}>{{ option.label }}</option>
        {% endfor %}
      </select>
    </label>

    <label class="jfcamp-operations__field">
      <span class="jfcamp-operations__label">Slot</span>
      <select class="form-select" name="slot">
        <option value="">Alle</option>
        {% for option in slots %}
          <option value="{{ option.value|e }}"{% if option.selected %} selected{% endif %}>{{ option.label }}</option>
        {% endfor %}
      </select>
    </label>
  </div>

  <div class="jfcamp-operations__actions">
    <button class="button button--primary" type="submit">Filtern</button>
    <a class="button" href="{{ reset_url }}">Zurücksetzen</a>
  </div>

  <p class="jfcamp-operations__help">Für eine Teilnahmeliste für Dozent*innen: Workshop und Slot auswählen, filtern und anschließend die gewünschte CSV exportieren.</p>

  <details class="jfcamp-operations__details">
    <summary>CSV-Spalten auswählen</summary>
    <div class="jfcamp-operations__column-grid">
      {% for column in columns %}
        <label>
          <input type="checkbox" name="columns[]" value="{{ column.key|e }}"{% if column.checked %} checked{% endif %}>
          {{ column.label }}
        </label>
      {% endfor %}
    </div>
    <p class="jfcamp-operations__help">Die Auswahl wird beim nächsten Filtern übernommen und für den CSV-Export verwendet.</p>
  </details>

  <div class="jfcamp-operations__export-action">
    <a class="button button--primary" href="{{ export_url }}">Gefilterte Liste als CSV exportieren</a>
  </div>
</form>
TWIG;
  }

}
