<?php
defined('PERSONS_PATH') or die('Hacking attempt!');

/*
 * Pure helpers and constants. This file declares functions and constants and
 * nothing else, so the unit suite can include it with no database and no Piwigo
 * bootstrap. maintain.class.php, the web-service handlers and the tests all read
 * the schema and the coordinate rules from here rather than each carrying a copy.
 *
 * ---------------------------------------------------------------------------
 * The coordinate contract, stated once and referenced everywhere else.
 *
 * Stored: normalized [0..1], CENTER origin, PRE-rotation. That is what MWG
 * declares normative, and what every other reader of these files expects.
 * Rendering converts to a top-left corner and multiplies by the element's
 * measured box; nothing else in this plugin invents a second convention.
 * ---------------------------------------------------------------------------
 */

/** Width of piwigo_persons.name and .url_name. */
define('PERSONS_NAME_MAX_BYTES', 255);

/**
 * The smallest box that may be drawn, as a fraction of each axis.
 *
 * A fraction rather than a pixel count on purpose: the picture page renders a
 * derivative whose pixel size changes with the window, so a pixel minimum would
 * mean a different real-world box at every width.
 */
define('PERSONS_MIN_BOX_FRACTION', 0.01);

/**
 * How far a region's AppliedToDimensions aspect ratio may drift from the
 * image's current one before the region is shown as possibly out of date.
 *
 * Not zero: a proportional resize goes through integer pixel dimensions, so
 * 4000x3000 -> 1999x1499 is the same picture with a ratio that no longer
 * matches to the last decimal.
 */
define('PERSONS_STALE_RATIO_TOLERANCE', 0.02);

/** Persons offered in the picker before the user has typed anything. */
define('PERSONS_PICKER_RECENT_LIMIT', 10);

/** Ceiling on a search result set. A wider net is capped, never refused. */
define('PERSONS_SEARCH_MAX_RESULTS', 25);

/** Photos one write-back or rescan request handles, so none can time out. */
define('PERSONS_WRITEBACK_MAX_CHUNK', 10);

/**
 * The config row recording when the index was last rebuilt from the files.
 *
 * The admin screen's only answer to "is what I am looking at current?". Written
 * by every rescan call, dropped on uninstall.
 */
define('PERSONS_LAST_RESCAN_PARAM', 'persons_last_rescan');

/** Seconds a writer waits for another writer's lock on the same file. */
define('PERSONS_LOCK_TIMEOUT_SECONDS', 30);

/** How often a waiting writer retries the lock it could not take. */
define('PERSONS_LOCK_RETRY_MICROSECONDS', 50000);

/*
 * Scratch space, mirroring plugins/provenance. Both are safe to delete when
 * nothing is writing. Derived from PERSONS_PATH rather than PHPWG_ROOT_PATH so
 * this file still loads with no Piwigo bootstrap, which is what lets the unit
 * suite include it.
 */
define('PERSONS_LOCK_DIR',
  dirname(dirname(rtrim(PERSONS_PATH, '/'))) . '/_data/persons/locks/');
define('PERSONS_ARGS_DIR',
  dirname(dirname(rtrim(PERSONS_PATH, '/'))) . '/_data/persons/args/');

/*
 * ---------------------------------------------------------------------------
 * The public picture page.
 *
 * Two prefilter anchors, both plain string matches against
 * themes/default/template/picture.tpl. tests/Unit/PicturePageAnchorTest.php is
 * the guard: a moved anchor injects nothing and the page renders perfectly
 * without the feature, which no other layer would report.
 * ---------------------------------------------------------------------------
 */

/**
 * The overlay's anchor: the photo element itself.
 *
 * The prefilter *wraps* this one rather than prepending to it. Nothing above
 * #theMainImage declares position: relative, so the wrapper is what gives the
 * absolutely positioned boxes a box to be positioned in.
 */
define('PERSONS_TPL_INJECT_POINT', '{$ELEMENT_CONTENT}');

/**
 * The person row's anchor: the close of <dl id="standard">.
 *
 * The same point plugins/provenance injects at. Both prepend and keep the
 * anchor, so whichever prefilter runs second still finds it.
 */
