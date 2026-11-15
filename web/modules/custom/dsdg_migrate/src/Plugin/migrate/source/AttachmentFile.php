<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\source;

use Drupal\file\Plugin\migrate\source\d7\File;

/**
 * D7 files used in a content_page's field_attachments.
 *
 * Each becomes a "file" media entity for the D11 field_attachments field.
 *
 * @MigrateSource(
 *   id = "dsdg_attachment_file",
 *   source_module = "file"
 * )
 */
class AttachmentFile extends File {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $attachments = $this->select('field_data_field_attachments', 'a')
      ->fields('a', ['field_attachments_fid'])
      ->condition('a.bundle', 'content_page');
    $query->condition('f.fid', $attachments, 'IN');
    return $query;
  }

}
