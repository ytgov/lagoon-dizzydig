<?php

namespace Drupal\w3_data_upload\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\node\Entity\Node;
use Drupal\Core\Link;
use Drupal\views\Views;
use Drupal\user\Form\UserLoginForm;
use Drupal\Core\File\FileSystemInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\taxonomy\Entity\Term;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use \Drupal\Core\Url;
use \Drupal\Component\Utility\UrlHelper;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\media\Entity\Media;
use Drupal\Core\Serialization\Json;
use Drupal\user\Entity\User;

/**
 * Provides route responses for landing-page routing.
 */
class ImportDataController extends ControllerBase {

  public function content() {
    $site_link = \Drupal::config('w3_data_import.theme_settings')->get('site_basic_path');
    if (empty($site_link)) {
        echo "Please add the site basic path to continue data import"; die;
    }
    
    $output = "";
    $data = json_decode(file_get_contents($site_link . "/user_export?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    
    $users = count($data);

    foreach($data as $row){
        $username = $row->name;   // The username of the user.
          $email = $row->mail;  // The email address of the user.
          $password = $row->pass;     // The password of the user.
          $roles = $row->roles;  // Role (replace with the role you want to assign to the user, e.g., 'authenticated', 'administrator').
          $all_role = []; 
          foreach ($roles as $role) {
              if ($role == "authenticated user"){
                $all_role[] = 'authenticated';  
              }
              else {
                $all_role[] = $role;  
              }
              
          }
          // Query for the user by username.
          $user_storage = \Drupal::entityTypeManager()->getStorage('user');
          $existing_user = $user_storage->loadByProperties(['name' => $username]);
        
          // If the user doesn't exist, create a new user.
          if (empty($existing_user)) {
              // Create a new user entity.
              $user = User::create([
                'name' => $username,      // The username.
                'mail' => $email,         // The email address.
                'pass' => $password,      // The password.
                'status' => 1,            // Account status (1 = active, 0 = blocked).
                'roles' => $all_role,       // The roles to assign to the user (array of role machine names).
              ]);
            
              // Save the user.
              $user->save();
          }
    }
    
    $output .= " Users successfully imported <br><br>";
      
    $site_link = \Drupal::config('w3_data_import.theme_settings')->get('site_basic_path');
    if (empty($site_link)) {
        echo "Please add the site basic path to continue data import"; die;
    }
    
    $output = "";
    $data = json_decode(file_get_contents($site_link . "/categories_taxonomy?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    
    $categories = count($data->en);
    
    foreach($data as $row){
      foreach($row as $key => $row_data){
          $vocabulary = 'categories'; 
          
          $existing_term = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties(['name' => $row_data, 'vid' => $vocabulary]);
        
          // If the term doesn't exist, create it.
          if (empty($existing_term)) {
              // Create the taxonomy term.
              $term = Term::create([
                'name' => $row_data,  // The name of the term.
                'vid' => $vocabulary,  // The vocabulary machine name.
              ]);
            
              // Save the term.
              $term->save();
              
              // Add translation
                $term->addTranslation('fr', [
                  'name' => $data->fr[$key],
                ]);
                $term->save();
          }
       }
       break;
    }
    $output .= " Categories successfully imported <br><br>";
      
    $site_link = \Drupal::config('w3_data_import.theme_settings')->get('site_basic_path');
    if (empty($site_link)) {
        echo "Please add the site basic path to continue data import"; die;
    }
    
    $output = "";
    $data = json_decode(file_get_contents($site_link . "/basic-page-export?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    
    $basic_page = count($data);
    
    foreach($data as $row){

    	$values = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('title', $row->en->title)->execute();
    	$node_not_exists = empty($values);
      
      //News Import 
      if(!empty($row->en->title)) {
        $url = $row->en->field_attachments;
        $file = "";
        $image_media = "";

        if (!empty($url)) {
          $uri = system_retrieve_file($url, $destination = NULL, $managed = FALSE, $replace = FileSystemInterface::EXISTS_RENAME);

          if (!empty($uri)) {
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
              $file->setPermanent();
              $file->save();
            }
          }
        }
        
        if (!empty($row->en->body)) {
          $body = $row->en->body;
          if(strpos($body, "[img:") !== false){
            $string = $body;
            $out = delete_all_between('[', ']', $string);
            $body = $out;
          } 
        }
        else {
            $body = "";
        }
        
        $term_names = $row->en->field_categories;
        $field_categories = [];
        foreach ($term_names as $term_name) {
            // Try to load the term by its name and vocabulary ('tags' in this case).
            $term = \Drupal::entityTypeManager()
              ->getStorage('taxonomy_term')
              ->loadByProperties(['name' => $term_name, 'vid' => 'categories']); // Replace 'tags' with your vocabulary machine name.
        
            // If the term doesn't exist, create it.
            if (empty($term)) {
              $term = Term::create([
                'name' => $term_name,
                'vid' => 'tags',  // Replace 'tags' with the vocabulary machine name.
              ]);
              $term->save();
            } else {
              // Load the first term (in case multiple are found, but ideally there should be only one).
              $term = reset($term);
            }
        
            // Add the term ID to the list of term IDs.
            $field_categories[] = ['target_id' => $term->id()];

        }
        $date = strtotime($row->en->field_date_adopted);
        $now = DrupalDateTime::createFromTimestamp($date);
        $now->setTimezone(new \DateTimeZone('UTC'));
        
        
        $users = \Drupal::entityTypeManager()
          ->getStorage('user')
          ->loadByProperties(['name' => $row->en->uid]);
        
        if (!empty($users)) {
          $user = reset($users);
          $uid = $user->id();
        }
        
        $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => $row->en->title]);

        if (empty($nodes)) {
            $node = \Drupal::entityTypeManager()->getStorage('node')->create([
              'type'       => 'content_page', //===here asset is the content type mechine name
              'title'      => $row->en->title,
              'body' => ['value' => $body, 'format' => 'full_html'],
              'field_description' => $row->en->field_description,
              'created' => $row->en->created,
              'changed' => $row->en->changed,
              'field_date_adopted' => ['value' => $now->format('Y-m-d')],
              "field_categories" => $field_categories,
              "field_old_nid" => $row->en->nid,
              "uid" => $uid,
            ]);
    
            //$node->set('field_date_adopted', '2018-02-21');
    
            if (!empty($url) && !empty($file)) {
              $image_media = Media::create([
                  'name' => 'My media name',
                  'bundle' => 'file',
                  'uid' => 1,
                  'langcode' => 'en',
                  'status' => 1,
                  'field_media_file' => [
                    'target_id' => $file->id(),
                    'title' => $row->en->title,
                  ],
              ]);
              $image_media->save();
              $node->field_attachments = [
                'target_id' => $image_media->id(),
              ];
              
            }
            
            
    
            if ($row->en->status != '1') {
              $node->setUnpublished(false);
            }
            
    
            $node->save();
            
            foreach ($row->en->alias as $al) {
                PathAlias::create([
                  'path' => "/node/" . $node->id(),
                  'alias' => "/" . $al->alias,
                  'langcode' => 'en',
                ])->save();
            }
    
            if (!empty($row->fr->body)) {
              $body_fr = $row->fr->body;
              if(strpos($body_fr, "[img:") !== false){
                $string = $body_fr;
                $out = delete_all_between('[', ']', $string);
                $body_fr = $out;
              } 
            }
            else {
                $body_fr = "";
            }
    
            
            
            if(!empty($row->fr->title)) {
                //Creating the translation French in this case
                $node_fr = $node->addTranslation('fr');
                $node_fr->title = $row->fr->title;
                $node_fr->field_categories = $field_categories;
                $node_fr->uid = $uid;
                $node_fr->field_old_nid = $row->fr->nid;
                if (!empty($row->fr->body)) {
                    $node_fr->body->value = $body_fr;
                    $node_fr->body->format = 'full_html';
                }
                $node_fr->field_description = $row->fr->field_description;
                $node_fr->field_date_adopted = ['value' => $row->en->field_date_adopted];
                if (!empty($image_media)) {
                      $node->field_attachments = [
                        'target_id' => $image_media->id(),
                      ];
                    // $node_fr->field_banner_image[] = [
                    //     'target_id' => $file->id(),
                    //     'alt' => $row->fr->title,
                    //     'title' => $row->fr->title,
                    //   ];
                }
                if ($row->fr->status != '1') {
                    $node_fr->setUnpublished(false);
                }
    
                //Saving the node
                $node_fr->save();

            }
            
            foreach ($row->fr->alias as $alfr) {
                PathAlias::create([
                    'path' => "/node/" . $node->id(),
                    'alias' => "/" . $alfr->alias,
                    'langcode' => 'fr',
                ])->save();
            }
        }
        else {
            $node = reset($nodes);
            $nid = $node->field_old_nid->value;
            if ($row->en->nid == $nid) {
                $node->set('field_old_nid', $row->en->nid);
                $node->set('title', $row->en->title);
                $node->set('body', ['value' => $body, 'format' => 'full_html']);
                $node->set('field_description', $row->en->field_description);
                $node->set('created', $row->en->created);
                $node->set('changed', $row->en->changed);
                $node->set('field_date_adopted', ['value' => $now->format('Y-m-d')]);
                $node->set( "field_categories",$field_categories);
              
                $node->set("uid", $uid);
                // Save the node after updating the field
                $node->save();
                
                if (!empty($row->fr->body)) {
                  $body_fr = $row->fr->body;
                  if(strpos($body_fr, "[img:") !== false){
                    $string = $body_fr;
                    $out = delete_all_between('[', ']', $string);
                    $body_fr = $out;
                  } 
                }
                else {
                    $body_fr = "";
                }
        
                
                
                if(!empty($row->fr->title)) {
                    if ($node->hasTranslation($langcode)) {
                        //Creating the translation French in this case
                        $node_fr = $node->getTranslation($langcode);
                        $node_fr->title = $row->fr->title;
                        $node_fr->field_categories = $field_categories;
                        $node_fr->uid = $uid;
                        $node_fr->field_old_nid = $row->fr->nid;
                        if (!empty($row->fr->body)) {
                            $node_fr->body->value = $body_fr;
                            $node_fr->body->format = 'full_html';
                        }
                        $node_fr->field_description = $row->fr->field_description;
                        $node_fr->field_date_adopted = ['value' => $row->en->field_date_adopted];
                        if (!empty($image_media)) {
                              $node->field_attachments = [
                                'target_id' => $image_media->id(),
                              ];
                            // $node_fr->field_banner_image[] = [
                            //     'target_id' => $file->id(),
                            //     'alt' => $row->fr->title,
                            //     'title' => $row->fr->title,
                            //   ];
                        }
                        if ($row->fr->status != '1') {
                            $node_fr->setUnpublished(false);
                        }
            
                        //Saving the node
                        $node_fr->save();
                    }
    
                }
            }
            
        }
      }
    //   if ($row->en->title == "Electoral Districts") {
    //         dump($row);die;
    //     }
      //dump($row); die;
    }

    $output .= $basic_page . " Basic pages successfully imported <br><br>";
    
    
    \Drupal::database()
      ->update('node__field_date_adopted')
      ->expression(
        'field_date_adopted_value',
        'SUBSTRING(field_date_adopted_value, 1, 10)'
      )
      ->execute();

    // $data = json_decode(file_get_contents($site_link . "/menu_wet_footer_export?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    // $main_menu = count($data);

    // $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

    // $my_menu = $menu_link_storage->loadByProperties(['menu_name' => 'quick-links']);

    // foreach($data as $row){
    //       if ($row->en->title != 'Home' && !empty($row->en->title)) {

    //         $alias = \Drupal::service('path_alias.manager')->getPathByAlias($row->en->link);
            
            
    //             if (strpos($alias, 'node') !== false) {
    //                 $menu_item = MenuLinkContent::create([
    //                   'bundle' => 'menu_link_content',
    //                   'langcode' => 'en',
    //                   'title' => $row->en->title,
    //                   'menu_name' => 'quick-links',
    //                   'link' => ['uri' => 'internal:' . $alias],
    //                   'weight' => $row->en->weight,
    //                 ]);
    //                 $menu_item->save();
                    
    //                 if (!empty($row->fr->title)) {
    //                   $menu_item_fr = $menu_item->addTranslation('fr');
    //                   $menu_item_fr->title = $row->fr->title;
    //                   $menu_item_fr->link = ['uri' => 'internal:' . $alias];
    //                   $menu_item_fr->weight = $row->fr->weight;
    //                   $menu_item_fr->save();
    //                 }
    //             } 
    //             else {
    //                 $params = Url::fromUri('internal:/'.$row->en->link)->getRouteParameters();
    //                 $entity_type = key($params);
    //                 $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);
                    
    //                 $menu_item = MenuLinkContent::create([
    //                     'bundle' => 'menu_link_content',
    //                     'langcode' => 'en',
    //                     'title' => $row->en->title,
    //                     'menu_name' => 'quick-links',
    //                     'link' => ['uri' => 'internal:/node/' . $node->id()],
    //                     'weight' => $row->en->weight,
    //                 ]);
    //                 $menu_item->save();
            
    //                 if (!empty($row->fr->title)) {
    //                     $menu_item_fr = $menu_item->addTranslation('fr');
    //                     $menu_item_fr->title = $row->fr->title;
    //                     $menu_item_fr->link = ['uri' => 'internal:/node/' . $node->id()];
    //                     $menu_item_fr->weight = $row->fr->weight;
    //                     $menu_item_fr->save();
    //                 }
    //             }

    //     }
    // }
    
    // $data = json_decode(file_get_contents($site_link . "/top_menu_export?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    // $main_menu = count($data);

    // $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

    // $my_menu = $menu_link_storage->loadByProperties(['menu_name' => 'main']);

    // foreach($data as $row){
    //   if ($row->en->title != 'Home') {
          

    //     if ($row->en->title == 'Events' || $row->en->title == 'Feedback' || $row->en->title == 'Search the site') {
    //       $menu_item = MenuLinkContent::create([
    //         'bundle' => 'menu_link_content',
    //         'langcode' => 'en',
    //         'title' => $row->en->title,
    //         'menu_name' => 'main',
    //         'link' => ['uri' => 'internal:/' . $row->en->link],
    //         'weight' => $row->en->weight,
    //       ]);
    //       $menu_item->save();

    //       if (!empty($row->fr->title)) {
    //         $menu_item_fr = $menu_item->addTranslation('fr');
    //         $menu_item_fr->title = $row->fr->title;
    //         $menu_item_fr->link = ['uri' => 'internal:/' . $row->en->link];
    //         $menu_item_fr->weight = $row->fr->weight;
    //         $menu_item_fr->save();
    //       }
    //     }
    //     else {
    //       $alias = \Drupal::service('path_alias.manager')->getPathByAlias($row->en->link);
    //       $params = Url::fromUri('internal:/'.$row->en->link)->getRouteParameters();
    //       $entity_type = key($params);
    //       $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);

    //       $menu_item = MenuLinkContent::create([
    //         'bundle' => 'menu_link_content',
    //         'langcode' => 'en',
    //         'title' => $row->en->title,
    //         'menu_name' => 'main',
    //         'link' => ['uri' => 'internal:/node/' . $node->id()],
    //         'weight' => $row->en->weight,
    //       ]);
    //       $menu_item->save();

    //       if (!empty($row->fr->title)) {
    //         $menu_item_fr = $menu_item->addTranslation('fr');
    //         $menu_item_fr->title = $row->fr->title;
    //         $menu_item_fr->link = ['uri' => 'internal:/node/' . $node->id()];
    //         $menu_item_fr->weight = $row->fr->weight;
    //         $menu_item_fr->save();
    //       }
    //     }

    //   }
    // }

    $data = json_decode(file_get_contents($site_link . "/main_menu_export?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    $main_menu = count($data);

    $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

    $my_menu = $menu_link_storage->loadByProperties(['menu_name' => 'sub-menu']);

    foreach($data as $row){
          $menu_name = 'main';
          
            $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
            $menu_links = $storage->loadByProperties(['menu_name' => $menu_name, 'title' => $row->en->title]);
            
            if (empty($menu_links)) {
              
                  // Check if the menu already exists
                  $menu = \Drupal::entityTypeManager()->getStorage('menu')->load($menu_name);
                  $query = \Drupal::entityQuery('node')
                    ->condition('title', $row->en->title) // Add condition for title
                    ->accessCheck(TRUE)
                    ->range(0, 1); // Limit to the first result (optional, in case of multiple nodes with the same title)
                
                  // Execute the query and get the node IDs (if any)
                  $nids = $query->execute();
                
                  // Return the first node ID if found, otherwise NULL
                  $nid = !empty($nids) ? reset($nids) : NULL;
        
                  // 2. Create the Parent Menu Item
                  $parent_item = MenuLinkContent::create([
                    'menu_name' => $menu_name,
                    'title' => $row->en->title, // Parent menu title
                    'link' => ['uri' => 'entity:node/' . $nid],
                    'parent' => 0, // Root-level item (no parent)
                    'langcode' => 'en',
                  ]);
                  $parent_item->save();
                  
                  $menu = \Drupal::entityTypeManager()->getStorage('menu')->load($menu_name);
                  $query = \Drupal::entityQuery('node')
                    ->condition('title', $row->fr->title) // Add condition for title
                    ->accessCheck(TRUE)
                    ->range(0, 1); // Limit to the first result (optional, in case of multiple nodes with the same title)
                
                  // Execute the query and get the node IDs (if any)
                  $nids = $query->execute();
                
                  // Return the first node ID if found, otherwise NULL
                  $nid_fr = !empty($nids) ? reset($nids) : NULL;
                  
                    if (!empty($row->fr->title)) {
                      $menu_item_fr = $parent_item->addTranslation('fr');
                      $menu_item_fr->title = $row->fr->title;
                      $menu_item_fr->link = ['uri' => 'entity:node/' . $nid_fr];
                      //$menu_item_fr->weight = $row->fr->weight;
                      $menu_item_fr->save();
                    }
                  
                  if (!empty($row->en->children)) {
                      $child1 = $row->en->children;
                      foreach($child1 as $children1){
                          $query = \Drupal::entityQuery('node')
                            ->condition('title', $children1->title) // Add condition for title
                            ->accessCheck(TRUE)
                            ->range(0, 1); // Limit to the first result (optional, in case of multiple nodes with the same title)
                        
                          // Execute the query and get the node IDs (if any)
                          $nids = $query->execute();
                        
                          // Return the first node ID if found, otherwise NULL
                          $nid1 = !empty($nids) ? reset($nids) : NULL;
                          
                          if (!empty($nid1)) {
                              $link1 = ['uri' => 'entity:node/' . $nid1];
                          }
                          else {
                                $params = Url::fromUri('internal:/'.$children2->href)->getRouteParameters();
                                $entity_type = key($params);
                                $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);
                                $link2 = ['uri' => 'entity:node/' . $node->id()];
                          }
                          
                          
                          $child_item_1 = MenuLinkContent::create([
                            'menu_name' => $menu_name,
                            'title' => $children1->title, // Parent menu title
                            'link' => $link1,
                            'parent' => $parent_item->getPluginId(), // Set the parent item (link to the parent)
                            'langcode' => $children1->language,
                          ]);
                          $child_item_1->save();
                          
                          
                          if (!empty($children1->children)) {
                              $child2 = $children1->children;
                              foreach($child2 as $children2){
                                  
                                  if ($children2->title == "Colour and palette for digital services") {
                                      $children2->title = "Colour and palettes for digital services";
                                  }
                                  
                                  if ($children2->title == "Méthodes de recherche sur les utilisateurs les plus souvent utilisées par les équipes gouvernementales" || $children2->title == "Options pour se conformer aux normes des services numériques sur l’utilisabilité" || 
                                  $children2->title == "Qu’est-ce que la recherche sur les utilisateurs et pourquoi est-elle nécessaire?" || $children2->title == "Recrutement des participantes et participants pour la recherche sur les utilisateurs") {
                                     continue;
                                  }
                                  
                                  $query = \Drupal::entityQuery('node')
                                    ->condition('title', $children2->title) // Add condition for title
                                    ->accessCheck(TRUE)
                                    ->range(0, 1); // Limit to the first result (optional, in case of multiple nodes with the same title)
                                
                                  // Execute the query and get the node IDs (if any)
                                  $nids = $query->execute();
                                
                                  // Return the first node ID if found, otherwise NULL
                                  $nid2 = !empty($nids) ? reset($nids) : NULL;
                                  
                                  if (!empty($nid2)) {
                                      $link2 = ['uri' => 'entity:node/' . $nid2];
                                  }
                                  else {
                                        $params = Url::fromUri('internal:/'.$children2->href)->getRouteParameters();
                                        $entity_type = key($params);
                                        $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);
                                        $link2 = ['uri' => 'entity:node/' . $node->id()];
                                  }
                          
                                  $child_item_2 = MenuLinkContent::create([
                                    'menu_name' => $menu_name,
                                    'title' => $children2->title, // Parent menu title
                                    'link' => $link2,
                                    'parent' => $child_item_1->getPluginId(), // Set the parent item (link to the parent)
                                    'langcode' => $children2->language,
                                  ]);
                                  $child_item_2->save();
                                  
                                  
                                  if (!empty($children2->children)) {
                                      $child3 = $children2->children;
                                      foreach($child3 as $children3){
                                          
                                          if ($children3->title == "Colour and palette for digital services") {
                                              $children3->title = "Colour and palettes for digital services";
                                          }
                                          if ($children3->title == "ISBN (FR)") {
                                              $children3->title = "ISBN";
                                          }
                                          if ($children3->title == "Points") {
                                              $children3->title = "Ponctuation finale";
                                          }
                                          if ($children3->title == "Trait d’union") {
                                              $children3->title = "Traits d’union et tirets";
                                          }
                                         // dump($children3->title);
                                          if ($children3->title == "Méthodes de recherche sur les utilisateurs les plus souvent utilisées par les équipes gouvernementales" || $children3->title == "Options pour se conformer aux normes des services numériques sur l’utilisabilité" || 
                                          $children3->title == "Qu’est-ce que la recherche sur les utilisateurs et pourquoi est-elle nécessaire?" || $children3->title == "Recrutement des participantes et participants pour la recherche sur les utilisateurs") {
                                             continue;
                                          }
                                          
                                          $query = \Drupal::entityQuery('node')
                                            ->condition('title', $children3->title) // Add condition for title
                                            ->accessCheck(TRUE)
                                            ->range(0, 1); // Limit to the first result (optional, in case of multiple nodes with the same title)
                                        
                                          // Execute the query and get the node IDs (if any)
                                          $nids = $query->execute();
                                        
                                          // Return the first node ID if found, otherwise NULL
                                          $nid3 = !empty($nids) ? reset($nids) : NULL;
                                          
                                          if (!empty($nid3)) {
                                              $link3 = ['uri' => 'entity:node/' . $nid3];
                                          }
                                          else {
                                                $params = Url::fromUri('internal:/'.$children3->href)->getRouteParameters();
                                                $entity_type = key($params);
                                                $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);
                                                $link3 = ['uri' => 'entity:node/' . $node->id()];
                                          }
                                  
                                          $child_item_3 = MenuLinkContent::create([
                                            'menu_name' => $menu_name,
                                            'title' => $children3->title, // Parent menu title
                                            'link' => $link3,
                                            'parent' => $child_item_2->getPluginId(), // Set the parent item (link to the parent)
                                            'langcode' => $children3->language,
                                          ]);
                                          $child_item_3->save();
                                          
                                           if (!empty($children3->children)) {
                                              $child4 = $children3->children;
                                              foreach($child4 as $children4){
                                                  
                                                  if ($children4->title == "ISBN (FR)") {
                                                      $children4->title = "ISBN";
                                                  }
                                                  if ($children4->title == "Points") {
                                                      $children4->title = "Ponctuation finale";
                                                  }
                                                  if ($children4->title == "Trait d’union") {
                                                      $children4->title = "Traits d’union et tirets";
                                                  }
                                                  
                                                  
                                                  $query = \Drupal::entityQuery('node')
                                                    ->condition('title', $children4->title) // Add condition for title
                                                    ->accessCheck(TRUE)
                                                    ->range(0, 1); // Limit to the first result (optional, in case of multiple nodes with the same title)
                                                
                                                  // Execute the query and get the node IDs (if any)
                                                  $nids = $query->execute();
                                                
                                                  // Return the first node ID if found, otherwise NULL
                                                  $nid4 = !empty($nids) ? reset($nids) : NULL;
                                                  
                                                  if (!empty($nid4)) {
                                                      $link4 = ['uri' => 'entity:node/' . $nid4];
                                                  }
                                                  else {
                                                        $params = Url::fromUri('internal:/'.$children4->href)->getRouteParameters();
                                                        $entity_type = key($params);
                                                        $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);
                                                        $link4 = ['uri' => 'entity:node/' . $node->id()];
                                                  }
                                          
                                                  $child_item_4 = MenuLinkContent::create([
                                                    'menu_name' => $menu_name,
                                                    'title' => $children4->title, // Parent menu title
                                                    'link' => $link4,
                                                    'parent' => $child_item_3->getPluginId(), // Set the parent item (link to the parent)
                                                    'langcode' => $children4->language,
                                                  ]);
                                                  $child_item_4->save();
                                                  
                                              }
                                          }
                                          
                                      }
                                  }
                                  
                              }
                          }
                          
                      }
                  }
            }

    //   if ($row->en->title != 'Home') {

    //     $alias = \Drupal::service('path_alias.manager')->getPathByAlias($row->en->link);
    //         $params = Url::fromUri('internal:/'.$row->en->link)->getRouteParameters();
    //         $entity_type = key($params);
    //         $node = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);

    //     $menu_item = MenuLinkContent::create([
    //       'bundle' => 'menu_link_content',
    //       'langcode' => 'en',
    //       'title' => $row->en->title,
    //       'menu_name' => 'sub-menu',
    //       'link' => ['uri' => 'entity:node/' . $node->id()],
    //       'weight' => $row->en->weight,
    //     ]);
    //     $menu_item->save();

    //     if (!empty($row->fr->title)) {
    //       $menu_item_fr = $menu_item->addTranslation('fr');
    //       $menu_item_fr->title = $row->fr->title;
    //       $menu_item_fr->link = ['uri' => 'entity:node/' . $node->id()];
    //       $menu_item_fr->weight = $row->fr->weight;
    //       $menu_item_fr->save();
    //     }
    //   }
    }
    
    
    $data = json_decode(file_get_contents($site_link . "/menu_wet_footer_export?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9"));
    $main_menu = count($data);

    $menu_link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

    $my_menu = $menu_link_storage->loadByProperties(['menu_name' => 'quick-links']);

    foreach($data as $row){
          if ($row->en->title != 'Home' && !empty($row->en->title)) {

            $menu_name = 'footer';
          
            $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
            $menu_links = $storage->loadByProperties(['menu_name' => $menu_name, 'title' => $row->en->title]);
            
            if (empty($menu_links)) {

                    $menu_item = MenuLinkContent::create([
                      'bundle' => 'menu_link_content',
                      'langcode' => 'en',
                      'title' => $row->en->title,
                      'menu_name' => 'footer',
                      'link' => $row->en->link,
                      'weight' => $row->en->weight,
                    ]);
                    $menu_item->save();
                    
                    if (!empty($row->fr->title)) {
                      $menu_item_fr = $menu_item->addTranslation('fr');
                      $menu_item_fr->title = $row->fr->title;
                      $menu_item_fr->link = $row->fr->link;
                      $menu_item_fr->weight = $row->fr->weight;
                      $menu_item_fr->save();
                    }
            }

        }
    }
    
    $output .= "Menus successfully imported<br><br>"; 

    //Menu end
    
    
    $output .= "<a href='/'>Go to Home page</a>";
    
    return [
      '#markup' => $output,
    ];


	\Drupal::messenger()->addMessage('imported successfully');
  }

  
  }
