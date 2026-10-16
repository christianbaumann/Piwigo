<?php
/**
 * Scenario seeding CLI for the galleryrules E2E suite.
 *
 * Creates albums and a generated photo of its own - never a real scan - and
 * prints what it created. The Playwright process cannot share PHP state with
 * this one, so what was created is saved to a snapshot file and removed again
 * by --restore.
 *
 * Usage:
 *   php tests/e2e/support/seed.php --scenario=payload   a parent album, an album and a photo whose
 *                                                        Titel, Autor, Info, album name and description
 *                                                        each hold an <img onerror> naming its field
 *   php tests/e2e/support/seed.php --scenario=same-name an album with two photos of one file name, for the
 *                                                        replace option's refusal
 *   php tests/e2e/support/seed.php --restore
 *
 * Prints one JSON object on stdout; errors go to stderr with exit 1.
 * It mutates the database and is never safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

/** The attribute a payload's onerror writes; the spec reads it back. */
const XSS_MARKER = 'galleryrulesXss';

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** An image whose onerror records which field let it run. */
function payload(string $field): string
{
    return '<img src=x alt=galleryrules-' . $field . ' onerror=document.body.dataset.' . XSS_MARKER . '=this.alt>';
}

$args = getopt('', array('scenario::', 'restore'));

try
{
    $db = new Db();
    $builder = new FixtureBuilder($db);
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

if (!in_array($args['scenario'] ?? '', array('payload', 'same-name'), true))
{
    fail('--scenario must be one of: payload, same-name');
}
if (is_file(SNAPSHOT_FILE))
{
    fail('a previous seed was not restored; run --restore first');
}

$dir = dirname(SNAPSHOT_FILE);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir))
{
    fail("could not create $dir");
}

if ($args['scenario'] === 'same-name')
{
    seedSameName($db, $builder);
    exit(0);
}

try
{
    $suffix = bin2hex(random_bytes(4));
    $parentId = $builder->createTestAlbum('galleryrules-e2e-parent-' . $suffix);
    // Written after every step, so a seed that fails halfway is still restorable.
    file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
    $albumId = $builder->createTestAlbum('galleryrules-e2e-album-' . $suffix, $parentId);
    file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
    $imageId = $builder->createTestImage();
    file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));

    $builder->attachImage($imageId, $albumId);
    $builder->storePhotoFields($imageId, payload('name'), payload('author'), payload('comment'));
    $builder->storeAlbumFields($albumId, payload('album-name'), payload('album-description'));
    $builder->invalidateUserCache();
}
catch (RuntimeException $e)
{
    fail($e->getMessage());
}

echo json_encode(array(
    'image_id' => $imageId,
    'album_id' => $albumId,
    'picture_path' => '/picture.php?/' . $imageId . '/category/' . $albumId,
    'album_path' => '/index.php?/category/' . $albumId,
    'album_list_path' => '/index.php?/category/' . $parentId,
    'marker' => XSS_MARKER,
    'payloads' => array(
        'name' => payload('name'),
        'author' => payload('author'),
        'comment' => payload('comment'),
        'album_name' => payload('album-name'),
        'album_description' => payload('album-description'),
        ),
    )), "\n";

/** An album holding two photos whose rows share one file name. */
function seedSameName(Db $db, FixtureBuilder $builder): void
{
    $file = 'galleryrules-same-name.png';
    try
    {
        $albumId = $builder->createTestAlbum('galleryrules-e2e-same-name-' . bin2hex(random_bytes(4)));
        file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
        $imageIds = array();
        for ($i = 0; $i < 2; $i++)
        {
            $imageIds[] = $id = $builder->createTestImage();
            file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
            $builder->attachImage($id, $albumId);
            $db->query("UPDATE piwigo_images SET file = '" . $db->escape($file) . "' WHERE id = $id");
        }
        $shared = (int)$db->scalar(
            "SELECT COUNT(*) FROM piwigo_images i JOIN piwigo_image_category ic ON ic.image_id = i.id " .
            "WHERE ic.category_id = $albumId AND i.file = '" . $db->escape($file) . "'"
        );
        if ($shared !== 2)
        {
            throw new RuntimeException("expected 2 photos named $file in album $albumId, found $shared");
        }
        $builder->invalidateUserCache();
    }
    catch (RuntimeException $e)
    {
        fail($e->getMessage());
    }

    echo json_encode(array('album_id' => $albumId, 'image_ids' => $imageIds, 'file' => $file)), "\n";
}
