<?php
/**
 * Demo gallery seed for the German end-user handbook.
 *
 * Every screenshot in docs/handbuch/ shows this album and nothing else. The
 * gallery this repository points at holds recovered family scans of
 * identifiable private people, so the handbook photographs generated content
 * instead: ImageMagick draws six 1200x800 scenes with face-like shapes at fixed
 * positions, and those positions are printed in the output so shoot.js can drag
 * a person box over a face rather than over empty sky.
 *
 * It cannot reuse a plugin's test bootstrap - the handbook must not depend on a
 * plugin's scaffolding - so it assembles the runtime the way
 * plugins/persons/tests/Support/PiwigoRuntime::boot() does. Everything it then
 * calls is production code: associate_images_to_categories(), set_tags(),
 * update_category(), delete_elements(), delete_categories().
 *
 * Usage:
 *   php docs/handbuch/tools/seed.php --scenario=demo
 *   php docs/handbuch/tools/seed.php --restore
 *
 * Both print one JSON object on stdout. Errors go to stderr with exit 1.
 *
 * This inserts and deletes rows, writes image files and rewrites their
 * metadata. It is never safe against a production install, and refuses to run
 * without the throwaway marker the plugin suites already check.
 */

// ---------------------------------------------------------------- constants

/** The piwigo_config row that marks an install as expendable. */
const THROWAWAY_PARAM = 'persons_throwaway_install';

/** Where the generated photos live, relative to the gallery root. */
const DEMO_IMAGE_DIR = 'upload/handbuch-demo/';

/** Album the whole handbook is shot in. */
const DEMO_ALBUM_NAME = 'Handbuch-Beispielalbum';

const DEMO_ALBUM_COMMENT =
    'Beispielbilder für das Benutzerhandbuch. Alle Motive sind am Rechner erzeugt '
    . 'und zeigen keine realen Personen.';

/** Album-level provenance, inherited by every photo as read-only fields. */
const DEMO_ALBUM_PROVENANCE = array(
    'provenance_physical_album' => 'Beispielalbum, Karton 1',
    'provenance_owner'          => 'Musterarchiv',
    'provenance_scanned_on'     => '2026-03-14',
    'provenance_note'           => 'Erzeugte Beispielbilder, kein Originalbestand.',
    );

/** The persons the seed writes into a photo file and indexes. */
const DEMO_PERSONS = array('Anna Beispiel', 'Berta Muster');

/**
 * Persons the screenshot run creates through the browser.
 *
 * Removed unconditionally by --restore, before it even looks for a snapshot:
 * they are created by the code being photographed, so nothing else knows they
 * exist, and a leftover one puts a stranger at the top of the next run's picker.
 */
const SHOOT_PERSONS = array('Clara Beispiel');

/** Font with usable umlauts, present in the DDEV web image. */
const DEMO_FONT = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf';

const DEMO_WIDTH = 1200;
const DEMO_HEIGHT = 800;

/**
 * The smallest face box the handbook may ship, in pixels.
 *
 * 05-personen.html tells a reader to drag a rectangle over a face. A box much
 * smaller than this is fiddly with a mouse and unreadable in a screenshot, so a
 * scene whose faces shrink below it fails the seed rather than the reader.
 */
const MIN_FACE_PIXELS = 100;

/**
 * The six scenes.
 *
 * Each face is a centre in pixels plus a head radius; the region box written
 * into the file is derived from those, so the drawn shape and the tagged box
 * cannot drift apart. Colours vary per scene only so a reader can tell the
 * photos apart in a screenshot of an album grid.
 */
