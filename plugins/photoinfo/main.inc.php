<?php
/*
Plugin Name: Photo Info
Version: 1.0.0
Description: Edit a photo's description on the picture page and write it into the image file, ahead of its provenance. Requires the Provenance plugin.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: false
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'photoinfo')
{
  add_event_handler('init', 'photoinfo_folder_name_error');
  function photoinfo_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Photo Info folder name is incorrect, uninstall the plugin and rename it to "photoinfo"';
  }
  return;
}

// maintain.class.php defines this too, and runs first during install.
if (!defined('PHOTOINFO_PATH'))
{
  define('PHOTOINFO_PATH', PHPWG_PLUGINS_PATH . 'photoinfo/');
}
define('PHOTOINFO_XMP_CONFIG', PHOTOINFO_PATH . 'exiftool/pwginfo.config');

include_once(PHOTOINFO_PATH . 'include/functions.inc.php');

add_event_handler('ws_add_methods', 'photoinfo_add_methods');

// Puts the info text first in every caption provenance writes into a file.
// Provenance may load after this plugin, so nothing of it is called here.
add_event_handler('provenance_caption_parts', 'photoinfo_caption_parts_handler',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/writer.inc.php');

// The Info row. Registered only on the picture page, and the file behind it is
// pulled in only when the event actually fires.
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'photoinfo_picture_row',
    EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/events_public.inc.php');
}

/**
 * Registers the plugin's web-service method.
 *
 * @param array $arr
 */
function photoinfo_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'pwg.photoinfo.setInfo',
    'ws_photoinfo_setInfo',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'info' => array('default' => '', 'info' => 'The photo\'s description; empty to clear it'),
      'pwg_token' => array(),
      ),
    'Saves a photo\'s description and writes it into the image file, ahead of its provenance.',
    PHOTOINFO_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );
}
