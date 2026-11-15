<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Skips D7 translations superseded by another in the same language.
 *
 * Some D7 translation sets have more than one French node, usually old
 * unpublished copies. D11 allows one translation per language, so only one is
 * kept: the most recently changed published node, or the most recently
 * changed node if none is published.
 *
 * Usage:
 * @code
 * _keep:
 *   plugin: dsdg_skip_superseded_translation
 *   source: nid
 * @endcode
 */
#[MigrateProcess('dsdg_skip_superseded_translation')]
class DsdgSkipSupersededTranslation extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $kept = Database::getConnection('default', 'migrate')
      ->query('SELECT nid FROM {node} WHERE tnid = :tnid AND language = :langcode AND nid <> tnid ORDER BY status DESC, changed DESC, nid DESC', [
        ':tnid' => $row->getSourceProperty('tnid'),
        ':langcode' => $row->getSourceProperty('language'),
      ])
      ->fetchField();
    if ($kept && $kept != $value) {
      throw new MigrateSkipRowException("Superseded by D7 node $kept, the {$row->getSourceProperty('language')} translation kept for node {$row->getSourceProperty('tnid')}.");
    }
    return $value;
  }

}
