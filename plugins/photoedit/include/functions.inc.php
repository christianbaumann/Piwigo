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
 * Turns a stored centre of interest - four characters a..z for l, t, r, b,
 * see admin/picture_coi.php - with the photo.
 *
 * Uses core's char_to_fraction() / fraction_to_char()
 * (include/derivative_params.inc.php, loaded on every request).
 *
 * @param string|null $coi
 * @param int $turns
 * @return string|null
 */
function photoedit_turn_coi($coi, $turns)
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
    $turns
    );

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
