<?php
/**
 * Scenario seeding CLI for the persons E2E suite.
 *
 * Setup-before, not cleanup-after: the scenario creates an album and a photo of
 * its own, writes MWG regions into that photo's file with a plain exiftool call
 * and indexes them, then prints the state it achieved so a spec asserts against
 * what is really there rather than a shape guessed from the scenario name.
 *
 * A throwaway album and a copied photo, never a real scan: the regions are
 * written into the image file in place.
 *
 * PHPUnit fixtures live and die inside one process. Playwright seeds from a
 * separate short-lived process, so what was created is exported to a snapshot
 * file and re-imported by --restore, which removes it again.
 *
 * Usage:
 *   php tests/e2e/support/seed.php --scenario=overlay
 *   php tests/e2e/support/seed.php --scenario=stale
 *   php tests/e2e/support/seed.php --scenario=empty
 *   php tests/e2e/support/seed.php --read-file-regions=<photo id>
 *   php tests/e2e/support/seed.php --exiftool=missing|present
 *   php tests/e2e/support/seed.php --restore
 *
 * Both forms print one JSON object on stdout. Errors go to stderr with exit 1.
 *
 * This mutates the database and rewrites image files. It is not safe against a
 * production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

/** The names this suite tags with. Distinctive, so a leftover row is obvious. */
const SEEDED_PERSONS = array('E2E Overlay Jane', 'E2E Overlay John');

/**
 * The names the editor specs create through the UI.
 *
 * Removed by --restore alongside the seeded ones: they are created by the code
 * under test rather than by this script, so nothing else knows they exist.
 */
const EDITOR_PERSONS = array('E2E Editor Ada', 'E2E Editor Grace', 'E2E Admin Ada');

/**
 * The regions written into the fixture photo.
 *
 * Deliberately different sizes and both away from the edges, so a box that is
 * placed with the wrong origin, or with width and height swapped, lands
 * somewhere a 2px tolerance cannot forgive.
 */
const SEEDED_REGIONS = array(
    array('name' => 'E2E Overlay Jane', 'x' => 0.30, 'y' => 0.40, 'w' => 0.10, 'h' => 0.20),
    array('name' => 'E2E Overlay John', 'x' => 0.70, 'y' => 0.35, 'w' => 0.16, 'h' => 0.12),
);

/**
 * The AppliedToDimensions the regions are written against.
 *
 * 'overlay' writes the photo's own, so nothing is stale. 'stale' writes a ratio
 * no crop of this photo could have - four times as wide as it is tall - which is
 * far outside PERSONS_STALE_RATIO_TOLERANCE and is what a region written before
 * a re-crop looks like.
 *
 * @return array W, H
 */
function applied_dimensions(string $scenario, array $image): array
{
    if ($scenario === 'stale')
    {
        return array(4000, 1000);
    }

    return array((int)$image['width'], (int)$image['height']);
}

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
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
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir))
    {
        fail("could not create snapshot directory $dir");
    }
    file_put_contents(SNAPSHOT_FILE, json_encode($snapshot, JSON_PRETTY_PRINT));
}

/** The config row that points the plugin at its exiftool binary. */
const EXIFTOOL_PATH_PARAM = 'persons_exiftool_path';

/** A directory holding no exiftool, so the probe fails the way a bare host does. */
const EXIFTOOL_MISSING_DIR = '/nonexistent-persons-e2e/';

/**
 * Makes the server look as though it has no exiftool, and puts it back.
 *
 * load_conf_from_db() copies every piwigo_config row into $conf before plugins
 * load, and main.inc.php only defaults the path when nothing set it - so one row
 * is enough to send the probe at a binary that is not there. Forced rather than
 * hoped for: a spec about the disabled state must not pass on a host that simply
 * happens to lack exiftool for some other reason.
 */
