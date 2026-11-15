<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate\Row;
use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * D7 DSDG node revisions, in the order D7 saved them, in every language.
 *
 * D7 translations are separate nodes linked by tnid, each with its own
 * revisions. D11 keeps one revision history per page, so this returns the
 * revisions of source nodes and translations together, ordered by vid (D7
 * assigns vids in save order), for dsdg_content_page_revision to replay.
 *
 * Left out:
 * - Revisions of superseded translation nodes (SupersededTranslationsTrait).
 * - Workbench moderation's copies of the live revision. When a draft is saved
 *   on a published node, workbench_moderation saves the live revision again
 *   as a new revision, so that node.vid keeps pointing at live content. Such
 *   a copy starts its history "published" to "published" at the second the
 *   draft (a lower vid of the same node) was saved. D11 keeps drafts as
 *   pending revisions instead, so the copies would only repeat live content
 *   in the history, and replayed in vid order a copy would replace a draft
 *   that D7 later published.
 *
 * Adds these source properties:
 * - dsdg_nid: the D11 page's nid, the translation set's source node (tnid).
 * - dsdg_translation_source: for a translation, its source node's language;
 *   "und" for a source node.
 * - dsdg_workbench_state: the revision's last workbench state (draft,
 *   needs_review, published), or "unpublished" if that state took down a
 *   live revision (D7's "Unpublish" moves the live revision back to draft).
 *
 * @MigrateSource(
 *   id = "dsdg_content_page_revision",
 *   source_module = "node"
 * )
 */
class ContentPageRevision extends Node {

  use SupersededTranslationsTrait;

  /**
   * All revisions of each node, not just the current one.
   */
  const JOIN = '[n].[nid] = [nr].[nid]';

  /**
   * Workbench moderation history, keyed by vid.
   *
   * @var array
   */
  protected array $workbench;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    // From the D7 uuid module; the core source doesn't select it.
    $query->addField('n', 'uuid');
    if ($superseded = $this->supersededNodes()) {
      $query->condition('n.nid', $superseded, 'NOT IN');
    }
    $copies = array_keys(array_filter($this->workbench(), fn ($revision) => $revision['copy']));
    if ($copies) {
      $query->condition('nr.vid', $copies, 'NOT IN');
    }
    $query->orderBy('nr.vid');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function handleTranslations(SelectInterface $query) {
    // Source nodes and translations alike.
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return parent::fields() + [
      'vid' => $this->t('The primary identifier for this version.'),
      'uuid' => $this->t('The node UUID'),
      'dsdg_nid' => $this->t('The D11 page nid (the translation set source node)'),
      'dsdg_translation_source' => $this->t('The language a translation was translated from'),
      'dsdg_workbench_state' => $this->t('The last workbench moderation state'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['vid']['type'] = 'integer';
    $ids['vid']['alias'] = 'nr';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $nid = $row->getSourceProperty('nid');
    $tnid = $row->getSourceProperty('tnid') ?: $nid;
    $row->setSourceProperty('dsdg_nid', $tnid);
    $row->setSourceProperty('dsdg_translation_source', $tnid == $nid ? 'und' : $row->getSourceProperty('source_langcode'));

    $revision = $this->workbench()[$row->getSourceProperty('vid')] ?? NULL;
    if ($revision) {
      $row->setSourceProperty('dsdg_workbench_state', $revision['unpublished'] ? 'unpublished' : $revision['state']);
    }
    return parent::prepareRow($row);
  }

  /**
   * Returns each revision's workbench moderation history, keyed by vid.
   *
   * Each item has:
   * - state: the revision's last state.
   * - unpublished: TRUE if the revision was published before that state.
   * - copy: TRUE if it is workbench's copy of the live revision.
   */
  protected function workbench(): array {
    if (!isset($this->workbench)) {
      $this->workbench = [];
      $history = $this->select('workbench_moderation_node_history', 'h')
        ->fields('h', ['vid', 'nid', 'from_state', 'state', 'stamp'])
        ->orderBy('h.hid')
        ->execute()
        ->fetchAll();

      // Lowest vid saved as draft or for review, by nid and second.
      $drafts = [];
      foreach ($history as $item) {
        if ($item['state'] !== 'published') {
          $key = $item['nid'] . ':' . $item['stamp'];
          $drafts[$key] = min($drafts[$key] ?? PHP_INT_MAX, $item['vid']);
        }
      }

      foreach ($history as $item) {
        $vid = $item['vid'];
        if (!isset($this->workbench[$vid])) {
          $this->workbench[$vid] = [
            'state' => $item['state'],
            'unpublished' => FALSE,
            'copy' => $item['from_state'] === 'published' && $item['state'] === 'published'
            && ($drafts[$item['nid'] . ':' . $item['stamp']] ?? PHP_INT_MAX) < $vid,
          ];
        }
        else {
          $revision = &$this->workbench[$vid];
          $revision['unpublished'] = $item['state'] !== 'published' && ($revision['unpublished'] || $revision['state'] === 'published');
          $revision['state'] = $item['state'];
          unset($revision);
        }
      }
    }
    return $this->workbench;
  }

}
