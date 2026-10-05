<?php
defined('PHOTOEDIT_PATH') or die('Hacking attempt!');

/*
 * Pure functions: no database, no file, no Piwigo core. The unit suite loads
 * this file on its own.
 */

/** Quarter turns clockwise a request may ask for. */
define('PHOTOEDIT_MAX_TURNS', 3);

/** Seconds a write waits for another write to the same photo before giving up. */
define('PHOTOEDIT_LOCK_TIMEOUT_SECONDS', 30);
define('PHOTOEDIT_LOCK_RETRY_MICROSECONDS', 100000);

/** The shortest side, in pixels, a crop may leave. */
define('PHOTOEDIT_MIN_CROP_PX', 16);

/** JPEG quality for a re-encode when the source's own cannot be read. */
define('PHOTOEDIT_DEFAULT_JPEG_QUALITY', 95);

/**
 * Checks the edit a request asks for.
 *
 * $turns is a whole number of quarter turns clockwise, 0 to 3, relative to what
 * the page shows. $crop is empty for no crop, or "l,t,r,b" as fractions of the
 * turned view with l < r and t < b. An edit that neither turns nor crops is
 * refused: saving it would rewrite the file for nothing.
 *
 * @param mixed $turns as the request sent it
 * @param mixed $crop as the request sent it
 * @return array 'ok', 'error' (empty when ok), 'turns' (int), 'crop' (array l, t, r, b, or null)
 */
function photoedit_validate_request($turns, $crop)
{
  $refuse = function ($error)
  {
    return array('ok' => false, 'error' => $error, 'turns' => 0, 'crop' => null);
  };

  if (!is_scalar($turns) or !preg_match('/^\d+$/', trim((string)$turns)))
  {
    return $refuse('turns must be a whole number from 0 to '.PHOTOEDIT_MAX_TURNS);
  }
  $turns = (int)$turns;
  if ($turns > PHOTOEDIT_MAX_TURNS)
  {
    return $refuse('turns must be a whole number from 0 to '.PHOTOEDIT_MAX_TURNS);
  }

  $box = null;
  if (is_scalar($crop) and trim((string)$crop) !== '')
  {
    $parts = explode(',', (string)$crop);
    if (count($parts) != 4)
    {
      return $refuse('crop must be four fractions l,t,r,b');
    }

    $values = array();
    foreach ($parts as $part)
    {
      $part = trim($part);
      if (!is_numeric($part) or (float)$part < 0 or (float)$part > 1)
      {
        return $refuse('crop must be four fractions from 0 to 1');
      }
      $values[] = (float)$part;
    }

    list($l, $t, $r, $b) = $values;
    if ($l >= $r or $t >= $b)
    {
      return $refuse('crop must have l < r and t < b');
    }
    $box = array('l' => $l, 't' => $t, 'r' => $r, 'b' => $b);
  }
  elseif (!is_scalar($crop) and $crop !== null)
  {
    return $refuse('crop must be four fractions l,t,r,b');
  }

  if ($turns == 0 and $box === null)
  {
    return $refuse('nothing to do: neither turned nor cropped');
  }

  return array('ok' => true, 'error' => '', 'turns' => $turns, 'crop' => $box);
}

/**
 * Turns a box given as fractions of the photo, in quarter turns clockwise.
 *
 * One turn maps a point (x, y) to (1 - y, x), so the box's left edge becomes
 * its top and its bottom edge its left - the same turn
 * persons_rotate_region() applies to a centre and size.
 *
 * @param array $box 'l', 't', 'r', 'b' as fractions 0..1
 * @param int $turns quarter turns clockwise
 * @return array the turned box, same keys
 */
function photoedit_turn_box($box, $turns)
{
  $turns = ((int)$turns % 4 + 4) % 4;

  for ($i = 0; $i < $turns; $i++)
  {
    $box = array(
      'l' => 1 - $box['b'],
      't' => $box['l'],
      'r' => 1 - $box['t'],
      'b' => $box['r'],
      );
  }

  return $box;
}

/**
 * The photo's size after a number of quarter turns.
 *
 * @param int $width
 * @param int $height
 * @param int $turns
 * @return array width, height
 */
