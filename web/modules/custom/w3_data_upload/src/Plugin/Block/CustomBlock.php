<?php

namespace Drupal\w3_data_upload\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'CustomBlock' block.
 *
 * @Block(
 *   id = "custom_block_with_body",
 *   admin_label = @Translation("Custom Block with Body Field"),
 *   category = @Translation("Custom")
 * )
 */
class CustomBlock extends BlockBase implements BlockPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->configuration['block_title']['value']; // Load node with ID 1 (example)
    //dump($node); die;
      
      return [
        '#markup' => $this->t($this->configuration['block_title']['value']),
      ];

  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
     // dump($this->configuration['block_title']); die;
    // Optionally, you can add configuration settings for the block
    // Example: Text field for the block title.
    $form['block_title'] = [
      '#type' => 'textarea',
      '#format' => 'full_html',
      '#title' => $this->t('Block content'),
      '#default_value' => $this->configuration['block_title']['value'] ?? '',
      '#description' => $this->t('Enter a title for the block.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    
    $this->configuration['block_title'] = $form_state->getValue('block_title');
  }

}
