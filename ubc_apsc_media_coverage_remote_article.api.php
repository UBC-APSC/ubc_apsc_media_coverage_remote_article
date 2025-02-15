<?php

/**
 * @file
 *
 * The contents of this file are never loaded, or executed, it is purely for
 * documentation purposes.
 *
 * @link https://www.drupal.org/docs/develop/coding-standards/api-documentation-and-comment-standards#hooks
 * Read the standards for documenting hooks. @endlink
 *
 */
 
 
/**
 * @file
 * Implement and invoke hooks to customise node media coverage add and include autofill functionality.
 */
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AfterCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\ubc_apsc_media_coverage_remote_article\Ajax\UpdateCkeditorText;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Request;


/**
 * Implements hook_form_FORM_ID_alter().
 * Adds a URL input field (not recorded in the form submission values) and a 'check URL' button
 * Enables AJAX callback on the URL to fetch meta data and schema.org values to autofill the rest of the form
 */
function ubc_apsc_media_coverage_remote_article_form_alter(&$form, FormStateInterface $form_state) {

	switch($form['#form_id']) {
		case 'node_media_coverage_form':
			$form['remote_article__url'] = [
			  '#type' => 'url',
			  '#title' => 'Auto fill from remote article URL',
			  '#attributes' => [ 'class' => ['remote-article-url',],],
			  '#description' => 'This field is not saved, it is used to retrieve the meta data from the article and auto fill the form fields where possible.',
			  '#weight' => '-1',
			  '#maxlength' => 2084,
			];
			$form['categories_update'] = [
			  '#type' => 'button',
			  '#value' => t('Check URL'),
			  '#weight' => '-1',
			  '#ajax' => [
				'callback' => 'ubc_apsc_media_coverage_remote_article_ajax_callback',
				'progress' => [
				  'type' => 'throbber',
				  'message' => 'Retrieving information ...',
				],
			  ],
			];
			break;
		case 'node_ubc_announcement_form':
			$form['remote_article__url'] = [
			  '#type' => 'url',
			  '#title' => 'Auto fill from remote article URL',
			  '#attributes' => [ 'class' => ['remote-article-url',],],
			  '#description' => 'This field is not saved, it is used to retrieve the meta data from the article and auto fill the form fields where possible.',
			  '#weight' => '-1',
			  '#maxlength' => 2084,
			  '#states' => [
				// Show this field only if the radio 'External' announcement type is selected
				'visible' => [
					':input[name="field_announcement_type"]' => ['value' => '259'],
				],
			  ],
			];
			$form['categories_update'] = [
			  '#type' => 'button',
			  '#value' => t('Check URL'),
			  '#weight' => '-1',
			  '#ajax' => [
				'callback' => 'ubc_apsc_announcement_remote_article_ajax_callback',
				'progress' => [
				  'type' => 'throbber',
				  'message' => 'Retrieving information ...',
				],
			  ],
			  '#states' => [
				// Show this field only if the radio 'External' announcement type is selected
				'visible' => [
					':input[name="field_announcement_type"]' => ['value' => '259'],
				],
			  ],
			];
			break;
		default:
		break;
	}
	
	$form['#attached']['library'][] = 'ubc_apsc_media_coverage_remote_article/ckeditor-update';
	$form['#attached']['library'][] = 'ubc_apsc_media_coverage_remote_article/form-fill-assist';
	
	$messages = \Drupal::messenger()->deleteAll();
}

/**
 * Implements hook_entity_presave().
 * modify create date to match article date field before save
 * for sorting in views
 */
function ubc_apsc_media_coverage_remote_article_node_presave($entity) {
  switch ($entity->bundle()) {
    case 'media_coverage':
      // only on new content 
	  if ($entity->isNew() && $entity->revision_log->value == 'Node created programatically') {
		  $article_date = $entity->get('field_media_article_date')->getValue();
		  $entity->get('created')->setValue(strtotime($article_date[0]['value']));
	  }
    break;
	default:
	break;
  }
}

