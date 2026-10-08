<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Public-side injection. Pulled in only on the picture page, so the rest of the
 * gallery never loads it.
 */

/**
 * Puts the Datum and Info rows into the photo's information list, and takes
 * core's "Created on" row and its description off the page: the rows show both
 * instead.
 *
 * Everyone reads the rows; an administrator can click one to edit it. A photo
 * with no date or no description gets that row only for an administrator, who
 * needs it to add one.
 *
 * No query of its own: picture.php has already loaded the photo's whole row,
 * photoinfo's columns included, into $picture['current'], and rendered its
 * description into COMMENT_IMG.
 *
 * @return void
 */
function photoinfo_picture_rows()
{
  global $template, $picture;

  if (empty($picture['current']))
  {
    return;
  }

  $current = $picture['current'];
  $editable = is_admin();

  load_language('plugin.lang', PHOTOINFO_PATH);

  $rows = array(
    'EDITABLE' => $editable,
    'PATH' => PHOTOINFO_PATH,
    );

  $raw = isset($current['comment']) ? (string)$current['comment'] : '';
  // The test picture.php uses for COMMENT_IMG, so the row and core agree.
  $rows['INFO'] = (!empty($raw) or $editable);

  $date = photoinfo_date_from_row(
    isset($current['date_creation']) ? $current['date_creation'] : null,
    isset($current['photoinfo_date_precision']) ? $current['photoinfo_date_precision'] : null
    );
  $rows['DATE'] = ($date !== null or $editable);

  if ($date !== null)
  {
    $rows['DATE_TEXT'] = photoinfo_date_display($date);
    $rows['DATE_URL'] = make_index_url(
      array(
        'chronology_field' => 'created',
        'chronology_style' => 'monthly',
        'chronology_view' => 'list',
        'chronology_date' => photoinfo_date_chronology($date),
        )
      );
  }

  if ($editable)
  {
    $rows['RAW'] = $raw;
    $rows['YEAR'] = $date === null ? '' : $date['year'];
    $rows['MONTH'] = ($date === null or $date['month'] === null) ? '' : $date['month'];
    $rows['DAY'] = ($date === null or $date['day'] === null) ? '' : $date['day'];
    $rows['MONTHS'] = photoinfo_month_names();
    $rows['MIN_YEAR'] = PHOTOINFO_DATE_MIN_YEAR;
    $rows['MAX_YEAR'] = date('Y');
    $rows['IMAGE_ID'] = (int)$current['id'];
    $rows['WS_URL'] = get_root_url().'ws.php?format=json';
    $rows['TOKEN'] = get_pwg_token();
  }

  $template->assign('PHOTOINFO', $rows);

  $template->set_prefilter('picture', 'photoinfo_picture_prefilter');
}

/**
 * Removes core's "Created on" row and description block, and injects the Datum
 * and Info rows in front of the "Posted on" row - where "Created on" was.
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
  $content = preg_replace(PHOTOINFO_TPL_DATE_ROW_PATTERN, '', $content);

  return str_replace(
    PHOTOINFO_TPL_ROW_ANCHOR,
    photoinfo_template_include('public_date.tpl')
      .photoinfo_template_include('public_info.tpl')
      .PHOTOINFO_TPL_ROW_ANCHOR,
    $content
    );
}
