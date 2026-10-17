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
 *   php tests/e2e/support/seed.php --scenario=neighbours
 *   php tests/e2e/support/seed.php --scenario=empty --source=small
 *   php tests/e2e/support/seed.php --scenario=marked --rotation=3
 *   php tests/e2e/support/seed.php --scenario=marked-empty --rotation=3
 *   php tests/e2e/support/seed.php --scenario=overlay --drawn-by-viewer
 *   php tests/e2e/support/seed.php --scenario=overlay --coloured-tags
 *   php tests/e2e/support/seed.php --read-file-regions=<photo id>
 *   php tests/e2e/support/seed.php --exiftool=missing|present
 *   php tests/e2e/support/seed.php --originals=allowed|denied
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
const EDITOR_PERSONS = array('E2E Editor Ada', 'E2E Editor Grace', 'E2E Admin Ada', 'E2E Roles Mia');

/**
 * --coloured-tags: a typetags group holding the seeded persons' tags and an
 * ordinary tag on the photo, so the picture page decides which coloured badge
 * gets typetags' x. --restore removes both by name.
 */
const GUARD_GROUP_PREFIX = 'E2E Guard group ';
const GUARD_PLAIN_TAG = 'E2E Guard Plain';

/**
 * @return array person_tag_ids (name => id), plain_tag_id
 */
function colour_tags(Db $db, int $imageId): array
{
    if ($db->scalar("SELECT state FROM piwigo_plugins WHERE id = 'typetags'") !== 'active')
    {
        fail('--coloured-tags needs typetags active');
    }
    $db->query("INSERT INTO piwigo_typetags (name, color) VALUES ('" . GUARD_GROUP_PREFIX . bin2hex(random_bytes(4)) . "', '#3366cc')");
    $group = $db->insertId();
    $db->query("INSERT INTO piwigo_tags (name, url_name, id_typetags) VALUES ('" . GUARD_PLAIN_TAG . "', 'e2e_guard_plain', $group)");
    $plain = $db->insertId();
    $db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $plain)");

    $persons = array();
    foreach (SEEDED_PERSONS as $name)
    {
        $tag = (int)$db->scalar("SELECT tag_id FROM piwigo_persons WHERE name = '" . $db->escape($name) . "'");
        $db->query("UPDATE piwigo_tags SET id_typetags = $group WHERE id = $tag");
        $persons[$name] = $tag;
    }
    $coloured = (int)$db->scalar("SELECT COUNT(*) FROM piwigo_tags AS t JOIN piwigo_image_tag AS it ON it.tag_id = t.id"
        . " WHERE it.image_id = $imageId AND t.id_typetags = $group");
    if ($coloured !== count(SEEDED_PERSONS) + 1)
    {
        fail("--coloured-tags put $coloured coloured tags on the photo");
    }
    PiwigoRuntime::resetRequestCaches();
    persons_invalidate_tag_cache();

    return array('person_tag_ids' => $persons, 'plain_tag_id' => $plain);
}

function uncolour_tags(Db $db): void
{
    $plain = $db->scalar("SELECT id FROM piwigo_tags WHERE name = '" . GUARD_PLAIN_TAG . "'");
    if ($plain !== null)
    {
        $db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$plain);
        $db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$plain);
    }
    $groups = "SELECT id FROM piwigo_typetags WHERE name LIKE '" . GUARD_GROUP_PREFIX . "%'";
    $db->query("UPDATE piwigo_tags SET id_typetags = NULL WHERE id_typetags IN (SELECT id FROM ($groups) AS g)");
    $db->query("DELETE FROM piwigo_typetags WHERE name LIKE '" . GUARD_GROUP_PREFIX . "%'");
}

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

/** How far apart the neighbours' upload dates are put, so the album order is not left to a tie. */
const NEIGHBOUR_DATE_STEP = '1 DAY';

/**
 * Puts a previous and a next photo either side of the seeded one.
 *
 * The album is ordered by date_available DESC first (order_by_inside_category),
 * and all three rows were inserted in the same second, so the order would fall
 * to the random file names. Forcing the dates makes the seeded photo the middle
 * one, and the order is asserted - with the install's own ORDER BY clause, read
 * from piwigo_config rather than typed here a second time.
 *
 * @return array previous and next photo ids
 */