/**
* Callback for remote_article__url.
*
* Take the URL value from remote_article__url and retrieve OpenGraph or metadata
* return values through AjaxResponse
*
* @return AjaxResponse
* 
*/
function ubc_apsc_media_coverage_remote_article_ajax_callback(array &$form, FormStateInterface $form_state) {
	
	// array with metatags to search for and related form field selector
	$fetch_tags = [
		'title' => [
			'tag_name' => 'og:title',
			'schema_name' => 'headline',
			'selector' => '#edit-field-media-article-title-0-value',
			'value' => [],
			],
		'description' => [
			'tag_name' => 'og:description',
			'schema_name' => 'description',
			'selector' => '#edit-body-0-value',
			'value' => [],
			],
		'date' => [
			'tag_name' => 'article:published_time',
			'schema_name' => 'datePublished',
			'selector' => '#edit-field-media-article-date-0-value-date',
			'value' => [],
			],
		'time' => [
			'tag_name' => 'article:published_time',
			'schema_name' => 'datePublished',
			'selector' => '#edit-field-media-article-date-0-value-time',
			'value' => [],
			],
		'image' => [
			'tag_name' => 'og:image',
			'schema_name' => 'image',
			'selector' => '[data-media-library-widget-value="field_media_article_image"]',
			'value' => [],
			],
		'image:alt' => [
			'tag_name' => 'og:image:alt',
			'schema_name' => 'name',
			'selector' => '',
			'value' => [],
			],
		'url' => [
			'tag_name' => 'og:url',
			'schema_name' => 'url',
			'selector' => '#edit-field-media-article-url-0-uri',
			'value' => [],
			],
		'site' => [
			'tag_name' => 'og:site_name',
			'schema_name' => 'publisher',
			'selector' => '#edit-field-media-source-0-value',
			'value' => [],
			],
		];
	
	// retrieve autofill URL, attempt to fetch tags from source
	$url = $form_state->getValue('remote_article__url');
    $fetch_tags = file_get_remote_tags($url,$fetch_tags,'media-coverage-images');
	$message_status = 'messages--status';
	
	$response = new AjaxResponse();

	// remove autofill input URL
	$response->addCommand(new RemoveCommand('.alternative-options'));

	// cycle through values and fill form
	foreach($fetch_tags as $key => $process) {
	  $alt_values_available = false;
	  
	  //skip the media object + related
	  if($key == 'remote_media' || $key == 'image:alt')
		  continue;
	  
	  // update the media field with new image retrieved
	  if($key == 'image' && is_object($fetch_tags['remote_media'])) {
			$response->addCommand(new InvokeCommand($fetch_tags[$key]['selector'], 'val', [$fetch_tags['remote_media']->id()]));
			$confirmation_message .= $fetch_tags['remote_media']->size_status[0];
			$message_status = $fetch_tags['remote_media']->size_status[1];
			$response->addCommand(new InvokeCommand('[data-media-library-widget-update="field_media_article_image"]', 'trigger', ["mousedown"]));
	  }
	  elseif($key == 'description') {
		    // Custom AJAX to handle updating the CKEditor5 textarea
			$response->addCommand(new UpdateCkeditorText($fetch_tags[$key]['selector'], 'val', [$fetch_tags[$key]['value'][0]]));
	  }
	  elseif(!empty($fetch_tags[$key]['value'])) {
			// fill fields, add table with alternative options detected below field for editor to choose more appropriate values when available
			$response->addCommand(new InvokeCommand($fetch_tags[$key]['selector'], 'val', [$fetch_tags[$key]['value'][0]]));
			$c = count($fetch_tags[$key]['value']);
			if($c>1) {
				$alt_content = '';
				for($i = 1; $i < $c; $i++) {
					if(!empty($fetch_tags[$key]['value'][$i])) {
						
						$alt_values_available = true;
						
						switch($key) {
							case 'date':
								$alt_value = date('Y-m-d', strtotime($fetch_tags[$key]['value'][$i]));
							break;
							case 'time':
								$alt_value = date('H:m:s', strtotime($fetch_tags[$key]['value'][$i]));
							break;
							case 'site':
								if(is_string($fetch_tags[$key]['value'][$i]))
									$alt_value = $fetch_tags[$key]['value'][$i];
								else
									$alt_value = $fetch_tags[$key]['value'][$i]->name;
							break;
							default:
								// sometime values are not processed correctly and return as object, serialize to prevent error on return
								$alt_value = is_string($fetch_tags[$key]['value'][$i]) ? $fetch_tags[$key]['value'][$i] : serialize($fetch_tags[$key]['value'][$i]);
							break;
						}
							
						$alt_content .= "<tr><td>$i</td><td id='$key$i'>$alt_value</td><td><a class='remote-value-alternative' href='#' data-update-alt-value='#$key$i' data-update-target='{$fetch_tags[$key]['selector']}'>Select</a></td></tr>";
					}
				}
				if($alt_values_available) {
					$response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label">Other Options:</div><table class="alternative-options">'.$alt_content.'</table>'));
				}
			}
	  }
	  elseif($key == 'date') {
		  // specific message below field if date not found
		  $response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label not-found">Please find '.$key.'</div>'));  
		  $confirmation_message .= '<p>Check for missing value <strong>'.$key.'</strong></p>';
		  $message_status = ($image_media->size_status[1] != 'messages--error') ?: 'messages--warning';
	  }
	  else {
		  //  message below field if value not found
		  $response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label not-found">Missing '.$key.'</div>'));  
		  $confirmation_message .= '<p>Check for missing value <strong>'.$key.'</strong></p>';
		  $message_status = ($image_media->size_status[1] != 'messages--error') ?: 'messages--warning';
	  }
	}
	
	// add status message operation complete, include revision log message
    $response->addCommand(new PrependCommand('.region-highlighted', get_process_notification($confirmation_message, $message_status)));
	$response->addCommand(new HtmlCommand('#edit-revision-log-0-value', 'Node created programatically'));
	
	return $response;
}

