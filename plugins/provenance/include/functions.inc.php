<?php
defined('PROVENANCE_PATH') or die('Hacking attempt!');

/*
 * Pure helpers and constants. This file declares functions and constants and
 * nothing else, so the unit suite can include it with no database and no Piwigo
 * bootstrap. maintain.class.php, the web-service handlers and the tests all read
 * the schema from here rather than each carrying a copy.
 */

/** XMP namespace the writer declares. Must match exiftool/pwgprov.config. */
define('PROVENANCE_XMP_PREFIX', 'pwgprov');
define('PROVENANCE_XMP_NAMESPACE_URI', 'http://piwigo.org/ns/provenance/1.0/');

/**
 * The album-level columns, as column name => SQL definition.
 *
 * @return array
 */
function provenance_album_columns()
{
  return array(
    'provenance_physical_album' => 'VARCHAR(255) DEFAULT NULL',
    'provenance_owner'          => 'VARCHAR(255) DEFAULT NULL',
    'provenance_scanned_on'     => 'DATE DEFAULT NULL',
    'provenance_note'           => 'TEXT DEFAULT NULL',
    );
}

/**
 * The photo columns, as column name => SQL definition.
 *
 * The first four are copied down from the album and are album-authoritative.
 * provenance_note is the photo's own and is never written by an album operation.
 *
 * @return array
 */
function provenance_image_columns()
{
  return array(
    'provenance_physical_album' => 'VARCHAR(255) DEFAULT NULL',
    'provenance_owner'          => 'VARCHAR(255) DEFAULT NULL',
    'provenance_scanned_on'     => 'DATE DEFAULT NULL',
    'provenance_album_note'     => 'TEXT DEFAULT NULL',
    'provenance_note'           => 'TEXT DEFAULT NULL',
    );
}

/*
 * ---------------------------------------------------------------------------
 * History table shape. maintain.class.php builds the CREATE TABLE from these,
 * and the recorder validates against them, so an enum value cannot exist in one
 * place and not the other.
 * ---------------------------------------------------------------------------
 */

/** Width of piwigo_provenance_history.field. */
define('PROVENANCE_HISTORY_FIELD_MAX_BYTES', 64);

/** Rows pwg.provenance.getHistory returns when the caller asks for no size. */
define('PROVENANCE_HISTORY_PER_PAGE_DEFAULT', 50);

/** Ceiling on that: a larger request is clamped down to this, not refused. */
define('PROVENANCE_HISTORY_PER_PAGE_MAX', 500);

/**
 * What a history row can be about.
 *
 * @return array
 */
function provenance_history_objects()
{
  return array('album', 'photo');
}

/**
 * What caused a history row. One value per write path in the plugin, so a row
 * says which operation produced it without joining anything.
 *
 * @return array
 */
function provenance_history_sources()
{
  return array('album_edit', 'photo_edit', 'apply', 'inherit', 'writeback', 'truncation');
}

/**
 * The photo columns an album operation writes: everything except the photo's own
 * note. Keyed album column => image column, because the album stores its free
 * text in provenance_note while the photo keeps that name for its own.
 *
 * @return array
 */
function provenance_copy_down_map()
{
  return array(
    'provenance_physical_album' => 'provenance_physical_album',
    'provenance_owner'          => 'provenance_owner',
    'provenance_scanned_on'     => 'provenance_scanned_on',
    'provenance_note'           => 'provenance_album_note',
    );
}

/*
 * ---------------------------------------------------------------------------
 * Composition layer: what text goes into a file.
 *
 * Everything below is pure - no database, no HTTP, no shell - and is the single
 * definition of the separator, the byte cap, the field order and the tag names.
 * Callers read these rather than repeating a literal.
 * ---------------------------------------------------------------------------
 */

/** Joins the labelled provenance parts inside one caption slot. */
define('PROVENANCE_CAPTION_SEPARATOR', ' | ');

/** IPTC-IIM 2:120 Caption-Abstract byte cap (MWG 2.0 section 5.2). Bytes, not characters. */
define('PROVENANCE_IPTC_MAX_BYTES', 2000);

/** Appended to a caption cut down to the IPTC budget. */
define('PROVENANCE_TRUNCATION_MARK', '…');

/** The one caption slot that is byte-capped; every other slot takes the full text. */
define('PROVENANCE_IPTC_CAPTION_TAG', 'IPTC:Caption-Abstract');

/** Working area for the writer's lock files. */
define('PROVENANCE_LOCK_DIR',
  dirname(dirname(rtrim(PROVENANCE_PATH, '/'))) . '/_data/provenance/locks/');

/**
 * The order provenance values appear in a composed caption.
 *
 * Part of the contract: the caption is deterministic, so a re-write of an
 * unchanged photo produces byte-identical text and writes no history row.
 *
 * @return array
 */
function provenance_field_order()
{
  return array_keys(provenance_image_columns());
}

