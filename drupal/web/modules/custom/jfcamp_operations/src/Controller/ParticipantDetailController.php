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
    $canEditWishes = $this->currentUser()->hasPermission('edit jfcamp participant wishes');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail']],
      '#attached' => ['library' => ['jfcamp_operations/admin']],
      '#cache' => ['max-age' => 0],
    ];

    $build['top_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail__top-actions']],
      'back' => Link::fromTextAndUrl(
        $this->t('Zur Teilnehmerübersicht'),
        Url::fromRoute('jfcamp_operations.participants'),
      )->toRenderable(),
    ];
    $build['top_actions']['back']['#attributes']['class'] = ['button'];

    $wishStatusClass = $wishLabels ? 'is-ok' : 'is-warning';
    $assignmentStatusClass = $assignments ? 'is-ok' : 'is-warning';

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
      'status' => [
        '#markup' => '<div class="jfcamp-participant-detail__status-row">'
          . '<span class="jfcamp-participant-detail__status ' . $wishStatusClass . '">'
          . ($wishLabels ? $this->formatPlural(count($wishLabels), '1 Wunsch', '@count Wünsche') : $this->t('Keine Wünsche'))
          . '</span>'
          . '<span class="jfcamp-participant-detail__status ' . $assignmentStatusClass . '">'
          . ($assignments ? $this->formatPlural(count($assignments), '1 Zuteilung', '@count Zuteilungen') : $this->t('Keine Zuteilung'))
          . '</span>'
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

    $wishCard = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-participant-detail__card']],
      'header' => [
        '#markup' => '<div class="jfcamp-participant-detail__card-header"><div><div class="jfcamp-participant-detail__section-kicker">Prioritäten</div><h2>' . $this->t('Wünsche') . '</h2></div></div>',
      ],
      'content' => $wishItems
        ? [
          '#theme' => 'item_list',
          '#items' => $wishItems,
          '#attributes' => ['class' => ['jfcamp-participant-detail__wishes']],
        ]
        : ['#markup' => '<div class="jfcamp-participant-detail__callout is-warning"><strong>' . $this->t('Noch keine Wünsche abgegeben') . '</strong><span>' . $this->t('Für diese*n Teilnehmer*in sind aktuell keine Workshop-Wünsche hinterlegt.') . '</span></div>'],
    ];
    if ($canEditWishes) {
      $wishCard['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['jfcamp-participant-detail__card-actions']],
        'edit' => Link::fromTextAndUrl(
          $this->t('Wünsche bearbeiten'),
          Url::fromRoute('jfcamp_operations.wish_edit', ['participant_id' => $participant_id]),
        )->toRenderable(),
        'note' => [
          '#markup' => '<span class="jfcamp-participant-detail__action-note">' . $this->t('Bestehende Zuteilungen bleiben unverändert.') . '</span>',
        ],
      ];
      $wishCard['actions']['edit']['#attributes']['class'] = ['button', 'button--primary'];
    }
    $build['wishes'] = $wishCard;

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
          $this->t('Zuteilung ändern'),
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
      'header' => [
        '#markup' => '<div class="jfcamp-participant-detail__card-header"><div><div class="jfcamp-participant-detail__section-kicker">Aktueller Stand</div><h2>' . $this->t('Zuteilungen') . '</h2></div></div>',
      ],
      'content' => $assignmentItems ?: ['#markup' => '<div class="jfcamp-participant-detail__callout is-warning"><strong>' . $this->t('Noch keine Zuteilungen') . '</strong><span>' . $this->t('Für diese*n Teilnehmer*in sind aktuell keine Workshops zugeteilt.') . '</span></div>'],
    ];

    if ($canEditAssignments) {
      $build['assignments']['footer'] = [
        '#markup' => '<p class="jfcamp-participant-detail__hint">' . $this->t('Bei vollen Workshops kann ein kapazitätssicherer Tausch organisiert werden.') . '</p>',
      ];
    }

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
