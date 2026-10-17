<?php
/*
Plugin Name: Gallery Rules
Version: 1.0.0
Description: The fork's changes to how core Piwigo behaves, made through core's own events instead of edits to core.
Plugin URI: https://github.com/christianbaumann/Piwigo
Author: Christian Baumann
Has Settings: false
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'galleryrules')
{
  add_event_handler('init', 'galleryrules_folder_name_error');
  function galleryrules_folder_name_error()
  {
    global $page;
    $page['errors'][] = 'Gallery Rules folder name is incorrect, uninstall the plugin and rename it to "galleryrules"';
  }
  return;
}

// maintain.class.php defines this too, and runs first during install.
if (!defined('GALLERYRULES_PATH'))
{
  define('GALLERYRULES_PATH', PHPWG_PLUGINS_PATH . 'galleryrules/');
}

// Escapes before any other handler adds markup, such as core's pwg_nl2br at the neutral priority.
define('GALLERYRULES_PLAIN_TEXT_PRIORITY', EVENT_HANDLER_PRIORITY_NEUTRAL - 40);

include_once(GALLERYRULES_PATH . 'include/plain_text.inc.php');
include_once(GALLERYRULES_PATH . 'include/events_plain_text.inc.php');

add_event_handler('render_element_name', 'galleryrules_render_plain_text', GALLERYRULES_PLAIN_TEXT_PRIORITY);
add_event_handler('render_element_description', 'galleryrules_render_plain_text', GALLERYRULES_PLAIN_TEXT_PRIORITY);
add_event_handler('render_category_name', 'galleryrules_render_plain_text', GALLERYRULES_PLAIN_TEXT_PRIORITY);
add_event_handler('render_category_description', 'galleryrules_render_album_description', GALLERYRULES_PLAIN_TEXT_PRIORITY);
add_event_handler('loc_end_index_thumbnails', 'galleryrules_thumbnails_escaped_once');
add_event_handler('loc_end_picture', 'galleryrules_picture_author_escaped');

// The replace option refuses a file name several photos of the album share; wrapped after core registers it.
add_event_handler('ws_add_methods', 'galleryrules_wrap_upload',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, GALLERYRULES_PATH . 'include/events_replace.inc.php');

// addSimple refuses a file identical to an existing photo and puts a new one in its album at once.
add_event_handler('ws_add_methods', 'galleryrules_wrap_addsimple',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, GALLERYRULES_PATH . 'include/events_addsimple.inc.php');

// A photo's last album cannot be removed: the Batch Manager, the properties page and the API.
$galleryrules_last_album_file = GALLERYRULES_PATH . 'include/events_last_album.inc.php';
add_event_handler('loc_begin_element_set_global', 'galleryrules_batch_dissociate',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $galleryrules_last_album_file);
add_event_handler('picture_modify_before_update', 'galleryrules_properties_keep_last_album',
  EVENT_HANDLER_PRIORITY_NEUTRAL, $galleryrules_last_album_file);
// After photoinfo's wrapper of pwg.images.setInfo (neutral + 10), so a refused call reaches it not at all.
add_event_handler('ws_add_methods', 'galleryrules_wrap_album_methods',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 20, $galleryrules_last_album_file);

// An empty album's page tells an administrator that visitors do not see it.
add_event_handler('loc_begin_index', 'galleryrules_empty_album_note',
  EVENT_HANDLER_PRIORITY_NEUTRAL, GALLERYRULES_PATH . 'include/events_empty_album.inc.php');

// A blank name in the create-album dialog of Alben > Verwaltung shows a hint instead of nothing.
add_event_handler('loc_begin_admin_page', 'galleryrules_album_name_hint',
  EVENT_HANDLER_PRIORITY_NEUTRAL, GALLERYRULES_PATH . 'include/events_album_name.inc.php');