define('PERSONS_TPL_ROW_INJECT_POINT', "{/strip}\n</dl>");

/*
 * ---------------------------------------------------------------------------
 * The admin photo screen.
 *
 * One more prefilter anchor, matched against
 * admin/themes/default/template/picture_modify.tpl.
 * tests/Unit/PhotoModifyAnchorTest.php is its guard, for the same reason as the
 * two above: a moved anchor leaves the screen rendering perfectly, without the
 * only link to the tagging screen.
 * ---------------------------------------------------------------------------
 */

/**
 * The link's anchor: the action bar beside the photo screen's thumbnail.
 *
 * The prefilter keeps the anchor and puts the link straight after it, so the
 * link joins the row of icons the screen already offers. That thumbnail is far
 * too small to box a face on, which is why the link goes to a screen of its own
 * rather than the editor being injected here.
 */
define('PERSONS_TPL_PHOTO_ANCHOR', "<div class='picture-preview-actions'>");

/** The config parameter holding the picture page's row-visibility map. */
define('PERSONS_DISPLAY_INFO_PARAM', 'picture_informations');

/** This plugin's key inside that map. */
define('PERSONS_DISPLAY_INFO_KEY', 'persons');

/**
 * The person index table, as column name => SQL definition.
 *
 * id is a mediumint deliberately. The whole reason persons are not simply
 * piwigo_tags rows is that piwigo_tags.id is a smallint, and a gallery can hold
 * more people than that ceiling allows.
 *
 * @return array
 */
function persons_person_columns()
{
  return array(
    'id'           => 'mediumint(8) unsigned NOT NULL AUTO_INCREMENT',
    'name'         => 'varchar(' . PERSONS_NAME_MAX_BYTES . ') NOT NULL',
    'url_name'     => 'varchar(' . PERSONS_NAME_MAX_BYTES . ') BINARY DEFAULT NULL',
    'tag_id'       => 'smallint(5) unsigned DEFAULT NULL',
    'lastmodified' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    );
}

/**
 * The region index table, as column name => SQL definition.
 *
 * Every row here is derived: it is rebuilt from what the image file says, and
 * the table may be dropped and rescanned at any time without losing anything.
 *
 * area_* are the stored coordinate contract above. applied_* record the
 * AppliedToDimensions the region was written against, which is the only way to
 * tell a resized image from an untouched one. rotation_at_write records
 * images.rotation at the same moment, which is the only way to tell a changed
 * display transform from a physically rotated file.
 *
 * @return array
 */
function persons_region_columns()
{
  return array(
    'id'                => 'int(10) unsigned NOT NULL AUTO_INCREMENT',
    'image_id'          => 'mediumint(8) unsigned NOT NULL',
    'person_id'         => 'mediumint(8) unsigned NOT NULL',
    'area_x'            => 'double NOT NULL',
    'area_y'            => 'double NOT NULL',
    'area_w'            => 'double NOT NULL',
    'area_h'            => 'double NOT NULL',
    'applied_w'         => 'int(10) unsigned DEFAULT NULL',
    'applied_h'         => 'int(10) unsigned DEFAULT NULL',
    'rotation_at_write' => 'tinyint(3) unsigned DEFAULT NULL',
    'region_type'       => "enum('" . implode("','", persons_region_types()) . "') NOT NULL DEFAULT 'Face'",
    'source'            => "enum('" . implode("','", persons_region_sources()) . "') NOT NULL DEFAULT 'piwigo'",
    );
}

/**
 * The MWG region types. Face is what this plugin writes; the rest exist because
 * a file may already carry them and a merge must never drop one.
 *
 * @return array
 */
function persons_region_types()
{
  return array('Face', 'Pet', 'Focus', 'BarCode');
}

/**
 * Who wrote a region. 'foreign' marks one this plugin found in a file and did
 * not create, which is what keeps a merge from treating it as ours to rewrite.
 *
 * @return array
 */
function persons_region_sources()
{
  return array('piwigo', 'foreign');
}

/*
 * ---------------------------------------------------------------------------
 * Coordinate math. Pure, and the single implementation of the contract above.
 * ---------------------------------------------------------------------------
 */

