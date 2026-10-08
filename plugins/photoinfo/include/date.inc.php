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

/*
 * A photo's dating: one date, optionally qualified, or a range of two.
 *
 * A dating is array('qualifier' => string|null, 'start' => date, 'end' => date|null);
 * no dating at all is null. The qualifier is null for an exact date, and only
 * PHOTOINFO_QUALIFIER_BETWEEN carries an end. "ca." never takes a range
 * (design, "Qualifier and range as one select").
 */

define('PHOTOINFO_QUALIFIER_CIRCA', 'circa');
define('PHOTOINFO_QUALIFIER_BEFORE', 'before');
define('PHOTOINFO_QUALIFIER_AFTER', 'after');
define('PHOTOINFO_QUALIFIER_BETWEEN', 'between');

define('PHOTOINFO_DATE_ERROR_QUALIFIER', 'The qualifier must be empty, circa, before, after or between, and needs a date');
define('PHOTOINFO_DATE_ERROR_END', 'A range end needs the qualifier between, and between needs a range end');
define('PHOTOINFO_DATE_ERROR_END_BEFORE_START', 'The range end lies before its start');

/**
 * The qualifiers with the German word that shows each, in the order of the
 * column's ENUM and of the select on the page. Exact (no qualifier) is not
 * among them: it is the select's empty option.
 *
 * @return array qualifier => label
 */
function photoinfo_qualifier_labels()
{
  return array(
    PHOTOINFO_QUALIFIER_CIRCA => 'ca.',
    PHOTOINFO_QUALIFIER_BEFORE => 'vor',
    PHOTOINFO_QUALIFIER_AFTER => 'nach',
    PHOTOINFO_QUALIFIER_BETWEEN => 'zwischen',
    );
}

/**
 * Reads a dating from the form: the qualifier, the start's three fields and
 * the end's three fields.
 *
 * An empty start is "no date", and then the qualifier and the end must be empty
 * too. The end belongs to "between" alone, and "between" needs one. The end
 * must not lie before the start, compared at the coarser of the two
 * precisions: "14. März 1965–März 1965" passes, "1965–1964" does not.
 *
 * @param mixed $qualifier '' for exact
 * @param array $start year, month, day as the form sends them
 * @param array $end year, month, day as the form sends them
 * @param int $current_year
 * @return array array('dating' => dating|null, 'error' => string|null)
 */
function photoinfo_dating_from_input($qualifier, $start, $end, $current_year)
{
  $qualifier = trim((string)$qualifier);
  $start = photoinfo_date_from_input($start[0], $start[1], $start[2], $current_year);
  $end = photoinfo_date_from_input($end[0], $end[1], $end[2], $current_year);

  $error = photoinfo_dating_error($qualifier, $start, $end);
  if ($error !== null or $start['date'] === null)
  {
    return array('dating' => null, 'error' => $error);
  }

  return array(
    'dating' => array(
      'qualifier' => $qualifier === '' ? null : $qualifier,
      'start' => $start['date'],
      'end' => $end['date'],
      ),
    'error' => null,
    );
}

/**
 * Why a dating's parts do not fit together, see photoinfo_dating_from_input().
 *
 * @param string $qualifier trimmed, '' for exact
 * @param array $start photoinfo_date_from_input()'s answer for the start
 * @param array $end photoinfo_date_from_input()'s answer for the end
 * @return string|null null when they fit
 */
function photoinfo_dating_error($qualifier, $start, $end)
{
  if ($qualifier !== '' and !array_key_exists($qualifier, photoinfo_qualifier_labels()))
  {
    return PHOTOINFO_DATE_ERROR_QUALIFIER;
  }
  if ($start['error'] !== null)
  {
    return $start['error'];
  }
  if ($end['error'] !== null)
  {
    return $end['error'];
  }

  if ($start['date'] === null)
  {
    if ($qualifier !== '')
    {
      return PHOTOINFO_DATE_ERROR_QUALIFIER;
    }
    return $end['date'] === null ? null : PHOTOINFO_DATE_ERROR_END;
  }

  if (($qualifier === PHOTOINFO_QUALIFIER_BETWEEN) !== ($end['date'] !== null))
  {
    return PHOTOINFO_DATE_ERROR_END;
  }
  if ($end['date'] !== null and photoinfo_date_compare($end['date'], $start['date']) < 0)
  {
    return PHOTOINFO_DATE_ERROR_END_BEFORE_START;
  }

  return null;
}

/**
 * Compares two dates at the coarser of their precisions: 1965 and März 1965
 * are equal, März 1965 lies before April 1965.
 *
 * @param array $a
 * @param array $b
 * @return int below, equal to or above zero as $a lies before, on or after $b
 */
function photoinfo_date_compare($a, $b)
{
  foreach (array('year', 'month', 'day') as $part)
  {
    if ($a[$part] === null or $b[$part] === null)
    {
      return 0;
    }
    if ($a[$part] != $b[$part])
    {
      return $a[$part] < $b[$part] ? -1 : 1;
    }
  }

  return 0;
}

