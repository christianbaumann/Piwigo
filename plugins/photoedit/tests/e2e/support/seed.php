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

$args = getopt('', array('scenario::', 'restore', 'age-derivatives'));

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
        $stem = PIWIGO_ROOT . '_data/i/' . substr(ltrim($image['db_path'], './'), 0, -strlen('.png'));
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
    $builder->destroyTestAlbums();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

$scenario = $args['scenario'] ?? '';
if (!in_array($scenario, array('photo', 'marked'), true))
{
    fail('--scenario must be: photo, marked');
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
$image = $scenario === 'marked' ? $builder->createMarkedImage() : $builder->createTestImage();
$catId = $builder->createTestAlbum('Photoedit E2E ' . bin2hex(random_bytes(4)));
$builder->attachImage((int)$image['id'], $catId);
$builder->invalidateUserCache();

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
    'picture_path' => '/picture.php?/' . (int)$image['id'] . '/category/' . $catId,
    'album_path' => '/index.php?/category/' . $catId,
    )), "\n";
