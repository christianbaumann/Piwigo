<?php
/*
Plugin Name: Persons
Version: 1.0.0
Description: Draw a box around a person in a photo and name them. Regions are stored in the image file as MWG regions; the database holds a rebuildable index.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'persons')
{
  add_event_handler('init', 'persons_folder_name_error');
  function persons_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Persons folder name is incorrect, uninstall the plugin and rename it to "persons"';
  }
  return;
}

global $prefixeTable, $conf;

// maintain.class.php defines this too, and runs first during install.
if (!defined('PERSONS_PATH'))
{
  define('PERSONS_PATH', PHPWG_PLUGINS_PATH . 'persons/');
}
define('PERSONS_TABLE',        $prefixeTable . 'persons');
define('PERSONS_REGION_TABLE', $prefixeTable . 'person_region');

include_once(PERSONS_PATH . 'include/functions.inc.php');

// The binary is expected on PATH. A host that keeps it elsewhere sets the
// directory - with its trailing slash - in local/config/config.inc.php.
// Unlike plugins/provenance this plugin needs no -config file: exiftool has
// read and written XMP-mwg-rs built in since well before 13.25.
if (!isset($conf['persons_exiftool_path']))
{
  $conf['persons_exiftool_path'] = '';
}

add_event_handler('ws_add_methods', 'persons_add_methods');

// The public overlay and person row. Registered only on the picture page, and
// the file behind them is pulled in only when the event actually fires.
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'persons_picture_overlay',
    EVENT_HANDLER_PRIORITY_NEUTRAL, PERSONS_PATH . 'include/events_public.inc.php');
}

if (defined('IN_ADMIN'))
{
  include_once(PERSONS_PATH . 'include/events_admin.inc.php');

  add_event_handler('loc_begin_admin_page', 'persons_admin_photo_link');
}

/**
 * Registers the plugin's web-service methods.
 *
 * @param array $arr
 */
function persons_add_methods($arr)
{
  $service = &$arr[0];
  $file = PERSONS_PATH . 'include/ws_functions.inc.php';

  $service->addMethod(
    'pwg.persons.getList',
    'ws_persons_getList',
    array(
      'q' => array('flags' => WS_PARAM_OPTIONAL, 'info' => 'Substring of a name; omit for the most recently used'),
      'image_id' => array('flags' => WS_PARAM_OPTIONAL, 'type' => WS_TYPE_ID,
        'info' => 'Leaves out whoever is already on this photo'),
      'per_page' => array(
        'default' => PERSONS_PICKER_RECENT_LIMIT,
        'maxValue' => PERSONS_SEARCH_MAX_RESULTS,
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
        ),
      ),
    'Lists persons for a picker: the most recently used, or those matching a query.',
    $file
  );

  $service->addMethod(
    'pwg.persons.getRegions',
    'ws_persons_getRegions',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      ),
    'Reads the person regions indexed for one photo.',
    $file
  );

  $service->addMethod(
    'pwg.persons.addRegion',
    'ws_persons_addRegion',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'name' => array(),
      'x' => array('info' => 'Normalized [0..1], centre of the box, before rotation'),
      'y' => array('info' => 'Normalized [0..1], centre of the box, before rotation'),
      'w' => array('info' => 'Normalized [0..1]'),
      'h' => array('info' => 'Normalized [0..1]'),
      'type' => array('default' => 'Face', 'info' => 'One of: ' . implode(', ', persons_region_types())),
      'pwg_token' => array(),
      ),
    'Writes one named region into a photo\'s image file and reindexes it.',
    $file
  );

  $service->addMethod(
    'pwg.persons.deleteRegion',
    'ws_persons_deleteRegion',
    array(
      'region_id' => array('type' => WS_TYPE_ID),
      'pwg_token' => array(),
      ),
    'Removes one region from a photo\'s image file and reindexes it.',
    $file
  );

  // The three below reach past a single photo - a rename or a delete rewrites
  // every file carrying the person - so the per-image visibility gate cannot
  // bound them and they are administrator-only instead.
  $service->addMethod(
    'pwg.persons.rename',
    'ws_persons_rename',
    array(
      'person_id' => array('type' => WS_TYPE_ID),
      'name' => array(),
      'pwg_token' => array(),
      ),
    'Renames a person in the index, the mirrored tag and every image file.',
    $file,
    array('admin_only' => true)
  );

  $service->addMethod(
    'pwg.persons.delete',
    'ws_persons_delete',
    array(
      'person_id' => array('type' => WS_TYPE_ID),
      'pwg_token' => array(),
      ),
    'Removes a person and their regions from the index and from every image file.',
    $file,
    array('admin_only' => true)
  );

  $service->addMethod(
    'pwg.persons.rescan',
    'ws_persons_rescan',
    array(
      'image_ids' => array('info' => 'At most ' . PERSONS_WRITEBACK_MAX_CHUNK . ' comma-separated photo ids'),
      'pwg_token' => array(),
      ),
    'Rebuilds the person index of one chunk of photos from what their files say.',
    $file,
    array('admin_only' => true)
  );
}
