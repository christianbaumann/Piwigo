<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The change-log entries for regions a user added or deleted, announced by
 * persons through persons_regions_changed. One entry per region; the person
 * tag persons adds or removes with it gets none (design "What gets a row").
 */

/**
 * The ids recorded by the latest persons_regions_changed of this request.
 *
 * @param array|null $ids a list to remember, null to read
 * @return int[]
 */
function photoinfo_regions_recorded($ids = null)
{
  static $recorded = array();

  if ($ids !== null)
  {
    $recorded = $ids;
  }

  return $recorded;
}

/**
 * @param int $image_id
 * @param array $added list of array(name, box, drawn_by)
 * @param array $removed same
 */
function photoinfo_regions_changed($image_id, $added, $removed)
{
  photoinfo_regions_recorded(array());

  if (!photoinfo_history_available())
  {
    return;
  }

  $source = photoinfo_referer_contains('admin.php') ? 'ADMIN_PHOTO' : 'PICTURE_PAGE';

  $ids = array();
  foreach (array(array($added, false), array($removed, true)) as $pair)
  {
    foreach ($pair[0] as $region)
    {
      $value = provenance_history_encode_region($region['name'], $region['box'], $region['drawn_by']);
      $id = photoinfo_history_insert($image_id, PROVENANCE_HISTORY_FIELD_REGION,
        $pair[1] ? $value : null, $pair[1] ? null : $value, $source);
      if ($id !== null)
      {
        $ids[] = $id;
      }
    }
  }

  photoinfo_regions_recorded($ids);
}

/**
 * Adds history_id to the answer of addRegion / deleteRegion.
 *
 * @param array $answer
 * @param int $image_id
 * @return array
 */
function photoinfo_regions_answer($answer, $image_id)
{
  if (photoinfo_history_available())
  {
    $ids = photoinfo_regions_recorded();
    $answer['history_id'] = empty($ids) ? null : $ids[0];
  }

  return $answer;
}
