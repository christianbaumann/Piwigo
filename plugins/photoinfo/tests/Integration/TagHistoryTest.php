<?php
use PHPUnit\Framework\TestCase;

/**
 * Every tag added to or removed from a photo on an edit path writes one entry
 * into provenance's history, naming the account and the path. The person tags
 * persons keeps in step with regions write none.
 *
 * Requirement: design "What gets a row".
 */
final class TagHistoryTest extends TestCase
{
    /** A rendered admin page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private int $albumId;
    private array $image;
    private string $suffix;
    private int $accountId;
    private int $group;
    /** @var string[] names built in, adopted in tearDown whether or not the case got that far */
    private array $typedNames = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('typetags');

        $this->suffix = bin2hex(random_bytes(4));
        $this->albumId = $this->fixture->createTestAlbum('photoinfo-taghistory-' . $this->suffix);
        $this->image = $this->newPhoto();
        $this->group = $this->fixture->createGroup('Feste ' . $this->suffix);

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
        $this->accountId = (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'");
        $this->assertGreaterThan(0, $this->accountId, 'anti-vacuity: the test account is missing');
    }

    protected function tearDown(): void
    {
        foreach ($this->typedNames as $name)
        {
            $this->fixture->trackTagsNamed($name);
        }
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] The + badge: one entry, the tag added, the account and the picture page named. */
    public function testAddingABadgeWritesOneEntry(): void
    {
        $kirche = $this->tag('Kirche');

        $res = $this->badge('typetags.image.addTag', $kirche);

        $entries = $this->entries($this->image['id']);
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['old_value']);
        $this->assertSame(array('id' => $kirche, 'name' => $this->tagName('Kirche')), json_decode($entries[0]['new_value'], true));
        $this->assertSame(PROVENANCE_HISTORY_SOURCE_PICTURE_PAGE, $entries[0]['source']);
        $this->assertSame($this->accountId, (int)$entries[0]['performed_by']);
        $this->assertSame(array((int)$entries[0]['id']), $res['json']['result']['history_ids'], $res['body']);
    }

    /** [HAPPY] The x on a badge: one entry, the tag removed. */
    public function testRemovingABadgeWritesOneEntry(): void
    {
        $kirche = $this->tag('Kirche');
        $this->fixture->linkTag($this->image['id'], $kirche);

        $res = $this->badge('typetags.image.removeTag', $kirche);

        $entries = $this->entries($this->image['id']);
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['new_value']);
        $this->assertSame($kirche, json_decode($entries[0]['old_value'], true)['id']);
        $this->assertSame(array((int)$entries[0]['id']), $res['json']['result']['history_ids'], $res['body']);
    }

    /** [NEG] Adding a tag the photo carries already changes nothing and writes nothing. */
    public function testAddingACarriedTagWritesNoEntry(): void
    {
        $kirche = $this->tag('Kirche');
        $this->fixture->linkTag($this->image['id'], $kirche);

        $res = $this->badge('typetags.image.addTag', $kirche);

        $this->assertSame(array(), $this->entries($this->image['id']));
        $this->assertSame(array(), $res['json']['result']['history_ids'], $res['body']);
    }

    /** [HAPPY] The field for a new tag: one entry, with the tag the server created. */
    public function testATypedNewTagWritesOneEntry(): void
    {
        $name = $this->tagName('Neu');

        $res = $this->ws->call('typetags.image.addNewTag', array(
            'image_id' => $this->image['id'], 'tag_name' => $name, 'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $entries = $this->entries($this->image['id']);
        $this->assertCount(1, $entries);
        $this->assertSame($name, json_decode($entries[0]['new_value'], true)['name']);
        $this->assertSame(array((int)$entries[0]['id']), $res['json']['result']['history_ids'], $res['body']);
    }

    /** [HAPPY] pwg.images.setInfo with tag_ids: the difference only, from the API. */
    public function testSetInfoWritesTheDifference(): void
    {
        $old = $this->tag('Alt');
        $kept = $this->tag('Bleibt');
        $new = $this->tag('Neu');
        $this->fixture->linkTag($this->image['id'], $old);
        $this->fixture->linkTag($this->image['id'], $kept);

        $res = $this->ws->call('pwg.images.setInfo', array(
            'image_id' => $this->image['id'], 'tag_ids' => $kept . ',' . $new,
            'single_value_mode' => 'replace', 'multiple_value_mode' => 'replace',
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $entries = $this->entries($this->image['id']);
        $this->assertCount(2, $entries);
        $this->assertSame($new, json_decode($entries[1]['new_value'], true)['id'], 'the added tag is the older entry');
        $this->assertSame($old, json_decode($entries[0]['old_value'], true)['id'], 'the removed tag is the newer entry');
        foreach ($entries as $entry)
        {
            $this->assertSame(PROVENANCE_HISTORY_SOURCE_API, $entry['source']);
        }
    }

    /** [HAPPY] The photo properties screen: the difference, from the admin photo screen. */
    public function testAPropertiesSaveWritesTheDifference(): void
    {
        $kirche = $this->tag('Kirche');
        $row = $this->fixture->imageRow($this->image['id']);

        $body = $this->ws->postPage('/admin.php?page=photo-' . $this->image['id'] . '-properties', array(
            'name' => (string)$row['name'], 'author' => (string)$row['author'],
            'comment' => (string)$row['comment'], 'date_creation' => (string)$row['date_creation'],
            'level' => 0, 'associate' => array($this->albumId),
            'tags' => array('~~' . $kirche . '~~'),
            'pwg_token' => $this->ws->token(), 'submit' => 1,
            ));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the properties screen did not answer');
        $entries = $this->entries($this->image['id']);
        $this->assertCount(1, $entries);
        $this->assertSame(PROVENANCE_HISTORY_SOURCE_ADMIN_PHOTO, $entries[0]['source']);
        $this->assertSame($kirche, json_decode($entries[0]['new_value'], true)['id']);
    }

    /** [HAPPY] Removing two tags in the Batch Manager: two entries per photo. */
    public function testABatchRemovalWritesTwoEntriesPerPhoto(): void
    {
        $other = $this->newPhoto();
        $kirche = $this->tag('Kirche');
        $anna = $this->tag('Anna');
        foreach (array($this->image, $other) as $photo)
        {
            $this->fixture->linkTag($photo['id'], $kirche);
            $this->fixture->linkTag($photo['id'], $anna);
        }

        $this->postBatch(array($this->image['id'], $other['id']), array(
            'selectAction' => 'del_tags', 'del_tags' => array($kirche, $anna),
            ));

        foreach (array($this->image, $other) as $photo)
        {
            $entries = $this->entries($photo['id']);
            $this->assertCount(2, $entries, 'photo ' . $photo['id']);
            foreach ($entries as $entry)
            {
                $this->assertNull($entry['new_value']);
                $this->assertSame(PROVENANCE_HISTORY_SOURCE_BATCH_MANAGER, $entry['source']);
            }
        }
    }

    /** [ECP] A batch add writes an entry only for the photos that did not carry the tag. */
    public function testABatchAddSkipsAPhotoThatCarriesTheTag(): void
    {
        $other = $this->newPhoto();
        $kirche = $this->tag('Kirche');
        $this->fixture->linkTag($other['id'], $kirche);

        $this->postBatch(array($this->image['id'], $other['id']), array(
            'selectAction' => 'add_tags', 'add_tags' => array('~~' . $kirche . '~~'),
            ));

        $this->assertCount(1, $this->entries($this->image['id']));
        $this->assertSame(array(), $this->entries($other['id']));
    }

    /**
     * [NEG] persons_tags_changed: naming a region tags the photo with the
     * person, and that tag follows the region, which has its own entry.
     */
    public function testPersonsTagsChangedWritesNoTagEntry(): void
    {
        $this->fixture->assertPluginActive('persons');
        $name = $this->tagName('Anna');
        $this->fixture->trackPerson($name);

        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name,
            'x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2, 'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertCount(1, $this->fixture->tagIdsOf($this->image['id']), 'anti-vacuity: the region did not tag the photo');
        $this->assertSame(array(), $this->entries($this->image['id']));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array[] the photo's tag entries, newest first */
    private function entries(int $imageId): array
    {
        $result = $this->db->query(
            "SELECT * FROM piwigo_provenance_history WHERE object = 'photo' AND object_id = " . $imageId
            . " AND field = '" . PROVENANCE_HISTORY_FIELD_TAG . "' ORDER BY id DESC"
            );
        $this->assertNotFalse($result);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private function badge(string $method, int $tagId): array
    {
        $res = $this->ws->call($method, array(
            'image_id' => $this->image['id'], 'tag_id' => $tagId, 'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        return $res;
    }

    private function postBatch(array $ids, array $fields): void
    {
        $body = $this->ws->postPage('/admin.php?page=batch_manager&mode=global', array_merge(array(
            'pwg_token' => $this->ws->token(), 'selection' => $ids, 'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the Batch Manager did not answer');
    }

    private function newPhoto(): array
    {
        $photo = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($photo['file']);
        $this->fixture->attachImage($photo['id'], $this->albumId);

        return $photo;
    }

    /** A name unique to this run, so no real tag of the install is touched. */
    private function tagName(string $base): string
    {
        $name = $base . ' ' . $this->suffix;
        $this->typedNames[] = $name;

        return $name;
    }

    /** In a colour group, as typetags' badge methods only take coloured tags. */
    private function tag(string $base): int
    {
        return $this->fixture->createTag($this->tagName($base), $this->group);
    }
}
