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

/** The cloud shows this many tags, most photos first; the install's own value is a config default of 200. */
const CLOUD_ITEMS_PARAM = 'full_tag_cloud_items_number';
const CLOUD_ITEMS_ALL = 100000;

/** A short tag of several words, for the check that the cloud breaks only at spaces. */
const SHORT_TAG_NAME = 'Wrap Test kurz zwei';

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
        if (!empty($state['short_tag_id']))
        {
            $db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$state['short_tag_id']);
            $db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$state['short_tag_id']);
        }
        if (!empty($state['cloud_row']))
        {
            $db->query("DELETE FROM piwigo_config WHERE param = '" . CLOUD_ITEMS_PARAM . "'");
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
function saveWrapState(FixtureBuilder $builder, int $tagId, bool $cloudRow = false, int $shortTagId = 0): void
{
    if (!is_dir(dirname(WRAP_STATE_FILE)))
    {
        mkdir(dirname(WRAP_STATE_FILE), 0777, true);
    }
    file_put_contents(WRAP_STATE_FILE, json_encode(array(
        'test_objects' => $builder->exportTestObjects(),
        'tag_id' => $tagId,
        'cloud_row' => $cloudRow,
        'short_tag_id' => $shortTagId,
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

$db->query(
    "INSERT INTO piwigo_tags (name, url_name) VALUES ('" . $db->escape(SHORT_TAG_NAME) . "', 'provenance-e2e-wrap-test-kurz-zwei')"
);
$shortTagId = $db->insertId();
saveWrapState($builder, $tagId, false, max($shortTagId, 0));
if ($shortTagId <= 0 or $db->scalar("SELECT name FROM piwigo_tags WHERE id = $shortTagId") !== SHORT_TAG_NAME)
{
    fwrite(STDERR, "wrap-seed.php: the short tag was not stored whole\n");
    exit(1);
}
$db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $shortTagId)");

// The cloud takes the most photographed tags only; a one-photo tag is in it for sure
// once the limit is lifted. Refuse to overwrite a row the install has of its own.
if ($db->scalar("SELECT COUNT(*) FROM piwigo_config WHERE param = '" . CLOUD_ITEMS_PARAM . "'") > 0)
{
    fwrite(STDERR, "wrap-seed.php: the install sets " . CLOUD_ITEMS_PARAM . " itself\n");
    exit(1);
}
saveWrapState($builder, $tagId, true, $shortTagId);
$db->query(
    "INSERT INTO piwigo_config (param, value, comment) VALUES ('" . CLOUD_ITEMS_PARAM . "', '" . CLOUD_ITEMS_ALL . "', 'provenance E2E wrap seed')"
);
$builder->invalidateUserCache();
// The tag list is cached per user under cache_update_time, which has one-second
// resolution; a page viewed in the same second as the previous run's last view
// would be served that run's list, without this seed's tag.
sleep(1);

echo json_encode(array(
    'album_id' => $catId,
    'image_id' => $imageId,
    'tag_id' => $tagId,
    'tag_name' => $tagName,
    'short_tag_name' => SHORT_TAG_NAME,
    'description' => $word,
)), "\n";
