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

  public function dashboard(): array {
    $allowedRegions = $this->participantAccess->getAllowedRegions();
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
