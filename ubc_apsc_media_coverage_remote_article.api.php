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
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpFoundation\Request;


/**
 * Implements hook_form_FORM_ID_alter().
 * Adds a URL input field (not recorded in the form submission values) and a 'check URL' button
 * Attach a javascript library that enables users to click select one of the other retrieved values, when available
 * Enables AJAX callback on the URL to fetch meta data and schema.org values, used to (hopefully) autofill the rest of the form
 */
function ubc_apsc_media_coverage_remote_article_form_node_media_coverage_form_alter(&$form, \Drupal\Core\Form\FormStateInterface $form_state) {
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
	  if ($entity->isNew()) {
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
* Take the URL value from remote_article__url and retrieve OpenGraph or metadata values
* Return values through AjaxResponse to populate the node form fields with retrieved data, when available
* Include other values retrieved for each field for user review and selection, in case they could be more appropriate
* Display a Drupal message when remote data has been accessed.
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
    $fetch_tags = file_get_remote_tags($url,$fetch_tags);
	
	// notification message upon completed request, warns about image dimensions
	$image_size_status = is_object($fetch_tags['remote_media']) ? $fetch_tags['remote_media']->size_status : '';
	$success_message = <<<EOT
		<div data-drupal-messages='' class='messages-list'>
			<div class='messages__wrapper'>										  
			  <div role='contentinfo' aria-labelledby='message-status-title' class='messages-list__item messages messages--status'>
			    <div class='messages__header'>
				  <h2 id='message-status-title' class='messages__title'>Complete</h2>
				</div>
				<button type='button' class='button button--dismiss' title='Dismiss'><span class='icon-close'></span>Close</button>
				<div class='messages__content'>Please double check the values for accuracy.<br />$image_size_status</div>
			  </div>
			</div>
		</div>
EOT;
	
	
	$response = new AjaxResponse();

	// remove autofill input URL
	$response->addCommand(new RemoveCommand('.alternative-options'));
	
	// change the body field to unrestricted text, so we can insert a value outside of CKEditor
	$response->addCommand(new InvokeCommand('#edit_body_0_format__2_chosen', 'trigger', ["mousedown"]));
	$response->addCommand(new InvokeCommand('[data-option-array-index="2"]', 'trigger', ["mouseup"]));

	// cycle through values and fill form
	foreach($fetch_tags as $key => $process) {
	  $alt_values_available = false;
	  
	  //skip the media object + related
	  if($key == 'remote_media' || $key == 'image:alt')
		  continue;
	  
	  // update the media field with new image retrieved
	  if($key == 'image' && is_object($fetch_tags['remote_media'])) {
			$response->addCommand(new InvokeCommand($fetch_tags[$key]['selector'], 'val', [$fetch_tags['remote_media']->id()]));
			$response->addCommand(new InvokeCommand('[data-media-library-widget-update="field_media_article_image"]', 'trigger', ["mousedown"]));
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
							default:
								// sometime values are not processed correctly and return as object, serialize to prevent error on return
								$alt_value = is_string($fetch_tags[$key]['value'][$i]) ? $fetch_tags[$key]['value'][$i] : serialize($fetch_tags[$key]['value'][$i]);
							break;
						}
							
						$alt_content .= "<tr><td>$i</td><td id='$key$i'>$alt_value</td><td><a class='remote-value-alternative' href='#' data-update-alt-value='#$key$i' data-update-target='{$fetch_tags[$key]['selector']}'>Select</a></td></tr>";
					}
				}
				if($alt_values_available)
					$response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label">Other Options:</div><table class="alternative-options">'.$alt_content.'</table>', ['']));
				
			}
	  }
	  elseif($key == 'date') {
		  // specific message below field if date not found
		  $response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label not-found">Please find '.$key.'</div>', ['']));  
	  }
	  else {
		  //  message below field if value not found
		  $response->addCommand(new AfterCommand($fetch_tags[$key]['selector'], '<div class="alternative-options-label not-found">Missing '.$key.'</div>', ['']));  
	  }
	  
	}
	
	// revert the body field back to filtered text, trigger the confirmation click
	$response->addCommand(new InvokeCommand('#edit_body_0_format__2_chosen', 'trigger', ["mousedown"]));
	$response->addCommand(new InvokeCommand('[data-option-array-index="0"]', 'trigger', ["mouseup"]));
	$response->addCommand(new InvokeCommand('.ui-dialog-buttonpane button.button--primary', 'trigger', ["click"]));
	
	// add status message operation complete
    $response->addCommand(new PrependCommand('.region-highlighted', $success_message));
	
	return $response;
}

