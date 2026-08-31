<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Everything both region surfaces need to draw the same overlay.
 *
 * The public picture page and the admin tagging screen show the same boxes over
 * the same photo, drawn by the same overlay.js and editor.js. What differs is
 * only the page the markup lands on, so the region work sits here and neither
 * surface does coordinate arithmetic of its own.
 *
 * ---------------------------------------------------------------------------
 * Where the coordinate work happens.
 *
 * Every region reaches the browser already rotated into display orientation and
 * already converted from MWG's centre origin to a top-left corner, as plain
 * fractions of the displayed photo. The rotation and the conversion are the
 * pure helpers in functions.inc.php, which the unit suite covers - the overlay
 * script only multiplies those fractions by the element's measured box. A
 * second implementation of the region math in JavaScript would be a second
 * thing to keep correct, and the browser is the layer where that is hardest to
 * see going wrong.
 * ---------------------------------------------------------------------------
 */

include_once(PERSONS_PATH.'include/index.inc.php');
include_once(PERSONS_PATH.'include/exiftool.inc.php');

/**
 * Assigns everything template/public_overlay.tpl reads.
 *
 * Both surfaces call this and then place the markup themselves - the public
 * page through a prefilter that wraps the photo, the admin screen by rendering
 * its own template around it.
 *
 * @param int $image_id
 * @param array $image the photo's row; rotation, width and height are read from it
 * @return void
 */
function persons_assign_overlay($image_id, $image)
{
  global $template;

  // Not bailing on an empty region list: the first face on a photo is drawn
  // onto an empty stage, so a page without one is a photo nobody can ever tag.
  $rows = persons_indexed_regions($image_id);

  $rotation = isset($image['rotation']) ? (int)$image['rotation'] : 0;

  $boxes = array();
  $names = array();

  foreach ($rows as $row)
  {
    $box = persons_display_box($row, $rotation);
    if ($box === null)
    {
      continue;
    }

    $box['STALE'] = persons_region_is_stale(
      $row['applied_w'],
      $row['applied_h'],
      isset($image['width']) ? $image['width'] : null,
      isset($image['height']) ? $image['height'] : null
      );

    $box['URL'] = persons_person_gallery_url($row);

    $boxes[] = $box;

    if ($row['region_type'] == 'Face' and !isset($names[$row['name']]))
    {
      $names[$row['name']] = array(
        'NAME' => $row['name'],
        'URL'  => $box['URL'],
        );
    }
  }

  load_language('plugin.lang', PERSONS_PATH);

  $template->assign(array(
    'PERSONS_PATH'        => PERSONS_PATH,
    'PERSONS_BOXES'       => array_values($boxes),
    'PERSONS_NAMES'       => array_values($names),
    'PERSONS_STALE_TITLE' => l10n('Region may be out of date: the image was resized since it was tagged'),
    'PERSONS_IMAGE_ID'    => $image_id,
    'PERSONS_TOKEN'       => get_pwg_token(),
    'PERSONS_ROTATION'    => $rotation,
    'PERSONS_MIN_FRACTION' => PERSONS_MIN_BOX_FRACTION,
    // Probed here rather than discovered on the first failed save: an action
    // that can only fail should not be offered. One memoised subprocess per
    // render for a logged-in visitor is the price, and it is the same trade
    // plugins/provenance makes on its album screen.
    'PERSONS_EXIFTOOL'    => persons_exiftool_available(),
    ));
}

/**
 * One indexed region as the fractions of the *displayed* photo the overlay
 * needs, or null when the region does not belong on the picture at all.
 *
 * @param array $row a persons_indexed_regions() row
 * @param int $rotation images.rotation, the code 0..3
 * @return array|null id, name, left, top, w, h
 */
function persons_display_box($row, $rotation)
{
  $region = persons_rotate_region(
    array(
      'x' => (float)$row['area_x'],
      'y' => (float)$row['area_y'],
      'w' => (float)$row['area_w'],
      'h' => (float)$row['area_h'],
      ),
    $rotation
    );

  // MWG: a region whose centre is off the picture is dropped, one that merely
  // overruns an edge is clipped back to it.
  $region = persons_clip_region($region);
  if ($region === null)
  {
    return null;
  }

  $corner = persons_center_to_corner($region['x'], $region['y'], $region['w'], $region['h']);

  return array(
    'ID'   => (int)$row['id'],
    'NAME' => $row['name'],
    'TYPE' => $row['region_type'],
    'LEFT' => persons_percent($corner['left']),
    'TOP'  => persons_percent($corner['top']),
    'W'    => persons_percent($corner['w']),
    'H'    => persons_percent($corner['h']),
    );
}

/**
 * Where a person's name links to: the gallery page of their mirrored tag.
 *
 * That mirror is the whole reason browsing needs no new code. A person whose
 * tag row is gone - an administrator may delete a tag by hand - gets no link
 * rather than a broken one.
 *
 * @param array $row a persons_indexed_regions() row
 * @return string '' when there is nowhere to link to
 */
function persons_person_gallery_url($row)
{
  if (empty($row['tag_id']))
  {
    return '';
  }

  return make_index_url(array(
    'tags' => array(
      array(
        'id'       => (int)$row['tag_id'],
        'url_name' => $row['url_name'],
        ),
      ),
    ));
}