/**
 * MWG center origin -> top-left corner, both normalized.
 *
 * @param float $x centre x
 * @param float $y centre y
 * @param float $w
 * @param float $h
 * @return array left, top, w, h
 */
function persons_center_to_corner($x, $y, $w, $h)
{
  return array(
    'left' => $x - $w / 2,
    'top'  => $y - $h / 2,
    'w'    => $w,
    'h'    => $h,
    );
}

/**
 * Top-left corner -> MWG center origin, both normalized. The inverse of
 * persons_center_to_corner().
 *
 * @param float $l
 * @param float $t
 * @param float $w
 * @param float $h
 * @return array x, y, w, h
 */
function persons_corner_to_center($l, $t, $w, $h)
{
  return array(
    'x' => $l + $w / 2,
    'y' => $t + $h / 2,
    'w' => $w,
    'h' => $h,
    );
}

/**
 * Applies the MWG rule for a region that does not fit the image.
 *
 * A region whose CENTRE is outside [0..1] describes a subject that is not in
 * the picture at all and is dropped. A region whose centre is inside but whose
 * box overruns an edge is clipped to the edge - the subject is there, the
 * writer merely recorded a box larger than the frame.
 *
 * @param array $region x, y, w, h (plus any other keys, preserved)
 * @return array|null the clipped region, or null when it must be dropped
 */
function persons_clip_region($region)
{
  $x = (float)$region['x'];
  $y = (float)$region['y'];
  $w = (float)$region['w'];
  $h = (float)$region['h'];

  if ($x < 0 or $x > 1 or $y < 0 or $y > 1)
  {
    return null;
  }

  if ($w <= 0 or $h <= 0)
  {
    return null;
  }

  $corner = persons_center_to_corner($x, $y, $w, $h);

  $left   = max(0, $corner['left']);
  $top    = max(0, $corner['top']);
  $right  = min(1, $corner['left'] + $corner['w']);
  $bottom = min(1, $corner['top'] + $corner['h']);

  // A box that fits is returned untouched rather than recomputed. The round
  // trip through the corner form is not the identity in binary floating point -
  // 0.5 +/- 0.1/2 comes back as 0.10000000000000003 - and since these numbers
  // are written into the file as text, recomputing a region nobody clipped puts
  // a different value on disk than the one the user drew, in every file.
  if ($left === $corner['left'] and $top === $corner['top']
      and $right === $corner['left'] + $corner['w']
      and $bottom === $corner['top'] + $corner['h'])
  {
    return array_merge($region, array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h));
  }

  $centred = persons_corner_to_center($left, $top, $right - $left, $bottom - $top);

  return array_merge($region, $centred);
}

/**
 * Rotates a normalized centre-origin region by an images.rotation code.
 *
 * images.rotation is a code 0..3 meaning quarter turns clockwise, not degrees -
 * see include/derivative.inc.php:74-92, which reads it the same way and swaps
 * width and height for the odd codes. Anything outside 0..3 is taken modulo 4,
 * matching core; a negative code is treated as no rotation, because core never
 * produces one and guessing a direction for it would be inventing behaviour.
 *
 * @param array $region x, y, w, h (plus any other keys, preserved)
 * @param int $rotation_code
 * @return array
 */
function persons_rotate_region($region, $rotation_code)
{
  $code = (int)$rotation_code;

  if ($code < 0)
  {
    $code = 0;
  }
  $code = $code % 4;

  $x = (float)$region['x'];
  $y = (float)$region['y'];
  $w = (float)$region['w'];
  $h = (float)$region['h'];

  for ($turn = 0; $turn < $code; $turn++)
  {
    // One quarter turn clockwise: (x, y) -> (1 - y, x), and the axes swap.
    $rotated = array('x' => 1 - $y, 'y' => $x, 'w' => $h, 'h' => $w);
    $x = $rotated['x'];
    $y = $rotated['y'];
    $w = $rotated['w'];
    $h = $rotated['h'];
  }

  return array_merge($region, array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h));
}

