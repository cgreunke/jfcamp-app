<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\jfcamp_operations\Service\ParticipantOverviewService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only operational overview of participants and current assignments.
 */
final class ParticipantOverviewController extends ControllerBase {

  public function __construct(
    private ParticipantOverviewService $participantOverview,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.participant_overview'),
    );
  }

  public function overview(): array {
    $page = $this->participantOverview->getPage(50);
    $assignmentColumns = (int) $page['assignment_columns'];

    $header = [
      $this->t('Code'),
      $this->t('Vorname'),
      $this->t('Nachname'),
      $this->t('Regionalverband'),
      $this->t('Wünsche'),
    ];

    for ($slot = 1; $slot <= $assignmentColumns; $slot++) {
      $header[] = $this->t('Zuteilung @slot', ['@slot' => $slot]);
    }

    $rows = [];
    foreach ($page['rows'] as $participant) {
      $row = [
        $participant['code'] !== '' ? $participant['code'] : '—',
        $participant['first_name'] !== '' ? $participant['first_name'] : '—',
        $participant['last_name'] !== '' ? $participant['last_name'] : '—',
        $participant['region'] !== '' ? $participant['region'] : '—',
        (string) $participant['wish_count'],
      ];

      for ($slot = 0; $slot < $assignmentColumns; $slot++) {
        $row[] = $participant['assignments'][$slot] ?? '—';
      }

      $rows[] = $row;
    }

    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
    ];

    $build['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Lesende Übersicht über Teilnehmende, abgegebene Wünsche und den aktuell in Drupal gespeicherten Zuteilungsstand.'
      ) . '</p>',
    ];

    $build['summary'] = [
      '#markup' => '<p><strong>' . $this->t('@count Teilnehmende insgesamt', [
        '@count' => $page['total'],
      ]) . '</strong></p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('Keine Teilnehmenden gefunden.'),
      '#sticky' => TRUE,
      '#attributes' => [
        'class' => ['jfcamp-participant-overview'],
      ],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
