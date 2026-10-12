<?php

namespace Drupal\jfcamp_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\jfcamp_matching\Service\MatchingRunStorage;
use Drupal\jfcamp_operations\Service\AssignmentAuditService;
use Drupal\jfcamp_operations\Service\ParticipantAccessService;
use Drupal\jfcamp_operations\Service\ParticipantOverviewService;
use Drupal\jfcamp_operations\Service\WorkshopCapacityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operational landing page for JF Camp administration.
 */
final class JfCampDashboardController extends ControllerBase {

  public function __construct(
    private readonly ParticipantOverviewService $participantOverview,
    private readonly ParticipantAccessService $participantAccess,
    private readonly AssignmentAuditService $assignmentAudit,
    private readonly WorkshopCapacityService $workshopCapacity,
    private readonly MatchingRunStorage $runStorage,
    private readonly AccountProxyInterface $currentUserProxy,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('jfcamp_operations.participant_overview'),
      $container->get('jfcamp_operations.participant_access'),
      $container->get('jfcamp_operations.assignment_audit'),
      $container->get('jfcamp_operations.workshop_capacity'),
      $container->get('jfcamp_matching.run_storage'),
      $container->get('current_user'),
    );
  }

  public function dashboard(Request $request): array {
    $allowedRegions = $this->participantAccess->getAllowedRegions();

    // A restricted regional scope is the Teamer*innen workflow. Keep this
    // deliberately focused on the participants they are responsible for.
    if ($allowedRegions !== NULL && !$this->currentUserProxy->hasPermission('view all jfcamp participant regions')) {
      return $this->teamerDashboard($request, $allowedRegions);
    }

    return $this->leadershipDashboard($allowedRegions);
  }

  /**
   * Full operational dashboard for Camp-Leitung.
   */
  private function leadershipDashboard(?array $allowedRegions): array {
    $participants = $this->participantOverview->getSummary($allowedRegions);

    $cards = [];
    $cards[] = $this->card(
      $this->t('Teilnehmende'),
      (string) $participants['total'],
      $allowedRegions === NULL
        ? $this->t('Gesamtbestand')
        : $this->t('In deinem Regionalbereich'),
    );
    $cards[] = $this->card(
      $this->t('Wünsche abgegeben'),
      (string) $participants['with_wishes'],
      $this->t('@count ohne Wünsche', ['@count' => $participants['without_wishes']]),
      $participants['without_wishes'] > 0 ? 'warning' : NULL,
    );
    $cards[] = $this->card(
      $this->t('Vollständig zugeteilt'),
      (string) $participants['fully_assigned'],
      $this->t('@count unvollständig', ['@count' => $participants['incomplete']]),
      $participants['incomplete'] > 0 ? 'danger' : NULL,
    );

    if ($this->currentUserProxy->hasPermission('run jfcamp matching')) {
      $latest = $this->runStorage->latest();
      $applied = $this->runStorage->currentApplied();
      $value = $applied
        ? $this->t('Run #@id', ['@id' => $applied['id']])
        : $this->t('Kein Run angewendet');
      $note = $latest
        ? $this->t('Letzter Run #@id: @status / @apply', [
          '@id' => $latest['id'],
          '@status' => $latest['status'],
          '@apply' => $latest['apply_status'],
        ])
        : $this->t('Noch kein Matching-Run vorhanden');
      $cards[] = $this->card($this->t('Matching-Basis'), (string) $value, $note);
    }

    if ($this->currentUserProxy->hasPermission('view jfcamp assignment audit')) {
      $audit = $this->assignmentAudit->getCurrentAssignmentStatus();
      $cards[] = $this->card(
        $this->t('Manuelle Änderungen'),
        (string) $audit['manual_changes'],
        $audit['base_run_id']
          ? $this->t('Seit Apply von Run #@id', ['@id' => $audit['base_run_id']])
          : $this->t('Seit bestehendem Zuteilungsstand'),
        $audit['manual_changes'] > 0 ? 'warning' : NULL,
      );
    }

    if ($this->currentUserProxy->hasPermission('view jfcamp workshop capacities')) {
      $capacity = $this->workshopCapacity->getOverview()['summary'];
      $cards[] = $this->card(
        $this->t('Freie Plätze'),
        (string) $capacity['free'],
        $this->t('@full volle Slots · @over überbucht', [
          '@full' => $capacity['full_slot_occurrences'],
          '@over' => $capacity['overbooked_slot_occurrences'],
        ]),
        $capacity['overbooked_slot_occurrences'] > 0 ? 'danger' : NULL,
      );
    }

    return [
      '#attached' => [
        'library' => ['jfcamp_admin/dashboard'],
      ],
      'intro' => [
        '#markup' => '<div class="jfcamp-dashboard__intro"><p>'
          . $this->t('Operativer Überblick und Schnellzugriffe für das JF Camp.')
          . '</p></div>',
      ],
      'status' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-dashboard__grid']],
        'cards' => $cards,
      ],
      'quicklinks' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-dashboard__section']],
        'title' => ['#markup' => '<h2>' . $this->t('Schnellzugriffe') . '</h2>'],
        'links' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['jfcamp-dashboard__actions']],
          'items' => $this->quickLinks(),
        ],
      ],
    ];
  }

  /**
   * Mobile-first participant workspace for Teamer*innen.
   */
  private function teamerDashboard(Request $request, array $allowedRegions): array {
    $summary = $this->participantOverview->getSummary($allowedRegions);
    $view = (string) $request->query->get('view', '');
    if (!in_array($view, ['missing-wishes', 'all', 'assignments'], TRUE)) {
      $view = $summary['without_wishes'] > 0 ? 'missing-wishes' : 'all';
    }

    $filters = $view === 'missing-wishes' ? ['wishes' => 'no'] : [];
    $overview = $this->participantOverview->getOverview($filters, $allowedRegions);

    $regions = implode(', ', $allowedRegions);
    $build = [
      '#attached' => [
        'library' => ['jfcamp_admin/dashboard'],
      ],
      '#cache' => ['max-age' => 0],
      'intro' => [
        '#markup' => '<div class="jfcamp-teamer-home__intro">'
          . '<div class="jfcamp-teamer-home__eyebrow">' . $this->t('Teamer*innen-Ansicht') . '</div>'
          . '<h2>' . $this->t('Meine Teilnehmenden') . '</h2>'
          . '<p>' . $this->t('Regionalbereich: @regions', ['@regions' => $regions !== '' ? $regions : '—']) . '</p>'
          . '</div>',
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-teamer-home__summary']],
        'total' => $this->teamerStat($this->t('Teilnehmende'), (int) $summary['total']),
        'missing' => $this->teamerStat($this->t('Ohne Wünsche'), (int) $summary['without_wishes'], $summary['without_wishes'] > 0 ? 'warning' : NULL),
        'assigned' => $this->teamerStat($this->t('Vollständig zugeteilt'), (int) $summary['fully_assigned']),
      ],
      'filters' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-teamer-home__filters']],
        'missing' => $this->teamerFilterLink('missing-wishes', $view, $this->t('Ohne Wünsche'), (int) $summary['without_wishes']),
        'all' => $this->teamerFilterLink('all', $view, $this->t('Alle Teilnehmenden'), (int) $summary['total']),
        'assignments' => $this->teamerFilterLink('assignments', $view, $this->t('Zuteilungen'), (int) $summary['fully_assigned']),
      ],
    ];

    $cards = [];
    foreach ($overview['rows'] as $row) {
      $cards['participant_' . $row['participant_id']] = $this->teamerParticipantCard($row, $view === 'assignments');
    }

    $build['participants'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-teamer-home__participants']],
      'heading' => [
        '#markup' => '<div class="jfcamp-teamer-home__list-heading"><h2>'
          . ($view === 'missing-wishes'
            ? $this->t('Ohne Wünsche')
            : ($view === 'assignments' ? $this->t('Zuteilungen') : $this->t('Alle Teilnehmenden')))
          . '</h2><span>' . $this->formatPlural((int) $overview['total'], '1 Person', '@count Personen') . '</span></div>',
      ],
      'cards' => $cards ?: [
        '#markup' => '<div class="jfcamp-teamer-home__empty">'
          . ($view === 'missing-wishes'
            ? $this->t('Alle Teilnehmenden in deinem Regionalbereich haben Wünsche abgegeben.')
            : $this->t('Keine Teilnehmenden gefunden.'))
          . '</div>',
      ],
    ];

    return $build;
  }

  private function teamerStat(string $label, int $value, ?string $state = NULL): array {
    $classes = ['jfcamp-teamer-home__stat'];
    if ($state !== NULL) {
      $classes[] = 'jfcamp-teamer-home__stat--' . $state;
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => $classes],
      'value' => ['#markup' => '<strong>' . $value . '</strong>'],
      'label' => ['#markup' => '<span>' . $label . '</span>'],
    ];
  }

  private function teamerFilterLink(string $targetView, string $activeView, string $label, int $count): array {
    $link = Link::fromTextAndUrl(
      $this->t('@label · @count', ['@label' => $label, '@count' => $count]),
      Url::fromRoute('jfcamp_admin.overview', [], ['query' => ['view' => $targetView]]),
    )->toRenderable();
    $link['#attributes']['class'] = ['jfcamp-teamer-home__filter'];
    if ($targetView === $activeView) {
      $link['#attributes']['class'][] = 'is-active';
      $link['#attributes']['aria-current'] = 'page';
    }
    return $link;
  }

  private function teamerParticipantCard(array $row, bool $showAssignments): array {
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    if ($name === '') {
      $name = (string) ($row['code'] ?? $this->t('Teilnehmer*in'));
    }

    $url = Url::fromRoute('jfcamp_operations.participant_detail', [
      'participant_id' => (int) $row['participant_id'],
    ]);
    $nameLink = Link::fromTextAndUrl($name, $url)->toRenderable();
    $nameLink['#attributes']['class'] = ['jfcamp-teamer-home__participant-link'];

    $assignments = [];
    if ($showAssignments || !empty($row['assignments'])) {
      foreach (($row['assignments'] ?? []) as $index => $assignment) {
        $assignments['slot_' . ($index + 1)] = [
          '#markup' => '<div class="jfcamp-teamer-home__assignment">'
            . '<span>' . $this->t('Slot @slot', ['@slot' => $index + 1]) . '</span>'
            . '<strong>' . htmlspecialchars((string) $assignment, ENT_QUOTES, 'UTF-8') . '</strong>'
            . '</div>',
        ];
      }
    }

    $wishCount = (int) ($row['wish_count'] ?? 0);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-teamer-home__participant']],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-teamer-home__participant-header']],
        'identity' => [
          '#type' => 'container',
          'name' => $nameLink,
          'meta' => [
            '#markup' => '<div class="jfcamp-teamer-home__participant-meta">'
              . htmlspecialchars((string) ($row['code'] ?? ''), ENT_QUOTES, 'UTF-8')
              . ' · ' . htmlspecialchars((string) ($row['region'] ?? ''), ENT_QUOTES, 'UTF-8')
              . '</div>',
          ],
        ],
        'status' => [
          '#markup' => '<span class="jfcamp-teamer-home__wish-status '
            . ($wishCount > 0 ? 'is-ok' : 'is-warning') . '">'
            . ($wishCount > 0
              ? $this->t('@count Wünsche', ['@count' => $wishCount])
              : $this->t('Keine Wünsche'))
            . '</span>',
        ],
      ],
      'assignments' => $assignments
        ? [
          '#type' => 'container',
          '#attributes' => ['class' => ['jfcamp-teamer-home__assignments']],
          'items' => $assignments,
        ]
        : [],
    ];
  }

  private function card(string $label, string $value, string $note, ?string $state = NULL): array {
    $classes = ['jfcamp-dashboard__card'];
    if ($state !== NULL) {
      $classes[] = 'jfcamp-dashboard__card--' . $state;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => $classes],
      'label' => [
        '#markup' => '<div class="jfcamp-dashboard__card-label">' . $label . '</div>',
      ],
      'value' => [
        '#markup' => '<div class="jfcamp-dashboard__card-value">' . $value . '</div>',
      ],
      'note' => [
        '#markup' => '<div class="jfcamp-dashboard__card-note">' . $note . '</div>',
      ],
    ];
  }

  private function quickLinks(): array {
    $links = [];

    $this->addQuickLink(
      $links,
      'participants',
      'jfcamp_operations.participants',
      $this->t('Teilnehmende & Zuteilungen'),
      $this->t('Teilnehmende suchen, filtern, exportieren und Zuteilungen ansehen.'),
      'view jfcamp participant overview',
    );
    $this->addQuickLink(
      $links,
      'matching',
      'jfcamp_matching.dashboard',
      $this->t('Matching'),
      $this->t('Matching berechnen, prüfen und festschreiben.'),
      'run jfcamp matching',
    );
    $this->addQuickLink(
      $links,
      'capacity',
      'jfcamp_operations.workshop_capacity',
      $this->t('Workshops & Kapazitäten'),
      $this->t('Belegung, freie Plätze und volle Slots prüfen.'),
      'view jfcamp workshop capacities',
    );
    $this->addQuickLink(
      $links,
      'audit',
      'jfcamp_operations.assignment_audit',
      $this->t('Änderungsprotokoll'),
      $this->t('Manuelle Änderungen an Zuteilungen nachvollziehen.'),
      'view jfcamp assignment audit',
    );
    $this->addQuickLink(
      $links,
      'team',
      'jfcamp_team.overview',
      $this->t('Teamverwaltung'),
      $this->t('Teamer*innen und Regionalverbände verwalten.'),
      'manage jfcamp teamers',
    );
    $this->addQuickLink(
      $links,
      'import',
      'jfcamp_api.csv_import',
      $this->t('CSV-Import'),
      $this->t('Camp-Daten über den bestehenden CSV-Import einspielen.'),
      'import jfcamp csv',
    );

    return $links;
  }

  private function addQuickLink(
    array &$links,
    string $key,
    string $route,
    string $title,
    string $description,
    string $permission,
  ): void {
    if (!$this->currentUserProxy->hasPermission($permission)) {
      return;
    }

    $url = Url::fromRoute($route);
    $link = Link::fromTextAndUrl($title, $url)->toRenderable();
    $link['#attributes']['class'][] = 'jfcamp-dashboard__action-title';

    $links[$key] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-dashboard__action']],
      'title' => $link,
      'description' => [
        '#markup' => '<span class="jfcamp-dashboard__action-description">' . $description . '</span>',
      ],
    ];
  }

}
