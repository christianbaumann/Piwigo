<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core tag CRUD: create_tag() and delete_tags() in
 * admin/include/functions.php, reached through the four ws.php methods
 * admin/tags.php puts all of it in (the PHP file itself only renders and
 * removes orphans), plus the full-replace assignment set_tags() performs from
 * the photo-properties screen.
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a rename to a taken name is refused rather
 * than de-duplicated, that assignment replaces rather than appends, or that the
 * fork's colour table survives a core tag deletion - these record that it does
 * today. They report a change; they do not prove the behaviour right.
 *
 * They land and pass on their first run, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 *
 * Written because the German handbook documents this workflow and it had no
 * test at any layer.
 */
final class CoreTagCrudCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    /** Tags this test created, removed again in teardown. */
    private array $testTags = array();

    /** Colour rows this test created, removed again in teardown. */
    private array $testColors = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->testTags as $tagId)
        {
            $this->db->query('DELETE FROM `piwigo_image_tag` WHERE tag_id = ' . (int)$tagId);
            $this->db->query('DELETE FROM `piwigo_tags` WHERE id = ' . (int)$tagId);
        }
        $this->testTags = array();

        foreach ($this->testColors as $colorId)
        {
            $this->db->query('DELETE FROM `piwigo_typetags` WHERE id = ' . (int)$colorId);
        }
        $this->testColors = array();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    // ── create ────────────────────────────────────────────────────────────

    /** [HAPPY] pwg.tags.add creates the row and derives a url_name from the name. */
    public function testAddingATagCreatesTheRowAndAUrlName(): void
    {
        $name = $this->uniqueName('add');

        $id = $this->addTag($name);

        $this->assertSame($name, $this->column($id, 'name'));
        $this->assertNotSame('', (string)$this->column($id, 'url_name'), 'a tag with no url_name is unreachable');
    }

    /** [NEG] A second tag of the same name is refused and creates nothing. */
    public function testAddingADuplicateNameIsRefused(): void
    {
        $name = $this->uniqueName('dup');
        $this->addTag($name);
        $before = $this->tagCount();

        $res = $this->ws->call('pwg.tags.add', array('name' => $name));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($before, $this->tagCount(), 'a refused call must create no tag');
    }

    /** [ERR] A name with umlauts gets a url_name that is safe in a URL. */
    public function testATagWithAUmlautGetsAUsableUrlName(): void
    {
        $id = $this->addTag('Prüfung Straße ' . bin2hex(random_bytes(4)));

        $urlName = (string)$this->column($id, 'url_name');
        $this->assertNotSame('', $urlName, 'anti-vacuity: the url_name must not be empty');
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9_-]+$/i',
            $urlName,
            'str2url() folds umlauts away rather than leaving them in the URL'
        );
    }

    // ── rename ────────────────────────────────────────────────────────────

    /** [HAPPY] [ST] A rename changes the name and re-derives the url_name. */
    public function testRenamingChangesTheNameAndTheUrlName(): void
    {
        $id = $this->addTag($this->uniqueName('before'));
        $urlBefore = (string)$this->column($id, 'url_name');
        $after = $this->uniqueName('after');

        $this->renameTag($id, $after);

        $this->assertSame($after, $this->column($id, 'name'));
        $this->assertNotSame($urlBefore, $this->column($id, 'url_name'), 'the url_name follows the name');
    }

    /** [NEG] A rename onto a name another tag already holds is refused. */
    public function testRenamingToAnExistingNameIsRefused(): void
    {
        $taken = $this->uniqueName('taken');
        $this->addTag($taken);
        $id = $this->addTag($this->uniqueName('renamer'));
        $before = (string)$this->column($id, 'name');

        $res = $this->ws->call('pwg.tags.rename', array(
            'tag_id' => $id,
            'new_name' => $taken,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($before, $this->column($id, 'name'), 'a refused rename must change nothing');
    }

    // ── delete ────────────────────────────────────────────────────────────

    /** [HAPPY] [ST] Deleting a tag removes it and every photo link it had. */
    public function testDeletingRemovesTheTagAndItsImageLinks(): void
    {
        $id = $this->addTag($this->uniqueName('del'));
        $image = $this->fixture->createTestImage()['id'];
        $this->linkTag($image, $id);
        $this->assertSame(1, $this->linkCount($id), 'anti-vacuity: the tag must start linked to a photo');

        $this->deleteTag($id);

        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_tags` WHERE id = $id"));
        $this->assertSame(0, $this->linkCount($id), 'the photo links went with the tag');
    }

    /**
     * [NEG] Deleting a coloured tag leaves the fork's colour definition alone.
     *
     * piwigo_typetags holds one row per colour, shared by every tag that carries
     * it, and piwigo_tags.id_typetags points into it. Core's delete_tags() knows
     * nothing about either. This records that it takes the tag and leaves the
     * colour behind for the tags still using it.
     *
     * The colour is one this test creates rather than the install's own: a
     * mutation that made core cascade into the colour table would otherwise
     * delete a real colour, and reverting the mutation would not bring it back.
     */
    public function testDeletingAColoredTagLeavesNoOrphanTypetagsRow(): void
    {
        if (!$this->fixture->tableExists('piwigo_typetags') or !$this->fixture->columnExists('piwigo_tags', 'id_typetags'))
        {
            $this->markTestSkipped('the Colored Tags plugin is not installed on this install');
        }

        $colorId = $this->createColor();
        $id = $this->addTag($this->uniqueName('colored'));
        $this->db->query("UPDATE `piwigo_tags` SET id_typetags = $colorId WHERE id = $id");
        $this->assertSame($colorId, (int)$this->column($id, 'id_typetags'), 'fixture colour did not take effect');

        $this->deleteTag($id);

        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_tags` WHERE id = $id"));
        $this->assertSame(
            1,
            (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_typetags` WHERE id = $colorId"),
            'the colour definition must survive the tag that carried it'
        );
    }

    // ── merge ─────────────────────────────────────────────────────────────

    /** [HAPPY] [ST] A merge moves every photo link to the destination and drops the source. */
    public function testMergingMovesEveryImageLinkAndRemovesTheSource(): void
    {
        $source = $this->addTag($this->uniqueName('merge-src'));
        $destination = $this->addTag($this->uniqueName('merge-dst'));
        $image = $this->fixture->createTestImage()['id'];
        $this->linkTag($image, $source);
        $this->assertSame(0, $this->linkCount($destination), 'anti-vacuity: the destination must start empty');

        $res = $this->ws->call('pwg.tags.merge', array(
            'destination_tag_id' => $destination,
            'merge_tag_id' => array($source),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, $this->linkCount($destination), 'the photo moved to the destination tag');
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_tags` WHERE id = $source"));
    }

    /**
     * [ERR] [BVA] Merging a tag into itself is not a no-op: the request fails and
     * the tag survives.
     *
     * ws_tags_merge() subtracts the destination from the merge list and then
     * queries `tag_id IN ()` with what is left, which is empty. Recorded here
     * because the admin screen offers the combination and the handbook must not
     * suggest it.
     */
    public function testMergingATagIntoItselfIsRefusedOrIsANoOp(): void
    {
        $id = $this->addTag($this->uniqueName('merge-self'));
        $image = $this->fixture->createTestImage()['id'];
        $this->linkTag($image, $id);

        $res = $this->ws->call('pwg.tags.merge', array(
            'destination_tag_id' => $id,
            'merge_tag_id' => array($id),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertNotSame('ok', $res['json']['stat'] ?? null, 'the call reported success: ' . $res['body']);
        $this->assertSame(1, (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_tags` WHERE id = $id"), 'the tag survives');
        $this->assertSame(1, $this->linkCount($id), 'its photo link survives');
    }

    // ── assignment ────────────────────────────────────────────────────────

    /**
     * [ERR] Assignment from the photo screen replaces the whole list.
     *
     * set_tags() is a full replace, so a screen that posts one tag leaves the
     * photo with one tag, not with the one it had plus the new one. The handbook
     * has to say so.
     */
    public function testAssignmentReplacesRatherThanAppends(): void
    {
        $first = $this->addTag($this->uniqueName('assign-a'));
        $second = $this->addTag($this->uniqueName('assign-b'));
        $image = $this->photoOnTheProperties();

        $this->assignTags($image, array($first));
        $this->assertSame(array($first), $this->tagsOfPhoto($image), 'anti-vacuity: the photo must start with one tag');

        $this->assignTags($image, array($second));

        $this->assertSame(array($second), $this->tagsOfPhoto($image), 'the earlier tag was replaced, not kept');
    }

    /** [BVA] Posting no tag at all removes every tag from the photo. */
    public function testAssigningAnEmptyListRemovesEveryTag(): void
    {
        $tag = $this->addTag($this->uniqueName('assign-empty'));
        $image = $this->photoOnTheProperties();

        $this->assignTags($image, array($tag));
        $this->assertSame(array($tag), $this->tagsOfPhoto($image), 'anti-vacuity: the photo must start tagged');

        $this->assignTags($image, array());

        $this->assertSame(array(), $this->tagsOfPhoto($image));
    }

    // ── the gates ─────────────────────────────────────────────────────────

    /** [NEG] All four methods are admin_only, so an unauthenticated caller is refused. */
    public function testAGuestCannotCreateRenameOrDeleteATag(): void
    {
        $existing = $this->addTag($this->uniqueName('guest-target'));
        $before = $this->tagCount();

        foreach ($this->writeCalls($existing) as $label => $call)
        {
            $res = $this->ws->call($call[0], $call[1], false);
            $this->assertSame('fail', $res['json']['stat'] ?? null, "$label was allowed: " . $res['body']);
        }

        $this->assertSame($before, $this->tagCount(), 'a refused call must change nothing');
        $this->assertSame($existing, (int)$this->db->scalar(
            "SELECT id FROM `piwigo_tags` WHERE id = $existing"
        ));
    }

    /** [NEG] An authenticated non-admin is refused too - the gate is on status, not on login. */
    public function testANormalUserCannotCreateRenameOrDeleteATag(): void
    {
        $existing = $this->addTag($this->uniqueName('normal-target'));
        $before = $this->tagCount();

        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        foreach ($this->writeCalls($existing) as $label => $call)
        {
            $res = $normal->call($call[0], $call[1]);
            $this->assertSame('fail', $res['json']['stat'] ?? null, "$label was allowed: " . $res['body']);
        }
        $normal->logout();

        $this->assertSame($before, $this->tagCount(), 'a refused call must change nothing');
        $this->assertSame($existing, (int)$this->db->scalar(
            "SELECT id FROM `piwigo_tags` WHERE id = $existing"
        ));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The four write methods, with a token that is valid for whoever calls them.
     *
     * The token is deliberately the webmaster's: a call that got past the
     * admin_only gate would then be refused by the token check instead, and the
     * test would pass for the wrong reason. Both gate tests therefore assert the
     * database is unchanged as well as the answer.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    private function writeCalls(int $tagId): array
    {
        $token = $this->ws->token();
        return array(
            'pwg.tags.add' => array('pwg.tags.add', array('name' => $this->uniqueName('refused'))),
            'pwg.tags.rename' => array('pwg.tags.rename', array(
                'tag_id' => $tagId, 'new_name' => $this->uniqueName('refused'), 'pwg_token' => $token,
                )),
            'pwg.tags.delete' => array('pwg.tags.delete', array('tag_id' => $tagId, 'pwg_token' => $token)),
            );
    }

    /** Creates a tag through the API and remembers it for teardown. */
    private function addTag(string $name): int
    {
        $res = $this->ws->call('pwg.tags.add', array('name' => $name));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)($res['json']['result']['id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the call returned no tag id: ' . $res['body']);
        $this->testTags[] = $id;

        return $id;
    }

    private function renameTag(int $tagId, string $newName): void
    {
        $res = $this->ws->call('pwg.tags.rename', array(
            'tag_id' => $tagId,
            'new_name' => $newName,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function deleteTag(int $tagId): void
    {
        $res = $this->ws->call('pwg.tags.delete', array(
            'tag_id' => $tagId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** A fixture photo in a fixture album, the arrangement the properties screen needs. */
    private function photoOnTheProperties(): int
    {
        $album = $this->fixture->createTestAlbum('provenance-char-tags-' . bin2hex(random_bytes(4)));
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $album);

        return $image;
    }

    /**
     * Posts the photo-properties form with exactly this tag list.
     *
     * The screen sends existing tags as ~~<id>~~, which is what get_tag_ids()
     * reads back (admin/include/functions.php:2884).
     */
    private function assignTags(int $imageId, array $tagIds): void
    {
        $res = $this->ws->postPage('/admin.php?page=photo-' . $imageId . '-properties', array(
            'level' => 0,
            'submit' => 1,
            'pwg_token' => $this->ws->token(),
            'associate' => $this->albumsOfPhoto($imageId),
            'tags' => array_map(fn($id) => "~~$id~~", $tagIds),
            ));

        $this->assertSame(200, $res['http_code'], 'the properties screen did not answer');
    }

    /** @return int[] ascending, so the assertion does not depend on row order */
    private function tagsOfPhoto(int $imageId): array
    {
        $result = $this->db->query(
            'SELECT tag_id FROM `piwigo_image_tag` WHERE image_id = ' . $imageId . ' ORDER BY tag_id'
        );
        $ids = array();
        while ($row = $result->fetch_assoc())
        {
            $ids[] = (int)$row['tag_id'];
        }
        return $ids;
    }

    /** @return int[] */
    private function albumsOfPhoto(int $imageId): array
    {
        $result = $this->db->query(
            'SELECT category_id FROM `piwigo_image_category` WHERE image_id = ' . $imageId . ' ORDER BY category_id'
        );
        $ids = array();
        while ($row = $result->fetch_assoc())
        {
            $ids[] = (int)$row['category_id'];
        }
        return $ids;
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

    private function linkCount(int $tagId): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_image_tag` WHERE tag_id = $tagId");
    }

    /** A colour row of this test's own, forced and asserted, removed in teardown. */
    private function createColor(): int
    {
        $name = 'provenance-char-color-' . bin2hex(random_bytes(4));
        $this->db->query(
            "INSERT INTO `piwigo_typetags` (name, color) VALUES ('" . $this->db->escape($name) . "', '#123456')"
        );
        $id = $this->db->insertId();
        if ($id <= 0)
        {
            throw new RuntimeException('fixture colour row was not inserted');
        }
        $this->testColors[] = $id;

        return $id;
    }

    private function tagCount(): int
    {
        return (int)$this->db->scalar('SELECT COUNT(*) FROM `piwigo_tags`');
    }

    private function column(int $tagId, string $column): mixed
    {
        return $this->db->scalar("SELECT `$column` FROM `piwigo_tags` WHERE id = " . $tagId);
    }

    private function uniqueName(string $suffix): string
    {
        return 'provenance-char-tag-' . $suffix . '-' . bin2hex(random_bytes(4));
    }
}
