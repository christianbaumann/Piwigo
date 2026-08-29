<?php
/*
Plugin Name: Provenance
Version: 1.0.0
Description: Records where a scan came from - physical album, owner, scan date and notes - entered at album level, copied down onto its photos, and written into the image files.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: false
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'provenance')
{
  add_event_handler('init', 'provenance_folder_name_error');
  function provenance_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Provenance folder name is incorrect, uninstall the plugin and rename it to "provenance"';
  }
  return;
}

global $prefixeTable, $conf;

// maintain.class.php defines this too, and runs first during install.
if (!defined('PROVENANCE_PATH'))
{
  define('PROVENANCE_PATH', PHPWG_PLUGINS_PATH . 'provenance/');
}
define('PROVENANCE_HISTORY_TABLE', $prefixeTable . 'provenance_history');
define('PROVENANCE_XMP_CONFIG',    PROVENANCE_PATH . 'exiftool/pwgprov.config');

include_once(PROVENANCE_PATH . 'include/functions.inc.php');
include_once(PROVENANCE_PATH . 'include/history.inc.php');
include_once(PROVENANCE_PATH . 'include/exiftool.inc.php');

// The binary is expected on PATH. A host that keeps it elsewhere sets the
// directory - with its trailing slash - in local/config/config.inc.php.
if (!isset($conf['provenance_exiftool_path']))
{
  $conf['provenance_exiftool_path'] = '';
}

add_event_handler('ws_add_methods', 'provenance_add_methods');

// The two fork-local core triggers. Registered outside the IN_ADMIN block: the
// virtual-link funnel is reached from ws.php as well as from the admin screens,
// and the file is pulled in only when one of them actually fires.
add_event_handler('associate_images_to_categories', 'provenance_inherit_associated',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PROVENANCE_PATH . 'include/events_inherit.inc.php');
add_event_handler('site_update_associate_images', 'provenance_inherit_site_update',
  EVENT_HANDLER_PRIORITY_NEUTRAL, PROVENANCE_PATH . 'include/events_inherit.inc.php');

// The public row. Registered only on the picture page, and the file behind it is
// pulled in only when the event actually fires.
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'provenance_picture_row',
    EVENT_HANDLER_PRIORITY_NEUTRAL, PROVENANCE_PATH . 'include/events_public.inc.php');
}

if (defined('IN_ADMIN'))
{
  include_once(PROVENANCE_PATH . 'include/events_admin.inc.php');

  add_event_handler('loc_begin_admin_page', 'provenance_admin_album');
  add_event_handler('loc_begin_admin_page', 'provenance_admin_photo');
  add_event_handler('loc_end_element_set_global', 'provenance_batch_move_panel');
}

/**
 * Registers the plugin's web-service methods.
 *
 * @param array $arr
 */
function provenance_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'pwg.provenance.getHistory',
    'ws_provenance_getHistory',
    array(
      'object' => array('info' => 'One of: '.implode(', ', provenance_history_objects())),
      'object_id' => array('type' => WS_TYPE_ID),
      'date_min' => array('flags' => WS_PARAM_OPTIONAL, 'info' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM:SS'),
      'date_max' => array('flags' => WS_PARAM_OPTIONAL, 'info' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM:SS'),
      'per_page' => array(
        'default' => PROVENANCE_HISTORY_PER_PAGE_DEFAULT,
        'maxValue' => PROVENANCE_HISTORY_PER_PAGE_MAX,
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
        ),
      ),
    'Reads the provenance audit trail of one album or photo, newest first.',
    PROVENANCE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true)
  );

  $service->addMethod(
    'pwg.provenance.setAlbumInfo',
    'ws_provenance_setAlbumInfo',
    array(
      'cat_id' => array('type' => WS_TYPE_ID),
      'physical_album' => array('default' => ''),
      'owner' => array('default' => ''),
      'scanned_on' => array('default' => '', 'info' => 'YYYY-MM-DD, or empty to clear'),
      'note' => array('default' => ''),
      'pwg_token' => array(),
      ),
    'Saves the provenance of one album. Empty values clear the field.',
    PROVENANCE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.provenance.applyToPhotos',
    'ws_provenance_applyToPhotos',
    array(
      'cat_id' => array('type' => WS_TYPE_ID),
      'image_ids' => array('default' => '', 'info' => 'At most '.PROVENANCE_APPLY_MAX_CHUNK.' comma-separated photo ids, all in this album'),
      'pwg_token' => array(),
      ),
    'Copies an album\'s provenance onto the photos of one chunk. The photo\'s own note is never touched.',
    PROVENANCE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.provenance.setPhotoInfo',
    'ws_provenance_setPhotoInfo',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'note' => array('default' => ''),
      'pwg_token' => array(),
      ),
    'Saves one photo\'s own provenance note. The album-sourced values are not touched.',
    PROVENANCE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.provenance.writeBack',
    'ws_provenance_writeBack',
    array(
      'image_ids' => array('default' => '', 'info' => 'At most '.PROVENANCE_WRITEBACK_MAX_CHUNK.' comma-separated photo ids'),
      'pwg_token' => array(),
      ),
    'Writes the provenance of one chunk of photos into their image files as EXIF, IPTC and XMP.',
    PROVENANCE_PATH . 'include/ws_functions.inc.php',
    array('admin_only' => true, 'post_only' => true)
  );
}
