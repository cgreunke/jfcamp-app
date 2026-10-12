<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides the operational JF Camp participant detail page.
 */
final class ParticipantDetailController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $jfcampEntityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function access(int $participant_id, AccountInterface $account): AccessResult {
    $participant = $this->loadParticipant($participant_id, FALSE);
    if (!$participant) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    return AccessResult::allowedIf($participant->access('view', $account))
      ->addCacheableDependency($participant)
      ->cachePerPermissions()
      ->cachePerUser();
  }

  public function title(int $participant_id): string {
    $participant = $this->loadParticipant($participant_id);
    return $this->participantName($participant);
  }

  public function view(int $participant_id): array {
    $participant = $this->loadParticipant($participant_id);
    $name = $this->participantName($participant);
    $code = $participant->hasField('field_code') ? trim((string) $participant->get('field_code')->value) : '';
    $region = $participant->hasField('field_regionalverband') ? trim((string) $participant->get('field_regionalverband')->value) : '';

    $wishLabels = $this->loadWishLabels($participant);
    $assignments = [];
    if ($participant->hasField('field_zugewiesen')) {
      foreach ($participant->get('field_zugewiesen') as $delta => $item) {
        $workshop = $item->entity;
        $assignments[] = [
          'slot' => $delta + 1,
          'label' => $workshop ? $workshop->label() : (string) $this->t('Nicht zugewiesen'),
          'workshop_id' => $workshop ? (int) $workshop->id() : NULL,
        ];
      }
    }

    $canEditAssignments = $this->currentUser()->hasPermission('edit jfcamp participant assignments');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail']],
      '#attached' => ['library' => ['jfcamp_operations/admin']],
      '#cache' => ['max-age' => 0],
    ];

    $build['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail__hero']],
      'identity' => [
        '#markup' => '<div class="jfcamp-participant-detail__eyebrow">' . $this->t('Teilnehmer*in') . '</div>'
          . '<div class="jfcamp-participant-detail__name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>'
          . '<div class="jfcamp-participant-detail__meta">'
          . htmlspecialchars($code !== '' ? $code : '—', ENT_QUOTES, 'UTF-8')
          . ($region !== '' ? ' · ' . htmlspecialchars($region, ENT_QUOTES, 'UTF-8') : '')
          . '</div>',
      ],
    ];

    $wishItems = [];
    foreach ($wishLabels as $index => $label) {
      $wishItems[] = [
        '#markup' => '<span class="jfcamp-participant-detail__rank">' . ($index + 1) . '</span>'
          . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>',
      ];
    }

    $build['wishes'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail__card']],
      'title' => ['#markup' => '<h2>' . $this->t('Wünsche') . '</h2>'],
      'content' => $wishItems
        ? [
          '#theme' => 'item_list',
          '#items' => $wishItems,
          '#attributes' => ['class' => ['jfcamp-participant-detail__wishes']],
        ]
        : ['#markup' => '<p class="jfcamp-participant-detail__empty">' . $this->t('Keine Wünsche abgegeben.') . '</p>'],
    ];

    $assignmentItems = [];
    foreach ($assignments as $assignment) {
      $row = [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-participant-detail__assignment']],
        'slot' => ['#markup' => '<div class="jfcamp-participant-detail__slot">' . $this->t('Slot @slot', ['@slot' => $assignment['slot']]) . '</div>'],
        'label' => ['#markup' => '<div class="jfcamp-participant-detail__workshop">' . htmlspecialchars($assignment['label'], ENT_QUOTES, 'UTF-8') . '</div>'],
      ];
      if ($canEditAssignments) {
        $row['edit'] = Link::fromTextAndUrl(
          $this->t('Ändern'),
          Url::fromRoute('jfcamp_operations.assignment_edit', [
            'participant_id' => $participant_id,
            'slot' => $assignment['slot'],
          ]),
        )->toRenderable();
        $row['edit']['#attributes']['class'] = ['button', 'button--small'];
      }
      $assignmentItems['slot_' . $assignment['slot']] = $row;
    }

    $build['assignments'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail__card']],
      'title' => ['#markup' => '<h2>' . $this->t('Zuteilungen') . '</h2>'],
      'content' => $assignmentItems ?: ['#markup' => '<p class="jfcamp-participant-detail__empty">' . $this->t('Noch keine Zuteilungen vorhanden.') . '</p>'],
    ];

    return $build;
  }

  private function loadParticipant(int $participantId, bool $throw = TRUE): ?NodeInterface {
    $participant = $this->jfcampEntityTypeManager->getStorage('node')->load($participantId);
    if (!$participant instanceof NodeInterface || $participant->bundle() !== 'teilnehmer') {
      if ($throw) {
        throw new NotFoundHttpException();
      }
      return NULL;
    }
    return $participant;
  }

  private function participantName(NodeInterface $participant): string {
    $first = $participant->hasField('field_vorname') ? trim((string) $participant->get('field_vorname')->value) : '';
    $last = $participant->hasField('field_name') ? trim((string) $participant->get('field_name')->value) : '';
    $name = trim($first . ' ' . $last);
    return $name !== '' ? $name : $participant->label();
  }

  /** @return string[] */
  private function loadWishLabels(NodeInterface $participant): array {
    $storage = $this->jfcampEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wunsch')
      ->condition('status', 1)
      ->condition('field_teilnehmer.target_id', (int) $participant->id())
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return [];
    }

    $wish = $storage->load(reset($ids));
    if (!$wish instanceof NodeInterface || !$wish->hasField('field_wuensche')) {
      return [];
    }

    $labels = [];
    foreach ($wish->get('field_wuensche') as $item) {
      if ($item->entity) {
        $labels[] = $item->entity->label();
      }
    }
    return $labels;
  }

}
