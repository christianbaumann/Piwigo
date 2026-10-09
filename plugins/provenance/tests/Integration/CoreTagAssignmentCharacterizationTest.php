<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the core paths that put tags on photos and that
 * photoinfo's tag write-back hooks: the Batch Manager's add_tags and del_tags
 * actions (admin/batch_manager_global.php), pwg.images.setInfo's tag_ids and
 * tag_list parameters, pwg.tags.duplicate, and a typed name on the
 * photo-properties screen, all of which may create a tag on the fly through
 * get_tag_ids() -> tag_id_from_tag_name().
 *
 * Every case is [ERR]: the oracle is the current implementation. Nothing
 * promises that add_tags appends or that a typed name becomes a tag; these
 * record that it does today. They report a change; they prove nothing right.
 *
 * They pass on their first run, so each was watched go red by inverting the
 * expectation it pins (docs/agents/TESTING.md); core itself was not mutated.
 */
final class CoreTagAssignmentCharacterizationTest extends TestCase
{
    private const BATCH_MANAGER = '/admin.php?page=batch_manager&mode=global';
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    /** Tag names this test created or caused to be created, removed in teardown. */
    private array $testTagNames = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->testTagNames as $name)
        {
            $id = (int)$this->db->scalar(
                "SELECT id FROM `piwigo_tags` WHERE name = '" . $this->db->escape($name) . "'"
            );
            if ($id > 0)
            {
                $this->db->query('DELETE FROM `piwigo_image_tag` WHERE tag_id = ' . $id);
                $this->db->query('DELETE FROM `piwigo_tags` WHERE id = ' . $id);
            }
        }
        $this->testTagNames = array();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    // ── Batch Manager ─────────────────────────────────────────────────────

    /** [ERR] add_tags appends: a photo that had a tag keeps it next to the new one. */
    public function testBatchManagerAddTagsAppendsAndKeepsExisting(): void
    {
        $old = $this->addTag($this->uniqueName('bm-old'));
        $new = $this->addTag($this->uniqueName('bm-new'));
        $tagged = $this->fixture->createTestImage()['id'];
        $untagged = $this->fixture->createTestImage()['id'];
        $this->linkTag($tagged, $old);

        $this->batchManager(array($tagged, $untagged), 'add_tags', array('add_tags' => array("~~$new~~")));

        $this->assertSame($this->sorted(array($old, $new)), $this->tagsOfPhoto($tagged), 'the old tag stays beside the new one');
        $this->assertSame(array($new), $this->tagsOfPhoto($untagged));
    }

    /** [ERR] A value that is not ~~id~~ is taken as a name and creates that tag. */
    public function testBatchManagerAddTagsCreatesATypedName(): void
    {
        $name = $this->uniqueName('bm-typed');
        $this->testTagNames[] = $name;
        $image = $this->fixture->createTestImage()['id'];
        $this->assertSame(0, $this->tagIdByName($name), 'anti-vacuity: the tag must not exist yet');

        $this->batchManager(array($image), 'add_tags', array('add_tags' => array($name)));

        $id = $this->tagIdByName($name);
        $this->assertGreaterThan(0, $id, 'the typed name became a tag');
        $this->assertSame(array($id), $this->tagsOfPhoto($image));
    }

    /** [ERR] del_tags removes the selected tag from the selected photos and nothing else. */
    public function testBatchManagerDelTagsRemovesOnlyTheSelectedTag(): void
    {
        $gone = $this->addTag($this->uniqueName('bm-gone'));
        $kept = $this->addTag($this->uniqueName('bm-kept'));
        $selected = $this->fixture->createTestImage()['id'];
        $other = $this->fixture->createTestImage()['id'];
        foreach (array($selected, $other) as $image)
        {
            $this->linkTag($image, $gone);
            $this->linkTag($image, $kept);
        }

        $this->batchManager(array($selected), 'del_tags', array('del_tags' => array($gone)));

        $this->assertSame(array($kept), $this->tagsOfPhoto($selected));
        $this->assertSame($this->sorted(array($gone, $kept)), $this->tagsOfPhoto($other), 'an unselected photo keeps both');
    }

    // ── pwg.images.setInfo ────────────────────────────────────────────────

    /**
     * [ERR] tag_list (the Batch Manager's unit mode) creates a typed name and
     * links it, replacing the photo's other tags (set_tags(), not add_tags()).
     */
    public function testSetInfoTagListCreatesANewTag(): void
    {
        $name = $this->uniqueName('setinfo-typed');
        $this->testTagNames[] = $name;
        $old = $this->addTag($this->uniqueName('setinfo-old'));
        $image = $this->fixture->createTestImage()['id'];
        $this->linkTag($image, $old);
        $this->assertSame(0, $this->tagIdByName($name), 'anti-vacuity: the tag must not exist yet');

        $res = $this->ws->call('pwg.images.setInfo', array(
            'image_id' => $image,
            'tag_list' => array($name),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = $this->tagIdByName($name);
        $this->assertGreaterThan(0, $id, 'the typed name became a tag');
        $this->assertSame(array($id), $this->tagsOfPhoto($image), 'the earlier tag was replaced, not kept');
    }

    /** [ERR] tag_ids with multiple_value_mode=append keeps the photo's existing tags. */
    public function testSetInfoTagIdsAppendModeKeepsExisting(): void
    {
        $old = $this->addTag($this->uniqueName('append-old'));
        $new = $this->addTag($this->uniqueName('append-new'));
        $image = $this->fixture->createTestImage()['id'];
        $this->linkTag($image, $old);

        $res = $this->ws->call('pwg.images.setInfo', array(
            'image_id' => $image,
            'tag_ids' => (string)$new,
            'multiple_value_mode' => 'append',
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($this->sorted(array($old, $new)), $this->tagsOfPhoto($image));
    }

    // ── pwg.tags.duplicate ────────────────────────────────────────────────

    /** [ERR] A duplicate is a new tag linked to every photo the source is linked to. */
    public function testDuplicateCopiesEveryImageLink(): void
    {
        $source = $this->addTag($this->uniqueName('dup-src'));
        $images = array($this->fixture->createTestImage()['id'], $this->fixture->createTestImage()['id']);
        foreach ($images as $image)
        {
            $this->linkTag($image, $source);
        }
        $copyName = $this->uniqueName('dup-copy');
        $this->testTagNames[] = $copyName;

        $res = $this->ws->call('pwg.tags.duplicate', array(
            'tag_id' => $source,
            'copy_name' => $copyName,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $copy = $this->tagIdByName($copyName);
        $this->assertGreaterThan(0, $copy, 'the copy exists');
        $this->assertSame($this->sorted($images), $this->photosOfTag($copy), 'the copy carries every photo');
        $this->assertSame($this->sorted($images), $this->photosOfTag($source), 'the source keeps its photos');
    }

    // ── photo properties ──────────────────────────────────────────────────

    /** [ERR] A name typed into the properties screen's tag field creates the tag and links it. */
    public function testPhotoPropertiesTypedNameCreatesTheTag(): void
    {
        $name = $this->uniqueName('props-typed');
        $this->testTagNames[] = $name;
        $image = $this->photoOnTheProperties();
        $this->assertSame(0, $this->tagIdByName($name), 'anti-vacuity: the tag must not exist yet');

        $this->postProperties($image, array($name));

        $id = $this->tagIdByName($name);
        $this->assertGreaterThan(0, $id, 'the typed name became a tag');
        $this->assertSame(array($id), $this->tagsOfPhoto($image));
    }

    /** [ERR] A tag created on the fly carries no Colored Tags group. */
    public function testATagCreatedOnTheFlyHasNoGroup(): void
    {
        if (!$this->fixture->columnExists('piwigo_tags', 'id_typetags'))
        {
            $this->markTestSkipped('the Colored Tags plugin is not installed on this install');
        }
        $name = $this->uniqueName('props-group');
        $this->testTagNames[] = $name;
        $image = $this->photoOnTheProperties();
        $this->assertSame(0, $this->tagIdByName($name), 'anti-vacuity: the tag must not exist yet');

        $this->postProperties($image, array($name));

        $id = $this->tagIdByName($name);
        $this->assertGreaterThan(0, $id, 'anti-vacuity: the typed name must have become a tag');
        $this->assertNull(
            $this->db->scalar("SELECT id_typetags FROM `piwigo_tags` WHERE id = $id"),
            'core knows nothing of groups, so a new tag has none'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function batchManager(array $ids, string $action, array $fields): void
    {
        $res = $this->ws->postPage(self::BATCH_MANAGER, array_merge(array(
            'pwg_token' => $this->ws->token(),
            'selection' => $ids,
            'selectAction' => $action,
            'submit' => 1,
            ), $fields));

        $this->assertSame(200, $res['http_code'], 'the Batch Manager did not answer');
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$res['body']),
            'the answer is too short to be the rendered Batch Manager');
    }

    /** A fixture photo in a fixture album, the arrangement the properties screen needs. */
    private function photoOnTheProperties(): int
    {
        $album = $this->fixture->createTestAlbum('provenance-char-tagassign-' . bin2hex(random_bytes(4)));
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $album);

        return $image;
    }

    /** Posts the photo-properties form with these raw tag field values. */
    private function postProperties(int $imageId, array $rawTags): void
    {
        $res = $this->ws->postPage('/admin.php?page=photo-' . $imageId . '-properties', array(
            'level' => 0,
            'submit' => 1,
            'pwg_token' => $this->ws->token(),
            'associate' => $this->albumsOfPhoto($imageId),
            'tags' => $rawTags,
            ));

        $this->assertSame(200, $res['http_code'], 'the properties screen did not answer');
    }

    /** Creates a tag through the API and remembers it for teardown. */
    private function addTag(string $name): int
    {
        $res = $this->ws->call('pwg.tags.add', array('name' => $name));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)($res['json']['result']['id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the call returned no tag id: ' . $res['body']);
        $this->testTagNames[] = $name;

        return $id;
    }

    private function linkTag(int $imageId, int $tagId): void
    {
        $this->db->query("INSERT INTO `piwigo_image_tag` (image_id, tag_id) VALUES ($imageId, $tagId)");

        $linked = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_image_tag` WHERE image_id = $imageId AND tag_id = $tagId"
        );
        if ($linked !== 1)
        {
            throw new RuntimeException("photo $imageId was not tagged with $tagId");
        }
    }

    private function tagIdByName(string $name): int
    {
        return (int)$this->db->scalar(
            "SELECT id FROM `piwigo_tags` WHERE name = '" . $this->db->escape($name) . "'"
        );
    }

    /** @return int[] ascending */
    private function tagsOfPhoto(int $imageId): array
    {
        return $this->ids('SELECT tag_id FROM `piwigo_image_tag` WHERE image_id = ' . $imageId . ' ORDER BY tag_id');
    }

    /** @return int[] ascending */
    private function photosOfTag(int $tagId): array
    {
        return $this->ids('SELECT image_id FROM `piwigo_image_tag` WHERE tag_id = ' . $tagId . ' ORDER BY image_id');
    }

    /** @return int[] */
    private function albumsOfPhoto(int $imageId): array
    {
        return $this->ids('SELECT category_id FROM `piwigo_image_category` WHERE image_id = ' . $imageId . ' ORDER BY category_id');
    }

    /** @return int[] */
    private function ids(string $query): array
    {
        $result = $this->db->query($query);
        $ids = array();
        while ($row = $result->fetch_row())
        {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }

    /** @return int[] */
    private function sorted(array $ids): array
    {
        sort($ids);
        return array_map('intval', $ids);
    }

    private function uniqueName(string $suffix): string
    {
        return 'provenance-char-tagassign-' . $suffix . '-' . bin2hex(random_bytes(4));
    }
}
