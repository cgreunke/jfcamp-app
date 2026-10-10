<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\jfcamp_operations\Service\WorkshopCapacityService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Operational workshop and slot capacity overview.
 */
final class WorkshopCapacityController extends ControllerBase {

  public function __construct(
    private WorkshopCapacityService $workshopCapacity,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.workshop_capacity'),
    );
  }

  public function overview(): array {
    $data = $this->workshopCapacity->getOverview();
    $summary = $data['summary'];

    $header = [
      $this->t('Workshop'),
      $this->t('Raum'),
      $this->t('Kapazität je Slot'),
    ];
    for ($slot = 1; $slot <= $data['assignment_columns']; $slot++) {
      $header[] = $this->t('Slot @slot', ['@slot' => $slot]);
    }

    $rows = [];
    foreach ($data['rows'] as $workshop) {
      $row = [
        Link::fromTextAndUrl(
          $workshop['label'],
          Url::fromRoute('entity.node.canonical', ['node' => $workshop['workshop_id']]),
        ),
        $workshop['room'] !== '' ? $workshop['room'] : '—',
        (string) $workshop['capacity'],
      ];

      foreach ($workshop['slots'] as $slotData) {
        if ($slotData['overbooked']) {
          $label = $this->t('@occupied/@capacity · @over überbelegt', [
            '@occupied' => $slotData['occupied'],
            '@capacity' => $slotData['capacity'],
            '@over' => $slotData['overbooked_by'],
          ]);
        }
        elseif ($slotData['full']) {
          $label = $this->t('@occupied/@capacity · voll', [
            '@occupied' => $slotData['occupied'],
            '@capacity' => $slotData['capacity'],
          ]);
        }
        else {
          $label = $this->t('@occupied/@capacity · @free frei', [
            '@occupied' => $slotData['occupied'],
            '@capacity' => $slotData['capacity'],
            '@free' => $slotData['free'],
          ]);
        }

        $link = Link::fromTextAndUrl(
          $label,
          Url::fromRoute('jfcamp_operations.participants', [], [
            'query' => [
              'workshop' => $workshop['workshop_id'],
              'slot' => $slotData['slot'],
            ],
          ]),
        )->toRenderable();

        $classes = ['jfcamp-capacity-cell'];
        if ($slotData['overbooked']) {
          $classes[] = 'jfcamp-capacity-cell--error';
        }
        elseif ($slotData['full']) {
          $classes[] = 'jfcamp-capacity-cell--full';
        }

        $row[] = [
          'data' => $link,
          'class' => $classes,
        ];
      }

      $rows[] = $row;
    }

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
        'Aktuelle Belegung aus den produktiven Zuteilungen. Die Kapazität gilt pro Workshop und Slot. Ein Klick auf eine Slot-Belegung öffnet die passende Teilnehmerliste.'
      ) . '</p>',
    ];

    $statusClass = $summary['overbooked_slot_occurrences'] > 0 ? 'messages--error' : 'messages--status';
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', $statusClass]],
      'content' => [
        '#markup' => '<strong>' . $this->t('Gesamt:') . '</strong> '
          . $this->t('@workshops Workshops · @slots Slots · @occupied/@capacity Plätze belegt · @free frei', [
            '@workshops' => $summary['workshops'],
            '@slots' => $summary['slots'],
            '@occupied' => $summary['occupied'],
            '@capacity' => $summary['total_capacity'],
            '@free' => $summary['free'],
          ])
          . '<br><strong>' . $this->t('Volle Workshop-Slots:') . '</strong> ' . $summary['full_slot_occurrences']
          . ' · <strong>' . $this->t('Überbuchungen:') . '</strong> ' . $summary['overbooked_slot_occurrences'],
      ],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('Keine veröffentlichten Workshops gefunden.'),
      '#sticky' => TRUE,
    ];

    return $build;
  }

}
