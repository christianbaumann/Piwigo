<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * Pure helpers and constants. This file declares functions and constants and
 * nothing else, so the unit suite can include it with no database and no Piwigo
 * bootstrap. The argfile helpers call provenance's pure layer, which the unit
 * suite loads beside this file.
 */

include_once(PHOTOINFO_PATH.'include/date.inc.php');

/** The plugin photoinfo writes through: its exiftool runner, lock and caption slots. */
define('PHOTOINFO_REQUIRED_PLUGIN', 'provenance');

/** Why an activation without provenance is refused. */
define('PHOTOINFO_REQUIRES_PROVENANCE_MESSAGE',
  'Photo Info requires the Provenance plugin: activate Provenance first.');

/** Why a file write was refused before exiftool ran. */
define('PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE', 'File is missing or not writable');

/** What a failed file write on one of core's admin screens is introduced with. */
define('PHOTOINFO_ADMIN_ERROR_PREFIX', 'Photo Info: ');

/** XMP namespace the writer declares. Must match exiftool/pwginfo.config. */
define('PHOTOINFO_XMP_PREFIX', 'pwginfo');
define('PHOTOINFO_XMP_NAMESPACE_URI', 'http://piwigo.org/ns/photoinfo/1.0/');

/** The tag carrying the info text alone, so a rescan can read it back. */
define('PHOTOINFO_INFO_TAG', 'XMP-'.PHOTOINFO_XMP_PREFIX.':Info');

/** The tag carrying the date as EDTF, qualifier and range included. */
define('PHOTOINFO_DATE_EDTF_TAG', 'XMP-'.PHOTOINFO_XMP_PREFIX.':DateEDTF');

/** The namespace exiftool's -X output puts the tags of the XMP-pwginfo group in. */
define('PHOTOINFO_RDF_GROUP_URI', 'http://ns.exiftool.org/XMP/XMP-pwginfo/1.0/');

/** Photos one rescan request reads, so none can time out. */
define('PHOTOINFO_RESCAN_MAX_CHUNK', 10);

/** The standard slots for the start date, see photoinfo_date_xmp() and photoinfo_date_iptc(). */
define('PHOTOINFO_DATE_XMP_TAG', 'XMP-photoshop:DateCreated');
define('PHOTOINFO_DATE_IPTC_TAG', 'IPTC:DateCreated');

/**
 * The photo columns this plugin adds to images, as column name => SQL
 * definition. date_creation (core) holds the start of the date; these carry
 * what a DATETIME cannot.
 *
 * @return array
 */
function photoinfo_image_columns()
{
  $precisions = "ENUM('".implode("','", photoinfo_date_precisions())."')";

  return array(
    'photoinfo_date_qualifier' => "ENUM('".implode("','", array_keys(photoinfo_qualifier_labels()))."') DEFAULT NULL",
    'photoinfo_date_precision' => $precisions.' DEFAULT NULL',
    'photoinfo_date_end' => 'DATE DEFAULT NULL',
    'photoinfo_date_end_precision' => $precisions.' DEFAULT NULL',
    );
}

/**
 * The image columns a file write needs from photoinfo's side: the info text and
 * the date.
 *
 * @return array
 */
function photoinfo_written_columns()
{
  return array_merge(array('comment'), array_keys(photoinfo_dating_columns(null)));
}

/** This plugin's block in the provenance_caption_parts filter. */
define('PHOTOINFO_CAPTION_BLOCK', 'photoinfo');

/**
 * Puts the readable date and the info text, in that order and one line apart,
 * in front of the caption's other blocks.
 *
 * Without markup: every program reading the caption slots shows them as plain
 * text. The text as stored, markup included, goes into XMP-pwginfo:Info.
 *
 * @param array $blocks name => text, as the provenance_caption_parts filter passes them
 * @param string|null $info the photo's description
 * @param string $date the date as photoinfo_dating_display() gives it
 * @return array
 */
function photoinfo_caption_blocks($blocks, $info, $date = '')
{
  $lines = array_filter(
    array(trim((string)$date), trim(strip_tags((string)$info))),
    'strlen'
    );

  return array_merge(
    array(PHOTOINFO_CAPTION_BLOCK => implode("\n", $lines)),
    array_diff_key($blocks, array(PHOTOINFO_CAPTION_BLOCK => true))
    );
}