function photoedit_turned_size($width, $height, $turns)
{
  return ((int)$turns % 2 == 0)
    ? array((int)$width, (int)$height)
    : array((int)$height, (int)$width);
}

/**
 * Quarter turns clockwise from the raw file to the edited photo.
 *
 * images.rotation counts quarter turns *counter-clockwise*: core turns the
 * raw file by get_rotation_angle_from_code() through pwg_image::rotate(),
 * which turns counter-clockwise (EXIF Orientation 6, shown turned 90 degrees
 * clockwise, is code 3; measured 2026-10-05). The page shows the raw file
 * turned (4 - code) quarters clockwise, and the request's turns come on top.
 *
 * @param int $rotation_code images.rotation, 0..3
 * @param int $turns quarter turns clockwise relative to the page
 * @return int 0..3
 */
function photoedit_raw_turns($rotation_code, $turns)
{
  return ((4 - (int)$rotation_code % 4) % 4 + (int)$turns) % 4;
}

/**
 * The angle pwg_image::rotate() takes for a number of quarter turns clockwise.
 *
 * pwg_image::rotate() turns counter-clockwise (admin/include/image.class.php:
 * Imagick and ImageMagick are handed the negated angle, GD's imagerotate() is
 * counter-clockwise by definition), so a clockwise turn is the complement.
 *
 * @param int $turns
 * @return int 0, 90, 180 or 270
 */
function photoedit_rotate_angle($turns)
{
  return ((4 - ((int)$turns % 4)) % 4) * 90;
}

/**
 * A crop given as fractions of the turned photo, in whole pixels of it.
 *
 * Each edge is rounded to the nearest pixel. The fractions are already checked
 * to lie in 0..1, so a rounded edge cannot leave the photo. A frame that rounds
 * to the whole photo is no crop (rect null).
 *
 * @param array $box 'l', 't', 'r', 'b' as fractions 0..1, l < r and t < b
 * @param int $width the turned photo's width
 * @param int $height the turned photo's height
 * @return array 'ok', 'error' (empty when ok), 'rect' ('x', 'y', 'w', 'h', or null)
 */
function photoedit_crop_rect($box, $width, $height)
{
  $x = (int)round($box['l'] * $width);
  $y = (int)round($box['t'] * $height);
  $w = (int)round($box['r'] * $width) - $x;
  $h = (int)round($box['b'] * $height) - $y;

  if ($w < PHOTOEDIT_MIN_CROP_PX or $h < PHOTOEDIT_MIN_CROP_PX)
  {
    return array(
      'ok' => false,
      'error' => 'crop must leave at least '.PHOTOEDIT_MIN_CROP_PX.' pixels on each side',
      'rect' => null,
      );
  }

  $rect = ($w == $width and $h == $height)
    ? null
    : array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);

  return array('ok' => true, 'error' => '', 'rect' => $rect);
}

/**
 * What a validated request does to a photo of a given size: turn first, then
 * crop the turned photo.
 *
 * A stored rotation is baked into the pixels as well, so the raw file turns
 * by photoedit_raw_turns(); the crop is drawn on what the page then shows,
 * which is the raw file turned that far.
 *
 * @param int $width the raw file's width
 * @param int $height the raw file's height
 * @param int $turns quarter turns clockwise relative to the page
 * @param array|null $box the crop as fractions of the turned photo, or null
 * @param int $rotation_code images.rotation before the edit
 * @return array 'ok', 'error' (empty when ok), 'transform' ('rotation_before',
 *   'turns', 'raw_turns', 'crop_px' or null, 'width_before', 'height_before',
 *   'width_after', 'height_after')
 */