/**
 * Whether a region's recorded AppliedToDimensions no longer describes the image.
 *
 * Compared as an aspect ratio, not as pixel counts: a proportional downscale
 * leaves every region correct and must not be flagged, while a crop moves every
 * region and must be. Unknown applied dimensions are never called stale -
 * digiKam omits AppliedToDimensions entirely (bug 429219), and treating absent
 * as wrong would flag every file it wrote.
 *
 * @param int|null $applied_w
 * @param int|null $applied_h
 * @param int|null $image_w
 * @param int|null $image_h
 * @return bool
 */
function persons_region_is_stale($applied_w, $applied_h, $image_w, $image_h)
{
  $applied_w = (float)$applied_w;
  $applied_h = (float)$applied_h;
  $image_w   = (float)$image_w;
  $image_h   = (float)$image_h;

  if ($applied_w <= 0 or $applied_h <= 0 or $image_w <= 0 or $image_h <= 0)
  {
    return false;
  }

  $applied_ratio = $applied_w / $applied_h;
  $image_ratio   = $image_w / $image_h;

  return abs($applied_ratio - $image_ratio) > PERSONS_STALE_RATIO_TOLERANCE;
}

/**
 * The rotation the stored regions must be corrected by, or 0 for none.
 *
 * Two different events look alike in the database and must not be conflated:
 *
 *   images.rotation changed but the file's dimensions still match what the
 *   regions were written against - only the DISPLAY transform changed. MWG
 *   stores regions prior to Exif Orientation, so the stored regions are still
 *   correct and rewriting them would corrupt them.
 *
 *   the file's dimensions are the TRANSPOSE of applied_w/applied_h - the file
 *   was physically rotated by something outside Piwigo, and every region has to
 *   turn with it.
 *
 * Anything else (a crop, a non-proportional resize) is left alone; staleness
 * already covers it.
 *
 * @param int|null $stored_rotation images.rotation when the regions were written
 * @param int|null $current_rotation images.rotation now
 * @param int|null $applied_w AppliedToDimensions width at write time
 * @param int|null $applied_h AppliedToDimensions height at write time
 * @param int|null $file_w the file's width now
 * @param int|null $file_h the file's height now
 * @return int 0..3
 */
function persons_rotation_delta($stored_rotation, $current_rotation,
                                $applied_w, $applied_h, $file_w, $file_h)
{
  $applied_w = (int)$applied_w;
  $applied_h = (int)$applied_h;
  $file_w    = (int)$file_w;
  $file_h    = (int)$file_h;

  if ($applied_w <= 0 or $applied_h <= 0 or $file_w <= 0 or $file_h <= 0)
  {
    return 0;
  }

  // A square image transposes onto itself, so a physical rotation of one is
  // indistinguishable from no rotation at all. Reporting a delta here would be
  // a guess, and a wrong guess rewrites correct data.
  if ($applied_w === $applied_h)
  {
    return 0;
  }

  if (!($file_w === $applied_h and $file_h === $applied_w))
  {
    return 0;
  }

  $delta = ((int)$current_rotation - (int)$stored_rotation) % 4;
  if ($delta < 0)
  {
    $delta += 4;
  }

  return $delta;
}

/*
 * ---------------------------------------------------------------------------
 * Names and input validation.
 * ---------------------------------------------------------------------------
 */

/**
 * A person's name as it may be stored: no markup, no newlines, single spaces,
 * and short enough for the column.
 *
 * Newlines are flattened rather than rejected because the name reaches exiftool
 * inside a JSON argfile, where a raw newline is a different value than the one
 * the user typed.
 *
 * @param string $raw
 * @return string the cleaned name, or '' when nothing usable is left
 */
function persons_clean_name($raw)
{
  $name = strip_tags((string)$raw);
  $name = preg_replace('/\s+/u', ' ', $name);
  $name = trim((string)$name);

  if ($name === '')
  {
    return '';
  }

  if (strlen($name) > PERSONS_NAME_MAX_BYTES)
  {
    // Cut on a character boundary, never mid-sequence: a truncated UTF-8
    // sequence is not a shorter name, it is an invalid string.
    $name = mb_strcut($name, 0, PERSONS_NAME_MAX_BYTES, 'UTF-8');
    $name = trim($name);
  }

  return $name;
}

/**
 * Whether a value is usable as one of the normalized coordinates.
 *
 * @param mixed $value
 * @return bool
 */