/**
 * Cleans the info text the way core cleans a description on its photo edit
 * screen (admin/picture_modify.php): markup is kept only where the install
 * allows HTML descriptions.
 *
 * @param mixed $value
 * @param bool $allow_html $conf['allow_html_descriptions']
 * @return string
 */
function photoinfo_clean_info($value, $allow_html)
{
  $text = (string)$value;

  return trim($allow_html ? $text : strip_tags($text));
}

/** Name of the value file carrying a multi-line info text, see provenance_argfile_line(). */
define('PHOTOINFO_INFO_VALUE_FILE', 'info.txt');

/**
 * The texts one save writes, keyed by the name of the value file each goes to
 * when it has a line break: provenance's two caption texts and the info text.
 *
 * @param string $caption the composed caption, info text first
 * @param string $info the info text alone
 * @return array file name => text
 */
function photoinfo_argfile_values($caption, $info)
{
  $values = provenance_caption_values(provenance_normalize_caption($caption));
  $values[PHOTOINFO_INFO_VALUE_FILE] = provenance_normalize_caption($info);

  return $values;
}

/**
 * The argfile lines for one save: the composed caption in provenance's five
 * slots, and the info text alone in its own tag.
 *
 * Every line is emitted even when its value is empty, because exiftool reads an
 * empty value as "delete": clearing the text must clear it from the file too.
 *
 * @param string $caption the composed caption, info text first
 * @param string $info the info text alone
 * @param string $value_prefix path prefix of the value files
 * @return array
 */
function photoinfo_build_argfile($caption, $info, $value_prefix)
{
  $values = photoinfo_argfile_values($caption, $info);

  $lines = array('-charset', 'iptc=UTF8');
  $lines = array_merge($lines, provenance_caption_argfile_lines($values['caption.txt'], $value_prefix));
  $lines[] = provenance_argfile_line(PHOTOINFO_INFO_TAG, $values[PHOTOINFO_INFO_VALUE_FILE],
    $value_prefix.PHOTOINFO_INFO_VALUE_FILE);

  return $lines;
}

/**
 * The argfile lines for one date save: the composed caption in provenance's
 * five slots and the date in its three tags: the start in the two DateCreated
 * slots, the whole dating as EDTF. Never EXIF DateTimeOriginal: on a scan that
 * is the scan date, and it cannot say "1965".
 *
 * Every line is emitted even when its value is empty: clearing the date must
 * delete it from the file too.
 *
 * @param string $caption the composed caption, date and info text first
 * @param array|null $dating
 * @param string $value_prefix path prefix of the caption's value files
 * @return array
 */
function photoinfo_build_date_argfile($caption, $dating, $value_prefix)
{
  $lines = array('-charset', 'iptc=UTF8');
  $lines = array_merge($lines,
    provenance_caption_argfile_lines(provenance_normalize_caption($caption), $value_prefix));

  return array_merge($lines, photoinfo_date_argfile_lines($dating));
}

/**
 * The argfile lines for a save that writes everything photoinfo keeps in a
 * file: the composed caption, the info text alone and the date's three tags.
 * A save in one of core's screens takes this, as it can change both fields.
 *
 * @param string $caption the composed caption, date and info text first
 * @param string $info the info text alone
 * @param array|null $dating
 * @param string $value_prefix path prefix of the value files
 * @return array
 */
function photoinfo_build_full_argfile($caption, $info, $dating, $value_prefix)
{
  return array_merge(photoinfo_build_argfile($caption, $info, $value_prefix),
    photoinfo_date_argfile_lines($dating));
}

/**
 * The date's three tags: the start in the two DateCreated slots, the whole
 * dating as EDTF. An empty value deletes the tag.
 *
 * @param array|null $dating
 * @return array
 */
function photoinfo_date_argfile_lines($dating)
{
  $start = $dating === null ? null : $dating['start'];

  return array(
    '-'.PHOTOINFO_DATE_XMP_TAG.'='.photoinfo_date_xmp($start),
    '-'.PHOTOINFO_DATE_IPTC_TAG.'='.photoinfo_date_iptc($start),
    '-'.PHOTOINFO_DATE_EDTF_TAG.'='.photoinfo_dating_edtf($dating),
    );
}

