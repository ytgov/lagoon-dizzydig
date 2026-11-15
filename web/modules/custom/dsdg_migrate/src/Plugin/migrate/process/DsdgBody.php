<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\process;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Database;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Rewrites D7 DSDG body markup for D11.
 *
 * - [uuid-link:node:UUID] becomes the linked page's D7 URL (/{lang}/{alias}),
 *   which dsdg_url_alias migrates unchanged.
 * - [[{"fid":...}]] media tags become an <img> for images, or a link for other
 *   files, with data-entity-type/uuid so editor_file_reference tracks usage.
 *   dsdg_file keeps D7 file IDs, URIs and UUIDs.
 *
 * Reads the D7 database through the "migrate" connection.
 *
 * Usage:
 * @code
 * body/value:
 *   plugin: dsdg_body
 *   source: body/0/value
 * @endcode
 */
#[MigrateProcess('dsdg_body')]
class DsdgBody extends ProcessPluginBase {

  /**
   * URLs of D7 nodes, keyed by UUID.
   *
   * @var string[]
   */
  protected static array $nodeUrls = [];

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_string($value) || $value === '') {
      return $value;
    }
    $value = preg_replace_callback('/\[uuid-link:node:([0-9a-f-]{36})\]/', function ($match) use ($migrate_executable) {
      $url = $this->nodeUrl($match[1]);
      if ($url === NULL) {
        $migrate_executable->saveMessage("Link to unknown D7 node {$match[1]} left as is.");
        return $match[0];
      }
      return $url;
    }, $value);

    return preg_replace_callback('/\[\[(\{.+?\})\]\]/s', function ($match) use ($migrate_executable) {
      $tag = json_decode($match[1], TRUE);
      $markup = is_array($tag) ? $this->mediaMarkup($tag) : NULL;
      if ($markup === NULL) {
        $migrate_executable->saveMessage('Media tag left as is: ' . substr($match[0], 0, 120));
        return $match[0];
      }
      return $markup;
    }, $value);
  }

  /**
   * Returns the D7 URL of a node, or NULL if there's no such node.
   */
  protected function nodeUrl(string $uuid): ?string {
    if (!array_key_exists($uuid, static::$nodeUrls)) {
      $db = Database::getConnection('default', 'migrate');
      $node = $db->query('SELECT nid, tnid, language FROM {node} WHERE uuid = :uuid', [':uuid' => $uuid])->fetchObject();
      if (!$node) {
        static::$nodeUrls[$uuid] = NULL;
      }
      else {
        $langcode = in_array($node->language, ['und', 'zxx', ''], TRUE) ? 'en' : $node->language;
        $alias = $db->query("SELECT alias FROM {url_alias} WHERE source = :source AND language IN (:langcode, 'und') ORDER BY pid DESC", [
          ':source' => 'node/' . $node->nid,
          ':langcode' => $langcode,
        ])->fetchField();
        // Translations are merged into their source node in D11.
        $nid = $node->tnid && $node->tnid != $node->nid ? $node->tnid : $node->nid;
        static::$nodeUrls[$uuid] = '/' . $langcode . '/' . ($alias ?: 'node/' . $nid);
      }
    }
    return static::$nodeUrls[$uuid];
  }

  /**
   * Returns the markup for a D7 media tag, or NULL if its file is unknown.
   */
  protected function mediaMarkup(array $tag): ?string {
    $file = Database::getConnection('default', 'migrate')
      ->query('SELECT uri, filename, filemime, uuid FROM {file_managed} WHERE fid = :fid', [':fid' => $tag['fid'] ?? 0])
      ->fetchObject();
    if (!$file) {
      return NULL;
    }
    $url = \Drupal::service('file_url_generator')->generateString($file->uri);
    $attributes = [
      'data-entity-type' => 'file',
      'data-entity-uuid' => $file->uuid,
    ];
    // D7 keeps alt and title text both in the tag's attributes and in its
    // field overrides, e.g. "field_file_image_alt_text[und][0][value]".
    foreach (($tag['fields'] ?? []) as $name => $field_value) {
      foreach (['alt', 'title'] as $key) {
        if (str_contains($name, "_{$key}_text") && is_string($field_value) && $field_value !== '') {
          $attributes[$key] = $field_value;
        }
      }
    }
    foreach (['alt', 'title', 'style', 'class', 'width', 'height'] as $key) {
      if (!empty($tag['attributes'][$key])) {
        $attributes[$key] = $tag['attributes'][$key];
      }
    }

    if (str_starts_with($file->filemime, 'image/')) {
      $attributes = ['src' => $url] + $attributes + ['alt' => ''];
      return '<img' . $this->attributeString($attributes) . '>';
    }
    unset($attributes['alt'], $attributes['width'], $attributes['height']);
    $attributes = ['href' => $url] + $attributes;
    return '<a' . $this->attributeString($attributes) . '>' . Html::escape($attributes['title'] ?? $file->filename) . '</a>';
  }

  /**
   * Formats HTML attributes.
   */
  protected function attributeString(array $attributes): string {
    $string = '';
    foreach ($attributes as $name => $value) {
      $string .= ' ' . $name . '="' . Html::escape((string) $value) . '"';
    }
    return $string;
  }

}
