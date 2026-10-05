<?php
/*
Plugin Name: Photo Edit
Version: 1.0.0
Description: Turn a photo in 90 degree steps and crop it, on the picture page. The result is written into the image file.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: false
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'photoedit')
{
  add_event_handler('init', 'photoedit_folder_name_error');
  function photoedit_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Photo Edit folder name is incorrect, uninstall the plugin and rename it to "photoedit"';
  }
  return;
}

if (!defined('PHOTOEDIT_PATH'))
{
  define('PHOTOEDIT_PATH', PHPWG_PLUGINS_PATH . 'photoedit/');
}

global $conf;

// The binary is expected on PATH. A host that keeps it elsewhere sets the
// directory - with its trailing slash - in local/config/config.inc.php.
if (!isset($conf['photoedit_exiftool_path']))
{
  $conf['photoedit_exiftool_path'] = '';
}

add_event_handler('ws_add_methods', 'photoedit_add_methods');

// An edited photo keeps its path, so its URLs carry the file's version: a
// browser must not keep showing what it cached before the edit.
include_once(PHOTOEDIT_PATH . 'include/functions.inc.php');
add_event_handler('get_derivative_url', 'photoedit_derivative_url');
add_event_handler('get_src_image_url', 'photoedit_src_image_url');

// Registered everywhere rather than under IN_ADMIN: core's delete_elements()
// also runs from ws.php.
add_event_handler('delete_elements', 'photoedit_delete_elements',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOEDIT_PATH . 'include/pipeline.inc.php');

// The edit button. Registered only on the picture page, and the file behind it
// is pulled in only when the event actually fires.
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'photoedit_picture_button',
    EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOEDIT_PATH . 'include/events_public.inc.php');
}

/**
 * Registers the plugin's web-service method.
 *
 * @param array $arr
 */
function photoedit_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'pwg.photoedit.apply',
    'ws_photoedit_apply',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'turns' => array('default' => 0, 'info' => 'Quarter turns clockwise, 0 to 3, relative to what the page shows'),
      'crop' => array('default' => '', 'info' => 'l,t,r,b as fractions of the turned photo; empty for no crop'),
      'dry_run' => array('default' => false, 'type' => WS_TYPE_BOOL, 'info' => 'Report what the edit would do, write nothing'),
      'pwg_token' => array(),
      ),
    'Turns a photo, crops it and writes the result into its image file (PNG or JPEG). Webmaster only.',
    PHOTOEDIT_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );
}

/**
 * The version of one photo's file, or '' for a photo never edited.
 *
 * @param int $image_id
 * @return string
 */
function photoedit_url_version($image_id)
{
  global $conf;
  static $versions = null;

  if ($versions === null)
  {
    $versions = photoedit_decode_versions(isset($conf['photoedit_versions']) ? $conf['photoedit_versions'] : null);
  }

  return isset($versions[$image_id]) ? (string)$versions[$image_id] : '';
}

/**
 * get_derivative_url: versions a derivative linked directly under _data/i/.
 */
function photoedit_derivative_url($url, $params, $src_image, $rel_url)
{
  return photoedit_versioned_url($url, photoedit_url_version($src_image->id));
}

/**
 * get_src_image_url: versions the photo's own file, which the page shows when
 * no derivative is larger.
 */
function photoedit_src_image_url($url, $src_image)
{
  return photoedit_versioned_url($url, photoedit_url_version($src_image->id));
}