/**
 * What a save in one of core's screens - the photo properties screen, the
 * Batch Manager - changes on photoinfo's side. A date changed there is exact to
 * the day, so the plugin's precision, qualifier and range end are reset; a
 * removed date clears them (design, "Core admin screens set an exact date").
 * The file is written whenever the date or the description changed.
 *
 * A date posted unchanged is no change: those screens post every field on
 * every save, and a "ca. 1965" must survive a new title. Nor is a changed time
 * of day: photoinfo holds a date to the day at most, and core's date picker
 * posts a stored time back without its seconds.
 *
 * @param array|null $before date_creation and comment before the save; null
 *   when the save set the date outright (the Batch Manager's global action)
 * @param array $after date_creation and comment after the save
 * @return array 'columns' => the plugin's date columns to store, or null for
 *   no date change; 'write' => bool
 */
function photoinfo_core_edit($before, $after)
{
  $date_changed = ($before === null
    or photoinfo_date_day($before['date_creation']) !== photoinfo_date_day($after['date_creation']));

  $columns = null;
  if ($date_changed)
  {
    // Without a precision, a date_creation reads as an exact day.
    $columns = photoinfo_dating_columns(photoinfo_dating_from_row(array('date_creation' => $after['date_creation'])));
    // Core's value stays as core stored it, time of day included.
    unset($columns['date_creation']);
  }

  return array(
    'columns' => $columns,
    'write' => ($date_changed or $before['comment'] !== $after['comment']),
    );
}

/**
 * @param string|null $date_creation
 * @return string|null the day part, YYYY-MM-DD
 */
function photoinfo_date_day($date_creation)
{
  return $date_creation === null ? null : substr($date_creation, 0, 10);
}

/**
 * What a metadata sync may take from a file as the photo's date. A photo with a
 * date set with photoinfo gets that date back in the field mapped to
 * date_creation, even when the file had none or PHP read nothing: the
 * filesystem sync can write a missing value as NULL ("meta_empty_overrides").
 * Without one, the file's date is dropped unless the file carries camera
 * metadata (Make or Model): on a scan, the file's date is the scan date.
 *
 * @param array|null $exif what core read from the file; null when it read nothing
 * @param string|null $date_field the EXIF field mapped to date_creation
 * @param string|null $stored_date the photo's date_creation when photoinfo set it
 * @return array|null
 */
function photoinfo_sync_exif($exif, $date_field, $stored_date)
{
  if ($date_field === null)
  {
    return $exif;
  }

  if ($stored_date !== null)
  {
    $exif = is_array($exif) ? $exif : array();
    $exif[$date_field] = $stored_date;
    return $exif;
  }

  if (!is_array($exif) or !array_key_exists($date_field, $exif))
  {
    return $exif;
  }

  foreach (array('Make', 'Model') as $tag)
  {
    if (isset($exif[$tag]) and trim((string)$exif[$tag]) !== '')
    {
      return $exif;
    }
  }

  unset($exif[$date_field]);
  return $exif;
}

/**
 * The images.path of a file as core's sync names it: the root prefix followed
 * by the stored path. Plain string work, so a symlinked galleries/ or upload/
 * cannot move the file out of the root.
 *
 * @param string $file
 * @param string $root PHPWG_ROOT_PATH
 * @return string|null null for a file outside the root
 */
function photoinfo_image_path($file, $root)
{
  if (strpos($file, $root) !== 0)
  {
    return null;
  }

  $relative = substr($file, strlen($root));
  while (strpos($relative, './') === 0)
  {
    $relative = substr($relative, 2);
  }

  if ($relative === '' or preg_match('~(^|/)\.\.(/|$)~', $relative))
  {
    return null;
  }

  return './'.$relative;
}

/**
 * The original a representative file stands for: original_to_representative()
 * puts it under pwg_representative/ beside the original, with the original's
 * name and the representative's extension.
 *
 * @param string $path images.path form of the file core read
 * @return array|null the original's path up to and including the dot before
 *   its extension, and the representative_ext; null for no representative
 */
function photoinfo_representative_original($path)
{
  if (!preg_match('~^(.*/)pwg_representative/([^/]+\.)([^./]+)$~', $path, $m))
  {
    return null;
  }

  return array($m[1].$m[2], $m[3]);
}