/**
 * The dating a photo's row holds.
 *
 * @param array $row date_creation, photoinfo_date_precision, photoinfo_date_qualifier,
 *   photoinfo_date_end, photoinfo_date_end_precision; a missing key counts as NULL.
 *   A "between" with no end reads as exact.
 * @return array|null
 */
function photoinfo_dating_from_row($row)
{
  $row = array_merge(array_fill_keys(array_keys(photoinfo_dating_columns(null)), null), $row);

  $start = photoinfo_date_from_row($row['date_creation'], $row['photoinfo_date_precision']);
  if ($start === null)
  {
    return null;
  }

  $qualifier = $row['photoinfo_date_qualifier'];
  $end = null;
  if ($qualifier === PHOTOINFO_QUALIFIER_BETWEEN)
  {
    $end = photoinfo_date_from_row($row['photoinfo_date_end'], $row['photoinfo_date_end_precision']);
    if ($end === null)
    {
      // A range with no end is not one: read the start as exact.
      $qualifier = null;
    }
  }

  return array(
    'qualifier' => empty($qualifier) ? null : $qualifier,
    'start' => $start,
    'end' => $end,
    );
}

/**
 * The plugin's date columns for a dating, as column => value or null; the
 * start goes into core's date_creation.
 *
 * @param array|null $dating
 * @return array
 */
function photoinfo_dating_columns($dating)
{
  $has_end = ($dating !== null and $dating['end'] !== null);

  return array(
    'date_creation' => $dating === null ? null : photoinfo_date_start($dating['start']),
    'photoinfo_date_precision' => $dating === null ? null : photoinfo_date_precision($dating['start']),
    'photoinfo_date_qualifier' => $dating === null ? null : $dating['qualifier'],
    'photoinfo_date_end' => $has_end ? substr(photoinfo_date_start($dating['end']), 0, 10) : null,
    'photoinfo_date_end_precision' => $has_end ? photoinfo_date_precision($dating['end']) : null,
    );
}

/**
 * The dating as people read it: "ca. 1965", "vor 1965", "nach März 1965",
 * "1965–1970" (en dash, no spaces).
 *
 * @param array|null $dating
 * @return string empty for no date
 */
function photoinfo_dating_display($dating)
{
  if ($dating === null)
  {
    return '';
  }

  $start = photoinfo_date_display($dating['start']);

  if ($dating['qualifier'] === PHOTOINFO_QUALIFIER_BETWEEN)
  {
    return $start."\u{2013}".photoinfo_date_display($dating['end']);
  }
  if ($dating['qualifier'] !== null)
  {
    $labels = photoinfo_qualifier_labels();
    return $labels[$dating['qualifier']].' '.$start;
  }

  return $start;
}

/**
 * The dating as EDTF (ISO 8601-2): "1965~", "../1965", "1965-03/..", "1965/1970".
 *
 * @param array|null $dating
 * @return string empty for no date
 */
function photoinfo_dating_edtf($dating)
{
  if ($dating === null)
  {
    return '';
  }

  $start = photoinfo_date_edtf($dating['start']);

  switch ($dating['qualifier'])
  {
    case PHOTOINFO_QUALIFIER_CIRCA:
      return $start.'~';
    case PHOTOINFO_QUALIFIER_BEFORE:
      return '../'.$start;
    case PHOTOINFO_QUALIFIER_AFTER:
      return $start.'/..';
    case PHOTOINFO_QUALIFIER_BETWEEN:
      return $start.'/'.photoinfo_date_edtf($dating['end']);
  }

  return $start;
}

define('PHOTOINFO_DATE_ERROR_EDTF', 'Not an EDTF date this plugin writes');

/**
 * Reads a dating back from its EDTF string, the inverse of
 * photoinfo_dating_edtf(), so a rescan can rebuild the row from the file.
 *
 * Only the forms photoinfo_dating_edtf() writes are read. Anything else EDTF
 * allows - "1965/" (unknown end), "1965?" (uncertain), "../.." - names a dating
 * the editor cannot hold, and is refused rather than guessed at. Each parsed
 * date passes the form's own checks: year bounds, days of the month, end not
 * before start.
 *
 * @param string $edtf
 * @param int $current_year
 * @return array array('dating' => dating|null, 'error' => string|null)
 */
function photoinfo_dating_from_edtf($edtf, $current_year)
{
  $date = '(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?';
  $forms = array(
    '' => '~^'.$date.'$~',
    PHOTOINFO_QUALIFIER_CIRCA => '~^'.$date.'\~$~',
    PHOTOINFO_QUALIFIER_BEFORE => '~^\.\./'.$date.'$~',
    PHOTOINFO_QUALIFIER_AFTER => '~^'.$date.'/\.\.$~',
    PHOTOINFO_QUALIFIER_BETWEEN => '~^'.$date.'/'.$date.'$~',
    );

  foreach ($forms as $qualifier => $pattern)
  {
    if (preg_match($pattern, trim((string)$edtf), $m))
    {
      $m = array_pad($m, 7, '');
      return photoinfo_dating_from_input($qualifier,
        array($m[1], $m[2], $m[3]), array($m[4], $m[5], $m[6]), $current_year);
    }
  }

  return array('dating' => null, 'error' => PHOTOINFO_DATE_ERROR_EDTF);
}
