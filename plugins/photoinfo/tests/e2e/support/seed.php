<?php
/**
 * Scenario seeding CLI for the photoinfo E2E suite.
 *
 * Creates a public album of its own holding one copied photo - never a real
 * scan, since a save rewrites the file - and prints what it created. The
 * Playwright process cannot share PHP state with this one, so what was created
 * is saved to a snapshot file and removed again by --restore.
 *
 * Usage:
 *   php tests/e2e/support/seed.php --scenario=photo    a copy of a gallery PNG
 *   php tests/e2e/support/seed.php --read-file=<id>    the photo's caption slots and info tag
 *   php tests/e2e/support/seed.php --read-row=<id>     the photo's comment and date columns
 *   php tests/e2e/support/seed.php --read-date=<id>    the photo's date tags
 *   php tests/e2e/support/seed.php --read-only=<id>    makes the photo's file unwritable, so a save's file write fails
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

$args = getopt('', array('scenario::', 'restore', 'read-file:', 'read-row:', 'read-date:', 'read-only:'));

$db = new Db();
$builder = new FixtureBuilder($db);

try
{
    if (isset($args['read-file']))
    {
        $file = PIWIGO_ROOT . ltrim((string)$builder->imageRow((int)$args['read-file'])['path'], './');
        echo json_encode(FixtureBuilder::readFileTags($file)), "\n";
        exit(0);
    }

    if (isset($args['read-only']))
    {
        // --restore deletes the file whatever its mode: the directory stays writable.
        $file = PIWIGO_ROOT . ltrim((string)$builder->imageRow((int)$args['read-only'])['path'], './');
        chmod($file, 0444);
        clearstatcache();
        if (is_writable($file))
        {
            fail("could not make $file read-only");
        }
        echo json_encode(array('read_only' => true)), "\n";
        exit(0);
    }

    if (isset($args['read-date']))
    {
        $file = PIWIGO_ROOT . ltrim((string)$builder->imageRow((int)$args['read-date'])['path'], './');
        echo json_encode(FixtureBuilder::readDateTags($file)), "\n";
        exit(0);
    }

    if (isset($args['read-row']))
    {
        $row = $builder->imageRow((int)$args['read-row']);
        echo json_encode(array(
            'comment' => $row['comment'],
            'date_creation' => $row['date_creation'],
            'photoinfo_date_precision' => $row['photoinfo_date_precision'],
            )), "\n";
        exit(0);
    }
}
catch (RuntimeException $e)
{
    fail($e->getMessage());
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
if ($scenario !== 'photo')
{
    fail('--scenario must be: photo');
}

if (is_file(SNAPSHOT_FILE))
{
    fail('a previous seed was not restored; run --restore first');
}

$image = $builder->createTestImage();
$catId = $builder->createTestAlbum('Photoinfo E2E ' . bin2hex(random_bytes(4)));
$builder->attachImage((int)$image['id'], $catId);
$builder->invalidateUserCache();

$dir = dirname(SNAPSHOT_FILE);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir))
{
    fail("could not create $dir");
}
file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));

echo json_encode(array(
    'image_id' => (int)$image['id'],
    'album_id' => $catId,
    'picture_path' => '/picture.php?/' . (int)$image['id'] . '/category/' . $catId,
    'album_path' => '/index.php?/category/' . $catId,
    )), "\n";
