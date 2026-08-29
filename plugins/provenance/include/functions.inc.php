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