function set_exiftool_state(Db $db, string $state): array
{
    if (!in_array($state, array('missing', 'present'), true))
    {
        fail('--exiftool must be one of: missing, present');
    }

    $db->query("DELETE FROM piwigo_config WHERE param = '" . EXIFTOOL_PATH_PARAM . "'");

    if ($state === 'missing')
    {
        $db->query(
            "INSERT INTO piwigo_config (param, value) VALUES ('" . EXIFTOOL_PATH_PARAM . "', '"
            . $db->escape(EXIFTOOL_MISSING_DIR) . "')"
        );
    }

    $stored = $db->scalar("SELECT value FROM piwigo_config WHERE param = '" . EXIFTOOL_PATH_PARAM . "'");
    $expected = $state === 'missing' ? EXIFTOOL_MISSING_DIR : null;

    if ($stored !== $expected)
    {
        fail("could not force the exiftool state to $state");
    }

    return array('exiftool' => $state);
}

/**
 * What the image file itself says, read by a plain exiftool call.
 *
 * Deliberately not through the plugin's own reader: a spec asserting that a
 * write really landed in the file must not be satisfied by the same parser that
 * produced it. This runs in its own process, started after the browser's write
 * finished.
 *
 * @return array regions (name and area) and the PersonInImage names
 */
function read_file_regions(Db $db, int $imageId): array
{
    $path = $db->scalar('SELECT path FROM piwigo_images WHERE id = ' . $imageId);
    if ($path === null)
    {
        fail("no photo with id $imageId");
    }

    $file = PIWIGO_ROOT . ltrim((string)$path, './');
    if (!is_file($file))
    {
        fail("photo $imageId has no file at $file");
    }

    $out = array();
    $status = 1;
    exec(
        'exiftool -json -struct -charset filename=UTF8 -XMP-mwg-rs:RegionInfo'
        . ' -XMP-iptcExt:PersonInImage ' . escapeshellarg($file) . ' 2>/dev/null',
        $out,
        $status
    );

    if ($status !== 0)
    {
        fail('exiftool could not read ' . $file);
    }

    $decoded = json_decode(implode("\n", $out), true);
    $info = $decoded[0]['RegionInfo']['RegionList'] ?? array();

    $regions = array();
    foreach ($info as $entry)
    {
        $regions[] = array(
            'name' => $entry['Name'] ?? '',
            'type' => $entry['Type'] ?? '',
            'x' => isset($entry['Area']['X']) ? (float)$entry['Area']['X'] : null,
            'y' => isset($entry['Area']['Y']) ? (float)$entry['Area']['Y'] : null,
            'w' => isset($entry['Area']['W']) ? (float)$entry['Area']['W'] : null,
            'h' => isset($entry['Area']['H']) ? (float)$entry['Area']['H'] : null,
            );
    }

    $persons = $decoded[0]['PersonInImage'] ?? array();

    return array(
        'photo_id' => $imageId,
        'regions' => $regions,
        'persons' => is_array($persons) ? $persons : array($persons),
        );
}

/**
 * Every person in the index with the photo and region counts the database holds.
 *
 * The oracle for the admin screen's own numbers. Read here rather than in the
 * spec because a browser cannot reach MariaDB, and computed with the same two
 * aggregate forms the screen uses so that a mistake in one of them shows up as
 * a disagreement rather than as two matching wrong numbers - the query lives in
 * admin/persons.php and this one has to be written separately for it to be a
 * check at all.
 *
 * @return array name => array(photos, regions)
 */