/**
 * The info text and the EDTF date out of what `exiftool -X` printed for a file.
 *
 * -X rather than -j: JSON prints a number-like value unquoted, so an info text
 * "1.50" would come back as the float 1.5 (exiftool 13.25, measured
 * 2026-10-08). The RDF/XML output keeps every value as text.
 *
 * @param string $xml
 * @return array|null array('info' => string|null, 'edtf' => string|null), a
 *   tag the file does not carry or carries empty being null; null when $xml is
 *   not exiftool's output
 */
function photoinfo_parse_rescan_xml($xml)
{
  $document = new DOMDocument();
  if (trim((string)$xml) === '' or !@$document->loadXML($xml, LIBXML_NONET))
  {
    return null;
  }

  $values = array();
  foreach (array('info' => 'Info', 'edtf' => 'DateEDTF') as $key => $tag)
  {
    $nodes = $document->getElementsByTagNameNS(PHOTOINFO_RDF_GROUP_URI, $tag);
    $value = $nodes->length > 0 ? $nodes->item(0)->textContent : '';
    $values[$key] = $value === '' ? null : $value;
  }

  return $values;
}

/**
 * What a rescan stores for one photo: the info text and the dating its file
 * carries. A tag the file does not carry leaves its column alone.
 *
 * A date_creation already on the file's day stays as stored, time of day
 * included, as on a save in core's screens (photoinfo_core_edit()).
 *
 * @param array $tags photoinfo_parse_rescan_xml()'s answer
 * @param bool $allow_html $conf['allow_html_descriptions']
 * @param int $current_year
 * @param string|null $stored_date the photo's date_creation
 * @return array array('columns' => column => value, 'error' => string|null);
 *   an unreadable date leaves the date columns out and says why
 */
function photoinfo_rescan_columns($tags, $allow_html, $current_year, $stored_date = null)
{
  $columns = array();
  $error = null;

  if ($tags['info'] !== null)
  {
    $info = photoinfo_clean_info($tags['info'], $allow_html);
    if ($info !== '')
    {
      $columns['comment'] = $info;
    }
  }

  if ($tags['edtf'] !== null)
  {
    $parsed = photoinfo_dating_from_edtf($tags['edtf'], $current_year);
    if ($parsed['error'] === null)
    {
      $dating_columns = photoinfo_dating_columns($parsed['dating']);
      if (photoinfo_date_day($stored_date) === photoinfo_date_day($dating_columns['date_creation']))
      {
        unset($dating_columns['date_creation']);
      }
      $columns = array_merge($columns, $dating_columns);
    }
    else
    {
      $error = $parsed['error'].': '.$tags['edtf'];
    }
  }

  return array('columns' => $columns, 'error' => $error);
}

/*
 * ---------------------------------------------------------------------------
 * A photo's tags in its file.
 * ---------------------------------------------------------------------------
 */

/** The fields a photo's tags are written to; the rescan reads the two XMP ones. */
define('PHOTOINFO_SUBJECT_TAG', 'XMP-dc:Subject');
define('PHOTOINFO_KEYWORDS_TAG', 'IPTC:Keywords');
define('PHOTOINFO_HIERARCHY_TAG', 'XMP-lr:HierarchicalSubject');

/** Set on every tag write, so a rescan can tell "no tags" from "never written". */
define('PHOTOINFO_TAGS_MARKER_TAG', 'XMP-'.PHOTOINFO_XMP_PREFIX.':TagsWritten');

/** Between a group and its tag in a hierarchy entry. */
define('PHOTOINFO_HIERARCHY_SEPARATOR', '|');

/** Tags that stay in the database and never reach a file, beside every name with a '?'. */
define('PHOTOINFO_LOCAL_ONLY_TAGS', array('Ausstellung'));

/** The typetags group a tag typed in while tagging is put into. */
define('PHOTOINFO_FREITEXT_GROUP', 'Freitext');

/**
 * @param string $name a tag's or a group's name
 * @return bool whether a tag of that name stays out of the file
 */
function photoinfo_tag_is_local_only($name)
{
  return in_array($name, PHOTOINFO_LOCAL_ONLY_TAGS, true) or strpos($name, '?') !== false;
}

