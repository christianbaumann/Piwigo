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

/** XMP namespace the writer declares. Must match exiftool/pwginfo.config. */
define('PHOTOINFO_XMP_PREFIX', 'pwginfo');
define('PHOTOINFO_XMP_NAMESPACE_URI', 'http://piwigo.org/ns/photoinfo/1.0/');

/** The tag carrying the info text alone, so a rescan can read it back. */
define('PHOTOINFO_INFO_TAG', 'XMP-'.PHOTOINFO_XMP_PREFIX.':Info');

/** The tag carrying the date as EDTF, qualifier and range included. */
define('PHOTOINFO_DATE_EDTF_TAG', 'XMP-'.PHOTOINFO_XMP_PREFIX.':DateEDTF');

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
 * every save, and a "ca. 1965" must survive a new title.
 *
 * @param array|null $before date_creation and comment before the save; null
 *   when the save set the date outright (the Batch Manager's global action)
 * @param array $after date_creation and comment after the save
 * @return array 'columns' => the plugin's date columns to store, or null for
 *   no date change; 'write' => bool
 */
function photoinfo_core_edit($before, $after)
{
  $date_changed = ($before === null or $before['date_creation'] !== $after['date_creation']);

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
