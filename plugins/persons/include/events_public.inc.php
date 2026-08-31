<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Public-side injection. Pulled in only on the picture page, so the rest of the
 * gallery never loads it.
 *
 * Three things land on the page: a positioning wrapper around the photo carrying
 * the region boxes, the editor that draws new ones onto it, and one row of names
 * in the photo's information list.
 *
 * The regions themselves are prepared by include/render.inc.php, which the admin
 * tagging screen shares: one implementation of the coordinate contract, two
 * surfaces showing it.
 */

include_once(PERSONS_PATH.'include/render.inc.php');

/**
 * Puts the region overlay and the person row on the public photo page.
 *
 * Guests get neither. Faces are personal data and decision 0019 keeps every
 * read of them behind a login, so an anonymous visitor must not even be able to
 * tell from the page source that a photo carries regions.
 *
 * @return void
 */
function persons_picture_overlay()
{
  global $template, $page, $picture;

  if (is_a_guest())
  {
    return;
  }

  $image_id = isset($page['image_id']) ? (int)$page['image_id'] : 0;
  if ($image_id <= 0)
  {
    return;
  }

  // picture.php has already loaded the photo's row; no query of our own for it.
  $image = isset($picture['current']) ? $picture['current'] : array();

  persons_assign_overlay($image_id, $image);

  $template->set_prefilter('picture', 'persons_picture_prefilter');
}

/**
 * Wraps the photo in the overlay's positioning context and adds the person row.
 *
 * Both injections keep their anchor, so plugins/provenance - which prepends at
 * the same row anchor - keeps working whichever prefilter runs first.
 *
 * @param string $content
 * @return string
 */
function persons_picture_prefilter($content)
{
  // set_prefilter() registers against a template handle, and the same handle
  // compiles sub-templates that carry neither anchor. Injecting into those
  // would put a second stage on the page.
  if (strpos($content, PERSONS_TPL_INJECT_POINT) === false)
  {
    return $content;
  }

  $stage = '<div id="persons-stage">'
    .PERSONS_TPL_INJECT_POINT
    .file_get_contents(PERSONS_PATH.'template/public_overlay.tpl')
    .'</div>';

  $content = str_replace(PERSONS_TPL_INJECT_POINT, $stage, $content);

  return str_replace(
    PERSONS_TPL_ROW_INJECT_POINT,
    file_get_contents(PERSONS_PATH.'template/public_persons.tpl').PERSONS_TPL_ROW_INJECT_POINT,
    $content
    );
}
