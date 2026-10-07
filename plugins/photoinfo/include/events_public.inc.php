<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Public-side injection. Pulled in only on the picture page, so the rest of the
 * gallery never loads it.
 */

/**
 * Puts the Info row into the photo's information list, and takes core's
 * description off the top of the photo: the row shows it instead.
 *
 * Everyone reads the row; an administrator can click it to edit. A photo with no
 * description gets a row only for an administrator, who needs it to add one.
 *
 * No query of its own: picture.php has already loaded the photo's row into
 * $picture['current'], and rendered its description into COMMENT_IMG.
 *
 * @return void
 */
function photoinfo_picture_row()
{
  global $template, $picture;

  if (empty($picture['current']))
  {
    return;
  }

  $raw = isset($picture['current']['comment']) ? (string)$picture['current']['comment'] : '';
  $editable = is_admin();

  // The test picture.php uses for COMMENT_IMG, so the row and core agree.
  if (empty($raw) and !$editable)
  {
    return;
  }

  load_language('plugin.lang', PHOTOINFO_PATH);

  $row = array(
    'EDITABLE' => $editable,
    'PATH' => PHOTOINFO_PATH,
    );

  if ($editable)
  {
    $row['RAW'] = $raw;
    $row['IMAGE_ID'] = (int)$picture['current']['id'];
    $row['WS_URL'] = get_root_url().'ws.php?format=json';
    $row['TOKEN'] = get_pwg_token();
  }

  $template->assign('PHOTOINFO', $row);

  $template->set_prefilter('picture', 'photoinfo_picture_prefilter');
}

/**
 * Removes core's description block and injects the Info row in front of the
 * "Posted on" row.
 *
 * @param string $content
 * @return string
 */
function photoinfo_picture_prefilter($content)
{
  // set_prefilter() registers against a template handle, and the same handle
  // compiles sub-templates that carry no anchor.
  if (strpos($content, PHOTOINFO_TPL_ROW_ANCHOR) === false)
  {
    return $content;
  }

  $content = str_replace(PHOTOINFO_TPL_COMMENT_BLOCK, '', $content);

  return str_replace(
    PHOTOINFO_TPL_ROW_ANCHOR,
    photoinfo_template_include('public_info.tpl').PHOTOINFO_TPL_ROW_ANCHOR,
    $content
    );
}