/**
* Callback for remote_article__url.
*
* Take the URL value from remote_article__url and retrieve OpenGraph or metadata
* return values through AjaxResponse
*
* @return AjaxResponse
* 
*/
function ubc_apsc_announcement_remote_article_ajax_callback(array &$form, FormStateInterface $form_state) {
	
	// array with metatags to search for and related form field selector
	$fetch_tags = [
		'title' => [
			'tag_name' => 'og:title',
			'schema_name' => 'headline',
			'selector' => '#edit-title-0-value',
			'value' => [],
			],
		'description' => [
			'tag_name' => 'og:description',
			'schema_name' => 'description',
			'selector' => '#edit-body-0-value',
			'value' => [],
			],
		'image' => [
			'tag_name' => 'og:image',
			'schema_name' => 'image',
			'selector' => '#edit-field-announcement-feature-image-0-upload',
			'value' => [],
			],
		'image:alt' => [
			'tag_name' => 'og:image:alt',
			'schema_name' => 'name',
			'selector' => '[data-drupal-selector="edit-field-announcement-feature-image-0-alt"]',
			'value' => [],
			],
		'url' => [
			'tag_name' => 'og:url',
			'schema_name' => 'url',
			'selector' => '#edit-field-media-article-url-0-uri',
			'value' => [],
			],
		'site' => [
			'tag_name' => 'og:site_name',
			'schema_name' => 'publisher',
			'selector' => '#edit-field-media-article-url-0-title',
			'value' => [],
			],
		];
	
	// retrieve autofill URL, attempt to fetch tags from source
	$url = $form_state->getValue('remote_article__url');
    $fetch_tags = file_get_remote_tags($url,$fetch_tags,'announcement-images');
	$message_status = 'messages--status';
	
	$response = new AjaxResponse();

	// remove autofill input URL
	$response->addCommand(new RemoveCommand('.alternative-options'));

	// cycle through values and fill form
	foreach($fetch_tags as $key => $process) {
	  $alt_values_available = false;
	  
	  //skip the media object
	  if($key == 'remote_media')
		  continue;
	  
	  // add the field id to the file upload field and trigger the upload button the refresh with the image preview, @todo, update the image alt tag
	  if($key == 'image' && is_object($fetch_tags['remote_media'])) {
			$response->addCommand(new InvokeCommand('[data-drupal-selector="edit-field-announcement-feature-image-0-fids"]', 'val', [$fetch_tags['remote_media']->id()]));
			$confirmation_message .= $fetch_tags['remote_media']->size_status[0];
			$message_status = $fetch_tags['remote_media']->size_status[1];
			$response->addCommand(new InvokeCommand('[data-drupal-selector="edit-field-announcement-feature-image-0-upload-button"]', 'trigger', ["mousedown"]));
	  }
	  elseif($key == 'description') {
		    // Custom AJAX to handle updating the CKEditor5 textarea
			$response->addCommand(new UpdateCkeditorText($fetch_tags[$key]['selector'], 'val', [$fetch_tags[$key]['value'][0]]));
	  }
	  elseif(!empty($fetch_tags[$key]['value'])) {
			// fill fields, add table with alternative options detected below field for editor to choose more appropriate values when available
			$response->addCommand(new InvokeCommand($fetch_tags[$key]['selector'], 'val', [$fetch_tags[$key]['value'][0]]));
			$c = count($fetch_tags[$key]['value']);
			if($c>1) {
				$alt_content = '';
				for($i = 1; $i < $c; $i++) {
					if(!empty($fetch_tags[$key]['value'][$i])) {
						
						$alt_values_available = true;
						
						switch($key) {
							case 'site':
								if(is_string($fetch_tags[$key]['value'][$i]))
									$alt_value = $fetch_tags[$key]['value'][$i];
								else
									$alt_value = $fetch_tags[$key]['value'][$i]->name;
							break;
							default:
								// sometime values are not processed correctly and return as object, serialize to prevent error on return
								$alt_value = is_string($fetch_tags[$key]['value'][$i]) ? $fetch_tags[$key]['value'][$i] : serialize($fetch_tags[$key]['value'][$i]);
							break;
						}
						
						$alt_content .= "<tr><td>$i</td><td id='$key$i'>$alt_value</td><td><a class='remote-value-alternative' href='#' data-update-alt-value='#$key$i' data-update-target='{$fetch_tags[$key]['selector']}'>Select</a></td></tr>";
					}
				}
				if($alt_values_available)
					$response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label">Other Options:</div><table class="alternative-options">'.$alt_content.'</table>'));
				
			}
	  }
	  else {
		  //  message below field if value not found
		  $response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label not-found">Missing '.$key.'</div>'));
		  $confirmation_message .= '<p>Check for missing values <strong>'.$key.'</strong></p>';
		  $message_status = ($image_media->size_status[1] != 'messages--error') ?: 'messages--warning';
	  }
	}
	$response->addCommand(new AfterCommand('#edit-field-announcement-meta-tags-0-advanced-robots-robots-noindex', '', ['checked', true]));
	// add status message operation complete
    $response->addCommand(new PrependCommand('.region-highlighted', get_process_notification($confirmation_message, $message_status)));
	$response->addCommand(new HtmlCommand('#edit-revision-log-0-value', 'Node created programatically'));

	return $response;
}

