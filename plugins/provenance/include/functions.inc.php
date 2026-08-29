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
  return array(
    'album_edit', 'photo_edit', 'apply', 'inherit', 'writeback', 'truncation',
    PROVENANCE_HISTORY_SOURCE_MOVE, PROVENANCE_HISTORY_SOURCE_ALBUM_DELETE,
    );
}

/*
 * ---------------------------------------------------------------------------
 * Modes: what an album operation does to a photo that already carries values.
 *
 * Core fires one trigger for every virtual link and offers no way to tell a
 * move from a plain association there, so the choice cannot be inferred - it
 * travels as an explicit request parameter that the Batch Manager's move panel
 * and the album-delete prompt post, and that an unattended API call omits.
 * ---------------------------------------------------------------------------
 */

/** Leave what the photo already carries. The default, and destroys nothing. */
define('PROVENANCE_MODE_KEEP', 'keep');

/** Empty the four album-sourced columns. The photo's own note is never touched. */
define('PROVENANCE_MODE_CLEAR', 'clear');

/** Overwrite them with the destination album's values. */
define('PROVENANCE_MODE_REPLACE', 'replace');

/** Request parameter carrying the choice made for a move or an association. */
define('PROVENANCE_MOVE_MODE_PARAM', 'provenance_move_mode');

/** Request parameter carrying the choice made when an album is deleted. */
define('PROVENANCE_DELETE_MODE_PARAM', 'provenance_delete_mode');

/** History source for a write an explicit move mode caused. */
define('PROVENANCE_HISTORY_SOURCE_MOVE', 'move');

/** History source for a write an album deletion caused. */
define('PROVENANCE_HISTORY_SOURCE_ALBUM_DELETE', 'album_delete');

/**
 * What a move or an association may do. Keep is first so a UI renders it as the
 * pre-selected choice.
 *
 * @return array
 */
function provenance_move_modes()
{
  return array(PROVENANCE_MODE_KEEP, PROVENANCE_MODE_CLEAR, PROVENANCE_MODE_REPLACE);
}

/**
 * What an album deletion may do. There is no album left to replace from, so
 * only two of the three apply.
 *
 * @return array
 */
function provenance_delete_modes()
{
  return array(PROVENANCE_MODE_KEEP, PROVENANCE_MODE_CLEAR);
}

/**
 * Reads a mode out of a request.
 *
 * Anything unusable - absent, empty, mistyped, not a string, or a mode the
 * caller's list does not allow - resolves to keep. A mode rides on a core web
 * service method the plugin cannot add a parameter to, so there is no call to
 * return an error from; falling back to the choice that destroys nothing is the
 * only safe reading of a value nobody can be asked about.
 *
 * @param array $request typically $_POST
 * @param string $param
 * @param array $allowed
 * @return string one of $allowed
 */
