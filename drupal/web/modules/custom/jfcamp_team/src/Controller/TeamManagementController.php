<?php

namespace Drupal\jfcamp_team\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Lists Teamer*innen managed by the camp leadership.
 */
final class TeamManagementController extends ControllerBase {

  public function overview(): array {
    $storage = $this->entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->condition('roles', 'teamer')
      ->sort('name')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $account) {
      $roles = $account->getRoles(TRUE);
      $manageable = $roles === ['teamer'];
      $regions = [];
      if ($account->hasField('field_jfcamp_regionalverbaende')) {
        foreach ($account->get('field_jfcamp_regionalverbaende') as $item) {
          $value = trim((string) $item->value);
          if ($value !== '') {
            $regions[] = $value;
          }
        }
      }
      sort($regions, SORT_NATURAL | SORT_FLAG_CASE);

      if ($manageable) {
        $edit_url = Url::fromRoute('jfcamp_team.edit', ['user_id' => $account->id()]);
        $name = Link::fromTextAndUrl($account->getAccountName(), $edit_url)->toRenderable();
        $name['#attributes']['class'][] = 'jfcamp-team__user-link';

        $operations = [
          '#type' => 'operations',
          '#links' => [
            'edit' => [
              'title' => $this->t('Bearbeiten'),
              'url' => $edit_url,
            ],
          ],
        ];
      }
      else {
        $name = ['#markup' => '<strong>' . htmlspecialchars($account->getAccountName(), ENT_QUOTES, 'UTF-8') . '</strong>'];
        $operations = ['#markup' => $this->t('Zusätzliche Rolle – nur Administrator')];
      }

      $rows[] = [
        'name' => ['data' => $name],
        'status' => $account->isActive() ? $this->t('Aktiv') : $this->t('Gesperrt'),
        'regions' => $regions ? implode(', ', $regions) : $this->t('Keine'),
        'operations' => ['data' => $operations],
      ];
    }

    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Hier verwaltet die Camp-Leitung ausschließlich Teamer*innen-Konten. Rollen mit zusätzlichen Rechten können nur von Administrator*innen bearbeitet werden.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Benutzername'),
          $this->t('Status'),
          $this->t('Regionalverbände'),
          $this->t('Aktionen'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Noch keine Teamer*innen vorhanden.'),
      ],
    ];
  }

}
