<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Maps a D7 alias source path to its D11 path.
 *
 * - node/N for a migrated content_page becomes /node/N. French translations
 *   (separate nodes in D7) become /node/{source nid}, the node they were
 *   merged into.
 * - taxonomy/term/N for a wetkit_categories term becomes /taxonomy/term/N.
 *
 * Anything else skips the row, with a message. dsdg_content_page and
 * dsdg_category keep D7 IDs, so no lookups are needed.
 *
 * Usage:
 * @code
 * path:
 *   plugin: dsdg_path
 *   source: source
 * @endcode
 */
#[MigrateProcess('dsdg_path')]
class DsdgPath extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $db = Database::getConnection('default', 'migrate');
    if (preg_match('#^node/(\d+)$#', $value, $match)) {
      $node = $db->query('SELECT nid, tnid, type FROM {node} WHERE nid = :nid', [':nid' => $match[1]])->fetchObject();
      $nid = $node && $node->tnid && $node->tnid != $node->nid ? $node->tnid : $node?->nid;
      // Only pages that were migrated (French-only pages are left out).
      if ($node && $node->type === 'content_page' && \Drupal::entityTypeManager()->getStorage('node')->load($nid)) {
        return '/node/' . $nid;
      }
    }
    elseif (preg_match('#^taxonomy/term/(\d+)$#', $value, $match)) {
      $vocabulary = $db->query('SELECT v.machine_name FROM {taxonomy_term_data} t INNER JOIN {taxonomy_vocabulary} v ON v.vid = t.vid WHERE t.tid = :tid', [':tid' => $match[1]])->fetchField();
      if ($vocabulary === 'wetkit_categories') {
        return '/taxonomy/term/' . $match[1];
      }
    }
    throw new MigrateSkipRowException("Alias source $value is not a migrated page or category.");
  }

}
