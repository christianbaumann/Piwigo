<?php
defined('PHOTOINFO_PATH') or die('Hacking attempt!');

/*
 * The photo date: an exact year, month and year, or day. Pure functions only,
 * so the unit suite can include this file with no database and no Piwigo
 * bootstrap.
 *
 * A date is an array('year' => int, 'month' => int|null, 'day' => int|null);
 * no date at all is null.
 */

/** The earliest year a photo can carry: before every photographic process. */
define('PHOTOINFO_DATE_MIN_YEAR', 1800);

define('PHOTOINFO_PRECISION_YEAR', 'year');
define('PHOTOINFO_PRECISION_MONTH', 'month');
define('PHOTOINFO_PRECISION_DAY', 'day');

/** Why a date is refused, as the WS method answers. */
define('PHOTOINFO_DATE_ERROR_YEAR', 'The year must have four digits, from 1800 to the current year');
define('PHOTOINFO_DATE_ERROR_MONTH', 'The month must be 1 to 12, and needs a year');
define('PHOTOINFO_DATE_ERROR_DAY', 'The day does not exist in that month, or has no month');

/**
 * @return array the date precisions, in the order of the column's ENUM
 */
function photoinfo_date_precisions()
{
  return array(PHOTOINFO_PRECISION_YEAR, PHOTOINFO_PRECISION_MONTH, PHOTOINFO_PRECISION_DAY);
}

/**
 * German month names, 1 to 12. The date is shown in German whatever the
 * visitor's language (design, "German display format").
 *
 * @return array
 */
function photoinfo_month_names()
{
  return array(1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');
}

/**
 * @param int $year
 * @param int $month 1 to 12
 * @return int
 */
function photoinfo_days_in_month($year, $month)
{
  if ($month == 2)
  {
    // Parenthesised whole: "=" binds tighter than "or".
    $leap = (($year % 4 == 0 and $year % 100 != 0) or $year % 400 == 0);
    return $leap ? 29 : 28;
  }

  return in_array($month, array(4, 6, 9, 11)) ? 30 : 31;
}

/**
 * Reads a date from the three form fields.
 *
 * All three empty is "no date". A month needs a year, a day needs a month, and
 * the year runs from 1800 to $current_year.
 *
 * @param mixed $year
 * @param mixed $month
 * @param mixed $day
 * @param int $current_year
 * @return array array('date' => date|null, 'error' => string|null)
 */
function photoinfo_date_from_input($year, $month, $day, $current_year)
{
  $year = trim((string)$year);
  $month = trim((string)$month);
  $day = trim((string)$day);

  if ($year === '' and $month === '' and $day === '')
  {
    return array('date' => null, 'error' => null);
  }

  if (!preg_match('/^\d{4}$/', $year) or $year < PHOTOINFO_DATE_MIN_YEAR or $year > $current_year)
  {
    return array('date' => null, 'error' => PHOTOINFO_DATE_ERROR_YEAR);
  }

  $date = array('year' => (int)$year, 'month' => null, 'day' => null);

  if ($month !== '')
  {
    if (!preg_match('/^\d{1,2}$/', $month) or $month < 1 or $month > 12)
    {
      return array('date' => null, 'error' => PHOTOINFO_DATE_ERROR_MONTH);
    }
    $date['month'] = (int)$month;
  }

  if ($day !== '')
  {
    if ($date['month'] === null or !preg_match('/^\d{1,2}$/', $day) or $day < 1
      or $day > photoinfo_days_in_month($date['year'], $date['month']))
    {
      return array('date' => null, 'error' => PHOTOINFO_DATE_ERROR_DAY);
    }
    $date['day'] = (int)$day;
  }

  return array('date' => $date, 'error' => null);
}

/**
 * @param array $date
 * @return string one of photoinfo_date_precisions()
 */
function photoinfo_date_precision($date)
{
  if ($date['day'] !== null)
  {
    return PHOTOINFO_PRECISION_DAY;
  }

  return $date['month'] !== null ? PHOTOINFO_PRECISION_MONTH : PHOTOINFO_PRECISION_YEAR;
}

/**
 * The value for images.date_creation: the start of the date, with unknown
 * parts set to 01, so the calendar, sorting and date search keep working.
 *
 * @param array $date
 * @return string
 */
function photoinfo_date_start($date)
{
  return sprintf('%04d-%02d-%02d 00:00:00', $date['year'],
    $date['month'] === null ? 1 : $date['month'],
    $date['day'] === null ? 1 : $date['day']);
}

/**
 * The date a photo's row holds.
 *
 * A date_creation without a precision was set by something other than this
 * plugin - core's admin screens or a camera's EXIF - and counts as exact to the
 * day (design, "Core admin screens set an exact date").
 *
 * @param string|null $date_creation
 * @param string|null $precision
 * @return array|null
 */
function photoinfo_date_from_row($date_creation, $precision)
{
  if (empty($date_creation) or !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date_creation, $m) or $m[1] == '0000')
  {
    return null;
  }

  $date = array('year' => (int)$m[1], 'month' => (int)$m[2], 'day' => (int)$m[3]);

  if ($precision == PHOTOINFO_PRECISION_YEAR)
  {
    $date['month'] = null;
  }
  if ($precision == PHOTOINFO_PRECISION_YEAR or $precision == PHOTOINFO_PRECISION_MONTH)
  {
    $date['day'] = null;
  }

  return $date;
}