function seed_neighbours(Db $db, FixtureBuilder $builder, int $mainId, int $catId): array
{
    $previous = $builder->createTestImage();
    save_test_objects($builder);
    $next = $builder->createTestImage();
    save_test_objects($builder);
    $builder->attachImage((int)$previous['id'], $catId);
    $builder->attachImage((int)$next['id'], $catId);

    $db->query('UPDATE piwigo_images SET date_available = NOW() WHERE id = ' . $mainId);
    $db->query('UPDATE piwigo_images SET date_available = NOW() + INTERVAL ' . NEIGHBOUR_DATE_STEP
        . ' WHERE id = ' . (int)$previous['id']);
    $db->query('UPDATE piwigo_images SET date_available = NOW() - INTERVAL ' . NEIGHBOUR_DATE_STEP
        . ' WHERE id = ' . (int)$next['id']);

    $orderBy = (string)$db->scalar("SELECT value FROM piwigo_config WHERE param = 'order_by_inside_category'");
    if (stripos($orderBy, 'ORDER BY') === false)
    {
        fail("order_by_inside_category is not an ORDER BY clause: '$orderBy'");
    }

    $order = array();
    $result = $db->query(
        'SELECT id FROM piwigo_images WHERE id IN'
        . ' (SELECT image_id FROM piwigo_image_category WHERE category_id = ' . $catId . ') ' . $orderBy
    );
    while ($row = $result->fetch_assoc())
    {
        $order[] = (int)$row['id'];
    }

    $expected = array((int)$previous['id'], $mainId, (int)$next['id']);
    if ($order !== $expected)
    {
        fail('neighbours are not in the order previous, seeded, next: ' . json_encode($order));
    }

    return array('previous' => (int)$previous['id'], 'next' => (int)$next['id']);
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
    // Written aside and renamed: a seed killed mid-write must not leave a
    // truncated snapshot that every later seed refuses.
    $tmp = SNAPSHOT_FILE . '.tmp';
    if (file_put_contents($tmp, json_encode($snapshot, JSON_PRETTY_PRINT)) === false or !rename($tmp, SNAPSHOT_FILE))
    {
        fail('could not write the snapshot ' . SNAPSHOT_FILE);
    }
}

/**
 * Records everything the builder owns, as soon as it owns it: a seed that fails
 * or throws after creating a photo still leaves --restore a snapshot naming it.
 */
function save_test_objects(FixtureBuilder $builder): void
{
    save_snapshot(array('test_objects' => $builder->exportTestObjects()));
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
 * Records an account as the drawer of every region the seed indexed, and
 * asserts it took.
 */
function mark_drawn_by(Db $db, int $imageId, string $username, int $expected): void
{
    $userId = $db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $db->escape($username) . "'");
    if ($userId === null)
    {
        fail("--drawn-by-viewer: no account $username; run create-test-users.php");
    }

    $db->query('UPDATE piwigo_person_region SET drawn_by = ' . (int)$userId . ' WHERE image_id = ' . $imageId);

    $marked = (int)$db->scalar(
        'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . $imageId . ' AND drawn_by = ' . (int)$userId
    );
    if ($marked !== $expected)
    {
        fail("--drawn-by-viewer marked $marked of $expected regions");
    }
}

/** The account the picture page specs run as: --originals sets its access to originals, --drawn-by-viewer makes it the drawer. */
const PAGE_ACCOUNT = 'persons_normal';

/**
 * Lets the normal test account see original files, or not.
 *
 * core offers the original only when the viewer's piwigo_user_infos.enabled_high
 * is 'true' (picture.php), and the zoom's 100 % is the largest file offered. The
 * column defaults to 'true' and create-test-users.php inherits it rather than
 * setting it, so both states are forced rather than hoped for. --restore puts
 * 'true' back: the column default, and what this test-only account holds when
 * nothing forced it.
 */