/*
* Parse the content of the remote URL provided and populate an array with desired matching values
* - Download remote URL
* - Parse DOM for relevant OpenGraph, Schema values
* - return values found to populate the fields on the node form
*/
function file_get_remote_tags($url,$fetch_tags) {
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
			if(stripos($schema_value->{'@type'}, 'article')) {
				foreach($fetch_tags as $key => $content) {
					if($key == 'image') {
						if($schema_value->image->{'@type'} == 'ImageObject' && !in_array($schema_value->{$fetch_tags[$key]['schema_name']}->url,$fetch_tags[$key]['value'])) {
							$fetch_tags[$key]['value'][] = $schema_value->{$fetch_tags[$key]['schema_name']}->url;
							$fetch_tags[$key.':alt']['value'][] = isset($schema_value->{$fetch_tags[$key]['schema_name']}->name)?: null;
						}
						elseif(filter_var($schema_value->image, FILTER_VALIDATE_URL))
							$fetch_tags[$key]['value'][] = $schema_value->image;
					}
					elseif($key == 'site' && !in_array($schema_value->{$fetch_tags[$key]['schema_name']}->name,$fetch_tags[$key]['value']))
						$fetch_tags[$key]['value'][] = $schema_value->{$fetch_tags[$key]['schema_name']}->name;
					elseif(!in_array($schema_value->{$fetch_tags[$key]['schema_name']},$fetch_tags[$key]['value']))
						$fetch_tags[$key]['value'][] = $schema_value->{$fetch_tags[$key]['schema_name']};
				}
			}
		}
	}
	
	
	if(isset($backup_desc) && !in_array($backup_desc,$fetch_tags['description']['value']))
		$fetch_tags['description']['value'][] = $backup_desc;

	if(isset($backup_title) && !in_array($backup_title,$fetch_tags['title']['value']))
		$fetch_tags['title']['value'][] = $backup_title;
	
	if(!empty($fetch_tags['image']['value']))			
		$fetch_tags['remote_media'] = get_remote_media($fetch_tags['image']['value'][0], $fetch_tags['image_alt']['value'][0]);
	
	if(!empty($fetch_tags['date']['value'])) {
		$fetch_tags['date']['value'][0] = date('Y-m-d', strtotime($fetch_tags['date']['value'][0]));
		$fetch_tags['time']['value'][0] = date('H:m:s', strtotime($fetch_tags['date']['value'][0]));
	}

	return $fetch_tags;
}

/* get html */
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

/* download and store media item in media library, return media object */
function get_remote_media($img_src, $alt = '') {
	
	$file_details = getimagesize($img_src);
	$image_details = explode('/',$file_details['mime']);
	
	if($image_details[0] == 'image') {
		$directory = 'public://media-coverage-images/remote-images/';

		$file_system = \Drupal::service('file_system');
		$file_system->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		
		$image_data = file_get_contents($img_src);
		$image_info = pathinfo(strtok($img_src, '?'));
		
		// try to create a sanitised file name, re-add file extensions as occasionally missing, creating issues with display
		$image_filename = $image_info['filename'].'.'.( empty($image_info['extension']) ? $image_details[1] : $image_info['extension']);
		
		// would it be better to have article title here?
		$alt = ( empty($alt) ? $image_filename : $alt);
		
		$file_repository = \Drupal::service('file.repository');
		$image = $file_repository->writeData($image_data, "$directory$image_filename", FileSystemInterface::EXISTS_REPLACE);
		
		
		$current_user_id = \Drupal::currentUser()->id();

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
		  'revision_log_message' => "Remote file retrieved by Media coverage. Original address $img_src",
		 ]);
		 
		$image_media->save();
		
		$image_media->size_status = warn_image_size($file_details[1], $file_details[0]);
	}
	
	return $image_media;

}

/* check image dimensions are appropriate, return message */
function warn_image_size($height, $width) {
	
	$img_warning = "Image dimensions {$width}x{$height}px";
	if($height < 400 )
		$img_warning .= ' <strong>likely not tall enough for top feature stories</strong>;';
	else 
		$img_warning .= ' acceptable height for top feature stories;';
	if($width < 600 )
		$img_warning .= ' <strong>likely not wide enough for top feature stories</strong>;';
	else 
		$img_warning .= ' acceptable width for top feature stories;';
	if($height < 200 )
		$img_warning .= ' <strong>likely not tall enough for news cards</strong>;';
	if($width < 300 )
		$img_warning .= ' <strong>likely not wide enough for news cards</strong>;';
	
	return $img_warning;

}
