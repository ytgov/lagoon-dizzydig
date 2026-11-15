<?php

namespace Drupal\menu_link_url_translation\Hook;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent;

/**
 * Hook implementations for menu_link_url_translation.
 */
class MenuLinkUrlTranslationHooks {

  public function __construct(
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Implements hook_entity_base_field_info_alter().
   *
   * Core defines a menu link's "link" field as shared by all translations.
   * Links to content don't need more (entity:node/N opens the page in the
   * current language), but links to external pages do, for example
   * https://yukon.ca/en/copyright and https://yukon.ca/fr/droit-dauteur.
   */
  #[Hook('entity_base_field_info_alter')]
  public function entityBaseFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type): void {
    if ($entity_type->id() === 'menu_link_content' && isset($fields['link'])) {
      $fields['link']->setTranslatable(TRUE);
    }
  }

  /**
   * Implements hook_preprocess_menu().
   *
   * The menu tree keeps one URL per link, from its default language, and core
   * translates only titles and descriptions. Use the URL of the link's
   * translation for the current language instead.
   */
  #[Hook('preprocess_menu')]
  public function preprocessMenu(array &$variables): void {
    if (!empty($variables['items'])) {
      $this->translateUrls($variables['items']);
    }
  }

  /**
   * Replaces menu item URLs with their translations' URLs, recursively.
   */
  protected function translateUrls(array &$items): void {
    foreach ($items as &$item) {
      if (($item['original_link'] ?? NULL) instanceof MenuLinkContent) {
        $entity = $this->entityRepository->loadEntityByUuid('menu_link_content', $item['original_link']->getDerivativeId());
        if ($entity) {
          $url = $this->entityRepository->getTranslationFromContext($entity)->getUrlObject();
          // Keep the options, such as attributes, set for the menu item.
          $url->mergeOptions($item['url']->getOptions());
          $item['url'] = $url;
        }
      }
      if (!empty($item['below'])) {
        $this->translateUrls($item['below']);
      }
    }
  }

}
