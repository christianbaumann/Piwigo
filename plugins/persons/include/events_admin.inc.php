<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/**
 * Admin-side injection. Loaded only when IN_ADMIN is defined, so the public
 * gallery never pays for it.
 */

/**
 * Puts a link to the tagging screen into the photo screen's action bar.
 *
 * The screen itself is admin/photo.php; nothing else links to it, so this is
 * the only way an administrator reaches it.
 */
function persons_admin_photo_link()
{
  global $template, $page;

  if ($page['page'] != 'photo')
  {
    return;
  }

  // admin/photo.php sets $page['tab'], but it runs after this event, so the
  // request parameter is read here with the same default that file applies.
  $tab = isset($_GET['tab']) ? $_GET['tab'] : 'properties';
  if ($tab != 'properties')
  {
    return;
  }

  $image_id = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;
  if ($image_id <= 0)
  {
    return;
  }

  load_language('plugin.lang', PERSONS_PATH);

  $template->assign('PERSONS_ADMIN_PHOTO_URL',
    get_root_url().'admin.php?page=plugin-persons&amp;image_id='.$image_id);

  $template->set_prefilter('picture_modify', 'persons_photo_prefilter');
}

/**
 * Injects the link as the first entry of that action bar.
 *
 * The anchor is a constant with a structural guard test behind it: a string
 * that silently stopped matching would leave the screen rendering perfectly,
 * with no way to the tagging screen at all.
 *
 * @param string $content
 * @return string
 */
function persons_photo_prefilter($content)
{
  $injection = file_get_contents(PERSONS_PATH.'template/admin_photo_link.tpl');

  return str_replace(
    PERSONS_TPL_PHOTO_ANCHOR,
    PERSONS_TPL_PHOTO_ANCHOR.$injection,
    $content
    );
}
