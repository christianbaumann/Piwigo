<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Undo of a region entry of a photo's change list, asked for by photoinfo
 * through persons_undo_region (a trigger_change). persons does not know who
 * asks, and answers only while it is active: a caller that gets its own
 * starting value back knows nobody answered.
 *
 * The change goes through persons_apply_change() and not through addRegion /
 * deleteRegion, so persons_regions_changed is not fired and no region entry is
 * written for it; the caller writes the one undo entry.
 */

define('PERSONS_UNDO_STATE', 'state');
define('PERSONS_UNDO_REMOVE', 'remove');
define('PERSONS_UNDO_RESTORE', 'restore');

/**
 * persons_undo_region (filter): $result (null), $image_id, $action, $region
 * (name, box x/y/w/h, drawn_by|null), $user_id, $is_admin.
 *
 * Answers by action:
 *   state   array('present' => bool): the index holds the region now
 *   remove  array('ok' => bool, 'forbidden' => bool, 'message' => string)
 *   restore the same; the region comes back with $region['drawn_by']
 *
 * @return array
 */
function persons_undo_region($result, $image_id, $action, $region, $user_id, $is_admin)
{
  $row = persons_find_region_row(persons_index_rows_of_image($image_id), $region);

  if ($action === PERSONS_UNDO_STATE)
  {
    return array('present' => $row !== null);
  }

  if ($action === PERSONS_UNDO_REMOVE)
  {
    if ($row === null)
    {
      return persons_undo_answer(false, false, 'No such region');
    }
    if (!persons_may_delete_region($row['drawn_by'], $user_id, $is_admin))
    {
      return persons_undo_answer(false, true, 'Only the person who drew this region can remove it');
    }
    $matcher = array('name' => $row['name'], 'x' => (float)$row['area_x'], 'y' => (float)$row['area_y'],
      'w' => (float)$row['area_w'], 'h' => (float)$row['area_h']);

    return persons_undo_outcome(persons_apply_change($image_id, array(), array($matcher)));
  }

  if ($action === PERSONS_UNDO_RESTORE)
  {
    if ($row !== null)
    {
      return persons_undo_answer(false, false, 'Region exists already');
    }
    list($x, $y, $w, $h) = array_values($region['box']);
    $add = array('name' => $region['name'], 'x' => (float)$x, 'y' => (float)$y,
      'w' => (float)$w, 'h' => (float)$h, 'type' => 'Face');
    if (persons_prepare_region_for_write($add) === null)
    {
      return persons_undo_answer(false, false, 'That box is too small or outside the photo');
    }

    return persons_undo_outcome(persons_apply_change($image_id, array($add), array(), true, $region['drawn_by']));
  }

  return persons_undo_answer(false, false, 'Unknown action');
}

/**
 * @param int $image_id
 * @return array[] the photo's index rows with the person's name
 */
function persons_index_rows_of_image($image_id)
{
  return query2array('
SELECT p.name, r.area_x, r.area_y, r.area_w, r.area_h, r.drawn_by
  FROM '.PERSONS_REGION_TABLE.' AS r
  JOIN '.PERSONS_TABLE.' AS p ON p.id = r.person_id
  WHERE r.image_id = '.(int)$image_id.'
;');
}

function persons_undo_answer($ok, $forbidden, $message)
{
  return array('ok' => $ok, 'forbidden' => $forbidden, 'message' => $message);
}

/**
 * @param array $outcome persons_apply_change()'s answer
 * @return array
 */
function persons_undo_outcome($outcome)
{
  return persons_undo_answer($outcome['ok'], false, $outcome['ok'] ? '' : $outcome['message']);
}
