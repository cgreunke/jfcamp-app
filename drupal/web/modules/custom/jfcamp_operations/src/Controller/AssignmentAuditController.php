<?php

namespace Drupal\jfcamp_operations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\jfcamp_operations\Service\AssignmentAuditService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the immutable history of manual assignment changes.
 */
final class AssignmentAuditController extends ControllerBase {

  public function __construct(
    private AssignmentAuditService $audit,
    private EntityTypeManagerInterface $userEntityTypeManager,
    private DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('jfcamp_operations.assignment_audit'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  public function overview(): array {
    $status = $this->audit->getCurrentAssignmentStatus();
    $auditRows = $this->audit->recent(200);

    $uids = array_values(array_unique(array_filter(array_map(
      static fn (array $row): int => (int) ($row['uid'] ?? 0),
      $auditRows,
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
        '#markup' => '<p>' . $this->t('Das Protokoll ist unveränderlich und dokumentiert jede über die JF-Camp-Oberfläche gespeicherte manuelle Zuteilungsänderung.') . '</p>',
      ],
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
    ];
  }

}
