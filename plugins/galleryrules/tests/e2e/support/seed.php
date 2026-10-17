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
 *   php tests/e2e/support/seed.php --scenario=empty     an album holding no photo, for the note an
 *                                                        administrator sees on its page
 *   php tests/e2e/support/seed.php --scenario=tags      three tags for the tag screen: one on one photo,
 *                                                        one on two, one on none and unused for two days
 *                                                        (--restore deletes them)
 *   php tests/e2e/support/seed.php --album-count       how many albums the install holds
 *   php tests/e2e/support/seed.php --album-name-hint   the German hint of the create-album dialog
 *   php tests/e2e/support/seed.php --track-album=<name> records an album a spec created through the
 *                                                        browser, so --restore deletes it
 *   php tests/e2e/support/seed.php --track-uploads=<album id> records the photos a spec uploaded into an
 *                                                        album through the browser, so --restore deletes them
 *                                                        with their files and lounge rows
 *   php tests/e2e/support/seed.php --restore
 *
 * Prints one JSON object on stdout; errors go to stderr with exit 1.
 * It mutates the database and is never safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const SNAPSHOT_FILE = __DIR__ . '/../.state/snapshot.json';

/** The attribute a payload's onerror writes; the spec reads it back. */
const XSS_MARKER = 'galleryrulesXss';

/** The start of every tag name the tags scenario creates; --restore deletes tags that begin with it. */
const TAG_PREFIX = 'galleryrules-e2e-';

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

$args = getopt('', array('scenario::', 'restore', 'album-count', 'album-name-hint', 'track-album:', 'track-uploads:'));

try
{
    $db = new Db();
    $builder = new FixtureBuilder($db);
}
catch (RuntimeException $e)
{
    fail($e->getMessage());
}

if (isset($args['album-count']))
{
    echo json_encode(array('count' => (int)$db->scalar('SELECT COUNT(*) FROM piwigo_categories'))), "\n";
    exit(0);
}

if (isset($args['album-name-hint']))
{
    include_once GALLERYRULES_PATH . 'include/events_album_name.inc.php';
    $lang = array();
    include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';
    echo json_encode(array('hint' => $lang[GALLERYRULES_ALBUM_NAME_MISSING])), "\n";
    exit(0);
}

if (isset($args['track-album']))
{
    $ids = array();
    $result = $db->query("SELECT id FROM piwigo_categories WHERE name = '" . $db->escape($args['track-album']) . "'");
    while ($row = $result->fetch_assoc())
    {
        $ids[] = (int)$row['id'];
    }
    if (count($ids) !== 1)
    {
        fail('expected one album named "' . $args['track-album'] . '", found ' . count($ids));
    }
    $snapshot = is_file(SNAPSHOT_FILE) ? json_decode((string)file_get_contents(SNAPSHOT_FILE), true) : array();
    $builder->importTestObjects(is_array($snapshot) ? $snapshot : array());
    $builder->importTestObjects(array('albums' => $ids));
    $dir = dirname(SNAPSHOT_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir))
    {
        fail("could not create $dir");
    }
    file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
    echo json_encode(array('album_id' => $ids[0])), "\n";
    exit(0);
}