function set_originals_state(Db $db, string $state): array
{
    if (!in_array($state, array('allowed', 'denied'), true))
    {
        fail('--originals must be one of: allowed, denied');
    }

    $value = $state === 'allowed' ? 'true' : 'false';
    $user = "SELECT id FROM piwigo_users WHERE username = '" . $db->escape(PAGE_ACCOUNT) . "'";
    $db->query("UPDATE piwigo_user_infos SET enabled_high = '$value' WHERE user_id = ($user)");

    $stored = $db->scalar("SELECT enabled_high FROM piwigo_user_infos WHERE user_id = ($user)");
    if ($stored !== $value)
    {
        fail('could not force ' . PAGE_ACCOUNT . "'s originals to $state; run tests/Support/create-test-users.php");
    }

    return array('originals' => $state);
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

$args = getopt('', array('scenario::', 'source::', 'rotation::', 'restore', 'read-file-regions::', 'exiftool::', 'originals::', 'person-counts', 'drawn-by-viewer', 'coloured-tags'));

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
    set_originals_state($db, 'allowed');
    $builder->destroyPersons(array_merge(SEEDED_PERSONS, EDITOR_PERSONS));
    uncolour_tags($db);

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

if (isset($args['originals']))
{
    echo json_encode(set_originals_state($db, (string)$args['originals'])), "\n";
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
if (!in_array($scenario, array('overlay', 'stale', 'empty', 'neighbours', 'marked', 'marked-empty'), true))
{
    fail('--scenario must be one of: overlay, stale, empty, neighbours, marked, marked-empty');
}

// 'marked' and 'marked-empty' generate a photo with a red block at
// FixtureBuilder::MARKER instead of copying one, and give it the images.rotation
// code --rotation asks for, as core would for a JPEG with an EXIF Orientation.
// 'marked' tags the block; 'marked-empty' is the editor's untagged start.
$marked = in_array($scenario, array('marked', 'marked-empty'), true);
$wantedRotation = (int)($args['rotation'] ?? 0);
if (!in_array($wantedRotation, array(0, 1, 2, 3), true))
{
    fail('--rotation must be a code 0..3');
}
if ($wantedRotation !== 0 && !$marked)
{
    fail('--rotation needs --scenario=marked or marked-empty');
}

if (!$builder->tableExists('piwigo_person_region'))
{
    fail('the persons plugin is not installed; activate it before seeding');
}

// A snapshot still here belongs to a seed nobody restored, a spec killed before
// its afterEach. Carried forward, so the next --restore removes that seed's
// photo and album too instead of this seed overwriting the only record of them.
$leftover = load_snapshot();
if ($leftover !== null)
{
    $builder->importTestObjects($leftover['test_objects'] ?? array());
}

// --source=small copies the smallest gallery image instead of the first one,
// for the picture page's upscaling case. Only the seeded photo, not its
// neighbours. The spec asserts the size it needs against what is printed.
try
{
    $image = $marked
        ? $builder->createMarkedImage()
        : $builder->createTestImage($args['source'] ?? FixtureBuilder::SOURCE_FIRST);
}
catch (InvalidArgumentException $e)
{
    fail('--source: ' . $e->getMessage());
}
save_test_objects($builder);
$catId = $builder->createTestAlbum('Persons E2E ' . bin2hex(random_bytes(4)));
save_test_objects($builder);
$builder->attachImage((int)$image['id'], $catId);
$builder->invalidateUserCache();

if ($marked)
{
    $db->query('UPDATE piwigo_images SET rotation = ' . $wantedRotation . ' WHERE id = ' . (int)$image['id']);
    if ((int)$db->scalar('SELECT rotation FROM piwigo_images WHERE id = ' . (int)$image['id']) !== $wantedRotation)
    {
        fail("the photo's rotation did not become $wantedRotation");
    }
}

// 'neighbours' puts a previous and a next photo either side of the seeded one,
// for the theme's click-to-navigate zones; like 'empty' it writes no regions.
$neighbours = null;
if ($scenario === 'neighbours')
{
    $neighbours = seed_neighbours($db, $builder, (int)$image['id'], $catId);
}

// 'empty' is the state the editor starts from: a photo nobody has tagged yet.
// It writes no regions at all rather than writing some and deleting them, so
// the file really is untouched when the first box is drawn onto it.
if (!in_array($scenario, array('empty', 'neighbours', 'marked-empty'), true))
{
    $regions = $scenario === 'marked'
        ? array(array_merge(array('name' => SEEDED_PERSONS[0]), FixtureBuilder::MARKER))
        : SEEDED_REGIONS;
    list($appliedW, $appliedH) = applied_dimensions($scenario, $image);
    $builder->writeRegionsWithExiftool($image, $regions, $appliedW, $appliedH);

    $outcome = persons_reindex_image((int)$image['id'], $image['file']);
    if (!$outcome['ok'])
    {
        fail('seed reindex failed: ' . $outcome['message']);
    }
    if ($outcome['regions'] !== count($regions))
    {
        fail('seed indexed ' . $outcome['regions'] . ' of ' . count($regions) . ' regions');
    }

    // Regions written by exiftool are nobody's (decision 0065); a spec that
    // deletes them as a normal account says who drew them.
    if (isset($args['drawn-by-viewer']))
    {
        mark_drawn_by($db, (int)$image['id'], PAGE_ACCOUNT, count($regions));
    }
}
elseif (isset($args['drawn-by-viewer']) or isset($args['coloured-tags']))
{
    fail("--drawn-by-viewer and --coloured-tags need a scenario that seeds regions, not '$scenario'");
}
else
{
    $indexed = (int)$db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$image['id']);
    if ($indexed !== 0)
    {
        fail("the '$scenario' scenario expected an untagged photo, found $indexed regions");
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
        persons_display_turns($rotation)
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

save_test_objects($builder);

$output = array(
    'scenario' => $scenario,
    'album_id' => $catId,
    'photo_id' => (int)$image['id'],
    'width' => (int)$image['width'],
    'height' => (int)$image['height'],
    'picture_path' => '/picture.php?/' . (int)$image['id'] . '/category/' . $catId,
    'album_path' => '/index.php?/category/' . $catId,
    'regions' => $expected,
    );
if ($marked)
{
    $output['rotation'] = $rotation;
    $output['marker'] = FixtureBuilder::MARKER;
}
if (isset($args['coloured-tags']))
{
    $output = array_merge($output, colour_tags($db, (int)$image['id']));
}
if ($neighbours !== null)
{
    $output['previous_path'] = '/picture.php?/' . $neighbours['previous'] . '/category/' . $catId;
    $output['next_path'] = '/picture.php?/' . $neighbours['next'] . '/category/' . $catId;
}

echo json_encode($output, JSON_UNESCAPED_UNICODE), "\n";