/**
 * The caption slots written in one exiftool invocation - the MWG mirror set
 * plus the two Q15(b) additions, verified to round-trip during research.
 *
 * @return array
 */
function provenance_caption_tags()
{
  return array(
    'EXIF:ImageDescription',
    PROVENANCE_IPTC_CAPTION_TAG,
    'XMP-dc:Description',
    'XMP-photoshop:Headline',
    'XMP-tiff:ImageDescription',
    );
}

/**
 * Provenance field => tag name inside the custom XMP namespace.
 *
 * The album's own free text lands on the photo as provenance_album_note, so the
 * photo's two notes stay distinguishable in the file as well as in the database.
 *
 * @return array
 */
function provenance_xmp_tag_map()
{
  return array(
    'provenance_physical_album' => 'PhysicalAlbum',
    'provenance_owner'          => 'Owner',
    'provenance_scanned_on'     => 'ScannedOn',
    'provenance_album_note'     => 'AlbumNote',
    'provenance_note'           => 'PhotoNote',
    );
}

/**
 * Joins already-labelled parts into the caption text.
 *
 * Labels come from l10n() at the call site, never from here, so this stays free
 * of the language layer and testable without Piwigo. Empty and whitespace-only
 * parts are dropped rather than leaving a doubled separator behind.
 *
 * @param array $parts provenance field => labelled text
 * @return string
 */
function provenance_compose_caption($parts)
{
  $present = array();

  foreach (provenance_field_order() as $field)
  {
    if (!isset($parts[$field]))
    {
      continue;
    }

    $part = trim($parts[$field]);
    if ($part !== '')
    {
      $present[] = $part;
    }
  }

  return implode(PROVENANCE_CAPTION_SEPARATOR, $present);
}

/**
 * Cuts text down to the IPTC byte budget on a UTF-8 character boundary.
 *
 * The cap is a byte cap, so strlen() is correct here and mb_strlen() is not. A
 * cut landing inside a multi-byte character would put invalid UTF-8 into the
 * IPTC packet, so the incomplete tail is dropped whole.
 *
 * @param string $text
 * @return array array('text' => string, 'truncated' => bool)
 */
function provenance_truncate_for_iptc($text)
{
  if (strlen($text) <= PROVENANCE_IPTC_MAX_BYTES)
  {
    return array('text' => $text, 'truncated' => false);
  }

  $cut = substr($text, 0, PROVENANCE_IPTC_MAX_BYTES - strlen(PROVENANCE_TRUNCATION_MARK));

  while ($cut !== '' and !mb_check_encoding($cut, 'UTF-8'))
  {
    $cut = substr($cut, 0, -1);
  }

  return array('text' => $cut . PROVENANCE_TRUNCATION_MARK, 'truncated' => true);
}

/**
 * Makes one value safe to carry on a single argfile line.
 *
 * exiftool reads its argfile one argument per line and applies no escaping, so
 * an unhandled newline would split a value into two arguments - the second of
 * which could look like a flag. Collapsing is deliberate: the note column is
 * free text an administrator may well type across several lines.
 *
 * @param string $value
 * @return string
 */
function provenance_sanitize_argfile_value($value)
{
  return trim(str_replace(array("\r\n", "\r", "\n"), ' ', $value));
}

/**
 * Builds the exiftool argfile lines for one photo.
 *
 * A value never reaches a command line (decision C8): the tag and its value
 * share one line separated by '=', which is also why a value starting with '-'
 * is inert rather than escaped.
 *
 * @param array $values provenance field => raw value
 * @param string $caption composed caption text
 * @return array argfile lines, empty when there is nothing to write
 */
function provenance_build_argfile($values, $caption)
{
  $caption = provenance_sanitize_argfile_value($caption);
  $lines = array();

  if ($caption !== '')
  {
    $iptc = provenance_truncate_for_iptc($caption);

    foreach (provenance_caption_tags() as $tag)
    {
      $lines[] = '-'.$tag.'='.($tag == PROVENANCE_IPTC_CAPTION_TAG ? $iptc['text'] : $caption);
    }
  }

  foreach (provenance_xmp_tag_map() as $field => $tag)
  {
    $value = isset($values[$field]) ? provenance_sanitize_argfile_value($values[$field]) : '';

    if ($value !== '')
    {
      $lines[] = '-XMP-'.PROVENANCE_XMP_PREFIX.':'.$tag.'='.$value;
    }
  }

  if (empty($lines))
  {
    return array();
  }

  return array_merge(array('-charset', 'iptc=UTF8'), $lines);
}

/**
 * The lock file guarding one image against a concurrent exiftool write.
 *
 * A separate file, never the image itself: exiftool replaces the image by
 * rename, so a lock held on the old inode would exclude nothing from the second
 * writer onwards - the measured mode in which the file is destroyed outright.
 *
 * @param string $image_path path of the image, relative or absolute
 * @return string
 */
function provenance_lock_path($image_path)
{
  return PROVENANCE_LOCK_DIR . sha1($image_path) . '.lock';
}