function persons_is_valid_normalized($value)
{
  if (is_bool($value) or is_array($value) or $value === null or $value === '')
  {
    return false;
  }

  if (!is_numeric($value))
  {
    return false;
  }

  $number = (float)$value;

  if (!is_finite($number))
  {
    return false;
  }

  return $number >= 0 and $number <= 1;
}

/**
 * Whether a drawn box is large enough to be a deliberate box rather than a
 * stray click. Both axes must clear the minimum, not either one.
 *
 * @param float $w
 * @param float $h
 * @return bool
 */
function persons_minimum_box_ok($w, $h)
{
  return (float)$w >= PERSONS_MIN_BOX_FRACTION and (float)$h >= PERSONS_MIN_BOX_FRACTION;
}

/*
 * ---------------------------------------------------------------------------
 * Working-area paths. Pure string builders; nothing here touches the disk.
 * ---------------------------------------------------------------------------
 */

/**
 * The lock file guarding one image against a concurrent exiftool write.
 *
 * A separate file, never the image itself: exiftool replaces the image by
 * rename, so a lock held on the old inode would exclude nothing from the second
 * writer onwards.
 *
 * @param string $image_path the path as piwigo_images stores it
 * @return string
 */
function persons_lock_path($image_path)
{
  return PERSONS_LOCK_DIR . sha1($image_path) . '.lock';
}

/**
 * A name for one write operation, unique enough that two requests never share
 * a working directory.
 *
 * @return string
 */
function persons_operation_id()
{
  return bin2hex(random_bytes(8));
}

/**
 * The directory holding one write operation's exiftool argfiles.
 *
 * Per operation rather than per file, so a crashed run leaves at most one
 * directory behind instead of orphan files nobody can attribute.
 *
 * @param string $operation_id
 * @return string
 */
function persons_operation_dir($operation_id)
{
  return PERSONS_ARGS_DIR . $operation_id . '/';
}

/*
 * ---------------------------------------------------------------------------
 * Reading what a file says. Pure: exiftool.inc.php shells out and hands the
 * decoded JSON here, so every shape a writer can produce is unit-testable with
 * no exiftool present.
 * ---------------------------------------------------------------------------
 */

/**
 * Turns exiftool's decoded -json -struct output into this plugin's shape.
 *
 * Reports what the file says; it does not decide what is worth keeping. A
 * region this plugin cannot index - one with no name, coordinates in a unit it
 * does not understand, or a Type outside the MWG schema - comes back verbatim
 * under 'unusable' so a later merge writes it out again untouched. Dropping it
 * here would delete another tool's data on the first write persons makes.
 *
 * @param mixed $decoded json_decode(..., true) of the exiftool output: the
 *   top-level list, a single file's object, or null when the decode failed
 * @return array array(
 *   'applied'  => array('w' => int|null, 'h' => int|null),
 *   'regions'  => list of array(name, x, y, w, h, type, source),
 *   'unusable' => the raw RegionList entries that could not be indexed,
 *   'names'    => XMP-iptcExt:PersonInImage as the file holds it,
 *   )
 */
