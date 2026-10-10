<?php

namespace Drupal\jfcamp_admin\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Uses the configured administration theme for JF Camp content routes.
 */
final class JfCampAdminThemeNegotiator implements ThemeNegotiatorInterface {

  private const BUNDLES = [
    'teilnehmer',
    'workshop',
    'wunsch',
  ];

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    if (!$this->currentUser->hasPermission('view the administration theme')) {
      return FALSE;
    }

    $routeName = (string) $route_match->getRouteName();

    if ($routeName === 'node.add') {
      $nodeType = $route_match->getParameter('node_type');
      return $nodeType instanceof NodeTypeInterface
        && in_array($nodeType->id(), self::BUNDLES, TRUE);
    }

    if (!$this->isNodeOperationRoute($routeName)) {
      return FALSE;
    }

    $node = $route_match->getParameter('node');
    return $node instanceof NodeInterface
      && in_array($node->bundle(), self::BUNDLES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    $adminTheme = (string) $this->configFactory->get('system.theme')->get('admin');
    return $adminTheme !== '' ? $adminTheme : NULL;
  }

  /**
   * Returns TRUE for the normal node view/edit/delete/revision operations.
   */
  private function isNodeOperationRoute(string $routeName): bool {
    return $routeName === 'entity.node.canonical'
      || $routeName === 'entity.node.edit_form'
      || $routeName === 'entity.node.delete_form'
      || str_starts_with($routeName, 'entity.node.revision');
  }

}