const DEMO_PHOTOS = array(
    array(
        'slug'   => 'sommerfest',
        'title'  => 'Sommerfest im Garten',
        'sky'    => '#cfe4f7-#8fb6dd',
        'ground' => '#b9cf9c',
        'faces'  => array(
            array('cx' => 380, 'cy' => 300, 'r' => 66, 'coat' => '#4c6f9b', 'skin' => '#f2d3b3'),
            array('cx' => 760, 'cy' => 320, 'r' => 60, 'coat' => '#9b4c5c', 'skin' => '#e6c19c'),
            ),
        ),
    array(
        'slug'   => 'rathaus',
        'title'  => 'Vor dem alten Rathaus',
        'sky'    => '#e6e1d2-#b6ab8d',
        'ground' => '#a99e86',
        'faces'  => array(
            array('cx' => 600, 'cy' => 310, 'r' => 70, 'coat' => '#3f5f4a', 'skin' => '#f0cfae'),
            ),
        ),
    array(
        'slug'   => 'musikkapelle',
        'title'  => 'Musikkapelle beim Umzug',
        'sky'    => '#dce9f2-#9ab6c9',
        'ground' => '#9c9c9c',
        'faces'  => array(
            array('cx' => 300, 'cy' => 330, 'r' => 56, 'coat' => '#2f4a72', 'skin' => '#f2d3b3'),
            array('cx' => 600, 'cy' => 310, 'r' => 58, 'coat' => '#2f4a72', 'skin' => '#e6c19c'),
            array('cx' => 900, 'cy' => 335, 'r' => 55, 'coat' => '#2f4a72', 'skin' => '#f2d3b3'),
            ),
        ),
    array(
        'slug'   => 'werkstatt',
        'title'  => 'Werkstatt in der Hauptstraße',
        'sky'    => '#e8dcc8-#c0a878',
        'ground' => '#8d7a5c',
        'faces'  => array(
            array('cx' => 480, 'cy' => 320, 'r' => 68, 'coat' => '#5a4630', 'skin' => '#e6c19c'),
            ),
        ),
    array(
        'slug'   => 'winterabend',
        'title'  => 'Winterabend am Marktplatz',
        'sky'    => '#4a5c78-#1e2a3e',
        'ground' => '#d8dee6',
        'faces'  => array(
            array('cx' => 420, 'cy' => 330, 'r' => 60, 'coat' => '#2a3550', 'skin' => '#f0cfae'),
            array('cx' => 780, 'cy' => 330, 'r' => 60, 'coat' => '#553049', 'skin' => '#e6c19c'),
            ),
        ),
    array(
        'slug'   => 'atelier',
        'title'  => 'Familienbild im Atelier',
        'sky'    => '#efe6d8-#cbbba0',
        'ground' => '#b8a88a',
        'faces'  => array(
            array('cx' => 470, 'cy' => 305, 'r' => 64, 'coat' => '#3b3b4c', 'skin' => '#f2d3b3'),
            array('cx' => 740, 'cy' => 305, 'r' => 64, 'coat' => '#6a3b3b', 'skin' => '#e6c19c'),
            ),
        ),
    );

/**
 * German texts for the photo-properties screenshots.
 *
 * Only two photos carry all four core fields: 03-fototexte.html shows a filled
 * screen next to an empty one, and a gallery where every photo is fully
 * described would not show what an unfilled field looks like.
 */
const DEMO_PHOTO_TEXTS = array(
    'sommerfest' => array(
        'author'      => 'Maria Musterfrau',
        'date_creation' => '1963-07-21',
        'comment'     => 'Gartenfest der Nachbarschaft. Die Namen der Gäste sind im Bild markiert.',
        'note'        => 'Rückseite beschriftet: "Sommerfest, Juli 1963".',
        ),
    'werkstatt' => array(
        'author'      => 'Josef Mustermann',
        'date_creation' => '1958-11-04',
        'comment'     => 'Blick in die Werkstatt an der Hauptstraße, kurz vor dem Umbau.',
        'note'        => 'Abzug leicht vergilbt, Ecke oben rechts fehlt.',
        ),
    );

/**
 * Colored tags per photo, by name.
 *
 * Existing tags of this install rather than new ones: 04-schlagworte.html
 * documents the eight German colored tags that are really there, and a
 * screenshot showing invented ones would document a gallery nobody has.
 */
const DEMO_PHOTO_TAGS = array(
    'sommerfest'   => array('Feste, Bräuche, Jahreskreis', 'Personen'),
    'rathaus'      => array('Häuser, Ortsansichten'),
    'musikkapelle' => array('Vereine, Gruppierungen', 'Feste, Bräuche, Jahreskreis'),
    'werkstatt'    => array('Gewerbe', 'Arbeiten'),
    'winterabend'  => array('Häuser, Ortsansichten'),
    'atelier'      => array('Personen'),
    );

/** The photo the seeded regions are written into. */
const DEMO_REGION_SLUG = 'sommerfest';

// ------------------------------------------------------------------ runtime

define('PHPWG_ROOT_PATH', dirname(__DIR__, 3) . '/');

/** Snapshot lives under _data/, which .gitignore already excludes. */
define('SNAPSHOT_FILE', PHPWG_ROOT_PATH . '_data/handbuch/snapshot.json');

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Assembles the runtime include/common.inc.php would build in a request.
 *
 * common.inc.php itself calls session_start(), which dies on the CLI without
 * $_SERVER['REMOTE_ADDR'], so the pieces are loaded directly and in the order
 * they depend on each other: constants.php reads $conf['data_location'], so it
 * cannot come before the config files.
 */