function persons_parse_regioninfo($decoded)
{
  $empty = array(
    'applied'  => array('w' => null, 'h' => null),
    'regions'  => array(),
    'unusable' => array(),
    'names'    => array(),
    );

  if (!is_array($decoded))
  {
    return $empty;
  }

  // -json emits a list of one object per file; a caller that already unwrapped
  // it hands over the object itself.
  if (isset($decoded[0]) and is_array($decoded[0]))
  {
    $decoded = $decoded[0];
  }

  $result = $empty;

  if (isset($decoded['PersonInImage']))
  {
    // A single value arrives as a bare string rather than a one-element list.
    $names = is_array($decoded['PersonInImage'])
      ? $decoded['PersonInImage']
      : array($decoded['PersonInImage']);

    foreach ($names as $name)
    {
      if (is_scalar($name) and (string)$name !== '')
      {
        $result['names'][] = (string)$name;
      }
    }
  }

  if (!isset($decoded['RegionInfo']) or !is_array($decoded['RegionInfo']))
  {
    return $result;
  }
  $info = $decoded['RegionInfo'];

  if (isset($info['AppliedToDimensions']) and is_array($info['AppliedToDimensions']))
  {
    $applied = $info['AppliedToDimensions'];
    $width  = persons_positive_int_or_null(isset($applied['W']) ? $applied['W'] : null);
    $height = persons_positive_int_or_null(isset($applied['H']) ? $applied['H'] : null);

    // Known only as a pair: everything downstream compares the two as a ratio,
    // so half of it is not half an answer, it is a misleading one. Absent stays
    // null - a zero would make every digiKam-written file look infinitely stale
    // (KDE bug 429219 omits AppliedToDimensions entirely).
    if ($width !== null and $height !== null)
    {
      $result['applied']['w'] = $width;
      $result['applied']['h'] = $height;
    }
  }

  if (!isset($info['RegionList']) or !is_array($info['RegionList']))
  {
    return $result;
  }
  $list = $info['RegionList'];

  // One region written as a bare object rather than a one-element list.
  if (isset($list['Area']) or isset($list['Name']))
  {
    $list = array($list);
  }

  foreach ($list as $raw)
  {
    if (!is_array($raw))
    {
      continue;
    }

    $region = persons_region_from_regionlist_entry($raw);

    if ($region === null)
    {
      $result['unusable'][] = $raw;
    }
    else
    {
      $result['regions'][] = $region;
    }
  }

  return $result;
}

/**
 * One RegionList entry as an indexable region, or null when it is not one.
 *
 * @param array $raw
 * @return array|null
 */
function persons_region_from_regionlist_entry($raw)
{
  $name = isset($raw['Name']) && is_scalar($raw['Name']) ? trim((string)$raw['Name']) : '';
  if ($name === '')
  {
    // A detected but unconfirmed face. This plugin has no unconfirmed state.
    return null;
  }

  // MWG makes Type optional and Face the default. A type outside the schema is
  // left alone rather than coerced: the region_type column is an ENUM of the
  // four MWG types, and MySQL turns anything else into '' - a row that claims
  // nothing at all.
  $type = isset($raw['Type']) && is_scalar($raw['Type']) ? (string)$raw['Type'] : 'Face';
  if (!in_array($type, persons_region_types(), true))
  {
    return null;
  }

  if (!isset($raw['Area']) or !is_array($raw['Area']))
  {
    return null;
  }
  $area = $raw['Area'];

  // MWG's default unit is normalized; anything else is in a coordinate system
  // this plugin cannot convert without knowing the writer's reference frame.
  $unit = isset($area['Unit']) && is_scalar($area['Unit']) ? (string)$area['Unit'] : 'normalized';
  if ($unit !== 'normalized')
  {
    return null;
  }

  $coordinates = array();
  foreach (array('x' => 'X', 'y' => 'Y', 'w' => 'W', 'h' => 'H') as $key => $tag)
  {
    // Values arrive as JSON strings from some writers - the bug Immich fixed in
    // PR #29333. is_numeric() accepts both; a string comparison would not.
    if (!isset($area[$tag]) or !is_numeric($area[$tag]))
    {
      return null;
    }
    $coordinates[$key] = (float)$area[$tag];
  }

  return array(
    'name'   => $name,
    'x'      => $coordinates['x'],
    'y'      => $coordinates['y'],
    'w'      => $coordinates['w'],
    'h'      => $coordinates['h'],
    'type'   => $type,
    // 'piwigo' is what this plugin may rewrite. Every other MWG type belongs to
    // whatever wrote it, and a merge leaves it alone.
    'source' => $type === 'Face' ? 'piwigo' : 'foreign',
    );
}

/**
 * A dimension as a positive int, or null when it is absent or unusable.
 *
 * @param mixed $value
 * @return int|null
 */
function persons_positive_int_or_null($value)
{
  if (!is_numeric($value))
  {
    return null;
  }

  $number = (int)$value;

  return $number > 0 ? $number : null;
}

/*
 * ---------------------------------------------------------------------------
 * The merge. Regions live in the image file and nowhere else, so this is the
 * one function here whose bug loses data outright: a write that rebuilt the
 * region list out of what this plugin understands would delete every region it
 * does not. Pure, so the whole table of cases is a unit test.
 * ---------------------------------------------------------------------------
 */