/**
 * Get success message.
 */
function get_process_notification($confirmation_message = '', $message_status = '') {
	return <<<EOT
		<div data-drupal-messages='' class='messages-list'>
			<div class='messages__wrapper'>										  
				<div role='contentinfo' aria-labelledby='message-status-title' class='messages-list__item messages $message_status'>
					<div class='messages__header'>
						<h2 id='message-status-title' class='messages__title'>Complete</h2>
					</div>
					<button type='button' class='button button--dismiss' title='Dismiss'><span class='icon-close'></span>Close</button>
					<div class='messages__content'>Please double check the values for accuracy.<br />$confirmation_message</div>
				</div>
			</div>
		</div>
EOT;
}

/**
 * Fetch remote tags.
 * Load HTML DOM from URL, parse for meta data.
 */
function file_get_remote_tags($url,$fetch_tags,$img_dir = '') {
	$html = file_get_contents_curl($url);

	//parsing begins here:
	$doc = new DOMDocument();
	@$doc->loadHTML($html);
	$nodes = $doc->getElementsByTagName('title');

	//get and display what you need:
	$backup_title = $nodes->item(0)->nodeValue;

	$metas = $doc->getElementsByTagName('meta');
	$scripts = $doc->getElementsByTagName('script');
	
	// metatags or schema.org json
	$metas_length = $metas->length;
	$scripts_length = $scripts->length;
	
	// first iteration, get og:values
	for ($i = 0; $i < $metas_length; $i++)
	{
		$meta = $metas->item($i);
		
		foreach($fetch_tags as $key => $content) {
			if($meta->getAttribute('property') == $content['tag_name'])
				$fetch_tags[$key]['value'][] = $meta->getAttribute('content');
		}
	}
	
	// second iteration, try to match other tags to fill in blanks
	for ($i = 0; $i < $metas_length; $i++)
	{
		$meta = $metas->item($i);
		
		foreach($fetch_tags as $key => $content) {
			if(strpos($meta->getAttribute('name'), $key) && !in_array($meta->getAttribute('content'),$fetch_tags[$key]['value']))
				$fetch_tags[$key]['value'][] = $meta->getAttribute('content');
			
			if($meta->getAttribute('name') == 'description')
				$backup_desc = $meta->getAttribute('content');
		}
	}
	
	// check for schema.org values
	for ($i = 0; $i < $scripts_length; $i++)
	{
		$script = $scripts->item($i);
		if($script->getAttribute('type') == 'application/ld+json') {
			$schema_value = json_decode($script->nodeValue);
			if(@stripos($schema_value->{'@type'}, 'article')) {
				foreach($fetch_tags as $key => $content) {
					if($key == 'image') {
						if(@$schema_value->image->{'@type'} == 'ImageObject' && !in_array(@$schema_value->{$fetch_tags[$key]['schema_name']}->url,$fetch_tags[$key]['value'])) {
							$fetch_tags[$key]['value'][] = $schema_value->{$fetch_tags[$key]['schema_name']}->url;
							$fetch_tags[$key.':alt']['value'][] = isset($schema_value->{$fetch_tags[$key]['schema_name']}->name)?: null;
						}
						elseif(filter_var(@$schema_value->image, FILTER_VALIDATE_URL))
							$fetch_tags[$key]['value'][] = $schema_value->image;
					}
					elseif($key == 'site' && !in_array(@$schema_value->{$fetch_tags[$key]['schema_name']}->name,$fetch_tags[$key]['value']))
						$fetch_tags[$key]['value'][] = $schema_value->{$fetch_tags[$key]['schema_name']}->name;
					elseif(!in_array(@$schema_value->{$fetch_tags[$key]['schema_name']},$fetch_tags[$key]['value']))
						$fetch_tags[$key]['value'][] = $schema_value->{$fetch_tags[$key]['schema_name']};
				}
			}
		}
	}
	
	if(isset($backup_desc) && !in_array($backup_desc,$fetch_tags['description']['value']))
		$fetch_tags['description']['value'][] = $backup_desc;

	if(isset($backup_title) && !in_array($backup_title,$fetch_tags['title']['value']))
		$fetch_tags['title']['value'][] = $backup_title;
	
	if(!empty($fetch_tags['image']['value'])) {
		$fetch_tags['remote_media'] = get_remote_media($fetch_tags['image']['value'][0], $fetch_tags['image:alt']['value'][0], $img_dir);
		$fetch_tags['image:alt']['value'][0] = $fetch_tags['image:alt']['value'][0] ?? $fetch_tags['remote_media']->alt_text;
	}
	
	if(!empty($fetch_tags['date']['value'])) {
		$fetch_tags['date']['value'][0] = date('Y-m-d', strtotime($fetch_tags['date']['value'][0]));
		$fetch_tags['time']['value'][0] = date('H:m:s', strtotime($fetch_tags['date']['value'][0]));
	}

	return $fetch_tags;
}

