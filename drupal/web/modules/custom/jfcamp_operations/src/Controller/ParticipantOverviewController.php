<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
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
    private PagerManagerInterface $pagerManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.participant_overview'),
      $container->get('pager.manager'),
    );
  }

  public function overview(Request $request): array {
    $filters = $this->filtersFromRequest($request);
    $data = $this->participantOverview->getOverview($filters);

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

    $header = [
      $this->t('Code'),
      $this->t('Vorname'),
      $this->t('Nachname'),
      $this->t('Regionalverband'),
      $this->t('Wünsche'),
    ];

    for ($slot = 1; $slot <= $data['assignment_columns']; $slot++) {
      $header[] = $this->t('Zuteilung @slot', ['@slot' => $slot]);
    }

    $rows = [];
    foreach ($pageRows as $participant) {
      $row = [
        $participant['code'] !== '' ? $participant['code'] : '—',
        $participant['first_name'] !== '' ? $participant['first_name'] : '—',
        $participant['last_name'] !== '' ? $participant['last_name'] : '—',
        $participant['region'] !== '' ? $participant['region'] : '—',
        (string) $participant['wish_count'],
      ];

      for ($slot = 0; $slot < $data['assignment_columns']; $slot++) {
        $row[] = $participant['assignments'][$slot] ?? '—';
      }

      $rows[] = $row;
    }

    $exportQuery = $filters;
    $exportQuery['columns'] = $selectedColumns;

    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Teilnehmende, Wunschabgaben und aktueller Zuteilungsstand. Filter wirken identisch auf Tabelle und CSV-Export.'
      ) . '</p>',
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
    $data = $this->participantOverview->getOverview($filters);
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

  private function filterTemplate(): string {
    return <<<'TWIG'
<form method="get" action="{{ action }}" style="margin:1rem 0 1.25rem;">
  <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end;">
    <label>
      <span style="display:block;font-weight:600;">Name / Code</span>
      <input type="search" name="q" value="{{ filters.q|e }}" placeholder="Suchen …">
    </label>

    <label>
      <span style="display:block;font-weight:600;">Regionalverband</span>
      <select name="region">
        <option value="">Alle</option>
        {% for option in regions %}
          <option value="{{ option.value|e }}"{% if option.selected %} selected{% endif %}>{{ option.label }}</option>
        {% endfor %}
      </select>
    </label>

    <label>
      <span style="display:block;font-weight:600;">Wünsche</span>
      <select name="wishes">
        <option value="">Alle</option>
        <option value="yes"{% if filters.wishes == 'yes' %} selected{% endif %}>vorhanden</option>
        <option value="no"{% if filters.wishes == 'no' %} selected{% endif %}>keine</option>
      </select>
    </label>

    <label>
      <span style="display:block;font-weight:600;">Zuteilung</span>
      <select name="complete">
        <option value="">Alle</option>
        <option value="yes"{% if filters.complete == 'yes' %} selected{% endif %}>vollständig</option>
        <option value="no"{% if filters.complete == 'no' %} selected{% endif %}>unvollständig</option>
      </select>
    </label>

    <label>
      <span style="display:block;font-weight:600;">Workshop</span>
      <select name="workshop">
        <option value="">Alle</option>
        {% for option in workshops %}
          <option value="{{ option.value|e }}"{% if option.selected %} selected{% endif %}>{{ option.label }}</option>
        {% endfor %}
      </select>
    </label>

    <label>
      <span style="display:block;font-weight:600;">Slot</span>
      <select name="slot">
        <option value="">Alle</option>
        {% for option in slots %}
          <option value="{{ option.value|e }}"{% if option.selected %} selected{% endif %}>{{ option.label }}</option>
        {% endfor %}
      </select>
    </label>

    <button class="button button--primary" type="submit">Filtern</button>
    <a class="button" href="{{ reset_url }}">Zurücksetzen</a>
  </div>

  <p style="margin-top:.75rem;"><small>Für eine Teilnahmeliste für Dozent*innen: Workshop und Slot auswählen, filtern und anschließend die gewünschte CSV exportieren.</small></p>

  <details style="margin-top:1rem;">
    <summary style="cursor:pointer;font-weight:600;">CSV-Spalten auswählen</summary>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem 1rem;margin-top:.75rem;">
      {% for column in columns %}
        <label style="white-space:nowrap;">
          <input type="checkbox" name="columns[]" value="{{ column.key|e }}"{% if column.checked %} checked{% endif %}>
          {{ column.label }}
        </label>
      {% endfor %}
    </div>
    <p><small>Die Auswahl wird beim nächsten Filtern übernommen und für den CSV-Export verwendet.</small></p>
  </details>

  <p style="margin-top:1rem;">
    <a class="button button--primary" href="{{ export_url }}">Gefilterte Liste als CSV exportieren</a>
  </p>
</form>
TWIG;
  }

}
