<?php
/**
 * Scenario seeding CLI for the photoedit E2E suite.
 *
 * Creates an album of its own holding one copied photo - never a real scan,
 * since later specs rewrite the file - and prints what it created. The
 * Playwright process cannot share PHP state with this one, so what was created
 * is saved to a snapshot file and removed again by --restore.
 *
 * Usage:
 *   php tests/e2e/support/seed.php --scenario=photo    a copy of a gallery PNG
 *   php tests/e2e/support/seed.php --scenario=marked   a generated 300x200 PNG, for specs that save
 *   php tests/e2e/support/seed.php --scenario=regions  marked, plus two person regions in the file
 *   php tests/e2e/support/seed.php --scenario=jpeg     marked, as an upright JPEG
 *   php tests/e2e/support/seed.php --scenario=warnings --drawn-by=NORMAL
 *                                   jpeg plus the two regions, indexed by persons
 *                                   and drawn by that TestUsers role (none for a guest)
 *   php tests/e2e/support/seed.php --scenario=neighbours photo, plus a previous and a next photo in the same album
 *   php tests/e2e/support/seed.php --age-derivatives   backdate the seeded photo's derivatives
 *   php tests/e2e/support/seed.php --restore
 *
 * Prints one JSON object on stdout; errors go to stderr with exit 1.
 * It mutates the database and is never safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$args = getopt('', array('scenario::', 'restore', 'age-derivatives', 'drawn-by::'));

/** How far apart the neighbours' upload dates are put, so the album order is not left to a tie. */
const NEIGHBOUR_DATE_STEP = '1 DAY';

/**
 * Puts a previous and a next photo either side of the seeded one, so the
 * theme offers both navigation arrows as links.
 *
 * The album is ordered by date_available DESC first (order_by_inside_category),
 * and all three rows were inserted in the same second, so the order would fall
 * to the random file names. Forcing the dates makes the seeded photo the middle
 * one, and the order is asserted with the install's own ORDER BY clause, read
 * from piwigo_config. On a failed check the fixtures are removed before
 * exiting: no snapshot is saved yet, so --restore would never find them.
 *
 * @return array previous and next photo ids
 */
function seed_neighbours(Db $db, FixtureBuilder $builder, int $mainId, int $catId): array
{
    $previous = $builder->createTestImage();
    $next = $builder->createTestImage();
    $builder->attachImage((int)$previous['id'], $catId);
    $builder->attachImage((int)$next['id'], $catId);

    $db->query('UPDATE piwigo_images SET date_available = NOW() WHERE id = ' . $mainId);
    $db->query('UPDATE piwigo_images SET date_available = NOW() + INTERVAL ' . NEIGHBOUR_DATE_STEP
        . ' WHERE id = ' . (int)$previous['id']);
    $db->query('UPDATE piwigo_images SET date_available = NOW() - INTERVAL ' . NEIGHBOUR_DATE_STEP
        . ' WHERE id = ' . (int)$next['id']);

    $orderBy = (string)$db->scalar("SELECT value FROM piwigo_config WHERE param = 'order_by_inside_category'");
    $order = array();
    if (stripos($orderBy, 'ORDER BY') !== false)
    {
        $result = $db->query(
            'SELECT id FROM piwigo_images WHERE id IN'
            . ' (SELECT image_id FROM piwigo_image_category WHERE category_id = ' . $catId . ') ' . $orderBy
        );
        while ($row = $result->fetch_assoc())
        {
            $order[] = (int)$row['id'];
        }
    }

    if ($order !== array((int)$previous['id'], $mainId, (int)$next['id']))
    {
        $builder->destroyTestImages();
        $builder->destroyTestAlbums();
        fail("the photos are not in the order previous, seeded, next under '$orderBy': " . json_encode($order));
    }

    return array('previous' => (int)$previous['id'], 'next' => (int)$next['id']);
}

/**
 * Has persons index the seeded regions, as the webmaster over ws.php, and
 * records the account of one TestUsers role (WEBMASTER, ADMIN, NORMAL, GENERIC)
 * as their drawer, so a normal account may cut them away (persons decision
 * 0065). Asserts both took effect.
 */
function index_regions_drawn_by(Db $db, array $image, ?string $role): void
{
    $client = new WsClient();
    list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
    $client->login($username, $password);
    $res = $client->call('pwg.persons.rescan', array('image_ids' => (string)$image['id'], 'pwg_token' => $client->token()));
    $count = (int)$db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$image['id']);
    if (($res['json']['stat'] ?? null) !== 'ok' || $count !== 2)
    {
        throw new RuntimeException("persons indexed $count of 2 regions: " . $res['body']);
    }
    if ($role === null)
    {
        return;
    }

    $constant = 'TestUsers::' . strtoupper($role);
    if (!defined($constant))
    {
        throw new RuntimeException("--drawn-by: no role $role in TestUsers");
    }
    $drawer = (int)$db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $db->escape(constant($constant)) . "'");
    $db->query("UPDATE piwigo_person_region SET drawn_by = $drawer WHERE image_id = " . (int)$image['id']);
    if ($drawer <= 0 || (int)$db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$image['id'] . " AND drawn_by = $drawer") !== 2)
    {
        throw new RuntimeException("--drawn-by=$role did not take: run create-test-users.php");
    }
}