function boot_piwigo(): void
{
    global $conf, $prefixeTable, $page, $user, $lang, $lang_info, $logger, $persistent_cache;

    $conf = array();
    $page = array();
    $user = array();
    $lang = array();
    $lang_info = array();

    require PHPWG_ROOT_PATH . 'include/config_default.inc.php';
    @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
    require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
    require_once PHPWG_ROOT_PATH . 'include/constants.php';
    require_once PHPWG_ROOT_PATH . 'include/dblayer/functions_' . $conf['dblayer'] . '.inc.php';

    $conf['die_on_sql_error'] = true;
    $conf['show_queries'] = false;

    pwg_db_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
    pwg_db_check_charset();

    require_once PHPWG_ROOT_PATH . 'include/functions.inc.php';

    $conf['webmaster_id'] = $conf['webmaster_id'] ?? 1;
    load_conf_from_db();

    // Two globals common.inc.php builds and the admin helpers then use without
    // checking: invalidate_user_cache() calls $persistent_cache->purge() and
    // several helpers log. Both fatal on null.
    require_once PHPWG_ROOT_PATH . 'include/Logger.class.php';
    require_once PHPWG_ROOT_PATH . 'include/cache.class.php';
    $persistent_cache = new PersistentFileCache();
    $logger = new Logger(array(
        'directory' => PHPWG_ROOT_PATH . $conf['data_location'] . $conf['log_dir'],
        'severity' => $conf['log_level'],
        'filename' => 'log_handbuch_seed.txt',
        'globPattern' => 'log_*.txt',
        'archiveDays' => $conf['log_archive_days'],
        ));

    // Writes are attributed to the webmaster, the same account the handbook
    // tells a reader to use for the admin screens.
    $user = array('id' => (int)$conf['webmaster_id']);

    require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
}

/**
 * Refuses to touch an install that has not been declared expendable.
 *
 * The same marker and the same failure mode as
 * FixtureBuilder::assertThrowawayInstall(): this script deletes photo rows and
 * their files, and on 2026-08-29 an install holding real scans lost every photo
 * row during a plugin test run.
 */
function assert_throwaway_install(): void
{
    $row = pwg_db_fetch_row(pwg_query(
        "SELECT value FROM " . CONFIG_TABLE . " WHERE param = '" . THROWAWAY_PARAM . "'"
        ));

    if (!is_array($row) or (string)$row[0] !== '1')
    {
        fail(
            "This install is not marked as a throwaway, and the handbook seed deletes content.\n"
            . "It creates and deletes albums and photos and rewrites image files in place.\n"
            . "Run it only against an install whose gallery you can afford to lose, and mark it with:\n"
            . "  ddev exec php plugins/persons/tests/Support/create-test-users.php\n"
            . "Never mark a production install."
            );
    }
}

function load_snapshot(): ?array
{
    if (!is_file(SNAPSHOT_FILE))
    {
        return null;
    }

    $decoded = json_decode((string)file_get_contents(SNAPSHOT_FILE), true);
    if (!is_array($decoded))
    {
        fail('snapshot file is not valid JSON: ' . SNAPSHOT_FILE);
    }

    return $decoded;
}

