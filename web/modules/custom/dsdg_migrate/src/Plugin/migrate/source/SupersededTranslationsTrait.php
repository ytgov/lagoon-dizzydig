<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\source;

/**
 * Finds D7 translation nodes superseded by another in the same language.
 *
 * Some D7 translation sets have more than one French node, usually old
 * unpublished copies. D11 allows one translation per language, so only one is
 * kept: the most recently changed published node, or the most recently
 * changed node if none is published.
 *
 * For D7 source plugins (SqlBase).
 */
trait SupersededTranslationsTrait {

  /**
   * Nids of superseded D7 translation nodes.
   *
   * @var int[]
   */
  protected array $superseded;

  /**
   * Returns the nids of superseded D7 translation nodes.
   */
  protected function supersededNodes(): array {
    if (!isset($this->superseded)) {
      $this->superseded = [];
      $kept = [];
      $nodes = $this->select('node', 'n')
        ->fields('n', ['nid', 'tnid', 'language'])
        ->where('[n].[tnid] > 0 AND [n].[tnid] <> [n].[nid]')
        ->orderBy('n.status', 'DESC')
        ->orderBy('n.changed', 'DESC')
        ->orderBy('n.nid', 'DESC')
        ->execute();
      // Migrate source connections fetch rows as arrays.
      foreach ($nodes as $node) {
        $key = $node['tnid'] . ':' . $node['language'];
        if (isset($kept[$key])) {
          $this->superseded[] = $node['nid'];
        }
        else {
          $kept[$key] = TRUE;
        }
      }
    }
    return $this->superseded;
  }

}
