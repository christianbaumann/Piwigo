<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core's unused-tag cleanup: get_orphan_tags() and
 * delete_orphan_tags() in admin/include/functions.php, reached over HTTP through
 * the three places that call them (admin.php?page=tags&action=delete_orphans,
 * and the maintenance page's actions and env tabs), plus what admin/tags.php and
 * tags.tpl hand to the confirm dialog.
 *
 * Every case is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a tag is spared for a day after its last
 * change; these record that it does today. They report a change; they prove
 * nothing right. The cases that pinned a coloured and a person's tag as swept up
 * with the plain ones were replaced on purpose by galleryrules' OrphanTagsTest
 * (task 26: both are now kept).
 *
 * They pass on their first run, so each was watched go red by breaking the
 * behaviour it pins (docs/agents/TESTING.md).
 *
 * The cleanup is global: it sweeps every unused tag of the install, not only this
 * test's. setUp() therefore writes the unused tags that already exist to a git-ignored
 * file (OrphanTagSnapshot; a killed run's leftover is restored first) and
 * tearDown() puts back any the run swept away and removes the file.
 */
final class CoreOrphanTagsCharacterizationTest extends TestCase
{
    private const OLD_ENOUGH_DAYS = 2;
    private const TOO_YOUNG_DAYS = 0;
    private const MIN_PAGE_BYTES = 2000;

    private const PATHS = array(
        'tags page' => '/admin.php?page=tags&action=delete_orphans',
        'maintenance actions tab' => '/admin.php?page=maintenance&action=delete_orphan_tags',
        'maintenance env tab' => '/admin.php?page=maintenance&tab=env&action=delete_orphan_tags',
    );

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    /** Unused tags the install held before the run, on disk until tearDown puts them back. */
    private OrphanTagSnapshot $snapshot;
    private array $testTagIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);

        $this->snapshot = new OrphanTagSnapshot($this->db);
        $this->snapshot->take();
    }

    protected function tearDown(): void
    {
        foreach ($this->testTagIds as $id)
        {
            $this->db->query('DELETE FROM `piwigo_image_tag` WHERE tag_id = ' . (int)$id);
            $this->db->query('DELETE FROM `piwigo_tags` WHERE id = ' . (int)$id);
        }
        $this->snapshot->restore();
        $this->testTagIds = array();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    /** [ST] A run killed after the sweep leaves its snapshot file; the next take() gives the tags back. */
    public function testALeftoverSnapshotIsRestoredAtTheNextSetUp(): void
    {
        $id = $this->makeTag('keepme-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
        (new OrphanTagSnapshot($this->db))->take();
        $this->assertFileExists(OrphanTagSnapshot::path(), 'the snapshot is on disk before any sweep');

        $this->db->query('DELETE FROM `piwigo_tags` WHERE id = ' . $id);
        $this->assertFalse($this->tagExists($id), 'anti-vacuity: the simulated sweep removed the tag');

        (new OrphanTagSnapshot($this->db))->take();

        $this->assertTrue($this->tagExists($id), 'the next setUp puts the swept tag back');
    }

    /** [NEG] A tag this suite made (a killed run leaves it unused) is not written to the snapshot. */
    public function testFixtureTagsAreNotKept(): void
    {
        $fixtureId = $this->makeTag('plain-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
        $keepId = $this->makeTag('keepme-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
        (new OrphanTagSnapshot($this->db))->take();

        $kept = array_column(json_decode((string)file_get_contents(OrphanTagSnapshot::path()), true), 'id');
        $this->assertContains((string)$keepId, array_map('strval', $kept), 'anti-vacuity: a real unused tag is kept');
        $this->assertNotContains((string)$fixtureId, array_map('strval', $kept));
    }

    /** [HAPPY] [ECP] Each of the three cleanup paths removes an unused tag. */
    public function testEveryPathRemovesAnUnusedPlainTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $id = $this->makeTag('plain-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
            $this->assertTrue($this->tagExists($id), 'anti-vacuity: the tag must exist before the cleanup');

            $this->runCleanup($url);

            $this->assertFalse($this->tagExists($id), $path . ' removes an unused plain tag');
        }
    }

    /** [NEG] [ECP] A tag linked to a photo is not unused. */
    public function testATagOnAPhotoSurvives(): void
    {
        $id = $this->makeTag('used-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
        $imageId = $this->fixture->createTestImage()['id'];
        $this->db->query("INSERT INTO `piwigo_image_tag` (image_id, tag_id) VALUES ($imageId, $id)");

        $this->runCleanup(self::PATHS['tags page']);

        $this->assertTrue($this->tagExists($id));
    }

    /** [BVA] A tag changed today is spared, one changed two days ago is not. */
    public function testATagChangedWithinTheLastDayIsSpared(): void
    {
        $young = $this->makeTag('young-' . bin2hex(random_bytes(3)), self::TOO_YOUNG_DAYS);
        $old = $this->makeTag('old-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);

        $this->runCleanup(self::PATHS['tags page']);

        $this->assertTrue($this->tagExists($young), 'a tag younger than a day stays');
        $this->assertFalse($this->tagExists($old), 'anti-vacuity: the older tag goes, so the cleanup did run');
    }

    /** [ERR] The tags page offers the unused tags' names to the confirm dialog, joined by the script. */
    public function testTheTagsPageListsTheUnusedNamesForTheConfirm(): void
    {
        $name = 'listed-' . bin2hex(random_bytes(3));
        $this->makeTag($name, self::OLD_ENOUGH_DAYS);

        $page = $this->ws->fetchPage('/admin.php?page=tags');

        $this->assertSame(200, $page['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$page['body']));
        $this->assertSame(1, preg_match('~var orphan_tag_names = \[([^\]]*)\];~', $page['body'], $m), 'the name list var');
        $this->assertStringContainsString('"' . $name . '"', $m[1]);
    }

    /** [ERR] The confirm and the tag menu strings as tags.tpl emits them, in German. */
    public function testTheTagsPageEmitsTheCountAndConfirmStrings(): void
    {
        $page = $this->ws->fetchPage('/admin.php?page=tags');

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$page['body']));
        $this->assertStringContainsString("var str_number_photo = '%d Foto'", $page['body']);
        $this->assertStringContainsString("var str_number_photos = '%d Fotos'", $page['body']);
        $this->assertStringContainsString("var str_orphan_tags = 'Nicht benutzte Schlagworte (%s1):%s2';", $page['body']);
    }

    private function runCleanup(string $url): void
    {
        $response = $this->ws->fetchPage($url . '&pwg_token=' . $this->ws->token());
        $this->assertSame(200, $response['http_code'], 'the cleanup page answers');
    }

    private function makeTag(string $name, int $ageDays): int
    {
        $escaped = $this->db->escape($name);
        $this->db->query(
            "INSERT INTO `piwigo_tags` (name, url_name, lastmodified) VALUES ('$escaped', '$escaped', NOW() - INTERVAL $ageDays DAY)"
        );
        $id = $this->db->insertId();
        $this->testTagIds[] = $id;

        return $id;
    }

    private function tagExists(int $id): bool
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_tags` WHERE id = $id") === 1;
    }
}
