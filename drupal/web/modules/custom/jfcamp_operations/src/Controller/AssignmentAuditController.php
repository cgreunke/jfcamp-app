<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\jfcamp_operations\Service\AssignmentAuditService;
use Drupal\jfcamp_operations\Service\WishManagementService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the immutable history of manual assignment changes.
 */
final class AssignmentAuditController extends ControllerBase {

  public function __construct(
    private AssignmentAuditService $audit,
    private WishManagementService $wishManagement,
    private EntityTypeManagerInterface $userEntityTypeManager,
    private DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.assignment_audit'),
      $container->get('jfcamp_operations.wish_management'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  public function overview(): array {
    $status = $this->audit->getCurrentAssignmentStatus();
    $auditRows = $this->audit->recent(200);
    $wishAuditRows = $this->wishManagement->recentAudit(200);

    $uids = array_values(array_unique(array_filter(array_map(
      static fn (array $row): int => (int) ($row['uid'] ?? 0),
      array_merge($auditRows, $wishAuditRows),
    ))));
    $users = $uids
      ? $this->userEntityTypeManager->getStorage('user')->loadMultiple($uids)
      : [];

    $rows = [];
    foreach ($auditRows as $row) {
      $uid = (int) $row['uid'];
      $userLabel = $uid === 0
        ? (string) $this->t('System / CLI')
        : (isset($users[$uid]) ? $users[$uid]->getDisplayName() : (string) $this->t('Benutzer #@uid', ['@uid' => $uid]));

      $rows[] = [
        $this->dateFormatter->format((int) $row['created_at'], 'short'),
        $userLabel,
        $row['participant_code'] !== '' ? $row['participant_code'] : '#' . $row['participant_id'],
        (string) $row['slot'],
        $row['old_workshop_label'],
        $row['new_workshop_label'],
        $row['base_matching_run_id'] !== NULL
          ? '#' . $row['base_matching_run_id']
          : (string) $this->t('kein Run'),
      ];
    }

    $wishRows = [];
    foreach ($wishAuditRows as $row) {
      $uid = (int) $row['uid'];
      $userLabel = $uid === 0
        ? (string) $this->t('System / CLI')
        : (isset($users[$uid]) ? $users[$uid]->getDisplayName() : (string) $this->t('Benutzer #@uid', ['@uid' => $uid]));
      $formatWishes = static function (array $items): string {
        if (!$items) {
          return '—';
        }
        return implode(' · ', array_map(
          static fn (array $item, int $index): string => ($index + 1) . '. ' . (string) ($item['label'] ?? ('#' . ($item['id'] ?? '?'))),
          $items,
          array_keys($items),
        ));
      };
      $wishRows[] = [
        $this->dateFormatter->format((int) $row['created_at'], 'short'),
        $userLabel,
        $row['participant_code'] !== '' ? $row['participant_code'] : '#' . $row['participant_id'],
        $formatWishes($row['old_wishes']),
        $formatWishes($row['new_wishes']),
      ];
    }

    $baseText = $status['base_run_id'] !== NULL
      ? (string) $this->t('Matching Run #@run', ['@run' => $status['base_run_id']])
      : (string) $this->t('Bestehender Zuteilungsstand – kein Matching-Run angewendet');

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['jfcamp-operations']],
      '#attached' => ['library' => ['jfcamp_operations/admin']],
      '#cache' => ['max-age' => 0],
      'status' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', $status['base_run_id'] !== NULL ? 'messages--status' : 'messages--warning'],
        ],
        'content' => [
          '#markup' => '<strong>' . $this->t('Zuteilungsbasis:') . '</strong> ' . $baseText
            . '<br><strong>' . $this->t('Manuelle Änderungen seit dieser Basis:') . '</strong> '
            . $status['manual_changes'],
        ],
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Das Protokoll ist unveränderlich und dokumentiert manuelle Zuteilungs- und Wunschänderungen aus der JF-Camp-Oberfläche.') . '</p>',
      ],
      'assignment_title' => ['#markup' => '<h2>' . $this->t('Zuteilungsänderungen') . '</h2>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Zeitpunkt'),
          $this->t('Benutzer'),
          $this->t('Teilnehmer'),
          $this->t('Slot'),
          $this->t('Vorher'),
          $this->t('Nachher'),
          $this->t('Basis-Run'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Noch keine manuellen Zuteilungsänderungen protokolliert.'),
      ],
      'wish_title' => ['#markup' => '<h2>' . $this->t('Wunschänderungen') . '</h2>'],
      'wish_table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Zeitpunkt'),
          $this->t('Benutzer'),
          $this->t('Teilnehmer'),
          $this->t('Vorher'),
          $this->t('Nachher'),
        ],
        '#rows' => $wishRows,
        '#empty' => $this->t('Noch keine manuellen Wunschänderungen protokolliert.'),
      ],
    ];
  }

}
