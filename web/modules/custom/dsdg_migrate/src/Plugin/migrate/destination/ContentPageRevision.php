<?php

namespace Drupal\dsdg_migrate\Plugin\migrate\destination;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Saves D7 node revisions, in any language, as revisions of one D11 node.
 *
 * Rows must come in the order D7 saved them (the dsdg_content_page_revision
 * source). Each row is one revision in one language, saved the way core's
 * edit form would save it:
 * - A node's first revision creates the node, with the row's nid and UUID.
 * - A later one starts from the latest revision of its language (for a new
 *   translation, from the default revision), and createRevision() merges in
 *   the other translations from the default revision. So a draft in one
 *   language never carries another language's pending draft.
 * - It is the default revision if its moderation state is a default revision
 *   state (published, archived), or if the node isn't published. That's
 *   content moderation's rule, which it doesn't apply to syncing saves.
 * - Translations don't change untranslatable fields (attachments, date
 *   adopted); those come from the source language. Revision metadata (author,
 *   date, log message) is untranslatable too, but always the row's.
 * - The row's vid is kept.
 *
 * Rolling back a node's default revision deletes the node, as a node can't
 * exist without one. Rolling back another revision deletes that revision.
 * With --update, a migrated revision is changed in place.
 */
#[MigrateDestination(id: 'dsdg_content_page_revision')]
class ContentPageRevision extends EntityContentBase {

  /**
   * The moderation information service.
   */
  protected ModerationInformationInterface $moderationInformation;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $migration);
    $instance->moderationInformation = $container->get('content_moderation.moderation_information');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected static function getEntityTypeId($plugin_id) {
    return 'node';
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $revision_key = $this->getKey('revision');
    return [$revision_key => $this->getDefinitionFromEntity($revision_key)];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $langcode = $row->getDestinationProperty('langcode');
    if ($old_destination_id_values && ($revision = $this->storage->loadRevision(reset($old_destination_id_values)))) {
      return $this->updateEntity($revision->getTranslation($langcode), $row);
    }

    $nid = $row->getDestinationProperty('nid');
    $default_revision = $this->storage->load($nid);
    if (!$default_revision) {
      if (!in_array($row->getDestinationProperty('content_translation_source'), [NULL, 'und'], TRUE)) {
        throw new MigrateException("Node $nid doesn't exist, so its $langcode translation can't be added.");
      }
      return parent::getEntity($row, []);
    }

    $base_id = $this->storage->getLatestTranslationAffectedRevisionId($nid, $langcode);
    if ($base_id) {
      $base = $this->storage->loadRevision($base_id)->getTranslation($langcode);
    }
    else {
      // Not $default_revision itself, which is in the entity cache.
      $base = (clone $default_revision)->addTranslation($langcode);
    }
    $revision = $this->updateEntity($this->storage->createRevision($base), $row);

    $state = $this->moderationInformation->getWorkflowForEntity($revision)
      ->getTypePlugin()
      ->getState($revision->get('moderation_state')->value);
    $revision->isDefaultRevision($state->isDefaultRevisionState() || !$this->moderationInformation->isDefaultRevisionPublished($revision));
    return $revision;
  }

  /**
   * {@inheritdoc}
   */
  protected function updateEntity(EntityInterface $entity, Row $row) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $skipped = [];
    if (!$entity->isDefaultTranslation()) {
      // The revision ID and its author, date and log message are
      // untranslatable too, but belong to this revision.
      $revision_fields = array_values($entity->getEntityType()->getRevisionMetadataKeys());
      $revision_fields[] = $this->getKey('revision');
      foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
        if (!$definition->isTranslatable() && !in_array($field_name, $revision_fields, TRUE)) {
          $skipped[$field_name] = TRUE;
        }
      }
    }
    foreach ($row->getDestination() as $field_name => $values) {
      if (!isset($skipped[$field_name]) && $entity->$field_name instanceof TypedDataInterface) {
        $entity->$field_name->setValue($values);
      }
    }
    foreach ($row->getEmptyDestinationProperties() as $property) {
      if (!isset($skipped[explode(Row::PROPERTY_SEPARATOR, $property)[0]])) {
        $entity->$property = NULL;
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    $entity->setSyncing(TRUE);
    $entity->save();
    return [$entity->getRevisionId()];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $revision = $this->storage->loadRevision(reset($destination_identifier));
    if (!$revision) {
      return;
    }
    if ($revision->isDefaultRevision()) {
      $revision->setSyncing(TRUE);
      $revision->delete();
    }
    else {
      $this->storage->deleteRevision($revision->getRevisionId());
    }
  }

}