function save_snapshot(array $snapshot): void
{
    $dir = dirname(SNAPSHOT_FILE);
    if (!is_dir($dir) and !mkdir($dir, 0777, true) and !is_dir($dir))
    {
        fail("could not create snapshot directory $dir");
    }
    file_put_contents(SNAPSHOT_FILE, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// -------------------------------------------------------- photo generation

/**
 * Draws one scene with ImageMagick.
 *
 * Deliberately flat and obviously drawn: the point is a photo-shaped image
 * with a face-like shape big enough to drag a region box over, not a
 * convincing photograph. The title is burned into the image so a reader can
 * tell one screenshot from another even where the caption is cropped away.
 *
 * @param array $photo one entry of DEMO_PHOTOS
 * @param string $target absolute path to write
 */
function draw_demo_photo(array $photo, string $target): void
{
    $w = DEMO_WIDTH;
    $h = DEMO_HEIGHT;
    $horizon = (int)round($h * 0.68);

    $args = array(
        'magick', '-size', $w . 'x' . $h, 'gradient:' . $photo['sky'],
        '-fill', $photo['ground'], '-draw', "rectangle 0,$horizon " . ($w - 1) . ',' . ($h - 1),
        );

    foreach ($photo['faces'] as $face)
    {
        $cx = (int)$face['cx'];
        $cy = (int)$face['cy'];
        $r = (int)$face['r'];

        $body = array(
            'roundrectangle ' . ($cx - (int)round(1.5 * $r)) . ',' . ($cy + $r + 8)
            . ' ' . ($cx + (int)round(1.5 * $r)) . ',' . ($h - 1)
            . ' ' . (int)round(0.6 * $r) . ',' . (int)round(0.6 * $r),
            );
        $args[] = '-fill';
        $args[] = $face['coat'];
        $args[] = '-draw';
        $args[] = $body[0];

        $args[] = '-fill';
        $args[] = $face['skin'];
        $args[] = '-draw';
        $args[] = "circle $cx,$cy $cx," . ($cy - $r);

        $eye = max(4, (int)round(0.10 * $r));
        $ey = $cy - (int)round(0.18 * $r);
        $ex = (int)round(0.34 * $r);
        $args[] = '-fill';
        $args[] = '#2b2b2b';
        $args[] = '-draw';
        $args[] = 'circle ' . ($cx - $ex) . ",$ey " . ($cx - $ex) . ',' . ($ey - $eye);
        $args[] = '-draw';
        $args[] = 'circle ' . ($cx + $ex) . ",$ey " . ($cx + $ex) . ',' . ($ey - $eye);

        $args[] = '-fill';
        $args[] = 'none';
        $args[] = '-stroke';
        $args[] = '#8a5a4a';
        $args[] = '-strokewidth';
        $args[] = '4';
        $args[] = '-draw';
        $args[] = 'path "M ' . ($cx - $ex) . ',' . ($cy + (int)round(0.28 * $r))
            . " Q $cx," . ($cy + (int)round(0.62 * $r))
            . ' ' . ($cx + $ex) . ',' . ($cy + (int)round(0.28 * $r)) . '"';
        $args[] = '-stroke';
        $args[] = 'none';
    }

    $args[] = '-fill';
    $args[] = '#00000099';
    $args[] = '-draw';
    $args[] = 'rectangle 0,0 ' . ($w - 1) . ',96';
    $args[] = '-font';
    $args[] = DEMO_FONT;
    $args[] = '-pointsize';
    $args[] = '44';
    $args[] = '-fill';
    $args[] = '#ffffff';
    $args[] = '-annotate';
    $args[] = '+40+64';
    $args[] = $photo['title'];
    $args[] = $target;

    $command = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $output = array();
    $status = 1;
    exec($command, $output, $status);

    if ($status !== 0)
    {
        fail('ImageMagick failed for ' . $photo['slug'] . ': ' . implode(' ', $output));
    }

    // Anti-vacuity: a zero-byte or unreadable file would make every later
    // assertion about dimensions and region positions trivially true.
    clearstatcache(true, $target);
    if (!is_file($target) or filesize($target) < 1)
    {
        fail('generated photo is empty: ' . $target);
    }

    $dimensions = @getimagesize($target);
    if ($dimensions === false)
    {
        fail('generated photo has no readable dimensions: ' . $target);
    }
    if ((int)$dimensions[0] !== $w or (int)$dimensions[1] !== $h)
    {
        fail("generated photo is {$dimensions[0]}x{$dimensions[1]}, expected {$w}x{$h}: $target");
    }
}

/**
 * The MWG region boxes for one scene, derived from the drawn shapes.
 *
 * Centre-based and normalized, which is what the standard stores, and computed
 * from the same face constants the drawing used - so a box can never sit
 * somewhere the face is not.
 *
 * @return array list of x, y, w, h in 0..1
 */
function face_regions(array $photo): array
{
    $boxes = array();
    foreach ($photo['faces'] as $face)
    {
        $boxWidth = 2.1 * $face['r'];
        $boxHeight = 2.4 * $face['r'];
        if ($boxWidth < MIN_FACE_PIXELS or $boxHeight < MIN_FACE_PIXELS)
        {
            fail(sprintf(
                'scene %s has a %dx%d face box, below the %dpx a reader can drag over',
                $photo['slug'], (int)$boxWidth, (int)$boxHeight, MIN_FACE_PIXELS
                ));
        }

        $boxes[] = array(
            'x' => round($face['cx'] / DEMO_WIDTH, 4),
            'y' => round($face['cy'] / DEMO_HEIGHT, 4),
            'w' => round(2.1 * $face['r'] / DEMO_WIDTH, 4),
            'h' => round(2.4 * $face['r'] / DEMO_HEIGHT, 4),
            );
    }

    return $boxes;
}

// --------------------------------------------------------------- persistence

/**
 * Writes MWG regions into an image file with a plain exiftool call.
 *
 * Not through the persons plugin's own writer: 05-personen.html documents what
 * the plugin reads out of a file, and seeding through the code being
 * documented would only show that it agrees with itself.
 *
 * @param array $regions list of name, x, y, w, h
 */
function write_regions_with_exiftool(string $file, array $regions, int $appliedW, int $appliedH): void
{
    if (count($regions) === 0)
    {
        fail('anti-vacuity: seeding no regions would leave 05-personen.html nothing to photograph');
    }

    $list = array();
    $names = array();
    foreach ($regions as $region)
    {
        $list[] = array(
            'Area' => array(
                'X' => $region['x'],
                'Y' => $region['y'],
                'W' => $region['w'],
                'H' => $region['h'],
                'Unit' => 'normalized',
                ),
            'Name' => $region['name'],
            'Type' => 'Face',
            );
        $names[$region['name']] = true;
    }

    $payload = array(array(
        'RegionInfo' => array(
            'AppliedToDimensions' => array('W' => $appliedW, 'H' => $appliedH, 'Unit' => 'pixel'),
            'RegionList' => $list,
            ),
        'PersonInImage' => array_keys($names),
        ));

    $jsonFile = $file . '.seed.json';
    file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $output = array();
    $status = 1;
    exec(
        'exiftool -overwrite_original -charset filename=UTF8 -json=' . escapeshellarg($jsonFile)
        . ' ' . escapeshellarg($file) . ' 2>&1',
        $output,
        $status
        );
    @unlink($jsonFile);

    if ($status !== 0)
    {
        fail('writing regions failed: ' . implode(' ', $output));
    }
}

/**
 * Checks every scene against the drag floor before anything is written.
 *
 * face_regions() enforces the same rule, but it is first reached deep inside
 * the insert loop - by then there are rows and files a failure would strand.
 * A constant nobody can photograph is knowable before the first write, so it
 * is refused there.
 */
function assert_scenes_are_photographable(): void
{
    foreach (DEMO_PHOTOS as $photo)
    {
        face_regions($photo);
    }
}

/**
 * Refuses to publish a generated photo that is a byte copy of a gallery image.
 *
 * This is the machine half of "no screenshot shows a real person". Recognising
 * a face is not something a check can do, but the way a private scan could
 * reach the handbook is known and narrow: every plugin fixture builds its photo
 * by copying a real gallery image (FixtureBuilder::createTestImage()), and a
 * seed written the same way would publish one. Comparing bytes catches exactly
 * that, and claims no more.
 *
 * Against the files on disk, not piwigo_images.md5sum: measured 2026-08-31, all
 * 105 rows of this install carry a null checksum, so a column comparison would
 * have passed every generated photo for the wrong reason. Sizes are compared
 * first and a checksum is computed only where one matches, so the usual run
 * hashes nothing.
 *
 * @param array $files absolute paths of the generated photos
 */
function assert_no_generated_photo_is_a_gallery_copy(array $files): void
{
    $bySize = array();
    $result = pwg_query('SELECT path FROM ' . IMAGES_TABLE);
    while ($row = pwg_db_fetch_assoc($result))
    {
        // A leftover demo row is a generated photo by definition; comparing a
        // regenerated scene against it would report the seed to itself.
        if (str_contains($row['path'], DEMO_IMAGE_DIR))
        {
            continue;
        }

        $existing = PHPWG_ROOT_PATH . ltrim($row['path'], './');
        if (!is_file($existing))
        {
            continue;
        }
        $bySize[(int)filesize($existing)][] = $existing;
    }

    // Anti-vacuity: with nothing to compare against, every generated photo
    // would pass this for the wrong reason and the check would have stopped
    // existing.
    if (count($bySize) < 1)
    {
        fail('no gallery image file could be read; the real-photo guard would be vacuous');
    }

    foreach ($files as $file)
    {
        $size = (int)filesize($file);
        if (!isset($bySize[$size]))
        {
            continue;
        }

        $checksum = md5_file($file);
        foreach ($bySize[$size] as $existing)
        {
            if (md5_file($existing) === $checksum)
            {
                fail('generated photo ' . basename($file) . ' is a byte copy of ' . $existing);
            }
        }
    }
}

/**
 * Reads the written regions back with a plain exiftool call.
 *
 * Not through the persons plugin's reader: Phase 5 photographs the overlay the
 * plugin draws from these coordinates, so the seed has to know they really
 * landed in the file rather than that the plugin agrees with itself.
 *
 * @param array $expected list of name, x, y, w, h as written
 */
function assert_regions_reached_the_file(string $file, array $expected): void
{
    $out = array();
    $status = 1;
    exec(
        'exiftool -json -struct -charset filename=UTF8 -XMP-mwg-rs:RegionInfo '
        . escapeshellarg($file) . ' 2>/dev/null',
        $out,
        $status
        );

    if ($status !== 0)
    {
        fail('exiftool could not read back ' . $file);
    }

    $decoded = json_decode(implode("\n", $out), true);
    $list = $decoded[0]['RegionInfo']['RegionList'] ?? array();

    if (count($list) !== count($expected))
    {
        fail('the file holds ' . count($list) . ' of ' . count($expected) . ' written regions');
    }

    foreach ($expected as $i => $region)
    {
        $area = $list[$i]['Area'] ?? array();
        $sameName = ($list[$i]['Name'] ?? '') === $region['name'];
        // Parenthesised: 'and' binds looser than '=', so an unbracketed chain
        // would assign only the first comparison and pass on the rest.
        $samePlace = (
            abs((float)($area['X'] ?? -1) - $region['x']) < 0.001
            and abs((float)($area['Y'] ?? -1) - $region['y']) < 0.001
            and abs((float)($area['W'] ?? -1) - $region['w']) < 0.001
            and abs((float)($area['H'] ?? -1) - $region['h']) < 0.001
            );

        if (!$sameName or !$samePlace)
        {
            fail('region ' . $i . ' came back from the file as something else than it was written');
        }
    }
}

/**
 * Proves the German texts survived the round trip through MariaDB.
 *
 * Every title is compared against its constant on the way in, so the only way
 * this can rot is for the whole set to become ASCII and stop exercising the
 * charset at all. The umlaut count is the anti-vacuity guard against that.
 */
function assert_german_texts_round_tripped(array $imageIds): void
{
    $nonAscii = 0;
    $result = pwg_query(
        'SELECT name FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(',', $imageIds) . ')'
        );
    while ($row = pwg_db_fetch_assoc($result))
    {
        if ($row['name'] === '')
        {
            fail('a demo photo came back with an empty title');
        }
        if (preg_match('/[^\x00-\x7F]/', $row['name']) === 1)
        {
            $nonAscii++;
        }
    }

    if ($nonAscii < 1)
    {
        fail('no demo title carries a German special character; the charset round trip is untested');
    }
}

/** Resolves a tag name to its id, refusing to invent one this install lacks. */
function tag_id_by_name(string $name): int
{
    $row = pwg_db_fetch_assoc(pwg_query(
        'SELECT id FROM ' . TAGS_TABLE . " WHERE name = '" . pwg_db_real_escape_string($name) . "'"
        ));

    if (empty($row['id']))
    {
        fail("this install has no tag named '$name'; the handbook documents the tags that exist");
    }

    return (int)$row['id'];
}

/** Removes the person rows and the mirrored tags for the given names. */
function destroy_persons(array $names): void
{
    global $prefixeTable;

    $personsTable = $prefixeTable . 'persons';
    $regionTable = $prefixeTable . 'person_region';

    $installed = pwg_db_num_rows(pwg_query("SHOW TABLES LIKE '$personsTable'")) > 0;

    foreach ($names as $name)
    {
        $escaped = pwg_db_real_escape_string($name);

        if ($installed)
        {
            $row = pwg_db_fetch_assoc(pwg_query("SELECT id, tag_id FROM $personsTable WHERE name = '$escaped'"));
            if (!empty($row['id']))
            {
                pwg_query("DELETE FROM $regionTable WHERE person_id = " . (int)$row['id']);
                pwg_query("DELETE FROM $personsTable WHERE id = " . (int)$row['id']);
            }
            if (!empty($row['tag_id']))
            {
                pwg_query('DELETE FROM ' . IMAGE_TAG_TABLE . ' WHERE tag_id = ' . (int)$row['tag_id']);
                pwg_query('DELETE FROM ' . TAGS_TABLE . ' WHERE id = ' . (int)$row['tag_id']);
            }
        }

        pwg_query('DELETE FROM ' . TAGS_TABLE . " WHERE name = '$escaped'");
    }
}

// ---------------------------------------------------------------- entry point

$args = getopt('', array('scenario::', 'restore'));

boot_piwigo();
assert_throwaway_install();

if (isset($args['restore']))
{
    // Unconditional, before the snapshot is even looked for: the persons the
    // screenshot run creates through the browser outlive any snapshot, and a
    // leftover one would appear in the next run's picker.
    destroy_persons(array_merge(DEMO_PERSONS, SHOOT_PERSONS));

    // The whole directory belongs to the seed, so anything still in it is a
    // leftover - including a scene drawn by a run that died before its row was
    // inserted, which no snapshot can name.
    foreach (glob(PHPWG_ROOT_PATH . DEMO_IMAGE_DIR . '*') ?: array() as $leftover)
    {
        @unlink($leftover);
    }
    @rmdir(PHPWG_ROOT_PATH . DEMO_IMAGE_DIR);

    $snapshot = load_snapshot();
    if ($snapshot === null)
    {
        echo json_encode(array('restored' => false, 'reason' => 'no snapshot')), "\n";
        exit(0);
    }

    $imageIds = array_map('intval', $snapshot['image_ids'] ?? array());
    if (count($imageIds) > 0)
    {
        delete_elements($imageIds, true);
    }

    $albumId = (int)($snapshot['album_id'] ?? 0);
    if ($albumId > 0)
    {
        delete_categories(array($albumId));
        update_global_rank();
    }

    invalidate_user_cache();

    @unlink(SNAPSHOT_FILE);

    $remaining = pwg_db_fetch_row(pwg_query(
        'SELECT COUNT(*) FROM ' . CATEGORIES_TABLE . " WHERE name = '"
        . pwg_db_real_escape_string(DEMO_ALBUM_NAME) . "'"
        ));
    if ((int)$remaining[0] !== 0)
    {
        fail('the demo album survived --restore');
    }

    echo json_encode(array('restored' => true, 'album_id' => $albumId, 'photos' => count($imageIds))), "\n";
    exit(0);
}

if (($args['scenario'] ?? '') !== 'demo')
{
    fail('usage: seed.php --scenario=demo | --restore');
}

if (load_snapshot() !== null)
{
    fail('a demo album is already seeded; run --restore first');
}

assert_scenes_are_photographable();

// The album first, with its provenance, so the photos can be given the same
// inherited values in one place.
$created = create_virtual_category(DEMO_ALBUM_NAME, null, array('comment' => DEMO_ALBUM_COMMENT));
if (isset($created['error']))
{
    fail('could not create the demo album: ' . $created['error']);
}
$albumId = (int)$created['id'];

// From here on there is something to remove, and --restore is the only thing
// that knows how. The snapshot is extended as each photo lands rather than
// written once at the end, so a run that dies half way is still undoable.
save_snapshot(array('album_id' => $albumId, 'image_ids' => array()));

single_update(CATEGORIES_TABLE, DEMO_ALBUM_PROVENANCE, array('id' => $albumId));

$album = pwg_db_fetch_assoc(pwg_query(
    'SELECT name, comment, provenance_owner FROM ' . CATEGORIES_TABLE . ' WHERE id = ' . $albumId
    ));
if ($album['name'] !== DEMO_ALBUM_NAME or $album['comment'] !== DEMO_ALBUM_COMMENT
    or $album['provenance_owner'] !== DEMO_ALBUM_PROVENANCE['provenance_owner'])
{
    fail('the demo album did not take its name, description or provenance');
}

$dir = PHPWG_ROOT_PATH . DEMO_IMAGE_DIR;
if (!is_dir($dir) and !mkdir($dir, 0755, true) and !is_dir($dir))
{
    fail("cannot create $dir");
}

// Every scene is drawn before any row is inserted, so the copy guard below
// compares the generated files against a gallery that holds none of them yet.
$files = array();
foreach (DEMO_PHOTOS as $index => $photo)
{
    $file = $dir . sprintf('handbuch-%02d-%s.png', $index + 1, $photo['slug']);
    draw_demo_photo($photo, $file);
    $files[$photo['slug']] = $file;
}

assert_no_generated_photo_is_a_gallery_copy(array_values($files));

$imageIds = array();
$photos = array();

foreach (DEMO_PHOTOS as $photo)
{
    $file = $files[$photo['slug']];
    $name = basename($file);

    clearstatcache(true, $file);
    $insert = array(
        'file' => $name,
        'name' => $photo['title'],
        'date_available' => date('Y-m-d H:i:s'),
        'path' => './' . DEMO_IMAGE_DIR . $name,
        'filesize' => (int)ceil(filesize($file) / 1024),
        'width' => DEMO_WIDTH,
        'height' => DEMO_HEIGHT,
        'md5sum' => md5_file($file),
        'added_by' => (int)$conf['webmaster_id'],
        'rotation' => 0,
        'provenance_physical_album' => DEMO_ALBUM_PROVENANCE['provenance_physical_album'],
        'provenance_owner' => DEMO_ALBUM_PROVENANCE['provenance_owner'],
        'provenance_scanned_on' => DEMO_ALBUM_PROVENANCE['provenance_scanned_on'],
        'provenance_album_note' => DEMO_ALBUM_PROVENANCE['provenance_note'],
        );

    $text = DEMO_PHOTO_TEXTS[$photo['slug']] ?? null;
    if ($text !== null)
    {
        $insert['author'] = $text['author'];
        $insert['date_creation'] = $text['date_creation'];
        $insert['comment'] = $text['comment'];
        $insert['provenance_note'] = $text['note'];
    }

    single_insert(IMAGES_TABLE, $insert);
    $imageId = (int)pwg_db_insert_id(IMAGES_TABLE);

    $stored = pwg_db_fetch_assoc(pwg_query(
        'SELECT id, name, width, height FROM ' . IMAGES_TABLE . ' WHERE id = ' . $imageId
        ));
    if (empty($stored['id']) or $stored['name'] !== $photo['title'] or (int)$stored['width'] !== DEMO_WIDTH)
    {
        fail('photo ' . $name . ' was not inserted as expected');
    }

    $imageIds[] = $imageId;
    save_snapshot(array('album_id' => $albumId, 'image_ids' => $imageIds));

    $photos[$photo['slug']] = array(
        'id' => $imageId,
        'file' => $file,
        'title' => $photo['title'],
        'faces' => face_regions($photo),
        );
}

assert_german_texts_round_tripped($imageIds);

associate_images_to_categories($imageIds, array($albumId));

$linked = pwg_db_fetch_row(pwg_query(
    'SELECT COUNT(*) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id = ' . $albumId
    ));
if ((int)$linked[0] !== count($imageIds))
{
    fail('only ' . $linked[0] . ' of ' . count($imageIds) . ' photos reached the demo album');
}

foreach (DEMO_PHOTO_TAGS as $slug => $tagNames)
{
    $tagIds = array_map('tag_id_by_name', $tagNames);
    set_tags($tagIds, $photos[$slug]['id']);

    $assigned = pwg_db_fetch_row(pwg_query(
        'SELECT COUNT(*) FROM ' . IMAGE_TAG_TABLE . ' WHERE image_id = ' . $photos[$slug]['id']
        ));
    if ((int)$assigned[0] !== count($tagIds))
    {
        fail("photo $slug took " . $assigned[0] . ' of ' . count($tagIds) . ' tags');
    }
}

// The regions the persons overlay is photographed against.
$regionPhoto = $photos[DEMO_REGION_SLUG];
$regions = array();
foreach ($regionPhoto['faces'] as $i => $box)
{
    if ($i >= count(DEMO_PERSONS))
    {
        break;
    }
    $regions[] = array('name' => DEMO_PERSONS[$i]) + $box;
}

write_regions_with_exiftool($regionPhoto['file'], $regions, DEMO_WIDTH, DEMO_HEIGHT);
assert_regions_reached_the_file($regionPhoto['file'], $regions);

// The generated file changed size when exiftool rewrote it.
clearstatcache(true, $regionPhoto['file']);
single_update(
    IMAGES_TABLE,
    array(
        'filesize' => (int)ceil(filesize($regionPhoto['file']) / 1024),
        'md5sum' => md5_file($regionPhoto['file']),
        ),
    array('id' => $regionPhoto['id'])
    );

define('PERSONS_PATH', PHPWG_ROOT_PATH . 'plugins/persons/');
if (!is_file(PERSONS_PATH . 'include/index.inc.php'))
{
    fail('the persons plugin is not present; 05-personen.html cannot be illustrated without it');
}
// main.inc.php defines these when the plugin loads in a request; the indexer
// reads them directly, so a CLI caller has to supply them.
define('PERSONS_TABLE', $prefixeTable . 'persons');
define('PERSONS_REGION_TABLE', $prefixeTable . 'person_region');
require_once PERSONS_PATH . 'include/functions.inc.php';
require_once PERSONS_PATH . 'include/exiftool.inc.php';
require_once PERSONS_PATH . 'include/index.inc.php';

$outcome = persons_reindex_image($regionPhoto['id'], $regionPhoto['file']);
if (!$outcome['ok'])
{
    fail('indexing the demo regions failed: ' . $outcome['message']);
}
if ($outcome['regions'] !== count($regions))
{
    fail('indexed ' . $outcome['regions'] . ' of ' . count($regions) . ' demo regions');
}

update_category(array($albumId));
invalidate_user_cache();

$result = array(
    'album_id' => $albumId,
    'album_name' => DEMO_ALBUM_NAME,
    'album_path' => '/index.php?/category/' . $albumId,
    'region_photo_id' => $regionPhoto['id'],
    'persons' => DEMO_PERSONS,
    'photos' => array(),
    );

foreach ($photos as $slug => $photo)
{
    $result['photos'][$slug] = array(
        'id' => $photo['id'],
        'title' => $photo['title'],
        'picture_path' => '/picture.php?/' . $photo['id'] . '/category/' . $albumId,
        'faces' => $photo['faces'],
        );
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
