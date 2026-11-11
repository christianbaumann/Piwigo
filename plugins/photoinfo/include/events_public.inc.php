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
 * Everyone reads the rows; every logged-in account can click one to edit it
 * (decision 0058). A photo with no date or no description gets that row only
 * for an account that may edit, which needs it to add one. Whoever reaches the
 * page can see the photo, so the page needs no visibility check of its own.
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
  $editable = !is_a_guest();

  load_language('plugin.lang', PHOTOINFO_PATH);

  $rows = array(
    'EDITABLE' => $editable,
    'PATH' => PHOTOINFO_PATH,
    );

  $raw = isset($current['comment']) ? (string)$current['comment'] : '';
  // The test picture.php uses for COMMENT_IMG, so the row and core agree.
  $rows['INFO'] = (!empty($raw) or $editable);

  $dating = photoinfo_dating_from_row($current);
  $rows['DATE'] = ($dating !== null or $editable);

  if ($dating !== null)
  {
    $rows['DATE_TEXT'] = photoinfo_dating_display($dating);
    $rows['DATE_URL'] = make_index_url(
      array(
        'chronology_field' => 'created',
        'chronology_style' => 'monthly',
        'chronology_view' => 'list',
        'chronology_date' => photoinfo_date_chronology($dating['start']),
        )
      );
  }

  if ($editable)
  {
    $rows['RAW'] = $raw;
    $start = $dating === null ? null : $dating['start'];
    $end = $dating === null ? null : $dating['end'];
    $rows['QUALIFIER'] = ($dating === null or $dating['qualifier'] === null) ? '' : $dating['qualifier'];
    $rows['QUALIFIERS'] = photoinfo_qualifier_labels();
    $rows['START'] = photoinfo_date_fields($start);
    $rows['END'] = photoinfo_date_fields($end);
    $rows['MONTHS'] = photoinfo_month_names();
    $rows['MIN_YEAR'] = PHOTOINFO_DATE_MIN_YEAR;
    $rows['MAX_YEAR'] = date('Y');
    $rows['IMAGE_ID'] = (int)$current['id'];
    $rows['WS_URL'] = get_root_url().'ws.php?format=json';
    $rows['TOKEN'] = get_pwg_token();
  }

  $history = photoinfo_picture_history((int)$current['id']);
  if ($history !== null)
  {
    $rows['HISTORY'] = $history;
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
      .photoinfo_template_include('public_history.tpl')
      .PHOTOINFO_TPL_ROW_ANCHOR,
    $content
    );
}

/**
 * One date as the editor's three fields hold it.
 *
 * @param array|null $date
 * @return array year, month, day; '' where unknown
 */
function photoinfo_date_fields($date)
{
  $fields = array('year' => '', 'month' => '', 'day' => '');
  if ($date !== null)
  {
    foreach ($fields as $part => $empty)
    {
      $fields[$part] = $date[$part] === null ? '' : $date[$part];
    }
  }

  return $fields;
}

/**
 * The photo's last changes for the list under the info rows, or null when the
 * account may not read them (a guest, or a photo it cannot see) or provenance
 * is not loaded. The list reads what pwg.provenance.getHistory answers.
 *
 * @param int $image_id
 * @return array|null total, rows (each: id, text, by, when, own, undone, undoable), all_url, per_page
 */
function photoinfo_picture_history($image_id)
{
  if (!function_exists('provenance_may_read_history') or !provenance_may_read_history('photo', $image_id))
  {
    return null;
  }

  $history = provenance_fetch_history(
    'photo',
    $image_id,
    PHOTOINFO_HISTORY_LIST_SIZE,
    array(provenance_history_field_condition(provenance_history_list_fields()))
    );

  $entries = array();
  foreach (provenance_history_display_rows($history['rows']) as $row)
  {
    $entries[] = $row['display'] + array(
      'id' => $row['id'],
      'undoable' => photoinfo_history_offers_undo($row, $row['display']['own'], is_admin()),
      );
  }

  return array(
    'TOTAL' => $history['total_count'],
    'ROWS' => $entries,
    'ALL_URL' => make_index_url(array('section' => provenance_page_section('photo', $image_id))),
    'PER_PAGE' => PHOTOINFO_HISTORY_LIST_SIZE,
    'FIELDS' => implode(',', provenance_history_list_fields()),
    'UNDO_FIELDS' => implode(',', photoinfo_history_undo_fields()),
    'IS_ADMIN' => is_admin(),
    'TOKEN' => get_pwg_token(),
    'IMAGE_ID' => $image_id,
    );
}
