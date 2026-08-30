<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Public-side injection. Pulled in only on the picture page, so the rest of the
 * gallery never loads it.
 *
 * Two things land on the page: a positioning wrapper around the photo carrying
 * the region boxes, and one row of names in the photo's information list.
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

  $rows = persons_indexed_regions($image_id);
  if (count($rows) == 0)
  {
    return;
  }

  // picture.php has already loaded the photo's row; no query of our own for it.
  $image = isset($picture['current']) ? $picture['current'] : array();
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

  if (count($boxes) == 0)
  {
    return;
  }

  load_language('plugin.lang', PERSONS_PATH);

  $template->assign(array(
    'PERSONS_PATH'        => PERSONS_PATH,
    'PERSONS_BOXES'       => array_values($boxes),
    'PERSONS_NAMES'       => array_values($names),
    'PERSONS_STALE_TITLE' => l10n('Region may be out of date: the image was resized since it was tagged'),
    ));

  $template->set_prefilter('picture', 'persons_picture_prefilter');
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