function provenance_resolve_mode($request, $param, $allowed)
{
  if (!isset($request[$param]) or !is_string($request[$param]))
  {
    return PROVENANCE_MODE_KEEP;
  }

  $mode = trim($request[$param]);

  return in_array($mode, $allowed, true) ? $mode : PROVENANCE_MODE_KEEP;
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

/** Working area for the argfiles of one write-back operation. */
define('PROVENANCE_ARGS_DIR',
  dirname(dirname(rtrim(PROVENANCE_PATH, '/'))) . '/_data/provenance/args/');

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
 * Provenance field => the l10n key labelling it inside a caption.
 *
 * The keys live here so the caption's shape is one definition, but the lookup
 * itself happens at the call site: this file stays loadable without Piwigo's
 * language layer, which is what keeps the composition layer unit-testable.
 *
 * @return array
 */
function provenance_caption_label_keys()
{
  return array(
    'provenance_physical_album' => 'Physical album',
    'provenance_owner'          => 'Owner',
    'provenance_scanned_on'     => 'Scanned on',
    'provenance_album_note'     => 'Album note',
    'provenance_note'           => 'Note',
    );
}

/**
 * Labels the populated provenance values, ready for the composer.
 *
 * An absent label leaves the bare value rather than a leading colon: l10n()
 * answers with the key when a translation is missing, and a caption reading
 * ": Anna Mueller" would be written into every file of an album.
 *
 * @param array $values provenance field => raw value
 * @param array $labels provenance field => label text
 * @return array provenance field => labelled text, empty values dropped
 */
function provenance_caption_parts($values, $labels)
{
  $parts = array();

  foreach (provenance_field_order() as $field)
  {
    $value = isset($values[$field]) ? trim((string)$values[$field]) : '';

    if ($value === '')
    {
      continue;
    }

    $label = isset($labels[$field]) ? trim((string)$labels[$field]) : '';
    $parts[$field] = ($label === '' ? '' : $label.': ').$value;
  }

  return $parts;
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

/**
 * Names one write-back operation.
 *
 * Hex only, so the id can be concatenated into a path with no sanitising step
 * that a later caller could forget.
 *
 * @return string
 */
function provenance_operation_id()
{
  return bin2hex(random_bytes(8));
}

/**
 * Where one operation stages its argfiles.
 *
 * A directory per operation, removed whole in a finally, so a crashed run
 * leaves at most one directory behind instead of orphan files nobody can
 * attribute.
 *
 * @param string $operation_id
 * @return string
 */
function provenance_operation_dir($operation_id)
{
  return PROVENANCE_ARGS_DIR . $operation_id . '/';
}

/*
 * ---------------------------------------------------------------------------
 * The copy-down operation.
 *
 * The server never iterates a whole album in one request: the client cuts the
 * album into chunks and sends one at a time, so a large album cannot run into
 * the production 60 s request ceiling. The chunk ceiling lives here because
 * both sides need it - the client sizes its chunks by it, the handler refuses
 * anything larger.
 * ---------------------------------------------------------------------------
 */

/** Most photo ids one applyToPhotos request may carry. */
define('PROVENANCE_APPLY_MAX_CHUNK', 200);

/**
 * Turns the comma-joined chunk into photo ids.
 *
 * A malformed member rejects the whole list rather than being dropped: a chunk
 * that silently applies to fewer photos than it names would leave the album
 * half-applied with nothing saying so. (int) casting is deliberately not used -
 * it turns "3.5" into 3 and would write onto a photo nobody asked for.
 *
 * @param mixed $value comma-joined ids
 * @param int|null $max most ids the list may carry; the apply ceiling by default
 * @return array|null ids in the order given, deduplicated; null when unusable
 */
function provenance_parse_id_list($value, $max = null)
{
  if ($max === null)
  {
    $max = PROVENANCE_APPLY_MAX_CHUNK;
  }

  $ids = array();

  foreach (explode(',', (string)$value) as $member)
  {
    $member = trim($member);

    if ($member === '')
    {
      continue;
    }

    if (!preg_match('/^\d+$/', $member) or (int)$member <= 0)
    {
      return null;
    }

    $ids[] = (int)$member;
  }

  $ids = array_values(array_unique($ids));

  return count($ids) > $max ? null : $ids;
}

/*
 * ---------------------------------------------------------------------------
 * The file write-back.
 *
 * Constants only; the behaviour lives in exiftool.inc.php, which needs a shell
 * and a filesystem. Keeping the ceiling and the history field names here is what
 * lets the suite read them without one.
 * ---------------------------------------------------------------------------
 */

/**
 * Most photo ids one writeBack request may carry.
 *
 * Far below the copy-down ceiling: one exiftool invocation costs orders of
 * magnitude more than an UPDATE, and both share the production 60 s request
 * ceiling. The measurement behind the figure is dated in docs/agents/TESTING.md.
 */
define('PROVENANCE_WRITEBACK_MAX_CHUNK', 10);

/**
 * How long a writer waits for the lock on one image before giving up.
 *
 * A blocking flock would wait for ever behind a wedged process and take the
 * request's 60 s ceiling with it, so the wait is bounded and a photo that
 * cannot be locked is reported as failed like any other.
 */
define('PROVENANCE_LOCK_TIMEOUT_SECONDS', 30);

/** How long a writer sleeps between attempts at a held lock. */
define('PROVENANCE_LOCK_RETRY_MICROSECONDS', 50000);

/** History field naming a successful write of one file. */
define('PROVENANCE_HISTORY_FIELD_FILE', 'file');

/** History field naming a failed write of one file. */
define('PROVENANCE_HISTORY_FIELD_FILE_ERROR', 'file_error');

/*
 * ---------------------------------------------------------------------------
 * Template anchors the prefilters match against.
 *
 * A moved anchor is invisible at runtime - Smarty compiles the untouched
 * template and the page renders without the feature - so each one is a named
 * constant with a structural guard test behind it, and lives here rather than
 * in the event file so the unit suite can read it without loading admin code.
 * ---------------------------------------------------------------------------
 */

/** Injection point on the album properties screen: immediately before the Save button. */
define('PROVENANCE_TPL_ALBUM_ANCHOR', '<span class="buttonLike" id="cat-properties-save">');

/**
 * Injection point in the Batch Manager: the div that opens the move action's
 * panel, which the page shows only when 'move' is the chosen action.
 */
define('PROVENANCE_TPL_BATCH_MOVE_ANCHOR', '<div id="action_move" class="bulkAction">');

/** Injection point on the photo properties screen: immediately before its save bar. */
define('PROVENANCE_TPL_PHOTO_ANCHOR', '<div class="savebar-footer">');

/**
 * Injection point on the public picture page: the close of <dl id="standard">,
 * so the row lands as the last entry of the photo's information list.
 *
 * Deliberately not the {if isset($metadata)} point one line below, which the
 * Colored Tags plugin already injects at: an anchor spanning that point would
 * stop matching whenever that plugin's prefilter ran first, and the row would
 * disappear depending on nothing more than plugin load order.
 */
define('PROVENANCE_TPL_INJECT_POINT', "{/strip}\n</dl>");

/*
 * ---------------------------------------------------------------------------
 * Visibility of the public row.
 *
 * Core's own picture-page rows are switched by $conf['picture_informations'],
 * a serialized map edited on Administration > Configuration > Display. The
 * plugin seeds one more key into that map on install and removes it again on
 * uninstall, so the row is switchable where an administrator already looks -
 * see docs/agents/decisions/0010-provenance-row-visibility-key.md for what that
 * costs.
 * ---------------------------------------------------------------------------
 */

/** The config parameter holding the picture page's row-visibility map. */
define('PROVENANCE_DISPLAY_INFO_PARAM', 'picture_informations');

/** This plugin's key inside that map. */
define('PROVENANCE_DISPLAY_INFO_KEY', 'provenance');

/*
 * ---------------------------------------------------------------------------
 * Boundary validation for the album save.
 *
 * Pure, so the rules live at the unit layer and the web-service handler only
 * maps their outcome onto an HTTP status.
 * ---------------------------------------------------------------------------
 */

/**
 * Length of the two VARCHAR(255) provenance columns.
 *
 * Characters, not bytes: the tables are utf8mb4, where VARCHAR(255) holds 255
 * characters. The contrast with PROVENANCE_IPTC_MAX_BYTES is deliberate - that
 * one really is a byte budget, imposed by the IPTC packet rather than by MySQL.
 */
define('PROVENANCE_SHORT_TEXT_MAX_CHARS', 255);

/**
 * Whether a scan date is storable.
 *
 * Empty means the field is being cleared and is therefore valid. Anything else
 * must be exactly YYYY-MM-DD *and* a real calendar date: the shape alone would
 * let 2026-02-29 through, which MySQL then stores as 0000-00-00 - a provenance
 * fact quietly replaced by a wrong one.
 *
 * @param mixed $value
 * @return bool
 */
function provenance_is_valid_scanned_on($value)
{
  $value = trim((string)$value);

  if ($value === '')
  {
    return true;
  }

  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m))
  {
    return false;
  }

  return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * Cleans one single-line provenance value and reports whether it fits.
 *
 * Over-long input is reported, never cut: silently truncating a provenance fact
 * is worse than refusing the save, so the caller answers with an error and the
 * administrator sees what happened. Markup is stripped because this text is
 * destined for an EXIF/IPTC packet, where it would be meaningless - which is
 * also why $conf['allow_html_descriptions'] is not honoured here.
 *
 * @param mixed $value
 * @return array array('text' => string, 'too_long' => bool)
 */
function provenance_clean_short_text($value)
{
  $text = trim(strip_tags((string)$value));

  return array(
    'text' => $text,
    'too_long' => mb_strlen($text) > PROVENANCE_SHORT_TEXT_MAX_CHARS,
    );
}

/**
 * Cleans the free-text note.
 *
 * No length cap - the column is TEXT. Internal line breaks are kept; the writer
 * collapses them when a value reaches an argfile line, and doing it here would
 * destroy the administrator's formatting in the database as well.
 *
 * @param mixed $value
 * @return string
 */
function provenance_clean_note($value)
{
  return trim(strip_tags((string)$value));
}
