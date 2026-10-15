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