function photoedit_plan_edit($width, $height, $turns, $box, $rotation_code = 0)
{
  $raw_turns = photoedit_raw_turns($rotation_code, $turns);
  list($turned_width, $turned_height) = photoedit_turned_size($width, $height, $raw_turns);

  $rect = null;
  if ($box !== null)
  {
    $crop = photoedit_crop_rect($box, $turned_width, $turned_height);
    if (!$crop['ok'])
    {
      return array('ok' => false, 'error' => $crop['error'], 'transform' => null);
    }
    $rect = $crop['rect'];
  }

  // Whether the page would show anything new: a stored rotation the turns
  // undo still leaves the file to rewrite upright.
  if ((int)$turns == 0 and $rect === null)
  {
    return array('ok' => false, 'error' => 'nothing to do: neither turned nor cropped', 'transform' => null);
  }

  return array(
    'ok' => true,
    'error' => '',
    'transform' => array(
      'rotation_before' => (int)$rotation_code,
      'turns' => (int)$turns,
      'raw_turns' => $raw_turns,
      'crop_px' => $rect,
      'width_before' => (int)$width,
      'height_before' => (int)$height,
      'width_after' => $rect === null ? $turned_width : $rect['w'],
      'height_after' => $rect === null ? $turned_height : $rect['h'],
      ),
    );
}

/**
 * A box given as fractions of the turned photo, as fractions of the crop.
 *
 * The part outside the crop is cut off; a box with nothing left inside it -
 * touching the crop's edge is nothing - becomes null.
 *
 * @param array $box 'l', 't', 'r', 'b' as fractions of the turned photo
 * @param array $rect the crop, 'x', 'y', 'w', 'h' in pixels of the turned photo
 * @param int $width the turned photo's width
 * @param int $height the turned photo's height
 * @return array|null
 */
function photoedit_crop_box($box, $rect, $width, $height)
{
  $l = max($box['l'] * $width, $rect['x']);
  $t = max($box['t'] * $height, $rect['y']);
  $r = min($box['r'] * $width, $rect['x'] + $rect['w']);
  $b = min($box['b'] * $height, $rect['y'] + $rect['h']);

  if ($l >= $r or $t >= $b)
  {
    return null;
  }

  return array(
    'l' => ($l - $rect['x']) / $rect['w'],
    't' => ($t - $rect['y']) / $rect['h'],
    'r' => ($r - $rect['x']) / $rect['w'],
    'b' => ($b - $rect['y']) / $rect['h'],
    );
}

/**
 * Turns and crops a stored centre of interest - four characters a..z for
 * l, t, r, b, see admin/picture_coi.php - with the photo. One left wholly
 * outside the crop is dropped.
 *
 * The centre of interest is drawn on the shown photo, and i.php applies it
 * after turning (i.php: rotate, then crop), so it turns by the request's
 * turns only, never by the stored rotation.
 *
 * Uses core's char_to_fraction() / fraction_to_char()
 * (include/derivative_params.inc.php, loaded on every request).
 *
 * @param string|null $coi
 * @param array $transform as photoedit_plan_edit() returns it
 * @return string|null
 */
function photoedit_transform_coi($coi, $transform)
{
  if (empty($coi))
  {
    return null;
  }

  $box = photoedit_turn_box(
    array(
      'l' => char_to_fraction($coi[0]),
      't' => char_to_fraction($coi[1]),
      'r' => char_to_fraction($coi[2]),
      'b' => char_to_fraction($coi[3]),
      ),
    $transform['turns']
    );

  if ($transform['crop_px'] !== null)
  {
    list($width, $height) = photoedit_turned_size($transform['width_before'], $transform['height_before'], $transform['raw_turns']);
    $box = photoedit_crop_box($box, $transform['crop_px'], $width, $height);
    if ($box === null)
    {
      return null;
    }
  }

  return fraction_to_char($box['l']).fraction_to_char($box['t']).fraction_to_char($box['r']).fraction_to_char($box['b']);
}

/**
 * A photo's URL with the version of its file appended.
 *
 * An edit keeps the photo's path, so without this its derivatives and file
 * would be served at the URLs a browser already cached before the edit. Only
 * a plain URL is changed: one with a query already is i.php's, which reads
 * the query as its path.
 *
 * @param string $url
 * @param string $version empty for a photo never edited
 * @return string
 */
function photoedit_versioned_url($url, $version)
{
  if ($version === '' or strpos($url, '?') !== false)
  {
    return $url;
  }

  return $url.'?v='.$version;
}

/**
 * The photoedit_versions config row: each edited photo's file version by id.
 *
 * @param mixed $json the row's value, or null when there is none
 * @return array
 */
function photoedit_decode_versions($json)
{
  $versions = is_string($json) ? json_decode($json, true) : null;

  return is_array($versions) ? $versions : array();
}