/**
 * The date as people read it: "1965", "März 1965", "14. März 1965".
 *
 * @param array|null $date
 * @return string empty for no date
 */
function photoinfo_date_display($date)
{
  if ($date === null)
  {
    return '';
  }

  $text = (string)$date['year'];
  if ($date['month'] !== null)
  {
    $names = photoinfo_month_names();
    $text = $names[$date['month']].' '.$text;
  }
  if ($date['day'] !== null)
  {
    $text = $date['day'].'. '.$text;
  }

  return $text;
}

/**
 * The date as an EDTF string (ISO 8601-2): "1965", "1965-03", "1965-03-14".
 *
 * @param array|null $date
 * @return string empty for no date
 */
function photoinfo_date_edtf($date)
{
  if ($date === null)
  {
    return '';
  }

  $text = sprintf('%04d', $date['year']);
  if ($date['month'] !== null)
  {
    $text .= sprintf('-%02d', $date['month']);
  }
  if ($date['day'] !== null)
  {
    $text .= sprintf('-%02d', $date['day']);
  }

  return $text;
}

/**
 * The value for XMP-photoshop:DateCreated, an XMP date at the date's precision.
 *
 * @param array|null $date
 * @return string empty for no date
 */
function photoinfo_date_xmp($date)
{
  return photoinfo_date_edtf($date);
}

/**
 * The value for IPTC:DateCreated, which always has a day: 00 stands for an
 * unknown month or day (IPTC IIM 2:55).
 *
 * @param array|null $date
 * @return string empty for no date
 */
function photoinfo_date_iptc($date)
{
  if ($date === null)
  {
    return '';
  }

  return sprintf('%04d:%02d:%02d', $date['year'],
    $date['month'] === null ? 0 : $date['month'],
    $date['day'] === null ? 0 : $date['day']);
}

/**
 * The chronology a calendar link to the date opens: the year, the month or the
 * day, never a day the date does not name.
 *
 * @param array $date
 * @return array year, then month and day where known
 */
function photoinfo_date_chronology($date)
{
  $parts = array(sprintf('%04d', $date['year']));
  if ($date['month'] !== null)
  {
    $parts[] = sprintf('%02d', $date['month']);
  }
  if ($date['day'] !== null)
  {
    $parts[] = sprintf('%02d', $date['day']);
  }

  return $parts;
}
