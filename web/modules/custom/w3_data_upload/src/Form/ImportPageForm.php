<?php

namespace Drupal\w3_data_upload\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

/**
 * Class ModalExample.
 *
 * @package Drupal\hp\Form
 */
class ImportPageForm extends FormBase {
  
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'w3_import';
  }
  
  public function buildForm(array $form, FormStateInterface $form_state) {
  
    $form = array(
      '#attributes' => array('enctype' => 'multipart/form-data'),
    );

    $validators = array(
      'file_validate_extensions' => array('csv'),
    );
    $form['excel_file'] = array(
      '#type' => 'managed_file',
      '#name' => 'excel_file',
      '#title' => t('File *'),
      '#size' => 20,
      '#description' => t('Excel format only'),
      '#upload_validators' => $validators,
      '#upload_location' => 'public://',
    );
    
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    );

    return $form;

  }
  
  public function validateForm(array &$form, FormStateInterface $form_state) {    
    if ($form_state->getValue('excel_file') == NULL) {
      $form_state->setErrorByName('excel_file', $this->t('upload proper File'));
    }
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $file = \Drupal::entityTypeManager()->getStorage('file')
                    ->load($form_state->getValue('excel_file')[0]);    
	$full_path = $file->get('uri')->value;
	$file_name = basename($full_path);
	 
	$inputFileName = \Drupal::service('file_system')->realpath('public://'.$file_name);
		
	$spreadsheet = IOFactory::load($inputFileName);
		
	$sheetData = $spreadsheet->getActiveSheet();
		
	$rows = array();
	foreach ($sheetData->getRowIterator() as $row) {
		$cellIterator = $row->getCellIterator();
		$cellIterator->setIterateOnlyExistingCells(FALSE); 
		$cells = [];
		foreach ($cellIterator as $cell) {
			$cells[] = $cell->getValue();
		}
       $rows[] = $cells;
	   
	}

  //dump($rows); die;

	unset($rows[0]);
	$i = 1;
	foreach($rows as $row){
		
    	$values = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('title', $row[0])->execute();
    	$node_not_exists = empty($values);

      //News Import 
      if(!empty($row[1])) {
        $url = explode('"', $row[2]); 

        if (!empty($url[0])) {
          $uri = system_retrieve_file($url[3], $destination = NULL, $managed = FALSE, $replace = FileSystemInterface::EXISTS_RENAME);

          // check first if the file exists for the uri    
          $files = \Drupal::entityTypeManager()
            ->getStorage('file')
            ->loadByProperties(['uri' => $uri]);
          $file = reset($files);

          // if not create a file
          if (!$file) {
            $file = File::create([
              'uri' => $uri,
            ]);
            $file->save();
          }
        }

        $node = \Drupal::entityTypeManager()->getStorage('node')->create([
          'type'       => 'page', //===here asset is the content type mechine name
          'title'      => $row[0],
          'body' => ['value' => $row[1], 'format' => 'full_html'],
        ]);

        if (!empty($url[0])) {
          $node->field_banner_image[] = [
            'target_id' => $file->id(),
            'alt' => $row[0],
            'title' => $row[0],
          ];
        }
        
        if ($row[5] == 'No') {
          $node->setUnpublished(false);
        }

        $node->set('field_meta_tags', serialize([
          'title' => $row[0],
          'description' => $row[4],
          //'keywords' => 'Some,Keywords',
        ]));

        $node->save();
        
        // PathAlias::create([
        //     'path' => "/node/" . $node->id(),
        //     'alias' => str_replace('https://afforum.org', '', $row[6]),
        //     'langcode' => 'en',
        // ])->save();
      }

      
    	$i++;

    	//dump($row); die;
    }
	\Drupal::messenger()->addMessage('imported successfully');
  }
}