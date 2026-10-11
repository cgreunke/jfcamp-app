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
    $active = 0;
    $blocked = 0;

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

      if ($account->isActive()) {
        $active++;
      }
      else {
        $blocked++;
      }

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
        $operations = ['#markup' => $this->t('Nur Administrator')];
      }

      $status = $account->isActive()
        ? '<span class="jfcamp-team-status jfcamp-team-status--active">' . $this->t('Aktiv') . '</span>'
        : '<span class="jfcamp-team-status jfcamp-team-status--blocked">' . $this->t('Gesperrt') . '</span>';

      $regionMarkup = $regions
        ? implode(' ', array_map(
          static fn(string $region): string => '<span class="jfcamp-team-region">' . htmlspecialchars($region, ENT_QUOTES, 'UTF-8') . '</span>',
          $regions,
        ))
        : '<span class="jfcamp-team-muted">' . $this->t('Keine') . '</span>';

      $rows[] = [
        'name' => ['data' => $name],
        'status' => ['data' => ['#markup' => $status]],
        'regions' => ['data' => ['#markup' => $regionMarkup]],
        'operations' => ['data' => $operations],
      ];
    }

    return [
      '#attached' => [
        'library' => ['jfcamp_team/admin'],
      ],
      'intro' => [
        '#markup' => '<div class="jfcamp-team-callout">'
          . $this->t('Die Camp-Leitung verwaltet hier ausschließlich Teamer*innen-Konten. Regionalverbände steuern serverseitig, welche Teilnehmenden sichtbar sind. Konten mit zusätzlichen Rollen bleiben der Administration vorbehalten.')
          . '</div>',
      ],
      'summary' => [
        '#markup' => '<div class="jfcamp-team-summary">'
          . '<div><strong>' . count($ids) . '</strong><span>' . $this->t('Teamer*innen') . '</span></div>'
          . '<div><strong>' . $active . '</strong><span>' . $this->t('Aktiv') . '</span></div>'
          . '<div><strong>' . $blocked . '</strong><span>' . $this->t('Gesperrt') . '</span></div>'
          . '</div>',
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['jfcamp-team-table']],
        '#header' => [
          $this->t('Teamer*in'),
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