/** How far back --age-derivatives sets the derivatives' mtime: old enough for a browser to cache them for hours. */
const DERIVATIVE_AGE_SECONDS = 10 * 24 * 3600;

$db = new Db();
$builder = new FixtureBuilder($db);

// Makes the seeded photo's derivatives look old, as those of a real scan do.
// nginx serves them with nothing but Last-Modified, from which a browser derives
// how long it may reuse them without asking (heuristic freshness).
if (isset($args['age-derivatives']))
{
    $snapshot = is_file(SNAPSHOT_FILE) ? json_decode((string)file_get_contents(SNAPSHOT_FILE), true) : null;
    if (!is_array($snapshot) || empty($snapshot['images']))
    {
        fail('nothing seeded to age');
    }

    $aged = 0;
    foreach ($snapshot['images'] as $image)
    {
        $stem = PIWIGO_ROOT . '_data/i/' . substr(ltrim($image['db_path'], './'), 0, -strlen('.' . pathinfo($image['db_path'], PATHINFO_EXTENSION)));
        foreach (glob($stem . '-*') as $derivative)
        {
            touch($derivative, time() - DERIVATIVE_AGE_SECONDS);
            $aged++;
        }
    }
    if ($aged === 0)
    {
        fail('the seeded photo has no derivatives yet; open its page first');
    }

    echo json_encode(array('aged' => $aged)), "\n";
    exit(0);
}

if (isset($args['restore']))
{
    if (!is_file(SNAPSHOT_FILE))
    {
        echo json_encode(array('restored' => false, 'reason' => 'no snapshot')), "\n";
        exit(0);
    }

    $snapshot = json_decode((string)file_get_contents(SNAPSHOT_FILE), true);
    if (!is_array($snapshot))
    {
        fail('snapshot file is not valid JSON: ' . SNAPSHOT_FILE);
    }

    $builder->importTestObjects($snapshot);
    $builder->destroyTestImages();
    // A save made persons index the regions --scenario=regions wrote, and a
    // spec that tags a person through the UI uses one of these names too.
    $builder->destroyPersons(array(FixtureBuilder::REGION_KEPT['name'], FixtureBuilder::REGION_CUT['name']));
    $builder->destroyTestAlbums();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

$scenario = $args['scenario'] ?? '';
if (!in_array($scenario, array('photo', 'marked', 'regions', 'jpeg', 'neighbours', 'warnings'), true))
{
    fail('--scenario must be: photo, marked, regions, jpeg, neighbours, warnings');
}

if (is_file(SNAPSHOT_FILE))
{
    fail('a previous seed was not restored; run --restore first');
}

try
{
    $builder->assertPluginActive();
}
catch (RuntimeException $e)
{
    fail($e->getMessage());
}

// marked: a generated landscape PNG, which a save rewrites - never a copy of a scan.
if ($scenario === 'photo' or $scenario === 'neighbours')
{
    $image = $builder->createTestImage();
}
elseif ($scenario === 'jpeg' or $scenario === 'warnings')
{
    $image = $builder->createMarkedJpeg(1);
}
else
{
    $image = $builder->createMarkedImage();
}
if ($scenario === 'regions' or $scenario === 'warnings')
{
    // Only the file: the editor's warning reads the regions from there.
    $builder->writeRegions($image, array(FixtureBuilder::REGION_KEPT, FixtureBuilder::REGION_CUT));
}
$catId = $builder->createTestAlbum('Photoedit E2E ' . bin2hex(random_bytes(4)));
$builder->attachImage((int)$image['id'], $catId);
$neighbours = $scenario === 'neighbours' ? seed_neighbours($db, $builder, (int)$image['id'], $catId) : null;
$builder->invalidateUserCache();
if ($scenario === 'warnings')
{
    // A normal account's crop of a region somebody else drew is refused, so
    // the regions are the viewer's own; persons must index them first.
    try
    {
        index_regions_drawn_by($db, $image, $args['drawn-by'] ?? null);
    }
    catch (RuntimeException $e)
    {
        $builder->destroyTestImages();
        $builder->destroyTestAlbums();
        $builder->destroyPersons(array(FixtureBuilder::REGION_KEPT['name'], FixtureBuilder::REGION_CUT['name']));
        fail($e->getMessage());
    }
}

$dir = dirname(SNAPSHOT_FILE);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir))
{
    fail("could not create $dir");
}
file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));

echo json_encode(array(
    'photo_id' => (int)$image['id'],
    'album_id' => $catId,
    'width' => $image['width'],
    'height' => $image['height'],
    'kept_name' => FixtureBuilder::REGION_KEPT['name'],
    'cut_name' => FixtureBuilder::REGION_CUT['name'],
    'picture_path' => '/picture.php?/' . (int)$image['id'] . '/category/' . $catId,
    'album_path' => '/index.php?/category/' . $catId,
    'previous_path' => $neighbours === null ? null : '/picture.php?/' . $neighbours['previous'] . '/category/' . $catId,
    'next_path' => $neighbours === null ? null : '/picture.php?/' . $neighbours['next'] . '/category/' . $catId,
    )), "\n";
