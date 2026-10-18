<?php
use PHPUnit\Framework\TestCase;

/**
 * No tag tool separates a person's tag from its mark (design "Tag tools refuse
 * to remove or delete a person's tag"). Every path that takes a tag off a photo
 * or deletes it is tried once with the tag of a person marked on the photo -
 * refused, the tag still on the photo, the person's link answering - and once
 * with an ordinary tag, which still goes.
 *
 * Before, these paths removed the tag and kept the mark; the person link then
 * answered 401 or 404 (SES 14-21 F1, 15-01 F1/F2).
 */
final class TagToolGuardTest extends TestCase
{
    /** A rendered page shorter than this is an error page. */
    private const MIN_PAGE_BYTES = 2000;

    private const NAME = 'Persons Guard Jane';
    private const BATCH_GLOBAL = '/admin.php?page=batch_manager&mode=global';
    private const BATCH_UNIT = '/admin.php?page=batch_manager&mode=unit';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private int $image;
    private string $imageFile;
    private array $scratch = array();
    private int $album;
    /** The person's tag, put on the photo by the mark. */
    private int $person;
    /** An ordinary tag on the same photo. */
    private int $plain;
    private string $plainName;
    /** typetags groups this test created */
    private array $groups = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->fail('the persons plugin is not installed; activate it first');
        }

        $fixtureImage = $this->fixture->createTestImage();
        $this->image = (int)$fixtureImage['id'];
        $this->imageFile = $fixtureImage['file'];
        $this->album = $this->fixture->createTestAlbum('Persons guard fixture');
        $this->fixture->attachImage($this->image, $this->album);
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->mark($this->image);
        $this->person = (int)$this->db->scalar(
            "SELECT tag_id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::NAME) . "'");
        $this->assertGreaterThan(0, $this->person, 'anti-vacuity: the person was never mirrored as a tag');
        $this->assertTrue($this->carries($this->image, $this->person), 'anti-vacuity: the mark did not tag the photo');

        $this->plainName = 'Persons guard plain ' . bin2hex(random_bytes(4));
        $this->db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('" . $this->plainName . "', '"
            . str_replace(' ', '_', $this->plainName) . "')");
        $this->plain = $this->db->insertId();
        $this->link($this->image, $this->plain);
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::NAME));
        $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . $this->plain);
        $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . $this->plain);
        foreach ($this->groups as $id)
        {
            $this->db->query('UPDATE piwigo_tags SET id_typetags = NULL WHERE id_typetags = ' . $id);
            $this->db->query('DELETE FROM piwigo_typetags WHERE id = ' . $id);
        }
        $this->ws->logout();
    }

    // ── pwg.images.setInfo ────────────────────────────────────────────────

    /** [NEG] tag_ids in replace mode without the person's tag is refused. */
    public function testSetInfoReplaceWithoutThePersonsTagIsRefused(): void
    {
        $res = $this->setInfo(array('tag_ids' => (string)$this->plain, 'multiple_value_mode' => 'replace'));

        $this->assertRefused($res, PERSONS_TAG_REMOVAL_REFUSED_MESSAGE);
        $this->assertTrue($this->carries($this->image, $this->plain), 'a refused call changed the photo');
        $this->assertPersonIntact();
    }

    /** [HAPPY] tag_ids in replace mode keeping the person's tag takes an ordinary tag off. */
    public function testSetInfoReplaceKeepingThePersonsTagRemovesAnOrdinaryTag(): void
    {
        $res = $this->setInfo(array('tag_ids' => (string)$this->person, 'multiple_value_mode' => 'replace'));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertFalse($this->carries($this->image, $this->plain));
        $this->assertTrue($this->carries($this->image, $this->person));
    }

    /** [ECP] append mode removes nothing, so it is not refused. */
    public function testSetInfoAppendIsNotRefused(): void
    {
        $res = $this->setInfo(array('tag_ids' => (string)$this->plain, 'multiple_value_mode' => 'append'));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** [NEG] tag_list (the Batch Manager's unit mode) without the person's tag is refused. */
    public function testSetInfoTagListWithoutThePersonsTagIsRefused(): void
    {
        $res = $this->setInfo(array('tag_list' => array('~~' . $this->plain . '~~')));

        $this->assertRefused($res, PERSONS_TAG_REMOVAL_REFUSED_MESSAGE);
        $this->assertPersonIntact();
    }

    /** [ECP] tag_list naming the person by name keeps the tag, so the ordinary one goes. */
    public function testSetInfoTagListNamingThePersonKeepsItsTag(): void
    {
        $res = $this->setInfo(array('tag_list' => array(self::NAME)));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertFalse($this->carries($this->image, $this->plain));
        $this->assertTrue($this->carries($this->image, $this->person));
    }

    /** [ECP] The person's tag on a photo where the person is not marked may go. */
    public function testAPersonsTagWithoutTheMarkOnThePhotoIsRemovable(): void
    {
        $other = (int)$this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($other, $this->album);
        $this->link($other, $this->person);

        $res = $this->setInfo(array('tag_ids' => '', 'multiple_value_mode' => 'replace'), $other);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertFalse($this->carries($other, $this->person));
        $this->assertTrue($this->carries($this->image, $this->person), 'the marked photo lost the tag too');
    }

    // ── Batch Manager ─────────────────────────────────────────────────────

    /** [NEG] Global mode's del_tags of the person's tag is refused with the message. */
    public function testBatchManagerDelTagsOfThePersonsTagIsRefused(): void
    {
        $body = $this->batchGlobal(array($this->person, $this->plain));

        $this->assertStringContainsString($this->message(PERSONS_TAG_REMOVAL_REFUSED_MESSAGE), $body);
        $this->assertTrue($this->carries($this->image, $this->plain), 'the whole action is refused');
        $this->assertPersonIntact();
    }

    /** [HAPPY] Global mode's del_tags of an ordinary tag still works. */
    public function testBatchManagerDelTagsOfAnOrdinaryTagWorks(): void
    {
        $body = $this->batchGlobal(array($this->plain));

        $this->assertStringNotContainsString($this->message(PERSONS_TAG_REMOVAL_REFUSED_MESSAGE), $body);
        $this->assertFalse($this->carries($this->image, $this->plain));
        $this->assertTrue($this->carries($this->image, $this->person));
    }

    /** [NEG] The unit-mode form without the person's tag keeps it, saves the rest, and says why. */
    public function testBatchManagerUnitFormKeepsThePersonsTag(): void
    {
        $res = $this->ws->postPage(self::BATCH_UNIT, array(
            'pwg_token' => $this->ws->token(),
            'submit' => 1,
            'element_ids' => (string)$this->image,
            "name-{$this->image}" => 'Guard unit title',
            "author-{$this->image}" => '',
            "level-{$this->image}" => 0,
            "description-{$this->image}" => '',
            "tags-{$this->image}" => array(),
            ));

        $this->assertSame(200, $res['http_code']);
        $this->assertStringContainsString($this->message(PERSONS_TAG_REMOVAL_REFUSED_MESSAGE), (string)$res['body']);
        $this->assertFalse($this->carries($this->image, $this->plain), 'the ordinary tag was kept too');
        $this->assertSame('Guard unit title', $this->db->scalar('SELECT name FROM piwigo_images WHERE id = ' . $this->image));
        $this->assertPersonIntact();
    }

    // ── photo properties (Eigenschaften) ──────────────────────────────────

    /** [NEG] The properties form without the person's tag keeps it, saves the rest, and says why. */
    public function testPropertiesKeepThePersonsTag(): void
    {
        $body = $this->postProperties(array(), 'Guard properties title');

        $this->assertStringContainsString($this->message(PERSONS_TAG_REMOVAL_REFUSED_MESSAGE), $body);
        $this->assertFalse($this->carries($this->image, $this->plain), 'the ordinary tag was kept too');
        $this->assertSame('Guard properties title', $this->db->scalar('SELECT name FROM piwigo_images WHERE id = ' . $this->image));
        $this->assertPersonIntact();
    }

    /** [HAPPY] The properties form taking off an ordinary tag says nothing about persons. */
    public function testPropertiesRemoveAnOrdinaryTag(): void
    {
        $body = $this->postProperties(array('~~' . $this->person . '~~'), 'Guard properties plain');

        $this->assertStringNotContainsString($this->message(PERSONS_TAG_REMOVAL_REFUSED_MESSAGE), $body);
        $this->assertFalse($this->carries($this->image, $this->plain));
        $this->assertTrue($this->carries($this->image, $this->person));
    }

    // ── pwg.tags.* (Schlagwortverwaltung) ─────────────────────────────────

    /** [NEG] Deleting the person's tag is refused, also beside an ordinary tag. */
    public function testDeletingThePersonsTagIsRefused(): void
    {
        $res = $this->ws->call('pwg.tags.delete', array(
            'tag_id' => array($this->person, $this->plain),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertRefused($res, PERSONS_TAG_DELETE_REFUSED_MESSAGE);
        $this->assertSame(1, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_tags WHERE id = ' . $this->plain),
            'the whole call is refused');
        $this->assertPersonIntact();
    }

    /** [HAPPY] Deleting an ordinary tag still works. */
    public function testDeletingAnOrdinaryTagWorks(): void
    {
        $res = $this->ws->call('pwg.tags.delete', array(
            'tag_id' => array($this->plain),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_tags WHERE id = ' . $this->plain));
    }

    /** [NEG] Merging the person's tag into another, which deletes it, is refused. */
    public function testMergingThePersonsTagAwayIsRefused(): void
    {
        $res = $this->ws->call('pwg.tags.merge', array(
            'destination_tag_id' => $this->plain,
            'merge_tag_id' => array($this->person),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertRefused($res, PERSONS_TAG_DELETE_REFUSED_MESSAGE);
        $this->assertPersonIntact();
    }

    /** [HAPPY] Merging an ordinary tag into the person's tag still works. */
    public function testMergingAnOrdinaryTagIntoThePersonsTagWorks(): void
    {
        $res = $this->ws->call('pwg.tags.merge', array(
            'destination_tag_id' => $this->person,
            'merge_tag_id' => array($this->plain),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_tags WHERE id = ' . $this->plain));
        $this->assertPersonIntact();
    }

    // ── typetags ──────────────────────────────────────────────────────────

    /** [NEG] The coloured badge's x on the person's tag is refused. */
    public function testTypetagsRemoveOfThePersonsTagIsRefused(): void
    {
        $this->colour();

        $res = $this->removeTag($this->person);

        $this->assertRefused($res, PERSONS_TAG_REMOVAL_REFUSED_MESSAGE);
        $this->assertPersonIntact();
    }

    /** [HAPPY] The x on an ordinary coloured tag still works. */
    public function testTypetagsRemoveOfAnOrdinaryTagWorks(): void
    {
        $this->colour();

        $res = $this->removeTag($this->plain);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertFalse($this->carries($this->image, $this->plain));
    }

    /**
     * [NEG] The picture page offers no x on the person's tag: typetags'
     * list of removable coloured tags leaves it out, the ordinary one stays.
     * (The DOM itself is the E2E spec's.)
     */
    public function testThePicturePageOffersNoRemovalOfThePersonsTag(): void
    {
        $this->colour();

        $res = $this->ws->fetchPage('/picture.php?/' . $this->image . '/category/' . $this->album);
        $body = (string)$res['body'];
        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body));

        $this->assertSame(1, preg_match('/var assignedColoredIds = \[([0-9,]*)\];/', $body, $m),
            'typetags no longer emits assignedColoredIds');
        $ids = array_map('intval', array_filter(explode(',', $m[1]), 'strlen'));
        $this->assertContains($this->plain, $ids, 'anti-vacuity: the ordinary coloured tag is not removable');
        $this->assertNotContains($this->person, $ids);
    }

    // ── pwg.tags.rename ───────────────────────────────────────────────────

    /** [NEG] Renaming the person's tag is refused; the person is renamed under Personen. */
    public function testRenamingThePersonsTagIsRefused(): void
    {
        $res = $this->ws->call('pwg.tags.rename', array(
            'tag_id' => $this->person,
            'new_name' => 'Persons guard renamed ' . bin2hex(random_bytes(3)),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertRefused($res, PERSONS_TAG_RENAME_REFUSED_MESSAGE);
        $this->assertPersonIntact();
    }

    /** [HAPPY] Renaming an ordinary tag still works. */
    public function testRenamingAnOrdinaryTagWorks(): void
    {
        $new = 'Persons guard renamed ' . bin2hex(random_bytes(3));
        $res = $this->ws->call('pwg.tags.rename', array(
            'tag_id' => $this->plain,
            'new_name' => $new,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($new, $this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $this->plain));
    }

    // ── uploads with tag_ids ──────────────────────────────────────────────

    /** [NEG] pwg.images.uploadAsync replacing a photo with tag_ids that lack the person's tag keeps it. */
    public function testUploadAsyncReplaceKeepsThePersonsTag(): void
    {
        $file = $this->smallPng();
        $sum = md5_file($file);
        $res = $this->ws->upload('pwg.images.uploadAsync', $file, 'file', array(
            'chunk' => 0, 'chunk_sum' => $sum, 'chunks' => 1, 'original_sum' => $sum,
            'category' => $this->album, 'filename' => basename($this->imageFile),
            'image_id' => $this->image, 'tag_ids' => (string)$this->plain,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertTrue($this->carries($this->image, $this->person), "the replace dropped the person's tag");
        $this->assertTrue($this->carries($this->image, $this->plain), 'the tag the caller asked for was not set');
        $this->assertPersonIntact();
    }

    /** [NEG] pwg.images.add on an existing photo with tag_ids that lack the person's tag keeps it. */
    public function testImagesAddOnAnExistingPhotoKeepsThePersonsTag(): void
    {
        $file = $this->smallPng();
        $sum = md5_file($file);
        $chunk = $this->ws->call('pwg.images.addChunk', array(
            'data' => base64_encode(file_get_contents($file)), 'original_sum' => $sum,
            'type' => 'file', 'position' => 0,
            ));
        $this->assertSame('ok', $chunk['json']['stat'] ?? null, $chunk['body']);

        $res = $this->ws->call('pwg.images.add', array(
            'original_sum' => $sum, 'original_filename' => basename($this->imageFile),
            'image_id' => $this->image, 'check_uniqueness' => 'false',
            'tag_ids' => (string)$this->plain,
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertTrue($this->carries($this->image, $this->person), "pwg.images.add dropped the person's tag");
        $this->assertTrue($this->carries($this->image, $this->plain), 'the tag the caller asked for was not set');
        $this->assertPersonIntact();
    }

    /** [ECP] A replace whose tag_ids are empty still keeps the person's tag and drops the ordinary one. */
    public function testUploadAsyncReplaceWithEmptyTagIdsChangesNoTags(): void
    {
        $file = $this->smallPng();
        $sum = md5_file($file);
        $res = $this->ws->upload('pwg.images.uploadAsync', $file, 'file', array(
            'chunk' => 0, 'chunk_sum' => $sum, 'chunks' => 1, 'original_sum' => $sum,
            'category' => $this->album, 'filename' => basename($this->imageFile),
            'image_id' => $this->image, 'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertTrue($this->carries($this->image, $this->person));
        $this->assertTrue($this->carries($this->image, $this->plain), 'a call without tag_ids changed the tags');
    }

    // ── pwg.photoinfo.pruneTags ───────────────────────────────────────────

    /** [NEG] The prune removes a tag the file does not name, but not the tag of a person marked on the photo. */
    public function testPruneTagsKeepsThePersonsTag(): void
    {
        foreach (array('photoinfo', 'provenance') as $plugin)
        {
            if ($this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = '$plugin'") !== 'active')
            {
                $this->fail("$plugin is not active; activate it to run the prune case");
            }
        }
        $extraName = 'Persons guard extra ' . bin2hex(random_bytes(4));
        $this->db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('$extraName', '" . str_replace(' ', '_', $extraName) . "')");
        $extra = $this->db->insertId();
        $this->link($this->image, $extra);
        // The file names only the ordinary tag, as a file written elsewhere without the person's keyword would.
        $command = 'exiftool -config ' . escapeshellarg(PIWIGO_ROOT . 'plugins/photoinfo/exiftool/pwginfo.config')
            . ' -q -overwrite_original -XMP-dc:Subject= -XMP-lr:HierarchicalSubject= '
            . escapeshellarg('-XMP-dc:Subject=' . $this->plainName) . ' -XMP-pwginfo:TagsWritten=1 '
            . escapeshellarg($this->imageFile);
        exec($command . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $res = $this->ws->call('pwg.photoinfo.pruneTags', array(
            'image_ids' => (string)$this->image, 'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertFalse($this->carries($this->image, $extra), 'anti-vacuity: the prune removed nothing');
        $this->assertTrue($this->carries($this->image, $this->plain));
        $this->assertTrue($this->carries($this->image, $this->person), "the prune removed the person's tag");
        $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . $extra);
        $this->assertPersonIntact();
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function mark(int $imageId): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $imageId,
            'name' => self::NAME,
            'x' => 0.3, 'y' => 0.3, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** A small PNG carrying no marks, to replace the fixture photo with. */
    private function smallPng(): string
    {
        $file = sys_get_temp_dir() . '/persons_guard_' . bin2hex(random_bytes(6)) . '.png';
        $img = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 255, 0));
        imagepng($img, $file);
        $this->scratch[] = $file;
        return $file;
    }

    private function setInfo(array $params, ?int $imageId = null): array
    {
        return $this->ws->call('pwg.images.setInfo', array_merge(array(
            'image_id' => $imageId ?? $this->image,
            'pwg_token' => $this->ws->token(),
            ), $params));
    }

    private function removeTag(int $tagId): array
    {
        return $this->ws->call('typetags.image.removeTag', array(
            'image_id' => $this->image,
            'tag_id' => $tagId,
            'pwg_token' => $this->ws->token(),
            ));
    }

    /** @return string the rendered page */
    private function batchGlobal(array $tagIds): string
    {
        $res = $this->ws->postPage(self::BATCH_GLOBAL, array(
            'pwg_token' => $this->ws->token(),
            'selection' => array($this->image),
            'selectAction' => 'del_tags',
            'del_tags' => $tagIds,
            'submit' => 1,
            ));
        $this->assertSame(200, $res['http_code'], 'the Batch Manager did not answer');
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$res['body']));

        return (string)$res['body'];
    }

    /** @return string the rendered page */
    private function postProperties(array $rawTags, string $title): string
    {
        $res = $this->ws->postPage('/admin.php?page=photo-' . $this->image . '-properties', array(
            'name' => $title,
            'level' => 0,
            'submit' => 1,
            'pwg_token' => $this->ws->token(),
            'associate' => array($this->album),
            'tags' => $rawTags,
            ));
        $this->assertSame(200, $res['http_code'], 'the properties screen did not answer');
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$res['body']));

        return (string)$res['body'];
    }

    /** Puts the person's tag and the ordinary one into a typetags group of their own. */
    private function colour(): void
    {
        if ($this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = 'typetags'") !== 'active')
        {
            $this->fail('typetags is not active; activate it to run the coloured-badge cases');
        }
        $this->db->query("INSERT INTO piwigo_typetags (name, color) VALUES ('Persons guard " . bin2hex(random_bytes(4)) . "', '#3366cc')");
        $group = $this->db->insertId();
        $this->groups[] = $group;
        $this->db->query("UPDATE piwigo_tags SET id_typetags = $group WHERE id IN ({$this->person}, {$this->plain})");
        $this->assertSame(2, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_tags WHERE id_typetags = $group"));
    }

    private function assertRefused(array $res, string $key): void
    {
        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($this->message($key), $res['json']['message'] ?? null);
    }

    /** The refusal in the test account's language, naming the person. */
    private function message(string $key): string
    {
        $language = (string)$this->db->scalar('SELECT i.language FROM piwigo_user_infos AS i'
            . " JOIN piwigo_users AS u ON u.id = i.user_id WHERE u.username = '" . $this->db->escape(Config::username()) . "'");
        $lang = array();
        $file = PERSONS_PATH . 'language/' . $language . '/plugin.lang.php';
        if (is_file($file))
        {
            include $file;
        }
        return sprintf($lang[$key] ?? $key, self::NAME);
    }

    /** The person's tag is still on the photo, still its own, and its link answers. */
    private function assertPersonIntact(): void
    {
        $this->assertTrue($this->carries($this->image, $this->person), "the person's tag left the photo");
        $this->assertSame(self::NAME, $this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $this->person));

        $res = $this->ws->fetchPage('/picture.php?/' . $this->image . '/category/' . $this->album);
        $this->assertSame(1, preg_match('#<a class="person-box-label" href="([^"]+)"#', (string)$res['body'], $m),
            'no box label links to the person');
        $link = html_entity_decode($m[1]);
        $this->assertStringContainsString('tags/' . $this->person . '-', $link);
        $page = $this->ws->fetchPage('/' . preg_replace('#^\./#', '', $link));
        $this->assertSame(200, $page['http_code'], $link);
        $this->assertStringContainsString(self::NAME, (string)$page['body']);
    }

    private function carries(int $imageId, int $tagId): bool
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = $imageId AND tag_id = $tagId") > 0;
    }

    private function link(int $imageId, int $tagId): void
    {
        $this->db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $tagId)");
        $this->assertTrue($this->carries($imageId, $tagId), "anti-vacuity: photo $imageId was not tagged");
    }
}
