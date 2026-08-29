<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/**
 * Public-side injection. Pulled in only on the picture page, so the rest of the
 * gallery never loads it.
 */

/**
 * Puts one provenance row into the photo page's information list.
 *
 * The text is composed by the same pure layer the file write-back uses, so what
 * a visitor reads on the page and what exiftool put into the image file cannot
 * drift apart.
 *
 * No query of its own: picture.php has already loaded the photo's whole row,
 * provenance columns included, into $picture['current'].
 */
function provenance_picture_row()
{
  global $template, $picture;

  if (empty($picture['current']))
  {
    return;
  }

  load_language('plugin.lang', PROVENANCE_PATH);

  $labels = array();
  foreach (provenance_caption_label_keys() as $field => $key)
  {
    $labels[$field] = l10n($key);
  }

  $text = provenance_compose_caption(
    provenance_caption_parts($picture['current'], $labels)
    );

  // A photo with no provenance gets no row at all, rather than a labelled empty
  // one saying nothing.
  if ($text === '')
  {
    return;
  }

  $template->assign('PROVENANCE_TEXT', $text);

  $template->set_prefilter('picture', 'provenance_picture_prefilter');
}

/**
 * Injects the row at the close of <dl id="standard">, so it lands among the
 * photo's other information rather than loose between two definition lists.
 *
 * @param string $content
 * @return string
 */
function provenance_picture_prefilter($content)
{
  $injection = file_get_contents(PROVENANCE_PATH . 'template/public_provenance.tpl');

  return str_replace(
    PROVENANCE_TPL_INJECT_POINT,
    $injection . PROVENANCE_TPL_INJECT_POINT,
    $content
    );
}
