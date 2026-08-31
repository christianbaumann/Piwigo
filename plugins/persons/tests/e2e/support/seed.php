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

$args = getopt('', array('scenario::', 'restore'));

$db = new Db();
$builder = new FixtureBuilder($db);
PiwigoRuntime::loadPlugin();
PiwigoRuntime::resetRequestCaches();

if (isset($args['restore']))
{
    $snapshot = load_snapshot();
    if ($snapshot === null)
    {
        echo json_encode(array('restored' => false, 'reason' => 'no snapshot')), "\n";
        exit(0);
    }

    $builder->importTestObjects($snapshot['test_objects'] ?? array());
    $builder->destroyTestImages();
    $builder->destroyTestAlbums();
    $builder->destroyPersons(SEEDED_PERSONS);

    @unlink(SNAPSHOT_FILE);
    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

$scenario = $args['scenario'] ?? '';
if (!in_array($scenario, array('overlay', 'stale'), true))
{
    fail('--scenario must be one of: overlay, stale');
}

if (!$builder->tableExists('piwigo_person_region'))
{
    fail('the persons plugin is not installed; activate it before seeding');
}

$image = $builder->createTestImage();
$catId = $builder->createTestAlbum('Persons E2E ' . bin2hex(random_bytes(4)));
$builder->attachImage((int)$image['id'], $catId);
$builder->invalidateUserCache();

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