/**
 * Get HTML content using cURL.
 */
function file_get_contents_curl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

/**
 * Download and store file or media item in media library, return media object.
 */
function get_remote_media($img_src, $alt = '', $img_dir = '') {
	
	$file_details = getimagesize($img_src);
	$image_details = explode('/',$file_details['mime']);
	
	if($image_details[0] == 'image') {
		$directory = 'public://'.$img_dir.'/remote-images/' . date('Y-m') . '/';

		$file_system = \Drupal::service('file_system');
		$file_system->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		
		$image_data = file_get_contents($img_src);
		$image_info = pathinfo(strtok($img_src, '?'));
		
		// try to create a sanitised file name, re-add file extensions as occasionally missing, creating issues with display
		if (empty($image_info['extension'])) {
			$extension = $image_details[1];
		} else {
			$parts = explode(';', $image_info['extension']);
			$extension = $parts[0];
		}		
		$image_filename = $image_info['filename']. '_'. date('YmdHis') .'.'. $extension;
		// file name as alt text is not ideal for accessibility
		$alt = ( empty($alt) ? $image_filename : $alt);
		
		$file_repository = \Drupal::service('file.repository');
		$image = $file_repository->writeData($image_data, "$directory$image_filename", FileSystemInterface::EXISTS_REPLACE);
		$image->alt_text = $alt;
		
		$current_user_id = \Drupal::currentUser()->id();

		if($img_dir == 'announcement-images') {
			// set temporary in case the file gets discarded in the node form, this changes to permanent upon node save
			$image->setTemporary();
			$image->save();
			$image_media = $image;
		} else {
			$image_media = Media::create([
			  'name' => "$image_filename",
			  'bundle' => 'image',
			  'uid' => "$current_user_id",
			  'langcode' => 'en',
			  'status' => 1,
			  'field_media_image' => [
				'target_id' => "{$image->id()}",
				'alt' => t("$alt"),
				'title' => t("$image_filename"),
			  ],
			  'revision_log_message' => "Remote file retrieved programatically by Media coverage. Original address $img_src",
			]);
			 
			$image_media->save();
		}
	}
	$image_media->size_status = check_image_size($file_details[1], $file_details[0]);
	$image_media->alt_text = $alt;
	return $image_media;
}

/**
 * Check image dimensions are appropriate, return array with message and css class.
 */
function check_image_size($height, $width) {
	
	$img_notification = '<p>'."Image dimensions {$width}x{$height}px: ";
	$message_status = 'messages--status';
	
	if($height < 400 ) {
		$img_notification .= ' <strong>likely not tall enough for top feature stories</strong>;';
		$message_status = 'messages--warning';
	}
	else 
		$img_notification .= ' acceptable height for top feature stories;';
	
	if($width < 600 ) {
		$img_notification .= ' <strong>likely not wide enough for top feature stories</strong>;';
		$message_status = 'messages--warning';
	}
	else 
		$img_notification .= ' acceptable width for top feature stories;';
	
	if($height < 200 ) {
		$img_notification .= ' <strong>likely not tall enough for news cards</strong>;';
		$message_status = 'messages--error';
	}
	if($width < 300 ) {
		$img_notification .= ' <strong>likely not wide enough for news cards</strong>;';
		$message_status = 'messages--error';
	}
	
	$img_notification .= '<p>';
	return [$img_notification, $message_status];
}