/**
 * What a photo's tags become in its file: a flat keyword list, and
 * "Group|Tag" for every tag in a group. Local-only tags are left out, and a
 * group whose name holds the separator gives no hierarchy entry, as it would
 * split in the wrong place when read back.
 *
 * Control characters in a tag's or a group's name become spaces: a line
 * break would start a new exiftool option in the argfile.
 *
 * @param array $tags rows: name, group (null for none)
 * @return array array('subject' => names, 'hierarchy' => entries), each sorted
 *   and without duplicates
 */
function photoinfo_file_keywords($tags)
{
  $subject = array();
  $hierarchy = array();

  foreach ($tags as $tag)
  {
    $name = trim(preg_replace('/[[:cntrl:]]+/', ' ', (string)$tag['name']));
    if ($name === '' or photoinfo_tag_is_local_only($name))
    {
      continue;
    }

    $subject[] = $name;

    $group = trim(preg_replace('/[[:cntrl:]]+/', ' ', (string)$tag['group']));
    if ($group !== '' and strpos($group, PHOTOINFO_HIERARCHY_SEPARATOR) === false)
    {
      $hierarchy[] = $group.PHOTOINFO_HIERARCHY_SEPARATOR.$name;
    }
  }

  return array(
    'subject' => photoinfo_sorted_unique($subject),
    'hierarchy' => photoinfo_sorted_unique($hierarchy),
    );
}

/**
 * @param array $values strings
 * @return array sorted byte-wise, without duplicates, reindexed
 */
function photoinfo_sorted_unique($values)
{
  $values = array_unique($values);
  sort($values, SORT_STRING);

  return $values;
}

/**
 * The argfile lines of one tag write. exiftool replaces a list field with the
 * first value the run assigns it and adds the rest, so each field is replaced
 * as a whole; an empty list deletes it. The marker is set every time.
 *
 * @param array $keywords photoinfo_file_keywords()'s answer
 * @return array
 */
function photoinfo_build_tags_argfile($keywords)
{
  $fields = array(
    PHOTOINFO_SUBJECT_TAG => $keywords['subject'],
    PHOTOINFO_KEYWORDS_TAG => $keywords['subject'],
    PHOTOINFO_HIERARCHY_TAG => $keywords['hierarchy'],
    );

  $lines = array('-charset', 'iptc=UTF8');
  foreach ($fields as $field => $values)
  {
    if (count($values) == 0)
    {
      $lines[] = '-'.$field.'=';
    }
    foreach ($values as $value)
    {
      $lines[] = '-'.$field.'='.$value;
    }
  }
  $lines[] = '-'.PHOTOINFO_TAGS_MARKER_TAG.'=1';

  return $lines;
}

/*
 * ---------------------------------------------------------------------------
 * Template anchors the picture prefilter matches against, in
 * themes/default/template/picture.tpl.
 * ---------------------------------------------------------------------------
 */

/**
 * The Info row goes in front of the "Posted on" row, which puts it right after
 * the creation date's row.
 *
 * Without the tab that indents it in the file: core's own prefilter
 * (Template::prefilter_white_space) strips it before any plugin's runs.
 */
define('PHOTOINFO_TPL_ROW_ANCHOR', "{if \$display_info.posted_on}");

/**
 * Core's "Created on" row, which the Datum row replaces. A pattern rather than
 * a literal: its lines keep their indent after core's whitespace prefilter, and
 * the empty {if} left around it renders nothing.
 */
define('PHOTOINFO_TPL_DATE_ROW_PATTERN', '~<div id="datecreate" class="imageInfo">.*?</div>\s*~s');

/** Core's description above the photo, which the Info row replaces. */
define('PHOTOINFO_TPL_COMMENT_BLOCK',
  "{if isset(\$COMMENT_IMG)}\n<p class=\"imageComment\">{\$COMMENT_IMG}</p>\n{/if}\n");

/**
 * The Smarty tag a prefilter puts in place of one of this plugin's templates
 * (decision 0036: an include, never the file's content).
 *
 * @param string $name file name under template/
 * @return string
 */
function photoinfo_template_include($name)
{
  return "{include file='".realpath(PHOTOINFO_PATH.'template/'.$name)."'}";
}
