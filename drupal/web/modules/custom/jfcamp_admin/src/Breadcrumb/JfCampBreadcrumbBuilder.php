<?php

namespace Drupal\jfcamp_admin\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Provides one consistent breadcrumb hierarchy for JF Camp administration.
 */
final class JfCampBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  private const JFCAMP_BUNDLES = [
    'teilnehmer',
    'workshop',
    'wunsch',
  ];

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $routeName = (string) $route_match->getRouteName();

    if (
      str_starts_with($routeName, 'jfcamp_admin.')
      || str_starts_with($routeName, 'jfcamp_operations.')
      || str_starts_with($routeName, 'jfcamp_matching.')
      || $routeName === 'jfcamp_api.csv_import'
    ) {
      return TRUE;
    }

    if ($routeName === 'node.add') {
      $nodeType = $route_match->getParameter('node_type');
      return $nodeType instanceof NodeTypeInterface
        && in_array($nodeType->id(), self::JFCAMP_BUNDLES, TRUE);
    }

    if (!$this->isNodeOperationRoute($routeName)) {
      return FALSE;
    }

    $node = $route_match->getParameter('node');
    return $node instanceof NodeInterface
      && in_array($node->bundle(), self::JFCAMP_BUNDLES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $routeName = (string) $route_match->getRouteName();

    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route'])
      ->addLink(Link::createFromRoute('Verwaltung', 'system.admin'));

    if ($routeName === 'jfcamp_admin.overview') {
      return $breadcrumb;
    }

    $breadcrumb->addLink(Link::createFromRoute('JF Camp', 'jfcamp_admin.overview'));

    if ($routeName === 'jfcamp_operations.participant_detail') {
      $breadcrumb->addLink(Link::createFromRoute(
        'Teilnehmende & Zuteilungen',
        'jfcamp_operations.participants',
      ));
      return $breadcrumb;
    }

    if (in_array($routeName, ['jfcamp_operations.assignment_edit', 'jfcamp_operations.assignment_swap', 'jfcamp_operations.wish_edit'], TRUE)) {
      $breadcrumb->addLink(Link::createFromRoute(
        'Teilnehmende & Zuteilungen',
        'jfcamp_operations.participants',
      ));
      return $breadcrumb;
    }

    if (
      str_starts_with($routeName, 'jfcamp_matching.')
      && $routeName !== 'jfcamp_matching.dashboard'
    ) {
      $breadcrumb->addLink(Link::createFromRoute('Matching', 'jfcamp_matching.dashboard'));
      return $breadcrumb;
    }

    $node = $route_match->getParameter('node');
    if ($node instanceof NodeInterface) {
      $breadcrumb->addCacheableDependency($node);
      $this->addBundleParent($breadcrumb, $node->bundle());
      return $breadcrumb;
    }

    if ($routeName === 'node.add') {
      $nodeType = $route_match->getParameter('node_type');
      if ($nodeType instanceof NodeTypeInterface) {
        $breadcrumb->addCacheableDependency($nodeType);
        $this->addBundleParent($breadcrumb, $nodeType->id());
      }
    }

    return $breadcrumb;
  }

  /**
   * Adds the operational parent page for a JF Camp content bundle.
   */
  private function addBundleParent(Breadcrumb $breadcrumb, string $bundle): void {
    if ($bundle === 'teilnehmer') {
      $breadcrumb->addLink(Link::createFromRoute(
        'Teilnehmende & Zuteilungen',
        'jfcamp_operations.participants',
      ));
      return;
    }

    if ($bundle === 'workshop') {
      $breadcrumb->addLink(Link::createFromRoute(
        'Workshops & Kapazitäten',
        'jfcamp_operations.workshop_capacity',
      ));
    }
  }

  /**
   * Returns TRUE for normal JF Camp node operation routes.
   */
  private function isNodeOperationRoute(string $routeName): bool {
    return $routeName === 'entity.node.canonical'
      || $routeName === 'entity.node.edit_form'
      || $routeName === 'entity.node.delete_form'
      || str_starts_with($routeName, 'entity.node.revision');
  }

}
