<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

/*
 * Titel, Autor, Info, an album's name and its description are plain text on
 * every page (decision 0073). Core renders them through its render_* events as
 * HTML; these handlers make that HTML the escaped text.
 *
 * ws.php is left alone: its JSON is data, not HTML, and keeps the values as core
 * shapes them.
 */

/**
 * render_element_name, render_element_description, render_category_name.
 *
 * @param string|null $text
 * @return string|null
 */
function galleryrules_render_plain_text($text)
{
  if (defined('IN_WS'))
  {
    return $text;
  }
  return galleryrules_plain_text($text);
}

/**
 * render_category_description: escaped, then its lines kept. Core breaks the
 * lines itself (pwg_nl2br) only while allow_html_descriptions is off
 * (include/common.inc.php), so this does it only while that is on.
 *
 * @param string|null $text
 * @return string|null
 */
function galleryrules_render_album_description($text)
{
  global $conf;

  if (defined('IN_WS'))
  {
    return $text;
  }
  return $conf['allow_html_descriptions'] ? galleryrules_plain_text_lines($text) : galleryrules_plain_text($text);
}

/**
 * loc_end_index_thumbnails: core escapes a thumbnail's alt text and tooltip
 * once more after rendering the name (include/category_default.inc.php), which
 * would show "&amp;" and "&#039;" literally. Both are rebuilt from the stored
 * values, the tooltip through core's own get_thumbnail_title().
 *
 * @param array $thumbnails
 * @return array
 */
function galleryrules_thumbnails_escaped_once($thumbnails)
{
  foreach ($thumbnails as &$thumbnail)
  {
    $name = !empty($thumbnail['name']) ? $thumbnail['name'] : get_name_from_file($thumbnail['file']);
    $thumbnail['TN_ALT'] = galleryrules_plain_text($name);
    $thumbnail['TN_TITLE'] = get_thumbnail_title($thumbnail, $name, (string)$thumbnail['comment']);
  }
  return $thumbnails;
}

/**
 * loc_end_picture: the picture page shows Autor straight from the photo's row
 * (picture.php), through no render event.
 *
 * @return void
 */
function galleryrules_picture_author_escaped()
{
  global $template, $picture;

  if (!empty($picture['current']['author']))
  {
    $template->assign('INFO_AUTHOR', galleryrules_plain_text($picture['current']['author']));
  }
}
