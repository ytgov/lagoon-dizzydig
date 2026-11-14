<?php

namespace Drupal\w3_data_upload\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Implements website configuration form.
 */
class SiteConfig extends ConfigFormBase {

  /**
   * The config factory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * Class constructor.
   */
  public function __construct(ConfigFactoryInterface $configFactory, FileUsageInterface $file_usage) {
    parent::__construct($configFactory);
    $this->fileUsage = $file_usage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('file.usage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'w3_data_import.theme_settings',
    ];
  }

  /**
   * Returns the form’s unique ID.
   *
   * {@inheritdoc}.
   */
  public function getFormId() {
    return 'w3_data_import';
  }

  /**
   * Returns the form array.
   *
   * {@inheritdoc}.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('w3_data_import.theme_settings');

    // Site Details Section
    $form['site_basic_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site Basic Path'),
      '#open' => TRUE,
      '#default_value' => $config->get('site_basic_path'),
      '#description' => "Add site basic path",
    ];

    global $base_url;
    $form['markup'] = [
      '#type' => 'markup',
      '#markup' => '<a href="'.$base_url . '/import_data">Import Data</a>',
      '#weight' => '3',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $config = $this->config('w3_data_import.theme_settings');
    
    // Save Configuration Values
    $this->config('w3_data_import.theme_settings')
      ->set('site_basic_path', $form_state->getValue('site_basic_path'))
      ->save();
  }

}