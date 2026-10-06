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

  // Only this page has the information list a reload brings up to date; the
  // admin tagging screen shares the editor template and leaves this unset.
  $template->assign('PERSONS_RELOAD_ON_EXIT', true);

  $template->set_prefilter('picture', 'persons_picture_prefilter');
}

/**
 * Wraps the photo in the overlay's positioning context, adds the person row and,
 * after the information list, the editor's row.
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

  // Without the row anchor the editor stays in the stage, as it was before it
  // moved to the information panel, rather than vanishing beside its boxes.
  $has_row = strpos($content, PERSONS_TPL_ROW_INJECT_POINT) !== false;

  $stage = '<div id="persons-stage">'
    .PERSONS_TPL_INJECT_POINT
    .persons_template_include('public_overlay.tpl')
    .($has_row ? '' : persons_template_include('public_editor.tpl'))
    .'</div>';

  $content = str_replace(PERSONS_TPL_INJECT_POINT, $stage, $content);

  // The editor follows the anchor rather than preceding it: it is a control,
  // not a dt/dd pair, so it goes after </dl> instead of inside the list. The
  // list's own class gives the row the list's padding, which differs by skin.
  return str_replace(
    PERSONS_TPL_ROW_INJECT_POINT,
    persons_template_include('public_persons.tpl')
      .PERSONS_TPL_ROW_INJECT_POINT
      .'<div id="PersonsTagging" class="imageInfoTable">'
      .persons_template_include('public_editor.tpl')
      .'</div>',
    $content
    );
}