/**
 * How close two coordinates must be to describe the same box.
 *
 * Two orders of magnitude below the smallest box that may be drawn, so no two
 * distinct boxes can collide, and far above the error of a JSON round trip
 * through exiftool.
 */
define('PERSONS_REGION_MATCH_EPSILON', 1e-6);

/**
 * Builds the complete RegionInfo and PersonInImage to write into a file.
 *
 * Everything the file already holds is carried across untouched unless $remove
 * names it - including the entries the parser could not index, which are
 * written back verbatim. The only regions this function invents are the ones in
 * $add.
 *
 * @param array $existing the persons_parse_regioninfo() shape read from the file
 * @param array $add list of array(name, x, y, w, h, type) to add
 * @param array $remove list of matchers: array(name) removes every box of that
 *   person, array(name, x, y, w, h) removes just that box
 * @param int|null $applied_w the image's current width, or null when unknown
 * @param int|null $applied_h
 * @return array array('regioninfo' => array, 'names' => array). An empty array
 *   for either means "delete this tag", which is what exiftool's -json= reads a
 *   JSON [] as. A file carrying a RegionInfo with an empty RegionList claims to
 *   have been examined and found nobody - a different statement from a file
 *   that was never tagged, and one every other reader would have to
 *   special-case. Measured 2026-08-30 with exiftool 13.25: "" writes an empty
 *   structure rather than deleting, and null writes a literal null into the
 *   name list.
 */
function persons_merge_regions($existing, $add, $remove, $applied_w, $applied_h)
{
  $existing_regions = isset($existing['regions']) ? $existing['regions'] : array();
  $unusable         = isset($existing['unusable']) ? $existing['unusable'] : array();
  $existing_names   = isset($existing['names']) ? $existing['names'] : array();

  $kept = array();
  $dropped_names = array();

  foreach ($existing_regions as $region)
  {
    if (persons_region_matches_any($region, $remove))
    {
      $dropped_names[$region['name']] = true;
      continue;
    }
    $kept[] = $region;
  }

  foreach ($add as $region)
  {
    $region = persons_prepare_region_for_write($region);
    if ($region === null)
    {
      continue;
    }

    // The same box for the same person, added twice, is one region. Replacing
    // rather than skipping lets an add correct a region's type.
    $kept = array_values(array_filter(
      $kept,
      function ($existing_region) use ($region)
      {
        return !persons_region_matches($existing_region, $region);
      }
      ));

    $kept[] = $region;
  }

  $list = array();
  foreach ($kept as $region)
  {
    $list[] = array(
      'Area' => array(
        'X'    => $region['x'],
        'Y'    => $region['y'],
        'W'    => $region['w'],
        'H'    => $region['h'],
        'Unit' => 'normalized',
        ),
      'Name' => $region['name'],
      'Type' => $region['type'],
      );
  }

  // Verbatim, and last: MWG gives the list no meaningful order, and the parser
  // did not record where in the file these sat.
  foreach ($unusable as $entry)
  {
    $list[] = $entry;
  }

  return array(
    'regioninfo' => persons_build_regioninfo($list, $applied_w, $applied_h),
    'names'      => persons_merge_person_names($existing_names, $kept, $dropped_names),
    );
}

/**
 * The RegionInfo structure for a region list, or the empty array that asks
 * exiftool to delete the tag when there is none.
 *
 * @param array $list the RegionList entries
 * @param int|null $applied_w
 * @param int|null $applied_h
 * @return array
 */
function persons_build_regioninfo($list, $applied_w, $applied_h)
{
  if (count($list) == 0)
  {
    return array();
  }

  $info = array();

  $width  = persons_positive_int_or_null($applied_w);
  $height = persons_positive_int_or_null($applied_h);

  // Known only as a pair, and omitted rather than zeroed when unknown: a 0
  // would make every reader treat the regions as infinitely stale.
  if ($width !== null and $height !== null)
  {
    $info['AppliedToDimensions'] = array('W' => $width, 'H' => $height, 'Unit' => 'pixel');
  }

  $info['RegionList'] = $list;

  return $info;
}

