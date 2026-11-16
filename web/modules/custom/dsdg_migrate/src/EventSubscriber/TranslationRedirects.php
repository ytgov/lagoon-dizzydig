<?php

namespace Drupal\dsdg_migrate\EventSubscriber;

use Drupal\Core\Database\Database;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Redirects the URLs of D7 translation nodes to their D11 translation.
 *
 * D7 translations are separate nodes, which dsdg_content_page_revision merges
 * into their source node. Core's NodeTranslationExceptionSubscriber redirects
 * requests for the old node IDs (/fr/node/N) using the
 * node_translation_redirect key-value store and state flag, but core's
 * NodeTranslationMigrateSubscriber fills them only for migrations with
 * "translations: true" and the entity:node destination. So after
 * dsdg_content_page_revision is imported, this fills them for every D7
 * content_page translation node, superseded ones included (they redirect to
 * the translation that was kept), and empties them again on rollback.
 */
class TranslationRedirects implements EventSubscriberInterface {

  /**
   * The migration that merges D7 translation nodes.
   */
  const MIGRATION = 'dsdg_content_page_revision';

  /**
   * The key-value collection and state key core reads.
   */
  const KEY = 'node_translation_redirect';

  public function __construct(
    protected KeyValueFactoryInterface $keyValue,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::POST_IMPORT => 'onPostImport',
      MigrateEvents::POST_ROLLBACK => 'onPostRollback',
    ];
  }

  /**
   * Stores a redirect for each D7 translation node.
   */
  public function onPostImport(MigrateImportEvent $event): void {
    if ($event->getMigration()->id() === static::MIGRATION) {
      $this->keyValue->get(static::KEY)->setMultiple($this->redirects());
      $this->state->set(static::KEY, TRUE);
    }
  }

  /**
   * Removes the redirects.
   */
  public function onPostRollback(MigrateRollbackEvent $event): void {
    if ($event->getMigration()->id() === static::MIGRATION) {
      $this->keyValue->get(static::KEY)->deleteMultiple(array_keys($this->redirects()));
      $this->state->delete(static::KEY);
    }
  }

  /**
   * Returns [D11 nid, langcode], keyed by D7 translation node ID.
   */
  protected function redirects(): array {
    $redirects = [];
    $nodes = Database::getConnection('default', 'migrate')
      ->query("SELECT nid, tnid, language FROM {node} WHERE type = 'content_page' AND tnid <> 0 AND tnid <> nid")
      ->fetchAll();
    foreach ($nodes as $node) {
      $redirects[$node->nid] = [$node->tnid, $node->language];
    }
    return $redirects;
  }

}
