<?php

namespace Drupal\jfcamp_team\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Breadcrumbs for JF Camp team management routes.
 */
final class JfCampTeamBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  public function applies(RouteMatchInterface $route_match): bool {
    return str_starts_with((string) $route_match->getRouteName(), 'jfcamp_team.');
  }

  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);

    $breadcrumb->addLink(Link::createFromRoute(t('Verwaltung'), 'system.admin'));
    $breadcrumb->addLink(Link::createFromRoute(t('JF Camp'), 'jfcamp_admin.overview'));

    $route = (string) $route_match->getRouteName();
    if ($route !== 'jfcamp_team.overview') {
      $breadcrumb->addLink(Link::createFromRoute(t('Teamverwaltung'), 'jfcamp_team.overview'));
    }

    return $breadcrumb;
  }

}
