<?php
defined('GALLERYRULES_PATH') or die('Hacking attempt!');

/*
 * A blank album name shows a hint. Core's create dialog on Alben > Verwaltung
 * sends a blank name to pwg.categories.add, which refuses it with an HTTP error
 * the page only logs to the console: the dialog stays open with no message.
 * The plugin's template stops the save in the browser and says "Name fehlt"
 * next to the field.
 */

/** The hint shown next to the name field. */
define('GALLERYRULES_ALBUM_NAME_MISSING', 'Name missing');

/** The create dialog's name field in core's albums.tpl; the rename dialog's lacks the label text. */
define('GALLERYRULES_ALBUM_NAME_FIELD', "/\\{'Album name'\\|@translate\\}\\s*<input class=\"user-property-input\" \\/>/");

/**
 * loc_begin_admin_page: puts the hint into the albums page's create dialog.
 */
function galleryrules_album_name_hint()
{
  global $page, $template;

  if ('albums' != @$page['page'])
  {
    return;
  }

  load_language('plugin.lang', GALLERYRULES_PATH);
  $template->set_prefilter('albums', 'galleryrules_album_name_hint_prefilter');
}

/**
 * Appends the hint's include right after the create dialog's name field.
 *
 * @param string $content
 * @return string
 */
function galleryrules_album_name_hint_prefilter($content)
{
  return preg_replace_callback(
    GALLERYRULES_ALBUM_NAME_FIELD,
    function ($match)
    {
      return $match[0].galleryrules_album_name_hint_include();
    },
    $content,
    1
    );
}

/**
 * The hint as an include, so a change to its template needs no compiled-template purge (decision 0036).
 *
 * @return string
 */
function galleryrules_album_name_hint_include()
{
  return "{include file='".realpath(GALLERYRULES_PATH.'template/album_name_hint.tpl')."'}";
}
