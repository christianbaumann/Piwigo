<?php
use PHPUnit\Framework\TestCase;

/**
 * Every path that changes a photo's tags writes them into its file: the
 * keywords, the group hierarchy and the marker. Each path over its real
 * boundary, the file read back with a plain exiftool call.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", Phase 4 and its
 * Desired End State 1.
 */
final class TagWriteTest extends TestCase
{
    /** A rendered admin page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;
    /** The message a read-only file puts on an admin page. */
    private const WRITE_FAILED = PHOTOINFO_ADMIN_ERROR_PREFIX . PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE;
    /** How long the outside writer keeps the persons lock. */
    private const HOLD_SECONDS = 2;
    /** Attempts, 100 ms apart, at seeing the outside writer's lock taken. */
    private const HOLD_POLLS = 50;
    /** flock(1)'s exit status for "held by someone else" when asked with -E. */
    private const HELD = 9;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private int $albumId;
    private array $image;
    private string $suffix;
    private int $group;
    private ?string $marker = null;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('typetags');

        $this->suffix = bin2hex(random_bytes(4));
        $this->albumId = $this->fixture->createTestAlbum('photoinfo-tags-' . $this->suffix);
        $this->image = $this->newPhoto();
        $this->group = $this->fixture->createGroup('Feste ' . $this->suffix);

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);

        $this->assertNull(FixtureBuilder::readKeywords($this->image['file'])['XMP-pwginfo:TagsWritten'],
            'anti-vacuity: the copied photo already carries the marker');
    }

    protected function tearDown(): void
    {
        if ($this->marker !== null)
        {
            @unlink($this->marker);
        }
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    // ── core's screens ────────────────────────────────────────────────────

    /** [HAPPY] The photo properties screen: a grouped and an ungrouped tag. */
    public function testAPropertiesSaveWritesTheTags(): void
    {
        $kirmes = $this->tag('Kirmes', $this->group);
        $anna = $this->tag('Anna');

        $this->postProperties($this->image['id'], array('tags' => array('~~' . $kirmes . '~~', '~~' . $anna . '~~')));

        $this->assertFileTags($this->image, array($this->tagName('Anna'), $this->tagName('Kirmes')),
            array($this->groupName() . '|' . $this->tagName('Kirmes')));
    }

    /** [HAPPY] The Batch Manager's "add tags" writes every selected photo. */
    public function testABatchAddWritesEverySelectedPhoto(): void
    {
        $other = $this->newPhoto();
        $kirmes = $this->tag('Kirmes', $this->group);

        $this->postBatch(array($this->image['id'], $other['id']), array(
            'selectAction' => 'add_tags',
            'add_tags' => array('~~' . $kirmes . '~~'),
            ));

        foreach (array($this->image, $other) as $photo)
        {
            $this->assertFileTags($photo, array($this->tagName('Kirmes')), array($this->groupName() . '|' . $this->tagName('Kirmes')));
        }
    }

    /** [HAPPY] The Batch Manager's "remove tags" writes every selected photo. */
    public function testABatchRemovalWritesEverySelectedPhoto(): void
    {
        $other = $this->newPhoto();
        $kirmes = $this->tag('Kirmes');
        $anna = $this->tag('Anna');
        foreach (array($this->image, $other) as $photo)
        {
            $this->fixture->linkTag($photo['id'], $kirmes);
            $this->fixture->linkTag($photo['id'], $anna);
        }

        $this->postBatch(array($this->image['id'], $other['id']), array(
            'selectAction' => 'del_tags',
            'del_tags' => array($kirmes),
            ));

        foreach (array($this->image, $other) as $photo)
        {
            $this->assertFileTags($photo, array($this->tagName('Anna')), array());
        }
    }

    /** [HAPPY] pwg.images.setInfo, the Batch Manager's unit mode, says the file was written. */
    public function testSetInfoWritesTheTags(): void
    {
        $kirmes = $this->tag('Kirmes', $this->group);

        $res = $this->setInfo(array('tag_ids' => (string)$kirmes, 'multiple_value_mode' => 'replace'));

        $this->assertTrue($res['json']['result']['tags_written'] ?? null, $res['body']);
        $this->assertFileTags($this->image, array($this->tagName('Kirmes')), array($this->groupName() . '|' . $this->tagName('Kirmes')));
    }

    /** [NEG] setInfo without a tag change writes no tags and says nothing about them. */
    public function testSetInfoWithoutATagChangeWritesNoTags(): void
    {
        $res = $this->setInfo(array('name' => 'Neu ' . $this->suffix));

        $this->assertArrayNotHasKey('tags_written', (array)$res['json']['result'], $res['body']);
        $this->assertNull(FixtureBuilder::readKeywords($this->image['file'])['XMP-pwginfo:TagsWritten']);
    }

    // ── typetags' badges on the picture page ──────────────────────────────

    /** [HAPPY] typetags.image.addTag, then removeTag. */
    public function testTheTypetagsBadgesWriteTheFile(): void
    {
        $kirmes = $this->tag('Kirmes', $this->group);
        $params = array('image_id' => $this->image['id'], 'tag_id' => $kirmes, 'pwg_token' => $this->ws->token());

        $this->assertOk($this->ws->call('typetags.image.addTag', $params));
        $this->assertFileTags($this->image, array($this->tagName('Kirmes')), array($this->groupName() . '|' . $this->tagName('Kirmes')));

        $this->assertOk($this->ws->call('typetags.image.removeTag', $params));
        $this->assertFileTags($this->image, array(), array());
    }

    // ── the admin tags page ───────────────────────────────────────────────

    /** [HAPPY] A rename rewrites every photo with the tag. */
    public function testRenameRewritesEveryPhotoWithTheTag(): void
    {
        $other = $this->newPhoto();
        $kirmes = $this->tag('Kirmes', $this->group);
        $this->fixture->linkTag($this->image['id'], $kirmes);
        $this->fixture->linkTag($other['id'], $kirmes);

        $this->assertOk($this->ws->call('pwg.tags.rename', array(
            'tag_id' => $kirmes, 'new_name' => $this->tagName('Kirchweih'), 'pwg_token' => $this->ws->token(),
            )));

        foreach (array($this->image, $other) as $photo)
        {
            $this->assertFileTags($photo, array($this->tagName('Kirchweih')), array($this->groupName() . '|' . $this->tagName('Kirchweih')));
        }
    }

    /** [HAPPY] A copy is linked to the same photos, ungrouped. */
    public function testDuplicateAddsTheCopyToEveryPhoto(): void
    {
        $kirmes = $this->tag('Kirmes', $this->group);
        $this->fixture->linkTag($this->image['id'], $kirmes);

        $this->assertOk($this->ws->call('pwg.tags.duplicate', array(
            'tag_id' => $kirmes, 'copy_name' => $this->tagName('Kopie'), 'pwg_token' => $this->ws->token(),
            )));
        $this->fixture->tagIdNamed($this->tagName('Kopie'));

        $this->assertFileTags($this->image, array($this->tagName('Kirmes'), $this->tagName('Kopie')),
            array($this->groupName() . '|' . $this->tagName('Kirmes')));
    }

    /** [HAPPY] A merge rewrites the photos of every merged tag. */
    public function testMergeRewritesThePhotosOfTheMergedTags(): void
    {
        $other = $this->newPhoto();
        $kirmes = $this->tag('Kirmes', $this->group);
        $kirchweih = $this->tag('Kirchweih');
        $this->fixture->linkTag($this->image['id'], $kirchweih);
        $this->fixture->linkTag($other['id'], $kirmes);

        $this->assertOk($this->ws->call('pwg.tags.merge', array(
            'destination_tag_id' => $kirmes, 'merge_tag_id' => array($kirchweih), 'pwg_token' => $this->ws->token(),
            )));

        foreach (array($this->image, $other) as $photo)
        {
            $this->assertFileTags($photo, array($this->tagName('Kirmes')), array($this->groupName() . '|' . $this->tagName('Kirmes')));
        }
    }

    /** [HAPPY] Deleting a tag rewrites the photos that had it. */
    public function testDeleteRewritesThePhotosThatHadTheTag(): void
    {
        $kirmes = $this->tag('Kirmes');
        $anna = $this->tag('Anna');
        $this->fixture->linkTag($this->image['id'], $kirmes);
        $this->fixture->linkTag($this->image['id'], $anna);

        $this->assertOk($this->ws->call('pwg.tags.delete', array(
            'tag_id' => array($kirmes), 'pwg_token' => $this->ws->token(),
            )));

        $this->assertFileTags($this->image, array($this->tagName('Anna')), array());
    }

    /** [HAPPY] Moving a tag to another group rewrites the hierarchy. */
    public function testRegroupingRewritesTheHierarchy(): void
    {
        $kirmes = $this->tag('Kirmes', $this->group);
        $this->fixture->linkTag($this->image['id'], $kirmes);
        $other = $this->fixture->createGroup('Arbeiten ' . $this->suffix);

        $this->assertOk($this->ws->call('typetags.tags.setType', array(
            'tag_id' => array($kirmes), 'typetag_id' => $other, 'pwg_token' => $this->ws->token(),
            )));

        $this->assertFileTags($this->image, array($this->tagName('Kirmes')), array('Arbeiten ' . $this->suffix . '|' . $this->tagName('Kirmes')));
    }

    // ── what reaches the file ─────────────────────────────────────────────

    /** [BVA] Removing the last tag empties every field and keeps the marker. */
    public function testRemovingTheLastTagLeavesTheMarkerAndNoKeywords(): void
    {
        $kirmes = $this->tag('Kirmes', $this->group);
        $this->setInfo(array('tag_ids' => (string)$kirmes, 'multiple_value_mode' => 'replace'));
        $this->assertSame(array($this->tagName('Kirmes')), FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject'],
            'anti-vacuity: the tag never reached the file');

        $this->setInfo(array('tag_ids' => '', 'multiple_value_mode' => 'replace'));

        $this->assertFileTags($this->image, array(), array());
    }

    /** [NEG] Ausstellung stays in the database. */
    public function testAusstellungStaysOutOfTheFile(): void
    {
        $ausstellung = $this->ausstellungTag();
        $kirmes = $this->tag('Kirmes');

        $this->setInfo(array('tag_ids' => $ausstellung . ',' . $kirmes, 'multiple_value_mode' => 'replace'));

        $this->assertSame(array($ausstellung, $kirmes), $this->fixture->tagIdsOf($this->image['id']));
        $this->assertFileTags($this->image, array($this->tagName('Kirmes')), array());
    }

    /** [BVA] IPTC keeps 64 bytes of a keyword; the write still succeeds and XMP keeps it whole. */
    public function testAnIptcKeywordOver64BytesDoesNotFailTheWrite(): void
    {
        $long = str_repeat('x', 70) . $this->suffix;
        $id = $this->fixture->createTag($long);

        $res = $this->setInfo(array('tag_ids' => (string)$id, 'multiple_value_mode' => 'replace'));

        $this->assertTrue($res['json']['result']['tags_written'] ?? null, $res['body']);
        $tags = FixtureBuilder::readKeywords($this->image['file']);
        $this->assertSame(array($long), $tags['XMP-dc:Subject']);
        $this->assertSame(array(substr($long, 0, 64)), $tags['IPTC:Keywords']);
    }

    /** [ERR] Umlauts come back unchanged from both XMP and IPTC. Records exiftool's charset handling. */
    public function testUmlautsSurviveInIptcAndXmp(): void
    {
        $name = 'Ölmühle Bräuche ß ' . $this->suffix;
        $id = $this->fixture->createTag($name, $this->group);

        $this->setInfo(array('tag_ids' => (string)$id, 'multiple_value_mode' => 'replace'));

        $this->assertFileTags($this->image, array($name), array($this->groupName() . '|' . $name));
    }

    /** [NEG] A read-only file: the answer says so and the tags stay saved. */
    public function testAReadOnlyFileReportsTheFailureAndKeepsTheDatabase(): void
    {
        $kirmes = $this->tag('Kirmes');
        $this->lockFile($this->image);

        $res = $this->setInfo(array('tag_ids' => (string)$kirmes, 'multiple_value_mode' => 'replace'));

        $this->assertFalse($res['json']['result']['tags_written'] ?? null, $res['body']);
        $this->assertSame(PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE, $res['json']['result']['tags_message']);
        $this->assertSame(array($kirmes), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [NEG] On the photo properties screen a failed tag write shows once. */
    public function testAFailedTagWriteShowsOnThePropertiesScreen(): void
    {
        $kirmes = $this->tag('Kirmes');
        $this->lockFile($this->image);

        $body = $this->postProperties($this->image['id'], array('tags' => array('~~' . $kirmes . '~~')));

        $this->assertSame(1, substr_count($body, self::WRITE_FAILED), 'the failed write was not reported once');
        $this->assertSame(array($kirmes), $this->fixture->tagIdsOf($this->image['id']));
    }

    // ── persons and photoedit ─────────────────────────────────────────────

    /** [HAPPY] A face tagged with plugins/persons puts the name into the file. */
    public function testAPersonsRegionAddWritesTheName(): void
    {
        $this->fixture->assertPluginActive('persons');

        $res = $this->addRegion($this->tagName('Anna'), 0.5);

        $this->assertTagsWritten($res);
        $this->assertFileTags($this->image, array($this->tagName('Anna')), array());
    }

    /** [HAPPY] Removing a face's region takes the name out of the file. */
    public function testAPersonsRegionDeleteRemovesTheName(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->addRegion($this->tagName('Anna'), 0.15);
        $this->addRegion($this->tagName('Bert'), 0.85);
        $region = (int)$this->db->scalar(
            'SELECT r.id FROM piwigo_person_region AS r JOIN piwigo_persons AS p ON p.id = r.person_id ' .
            "WHERE r.image_id = {$this->image['id']} AND p.name = '" . $this->db->escape($this->tagName('Anna')) . "'"
        );

        $res = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $region, 'pwg_token' => $this->ws->token(),
            ));

        $this->assertTagsWritten($res);

        $this->assertFileTags($this->image, array($this->tagName('Bert')), array());
    }

    /** [HAPPY] Renaming a person rewrites every photo of theirs. */
    public function testAPersonsRenameRewritesEveryPhoto(): void
    {
        $this->fixture->assertPluginActive('persons');
        $other = $this->newPhoto();
        $this->addRegion($this->tagName('Anna'), 0.5);
        $this->addRegion($this->tagName('Anna'), 0.5, $other);
        $this->fixture->trackPerson($this->tagName('Anne'));

        $res = $this->ws->call('pwg.persons.rename', array(
            'person_id' => $this->personId($this->tagName('Anna')), 'name' => $this->tagName('Anne'),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertTagsWritten($res);

        foreach (array($this->image, $other) as $photo)
        {
            $this->assertFileTags($photo, array($this->tagName('Anne')), array());
        }
    }

    /** [HAPPY] Deleting a person takes the name out of every photo of theirs. */
    public function testAPersonsDeleteRemovesTheName(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->addRegion($this->tagName('Anna'), 0.15);
        $this->addRegion($this->tagName('Bert'), 0.85);

        $res = $this->ws->call('pwg.persons.delete', array(
            'person_id' => $this->personId($this->tagName('Anna')), 'pwg_token' => $this->ws->token(),
            ));

        $this->assertTagsWritten($res);

        $this->assertFileTags($this->image, array($this->tagName('Bert')), array());
    }

    /**
     * [NEG] pwg.persons.rescan re-adds a person tag the database lost, and
     * writes no file: the deploy runs it over the whole gallery.
     */
    public function testAPersonsRescanWritesNoFile(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->addRegion($this->tagName('Anna'), 0.5);
        $tags = $this->fixture->tagIdsOf($this->image['id']);
        $this->assertCount(1, $tags, 'anti-vacuity: the region add did not tag the photo');
        $this->db->query('DELETE FROM piwigo_image_tag WHERE image_id = ' . $this->image['id']);
        clearstatcache();
        $inode = fileinode($this->image['file']);

        $this->assertOk($this->ws->call('pwg.persons.rescan', array(
            'image_ids' => (string)$this->image['id'], 'pwg_token' => $this->ws->token(),
            )));

        $this->assertSame($tags, $this->fixture->tagIdsOf($this->image['id']), 'anti-vacuity: the rescan changed no tag');
        clearstatcache();
        $this->assertSame($inode, fileinode($this->image['file']), 'the rescan replaced the file');
    }

    /** [ST] A crop that cuts a face away drops the name from the keywords. */
    public function testAPhotoeditCropThatCutsAFaceRewritesTheKeywords(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->fixture->assertPluginActive('photoedit');
        $this->addRegion($this->tagName('Anna'), 0.15);
        $this->addRegion($this->tagName('Bert'), 0.85);
        $this->assertSame(array($this->tagName('Anna'), $this->tagName('Bert')),
            FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject'], 'anti-vacuity: the names never reached the file');

        $this->assertOk($this->ws->call('pwg.photoedit.apply', array(
            'image_id' => $this->image['id'], 'crop' => '0.5,0,1,1', 'pwg_token' => $this->ws->token(),
            )));

        $this->assertFileTags($this->image, array($this->tagName('Bert')), array());
    }

    /**
     * [ERR] A tag write waits for a persons write already running on the
     * photo: when the answer arrives, the outside writer has finished.
     */
    public function testATagWriteWaitsForAHeldPersonsLock(): void
    {
        $this->fixture->assertPluginActive('persons');
        PiwigoRuntime::boot();
        if (!defined('PERSONS_PATH'))
        {
            define('PERSONS_PATH', PIWIGO_ROOT . 'plugins/persons/');
        }
        require_once PERSONS_PATH . 'include/functions.inc.php';

        $path = persons_lock_path($this->image['db_path']);
        @mkdir(dirname($path), 0777, true);
        $this->marker = sys_get_temp_dir() . '/photoinfo-tags-lock-' . $this->suffix;
        exec(sprintf(
            'flock %s sh -c %s > /dev/null 2>&1 &',
            escapeshellarg($path),
            escapeshellarg('sleep ' . self::HOLD_SECONDS . '; touch ' . escapeshellarg($this->marker))
        ));
        $this->waitUntilHeld($path);

        $res = $this->setInfo(array('tag_ids' => (string)$this->tag('Kirmes'), 'multiple_value_mode' => 'replace'));

        $this->assertTrue($res['json']['result']['tags_written'] ?? null, $res['body']);
        $this->assertFileExists($this->marker, 'the tag write did not wait for the persons lock');
    }

    // ── helpers ───────────────────────────────────────────────────────────

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
        return $base . ' ' . $this->suffix;
    }

    private function groupName(): string
    {
        return 'Feste ' . $this->suffix;
    }

    private function tag(string $base, ?int $group = null): int
    {
        return $this->fixture->createTag($this->tagName($base), $group);
    }

    /** The install's Ausstellung tag, or one of this suite's own when the seed has not run. */
    private function ausstellungTag(): int
    {
        $id = (int)$this->db->scalar("SELECT id FROM piwigo_tags WHERE name = 'Ausstellung'");
        return $id > 0 ? $id : $this->fixture->createTag('Ausstellung');
    }

    private function addRegion(string $name, float $x, ?array $photo = null): array
    {
        $this->fixture->trackPerson($name);
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => ($photo ?? $this->image)['id'], 'name' => $name,
            'x' => $x, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertOk($res);
        return $res;
    }

    /** persons' answer says the files were written, as the wrapped methods' answers do. */
    private function assertTagsWritten(array $res): void
    {
        $this->assertTrue($res['json']['result']['tags_written'] ?? null, $res['body']);
        $this->assertSame('', $res['json']['result']['tags_message']);
    }

    private function personId(string $name): int
    {
        $id = (int)$this->db->scalar("SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape($name) . "'");
        $this->assertGreaterThan(0, $id, "no person named $name");
        return $id;
    }

    private function assertFileTags(array $photo, array $subject, array $hierarchy): void
    {
        sort($subject, SORT_STRING);
        sort($hierarchy, SORT_STRING);
        $tags = FixtureBuilder::readKeywords($photo['file']);

        $this->assertSame('1', $tags['XMP-pwginfo:TagsWritten'], 'the marker is missing');
        $this->assertSame($subject, $tags['XMP-dc:Subject'], 'XMP-dc:Subject');
        $this->assertSame($subject, $tags['IPTC:Keywords'], 'IPTC:Keywords');
        $this->assertSame($hierarchy, $tags['XMP-lr:HierarchicalSubject'], 'XMP-lr:HierarchicalSubject');
    }

    private function assertOk(array $res): void
    {
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function postProperties(int $imageId, array $fields): string
    {
        $row = $this->fixture->imageRow($imageId);
        $body = $this->ws->postPage('/admin.php?page=photo-' . $imageId . '-properties', array_merge(array(
            'name' => (string)$row['name'],
            'author' => (string)$row['author'],
            'comment' => (string)$row['comment'],
            'date_creation' => (string)$row['date_creation'],
            'level' => 0,
            'associate' => array($this->albumId),
            'pwg_token' => $this->ws->token(),
            'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the properties screen did not answer');
        return $body;
    }

    private function postBatch(array $ids, array $fields): void
    {
        $body = $this->ws->postPage('/admin.php?page=batch_manager&mode=global', array_merge(array(
            'pwg_token' => $this->ws->token(),
            'selection' => $ids,
            'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the Batch Manager did not answer');
        $this->assertStringNotContainsString(PHOTOINFO_ADMIN_ERROR_PREFIX, $body, 'a file write failed');
    }

    private function setInfo(array $fields): array
    {
        $res = $this->ws->call('pwg.images.setInfo', array_merge(array(
            'image_id' => $this->image['id'],
            'single_value_mode' => 'replace',
            'pwg_token' => $this->ws->token(),
            ), $fields));

        $this->assertOk($res);
        return $res;
    }

    private function lockFile(array $photo): void
    {
        chmod($photo['file'], 0444);
        clearstatcache();
        $this->assertFalse(is_writable($photo['file']), 'anti-vacuity: the file must be read-only');
    }

    private function waitUntilHeld(string $path): void
    {
        for ($i = 0; $i < self::HOLD_POLLS; $i++)
        {
            $output = array();
            $status = -1;
            exec('flock -n -E ' . self::HELD . ' ' . escapeshellarg($path) . ' true 2>&1', $output, $status);
            if ($status === self::HELD)
            {
                return;
            }
            usleep(100000);
        }
        $this->fail('the outside writer never took the persons lock');
    }
}
