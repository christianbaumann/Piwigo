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
 * what a DATETIME cannot. The qualifier and the range end are unused until
 * qualifiers and ranges are edited.
 *
 * @return array
 */
function photoinfo_image_columns()
{
  $precisions = "ENUM('".implode("','", photoinfo_date_precisions())."')";

  return array(
    'photoinfo_date_qualifier' => "ENUM('circa','before','after','between') DEFAULT NULL",
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
  return array('comment', 'date_creation', 'photoinfo_date_precision');
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
 * @param string $date the date as photoinfo_date_display() gives it
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
 * five slots and the date in its three tags. Never EXIF DateTimeOriginal: on a
 * scan that is the scan date, and it cannot say "1965".
 *
 * Every line is emitted even when its value is empty: clearing the date must
 * delete it from the file too.
 *
 * @param string $caption the composed caption, date and info text first
 * @param array|null $date
 * @param string $value_prefix path prefix of the caption's value files
 * @return array
 */
function photoinfo_build_date_argfile($caption, $date, $value_prefix)
{
  $lines = array('-charset', 'iptc=UTF8');
  $lines = array_merge($lines,
    provenance_caption_argfile_lines(provenance_normalize_caption($caption), $value_prefix));
  $lines[] = '-'.PHOTOINFO_DATE_XMP_TAG.'='.photoinfo_date_xmp($date);
  $lines[] = '-'.PHOTOINFO_DATE_IPTC_TAG.'='.photoinfo_date_iptc($date);
  $lines[] = '-'.PHOTOINFO_DATE_EDTF_TAG.'='.photoinfo_date_edtf($date);

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
