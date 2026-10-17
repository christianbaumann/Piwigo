<?php
/**
 * Seeds the album and tag the modus long-word spec browses (task 37).
 *
 * One throwaway public album whose description is a single word longer than
 * any window, holding one copied photo that carries a tag of the longest name
 * the tags table takes. Nothing of the install is changed, so --restore only
 * deletes what this created.
 *
 * Usage:
 *   php tests/e2e/support/wrap-seed.php
 *   php tests/e2e/support/wrap-seed.php --restore
 *
 * This mutates the database. It is not safe against a production install.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const WRAP_STATE_FILE = __DIR__ . '/../.state/wrap.json';

/** piwigo_tags.name is varchar(255). */
const TAG_NAME_CHARS = 255;

/** Wider than any window the spec uses at the theme's text size. */
const DESCRIPTION_WORD_CHARS = 400;

$db = new Db();
$builder = new FixtureBuilder($db);

if (in_array('--restore', $argv, true))
{
    if (is_file(WRAP_STATE_FILE))
    {
        $state = json_decode((string)file_get_contents(WRAP_STATE_FILE), true);
        $builder->importTestObjects($state['test_objects']);
        if ($state['tag_id'] > 0)
        {
            $db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$state['tag_id']);
            $db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$state['tag_id']);
        }
        $builder->destroyTestImages();
        $builder->destroyTestAlbums();
        $builder->invalidateUserCache();
        unlink(WRAP_STATE_FILE);
    }
    echo json_encode(array('restored' => true)), "\n";
    exit(0);
}

if (is_file(WRAP_STATE_FILE))
{
    fwrite(STDERR, "wrap-seed.php: an earlier seed is not restored\n");
    exit(1);
}

$tagName = str_repeat('Langwort', 40);
$tagName = substr($tagName, 0, TAG_NAME_CHARS);
$word = substr(str_repeat('Beschreibung', 40), 0, DESCRIPTION_WORD_CHARS);

/** Written after every creation, so a seed that dies halfway is still restorable. */
function saveWrapState(FixtureBuilder $builder, int $tagId): void
{
    if (!is_dir(dirname(WRAP_STATE_FILE)))
    {
        mkdir(dirname(WRAP_STATE_FILE), 0777, true);
    }
    file_put_contents(WRAP_STATE_FILE, json_encode(array(
        'test_objects' => $builder->exportTestObjects(),
        'tag_id' => $tagId,
    )));
}

$catId = $builder->createTestAlbum('provenance E2E long words');
saveWrapState($builder, 0);
$db->query("UPDATE piwigo_categories SET comment = '" . $db->escape($word) . "' WHERE id = $catId");
$imageId = $builder->createTestImage()['id'];
saveWrapState($builder, 0);
$builder->attachImage($imageId, $catId);

$db->query(
    "INSERT INTO piwigo_tags (name, url_name) VALUES ('" . $db->escape($tagName) . "', 'provenance-e2e-langwort')"
);
$tagId = $db->insertId();
saveWrapState($builder, max($tagId, 0));
if ($tagId <= 0 or $db->scalar("SELECT name FROM piwigo_tags WHERE id = $tagId") !== $tagName)
{
    fwrite(STDERR, "wrap-seed.php: the tag was not stored whole\n");
    exit(1);
}
$db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $tagId)");
$builder->invalidateUserCache();

echo json_encode(array(
    'album_id' => $catId,
    'image_id' => $imageId,
    'tag_id' => $tagId,
    'tag_name' => $tagName,
    'description' => $word,
)), "\n";
