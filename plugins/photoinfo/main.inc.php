<?php
/*
Plugin Name: Photo Info
Version: 1.1.0
Description: Edit a photo's date and description on the picture page and write them into the image file, ahead of its provenance. Requires the Provenance plugin.
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

// Keeps a metadata sync from replacing the photo's date with a scan date.
add_event_handler('format_exif_data', 'photoinfo_format_exif_data',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/events_sync.inc.php');

// A date or description saved in one of core's screens reaches the plugin's
// columns and the file. pwg.images.setInfo is wrapped after core registers it.
add_event_handler('picture_modify_before_update', 'photoinfo_picture_modify_before_update',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/events_core_edit.inc.php');
add_event_handler('loc_end_picture_modify', 'photoinfo_picture_modify_after_save',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/events_core_edit.inc.php');
add_event_handler('element_set_global_action', 'photoinfo_element_set_global_action',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/events_core_edit.inc.php');
add_event_handler('ws_add_methods', 'photoinfo_wrap_core_methods',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, PHOTOINFO_PATH . 'include/events_core_edit.inc.php');

// A photo's tags reach its file whenever they change, on every path that changes them.
$photoinfo_tags_file = PHOTOINFO_PATH . 'include/events_tags.inc.php';
add_event_handler('picture_modify_before_update', 'photoinfo_tags_picture_modify_before_update',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
add_event_handler('loc_end_picture_modify', 'photoinfo_tags_picture_modify_after_save',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
add_event_handler('loc_begin_element_set_global', 'photoinfo_tags_begin_element_set_global',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
add_event_handler('element_set_global_action', 'photoinfo_tags_element_set_global_action',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
add_event_handler('ws_add_methods', 'photoinfo_wrap_tag_methods',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, $photoinfo_tags_file);
add_event_handler('typetags_tags_regrouped', 'photoinfo_tags_regrouped',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
add_event_handler('persons_tags_changed', 'photoinfo_persons_tags_changed',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
add_event_handler('photoedit_begin', 'photoinfo_photoedit_begin',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $photoinfo_tags_file);
// After provenance and persons, whose handlers give back the locks the edit held.
add_event_handler('photoedit_end', 'photoinfo_photoedit_end',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, $photoinfo_tags_file);

// The Datum and Info rows. Registered only on the picture page, and the file behind it is
// pulled in only when the event actually fires.
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'photoinfo_picture_rows',
    EVENT_HANDLER_PRIORITY_NEUTRAL, PHOTOINFO_PATH . 'include/events_public.inc.php');
}

/**
 * Registers the plugin's web-service methods.
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

  // Defaults of '' rather than required: core's ws layer reads an empty string
  // as a missing parameter, and empty fields are how a date is cleared.
  $service->addMethod(
    'pwg.photoinfo.setDate',
    'ws_photoinfo_setDate',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'qualifier' => array('default' => '', 'info' => 'circa, before, after or between; empty for an exact date'),
      'year' => array('default' => '', 'info' => 'Four digits, 1800 to the current year; every field empty to clear the date'),
      'month' => array('default' => '', 'info' => '1 to 12, or empty when unknown'),
      'day' => array('default' => '', 'info' => 'Day of the month, or empty when unknown; needs a month'),
      'end_year' => array('default' => '', 'info' => 'The range end, only with between; not before the start'),
      'end_month' => array('default' => '', 'info' => 'The range end\'s month, or empty when unknown'),
      'end_day' => array('default' => '', 'info' => 'The range end\'s day, or empty when unknown; needs a month'),
      'pwg_token' => array(),
      ),
    'Saves a photo\'s date - a year, a month or a day, optionally circa, before, after, or a range - and writes it into the image file.',
    PHOTOINFO_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.photoinfo.rescan',
    'ws_photoinfo_rescan',
    array(
      'image_ids' => array('info' => 'At most ' . PHOTOINFO_RESCAN_MAX_CHUNK . ' comma-separated photo ids'),
      'pwg_token' => array(),
      ),
    'Restores the date and description of one chunk of photos from what their files say. Writes no file.',
    PHOTOINFO_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );
}
