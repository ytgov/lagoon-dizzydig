<?php

namespace Drupal\dsdg_search\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The search page's breadcrumb: Home / Search / keywords.
 */
class SearchBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, $cacheability = NULL): bool {
    return $route_match->getRouteName() === DSDG_SEARCH_ROUTE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['url.path', 'languages:language_interface']);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $keywords = (string) $route_match->getRawParameter('arg_0');
    if ($keywords === '') {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Search'), '<none>'));
    }
    else {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Search'), DSDG_SEARCH_ROUTE));
      $breadcrumb->addLink(Link::createFromRoute($keywords, '<none>'));
    }
    return $breadcrumb;
  }

}
