<?php

namespace Drupal\dsdg_search\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects search form submissions to /search/content/{keywords}.
 *
 * The search form submits ?search_api_fulltext=KEYWORDS, which D7 never used.
 * Other query parameters (facets: f[0]=...) are kept.
 */
class KeywordsRedirect implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (32), so the route is known.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Redirects a request with the keywords in the query string.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== DSDG_SEARCH_ROUTE || !$request->query->has('search_api_fulltext')) {
      return;
    }
    $query = $request->query->all();
    // A route parameter can't contain a slash.
    $keywords = trim(str_replace('/', ' ', (string) $query['search_api_fulltext']));
    unset($query['search_api_fulltext']);
    $url = Url::fromRoute(DSDG_SEARCH_ROUTE, ['arg_0' => $keywords === '' ? NULL : $keywords], ['query' => $query, 'absolute' => TRUE]);
    $event->setResponse(new TrustedRedirectResponse($url->toString(), 302));
  }

}
