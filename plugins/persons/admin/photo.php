<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Tagging screen for one photo.
 *
 * Modelled on admin/picture_coi.php: one large derivative, rendered on its own,
 * with none of the picture page's machinery around it - no RVAS rewriting the
 * element, no <area> map, no click-to-navigate handler. The editor therefore
 * runs on a static element, and the only difference from the public page is the
 * element it measures.
 *
 * It is a screen of its own rather than an injection into the photo properties
 * tab because picture_modify.tpl's photo is a thumbnail
 * (admin/themes/default/template/picture_modify.tpl:114) - far too small to box
 * a face on.
 *
 * admin/plugin.php has already checked administrator status; the check is
 * repeated here so the file cannot be reached authorised by its caller alone.
 */

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('image_id', $_GET, false, PATTERN_ID);

include_once(PERSONS_PATH.'include/render.inc.php');

$image_id = (int)$_GET['image_id'];

$result = pwg_query('
SELECT *
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
;');

if (!pwg_db_num_rows($result))
{
  $page['errors'][] = l10n('This photo no longer exists');
  return;
}

$image = pwg_db_fetch_assoc($result);

persons_assign_overlay($image_id, $image);

$template->assign(array(
  'PERSONS_ADMIN_PHOTO' => array(
    'TITLE'    => render_element_name($image),
    'ALT'      => $image['file'],
    'U_IMG'    => DerivativeImage::url(IMG_LARGE, $image),
    'U_RETURN' => get_root_url().'admin.php?page=photo-'.$image_id,
    ),
  // The overlay template is inlined into the picture page by a prefilter there;
  // here it is a plain Smarty include, so the markup has exactly one source.
  'PERSONS_OVERLAY_TPL' => realpath(PERSONS_PATH.'template/public_overlay.tpl'),
  ));

$template->set_filename('persons_admin_photo', realpath(PERSONS_PATH.'template/admin_photo.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'persons_admin_photo');
