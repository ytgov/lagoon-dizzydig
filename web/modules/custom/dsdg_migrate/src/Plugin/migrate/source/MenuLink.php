<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\source;

use Drupal\menu_link_content\Plugin\migrate\source\MenuLink as CoreMenuLink;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * D7 DSDG menu links, with French links as translations.
 *
 * D7 keeps separate English and French links (i18n "translate and localize")
 * and links only a few of them as translations. D11 menus don't filter links
 * by language, so a French link becomes a translation of its English pair:
 * - a link to a page pairs with the English link to that page's source node;
 * - other links pair through the "pairs" configuration (French mlid =>
 *   English mlid).
 * French links without a pair (to the French-only pages, which aren't
 * migrated) and links to superseded French pages are skipped.
 *
 * Adds these source properties:
 * - dsdg_menu: the D11 menu, from the "menus" configuration.
 * - dsdg_pair: for a French link, the English link's mlid.
 * - dsdg_uri: entity:node/N for pages (French pages map to their source
 *   node), the URL for external links, internal:/PATH otherwise.
 * - dsdg_parent: the parent's mlid, using its English pair if it has one.
 *
 * Configuration:
 * - menus: D11 menu name, keyed by D7 menu name. Other menus are skipped.
 * - pairs: English mlid, keyed by French mlid, for links not to pages.
 * - translations: FALSE (default) returns English and language-neutral
 *   links; TRUE returns paired French links.
 *
 * @MigrateSource(
 *   id = "dsdg_menu_link",
 *   source_module = "menu"
 * )
 */
class MenuLink extends CoreMenuLink {

  /**
   * English mlid for each paired French mlid, by D7 menu.
   *
   * @var array
   */
  protected array $pairs;

  /**
   * Nids of superseded D7 translation nodes.
   *
   * @var int[]
   */
  protected array $superseded;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->configuration['menu_name'] = array_keys($this->configuration['menus']);
    return parent::query();
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $result = parent::prepareRow($row);

    // Links to French pages that dsdg_content_page_translation skipped as
    // superseded duplicates are skipped too. Only the main migration reports
    // them.
    if (preg_match('#^node/(\d+)$#', $row->getSourceProperty('link_path'), $match) && in_array($match[1], $this->supersededNodes())) {
      if (empty($this->configuration['translations'])) {
        $this->idMap->saveMessage($row->getSourceIdValues(), "Link to superseded French page node/{$match[1]} skipped.", MigrationInterface::MESSAGE_INFORMATIONAL);
      }
      return $this->skip($row);
    }

    $mlid = $row->getSourceProperty('mlid');
    $pair = $this->pairs()[$mlid] ?? NULL;
    $is_french = $row->getSourceProperty('language') === 'fr';
    if ($is_french && !$pair) {
      // Unpaired French links go to the French-only pages, which
      // dsdg_content_page leaves out. Only the main migration reports them.
      if (empty($this->configuration['translations'])) {
        $this->idMap->saveMessage($row->getSourceIdValues(), "French link \"{$row->getSourceProperty('link_title')}\" has no English link; skipped.", MigrationInterface::MESSAGE_INFORMATIONAL);
      }
      return $this->skip($row);
    }
    if (!empty($this->configuration['translations']) !== $is_french) {
      // The other migration handles this row.
      return $this->skip($row);
    }

    $row->setSourceProperty('dsdg_menu', $this->configuration['menus'][$row->getSourceProperty('menu_name')]);
    $row->setSourceProperty('dsdg_pair', $pair);
    $row->setSourceProperty('dsdg_uri', $this->uri($row));
    $plid = $row->getSourceProperty('plid');
    $row->setSourceProperty('dsdg_parent', $this->pairs()[$plid] ?? $plid);
    return $result;
  }

  /**
   * Skips a row, recording it as ignored.
   *
   * As SourcePluginBase::prepareRow() does for skipped rows, so that
   * migrations depending on this one see it as complete.
   */
  protected function skip(Row $row): bool {
    $this->idMap->saveIdMapping($row, [], MigrateIdMapInterface::STATUS_IGNORED);
    $this->currentRow = NULL;
    $this->currentSourceIds = NULL;
    return FALSE;
  }

  /**
   * Returns the nids of superseded D7 translation nodes.
   *
   * Uses the same rule as DsdgSkipSupersededTranslation: in each translation
   * set and language, the most recently changed published node is kept.
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

  /**
   * Returns the link's D11 URI.
   */
  protected function uri(Row $row): string {
    $path = $row->getSourceProperty('link_path');
    if ($row->getSourceProperty('external')) {
      return $path;
    }
    if (preg_match('#^node/(\d+)$#', $path, $match)) {
      $tnid = $this->select('node', 'n')
        ->fields('n', ['tnid'])
        ->condition('n.nid', $match[1])
        ->execute()
        ->fetchField();
      return 'entity:node/' . ($tnid ?: $match[1]);
    }
    return $path === '<front>' ? 'internal:/' : 'internal:/' . $path;
  }

  /**
   * Returns the English mlid for each paired French mlid.
   */
  protected function pairs(): array {
    if (!isset($this->pairs)) {
      $this->pairs = $this->configuration['pairs'] ?? [];
      // A French link to a page pairs with the English link, in the same menu,
      // to that page's source node.
      $query = $this->select('menu_links', 'f');
      $query->innerJoin('node', 'fn', "[f].[link_path] = CONCAT('node/', [fn].[nid])");
      $query->innerJoin('menu_links', 'e', "[e].[menu_name] = [f].[menu_name] AND [e].[module] = 'menu' AND [e].[language] = 'en' AND [e].[link_path] = CONCAT('node/', IF([fn].[tnid] > 0, [fn].[tnid], [fn].[nid]))");
      $query->fields('f', ['mlid']);
      $query->addField('e', 'mlid', 'en_mlid');
      $query->condition('f.module', 'menu')
        ->condition('f.language', 'fr')
        ->condition('f.menu_name', array_keys($this->configuration['menus']), 'IN');
      $this->pairs += $query->execute()->fetchAllKeyed();
    }
    return $this->pairs;
  }

}
