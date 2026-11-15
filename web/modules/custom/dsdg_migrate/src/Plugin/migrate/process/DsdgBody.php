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
 *   which dsdg_url_alias migrates unchanged. Like D7, which renders these
 *   links in the current language, it links to the page's published
 *   translation in the body's language (the row's "language", English by
 *   default) if there is one, and to the page in its own language otherwise.
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
   * URLs of D7 nodes, keyed by language and UUID.
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
    $langcode = $this->langcode((string) $row->getSourceProperty('language'));
    $value = preg_replace_callback('/\[uuid-link:node:([0-9a-f-]{36})\]/', function ($match) use ($migrate_executable, $langcode) {
      $url = $this->nodeUrl($match[1], $langcode);
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
   * Returns a D7 language code, with language-neutral codes as English.
   */
  protected function langcode(string $langcode): string {
    return in_array($langcode, ['und', 'zxx', ''], TRUE) ? 'en' : $langcode;
  }

  /**
   * Returns the URL of a D7 node for a body in a language.
   *
   * The URL is for the node's translation in that language if there is one,
   * otherwise for the node itself. NULL if there's no such node.
   */
  protected function nodeUrl(string $uuid, string $langcode): ?string {
    $key = $langcode . ':' . $uuid;
    if (!array_key_exists($key, static::$nodeUrls)) {
      $db = Database::getConnection('default', 'migrate');
      $node = $db->query('SELECT nid, tnid, language FROM {node} WHERE uuid = :uuid', [':uuid' => $uuid])->fetchObject();
      if (!$node) {
        static::$nodeUrls[$key] = NULL;
      }
      else {
        // Translations are merged into their source node in D11.
        $set = $node->tnid ?: $node->nid;
        // The published translation in the body's language (the one
        // SupersededTranslationsTrait keeps). Visitors can't see an
        // unpublished one, so link to the node itself instead.
        $target = $db->query('SELECT nid, language FROM {node} WHERE (nid = :set OR tnid = :set) AND language = :langcode AND status = 1 ORDER BY changed DESC, nid DESC', [
          ':set' => $set,
          ':langcode' => $langcode,
        ])->fetchObject() ?: $node;
        $target_langcode = $this->langcode($target->language);
        $alias = $db->query("SELECT alias FROM {url_alias} WHERE source = :source AND language IN (:langcode, 'und') ORDER BY pid DESC", [
          ':source' => 'node/' . $target->nid,
          ':langcode' => $target_langcode,
        ])->fetchField();
        static::$nodeUrls[$key] = '/' . $target_langcode . '/' . ($alias ?: 'node/' . $set);
      }
    }
    return static::$nodeUrls[$key];
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