/**
 * The PersonInImage list to write.
 *
 * A name the file carries that no region backs is left alone - some tools write
 * the list on its own, and it is not this plugin's to delete. A name is dropped
 * only when the regions carrying it were the ones just removed.
 *
 * @param array $existing_names PersonInImage as the file holds it
 * @param array $kept the regions that survive the merge
 * @param array $dropped_names name => true for every region removed
 * @return array empty asks exiftool to delete the tag
 */
function persons_merge_person_names($existing_names, $kept, $dropped_names)
{
  $final = array();
  foreach ($kept as $region)
  {
    if ($region['type'] === 'Face')
    {
      $final[$region['name']] = true;
    }
  }

  $names = array();
  foreach ($existing_names as $name)
  {
    if (isset($dropped_names[$name]) and !isset($final[$name]))
    {
      continue;
    }
    if (!in_array($name, $names, true))
    {
      $names[] = $name;
    }
  }

  foreach (array_keys($final) as $name)
  {
    if (!in_array($name, $names, true))
    {
      $names[] = $name;
    }
  }

  return $names;
}

/**
 * A caller's region as it may be written, or null when it may not be.
 *
 * @param array $region name, x, y, w, h and optionally type
 * @return array|null
 */
function persons_prepare_region_for_write($region)
{
  $name = persons_clean_name(isset($region['name']) ? $region['name'] : '');
  if ($name === '')
  {
    return null;
  }

  foreach (array('x', 'y', 'w', 'h') as $key)
  {
    if (!isset($region[$key]) or !persons_is_valid_normalized($region[$key]))
    {
      return null;
    }
  }

  $type = isset($region['type']) ? (string)$region['type'] : 'Face';
  if (!in_array($type, persons_region_types(), true))
  {
    return null;
  }

  $clipped = persons_clip_region(array(
    'x' => (float)$region['x'],
    'y' => (float)$region['y'],
    'w' => (float)$region['w'],
    'h' => (float)$region['h'],
    ));

  if ($clipped === null)
  {
    return null;
  }

  // Checked after clipping: a box that is only large enough because it runs off
  // the edge of the photo is not large enough.
  if (!persons_minimum_box_ok($clipped['w'], $clipped['h']))
  {
    return null;
  }

  return array(
    'name' => $name,
    'x'    => $clipped['x'],
    'y'    => $clipped['y'],
    'w'    => $clipped['w'],
    'h'    => $clipped['h'],
    'type' => $type,
    );
}

/**
 * A normalized fraction as the CSS percentage the box is laid out with.
 *
 * Fixed precision rather than PHP's float-to-string: 0.4 - 0.1 / 2 prints as
 * 34.999999999999996, and four decimals of a percent is a hundredth of a pixel
 * on a 1000px photo - far below what the 2px E2E tolerance can see.
 *
 * @param float $fraction
 * @return string
 */
function persons_percent($fraction)
{
  return sprintf('%.4F', $fraction * 100) . '%';
}

/**
 * Whether a region is the one a matcher describes.
 *
 * A matcher with no coordinates matches every box of that person - which is how
 * "this person is not in this photo" is expressed. One with coordinates matches
 * a single box, so two boxes of the same person on one photo stay distinct.
 *
 * @param array $region
 * @param array $matcher
 * @return bool
 */
function persons_region_matches($region, $matcher)
{
  if (!isset($matcher['name']) or (string)$matcher['name'] !== (string)$region['name'])
  {
    return false;
  }

  foreach (array('x', 'y', 'w', 'h') as $key)
  {
    if (!isset($matcher[$key]))
    {
      // A name-only matcher. Every coordinate is a wildcard, not just this one.
      return true;
    }

    if (abs((float)$matcher[$key] - (float)$region[$key]) > PERSONS_REGION_MATCH_EPSILON)
    {
      return false;
    }
  }

  return true;
}

/**
 * @param array $region
 * @param array $matchers
 * @return bool
 */
function persons_region_matches_any($region, $matchers)
{
  foreach ($matchers as $matcher)
  {
    if (persons_region_matches($region, $matcher))
    {
      return true;
    }
  }

  return false;
}