if (isset($args['track-uploads']))
{
    $images = array();
    $result = $db->query(
        'SELECT i.id, i.path FROM piwigo_images i JOIN piwigo_image_category ic ON ic.image_id = i.id ' .
        'WHERE ic.category_id = ' . (int)$args['track-uploads'] .
        ' UNION SELECT i.id, i.path FROM piwigo_images i JOIN piwigo_lounge l ON l.image_id = i.id ' .
        'WHERE l.category_id = ' . (int)$args['track-uploads']
    );
    while ($row = $result->fetch_assoc())
    {
        $images[] = array('id' => (int)$row['id'], 'file' => PIWIGO_ROOT . ltrim($row['path'], './'));
    }
    $snapshot = is_file(SNAPSHOT_FILE) ? json_decode((string)file_get_contents(SNAPSHOT_FILE), true) : array();
    $builder->importTestObjects(array('images' => $images));
    $builder->importTestObjects(is_array($snapshot) ? $snapshot : array());
    file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
    echo json_encode(array('image_ids' => array_column($images, 'id'))), "\n";
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
    foreach ($snapshot['tags'] ?? array() as $tagId)
    {
        $db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$tagId);
        $db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$tagId);
    }
    // Tags a spec made through the browser (a Duplikat, a rename) carry the seeded prefix too.
    $result = $db->query("SELECT id FROM piwigo_tags WHERE name LIKE '" . TAG_PREFIX . "%'");
    while ($row = $result->fetch_assoc())
    {
        $db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$row['id']);
        $db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$row['id']);
    }
    foreach ($snapshot['images'] ?? array() as $image)
    {
        $db->query('DELETE FROM piwigo_lounge WHERE image_id = ' . (int)$image['id']);
    }
    $builder->destroyTestImages();
    $builder->destroyTestAlbums();
    unlink(SNAPSHOT_FILE);

    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

if (!in_array($args['scenario'] ?? '', array('payload', 'same-name', 'empty', 'tags'), true))
{
    fail('--scenario must be one of: payload, same-name, empty, tags');
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
if ($args['scenario'] === 'empty')
{
    seedEmpty($db, $builder);
    exit(0);
}
if ($args['scenario'] === 'tags')
{
    seedTags($db, $builder);
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

/** An album holding no photo, its emptiness asserted, and the German note its page shows an administrator. */
function seedEmpty(Db $db, FixtureBuilder $builder): void
{
    try
    {
        $albumId = $builder->createTestAlbum('galleryrules-e2e-empty-' . bin2hex(random_bytes(4)));
        file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects(), JSON_PRETTY_PRINT));
        if ((int)$db->scalar("SELECT COUNT(*) FROM piwigo_image_category WHERE category_id = $albumId") !== 0)
        {
            throw new RuntimeException("album $albumId is not empty");
        }
        $builder->invalidateUserCache();
    }
    catch (RuntimeException $e)
    {
        fail($e->getMessage());
    }

    include_once GALLERYRULES_PATH . 'include/events_empty_album.inc.php';
    $lang = array();
    include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';

    echo json_encode(array('album_id' => $albumId, 'note' => $lang[GALLERYRULES_EMPTY_ALBUM_NOTE])), "\n";
}

/** Three tags of this suite's own, on one photo, on two photos and on none, for the tag screen's menu and rename. */
function seedTags(Db $db, FixtureBuilder $builder): void
{
    $suffix = bin2hex(random_bytes(4));
    $names = array(
        'one' => TAG_PREFIX . 'one-' . $suffix,
        'two' => TAG_PREFIX . 'two-' . $suffix,
        'none' => TAG_PREFIX . 'none-' . $suffix,
        );
    $tagIds = array();
    try
    {
        foreach ($names as $key => $name)
        {
            $escaped = $db->escape($name);
            $db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('$escaped', '$escaped')");
            $tagIds[$key] = $db->insertId();
            file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects() + array('tags' => array_values($tagIds)), JSON_PRETTY_PRINT));
        }
        $imageIds = array();
        foreach (array('first', 'second') as $unused)
        {
            $imageIds[] = $builder->createTestImage();
            file_put_contents(SNAPSHOT_FILE, json_encode($builder->exportTestObjects() + array('tags' => array_values($tagIds)), JSON_PRETTY_PRINT));
        }
        $db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageIds[0], $tagIds[one]), ($imageIds[0], $tagIds[two]), ($imageIds[1], $tagIds[two])");
        // Unused for more than a day, so the tags page offers it to the cleanup.
        $db->query("UPDATE piwigo_tags SET lastmodified = NOW() - INTERVAL 2 DAY WHERE id = $tagIds[none]");
    }
    catch (RuntimeException $e)
    {
        fail($e->getMessage());
    }

    echo json_encode(array('names' => $names, 'tag_ids' => $tagIds)), "\n";
}