function person_counts(Db $db): array
{
    $counts = array();

    $result = $db->query('
SELECT p.name,
       (SELECT COUNT(*) FROM piwigo_person_region WHERE person_id = p.id) AS regions,
       (SELECT COUNT(DISTINCT image_id) FROM piwigo_person_region WHERE person_id = p.id) AS photos
  FROM piwigo_persons AS p
');

    while ($row = $result->fetch_assoc())
    {
        $counts[$row['name']] = array(
            'photos' => (int)$row['photos'],
            'regions' => (int)$row['regions'],
            );
    }

    return $counts;
}

$args = getopt('', array('scenario::', 'restore', 'read-file-regions::', 'exiftool::', 'person-counts'));

$db = new Db();
$builder = new FixtureBuilder($db);
PiwigoRuntime::loadPlugin();
PiwigoRuntime::resetRequestCaches();

if (isset($args['restore']))
{
    // Unconditional, before the snapshot is even looked for: a spec killed
    // between forcing the state and restoring it would otherwise leave every
    // later page without an editor, and the persons the specs create through
    // the UI are known by name rather than by anything the snapshot holds - a
    // leftover one puts a stranger at the top of the next run's picker.
    $db->query("DELETE FROM piwigo_config WHERE param = '" . EXIFTOOL_PATH_PARAM . "'");
    $builder->destroyPersons(array_merge(SEEDED_PERSONS, EDITOR_PERSONS));

    $snapshot = load_snapshot();
    if ($snapshot === null)
    {
        echo json_encode(array('restored' => false, 'reason' => 'no snapshot')), "\n";
        exit(0);
    }

    $builder->importTestObjects($snapshot['test_objects'] ?? array());
    $builder->destroyTestImages();
    $builder->destroyTestAlbums();

    @unlink(SNAPSHOT_FILE);
    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

if (isset($args['exiftool']))
{
    echo json_encode(set_exiftool_state($db, (string)$args['exiftool'])), "\n";
    exit(0);
}

if (isset($args['person-counts']))
{
    echo json_encode(person_counts($db), JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

if (isset($args['read-file-regions']))
{
    echo json_encode(read_file_regions($db, (int)$args['read-file-regions']), JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

$scenario = $args['scenario'] ?? '';
if (!in_array($scenario, array('overlay', 'stale', 'empty'), true))
{
    fail('--scenario must be one of: overlay, stale, empty');
}

if (!$builder->tableExists('piwigo_person_region'))
{
    fail('the persons plugin is not installed; activate it before seeding');
}

$image = $builder->createTestImage();
$catId = $builder->createTestAlbum('Persons E2E ' . bin2hex(random_bytes(4)));
$builder->attachImage((int)$image['id'], $catId);
$builder->invalidateUserCache();

// 'empty' is the state the editor starts from: a photo nobody has tagged yet.
// It writes no regions at all rather than writing some and deleting them, so
// the file really is untouched when the first box is drawn onto it.
if ($scenario !== 'empty')
{
    list($appliedW, $appliedH) = applied_dimensions($scenario, $image);
    $builder->writeRegionsWithExiftool($image, SEEDED_REGIONS, $appliedW, $appliedH);

    $outcome = persons_reindex_image((int)$image['id'], $image['file']);
    if (!$outcome['ok'])
    {
        fail('seed reindex failed: ' . $outcome['message']);
    }
    if ($outcome['regions'] !== count(SEEDED_REGIONS))
    {
        fail('seed indexed ' . $outcome['regions'] . ' of ' . count(SEEDED_REGIONS) . ' regions');
    }
}
else
{
    $indexed = (int)$db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$image['id']);
    if ($indexed !== 0)
    {
        fail("the 'empty' scenario expected an untagged photo, found $indexed regions");
    }
}

// The corners the boxes must land on, computed with the same pure helpers the
// page uses. A spec that recomputed them would be a second implementation of
// the conversion, agreeing with a wrong one just as happily.
$rotation = (int)($db->scalar('SELECT COALESCE(rotation, 0) FROM piwigo_images WHERE id = ' . (int)$image['id']));

$expected = array();
foreach (persons_indexed_regions((int)$image['id']) as $row)
{
    $rotated = persons_rotate_region(
        array(
            'x' => (float)$row['area_x'],
            'y' => (float)$row['area_y'],
            'w' => (float)$row['area_w'],
            'h' => (float)$row['area_h'],
            ),
        $rotation
        );
    $corner = persons_center_to_corner($rotated['x'], $rotated['y'], $rotated['w'], $rotated['h']);

    $expected[] = array(
        'region_id' => (int)$row['id'],
        'name' => $row['name'],
        // Computed with the same helper the page uses, so the spec asserts
        // against what the page will really decide rather than against the
        // scenario name.
        'stale' => persons_region_is_stale(
            $row['applied_w'], $row['applied_h'], (int)$image['width'], (int)$image['height']),
        'left' => $corner['left'],
        'top' => $corner['top'],
        'w' => $corner['w'],
        'h' => $corner['h'],
        );
}

save_snapshot(array('test_objects' => $builder->exportTestObjects()));

echo json_encode(array(
    'scenario' => $scenario,
    'album_id' => $catId,
    'photo_id' => (int)$image['id'],
    'picture_path' => '/picture.php?/' . (int)$image['id'] . '/category/' . $catId,
    'album_path' => '/index.php?/category/' . $catId,
    'regions' => $expected,
    ), JSON_UNESCAPED_UNICODE), "\n";
