<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the two core files Phase 7 patches:
 * associate_images_to_categories() in admin/include/functions.php and the
 * storage-link insert in admin/site_update.php.
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing in Piwigo's documentation promises that a re-association
 * preserves rank or that the filesystem sync fills storage_category_id - these
 * tests record that it does today, so a patch that changes it says so instead of
 * passing quietly. They report a change; they do not prove the behaviour right.
 *
 * They land and pass before either patch is written, which is normally the tell
 * that a test recorded code rather than drove it. Here that is the point, so the
 * strength check moves: each was watched go red by breaking the behaviour it
 * claims to watch (see the Phase 7 notes in the plan).
 *
 * Drives the real boundaries: pwg.images.setCategory over ws.php for the three
 * association functions, and an admin.php POST for the sync.
 */
final class CoreAssociationCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPhysicalAlbums();
        $this->ws->logout();
    }

    // ── associate_images_to_categories() ──────────────────────────────────

    /** [ERR] Each new pair takes ++max_rank of its category, in the order given. */
    public function testANewPairTakesTheNextRankInItsCategory(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-rank');
        $first = $this->fixture->createTestImage()['id'];
        $second = $this->fixture->createTestImage()['id'];

        $this->associate(array($first), $album);
        $this->associate(array($second), $album);

        $this->assertSame(1, $this->rankOf($first, $album));
        $this->assertSame(2, $this->rankOf($second, $album));
    }

    /** [ERR] Ranks are per category, so a second album starts counting again. */
    public function testRanksAreCountedPerCategory(): void
    {
        $a = $this->fixture->createTestAlbum('provenance-char-rank-a');
        $b = $this->fixture->createTestAlbum('provenance-char-rank-b');
        $first = $this->fixture->createTestImage()['id'];
        $second = $this->fixture->createTestImage()['id'];

        $this->associate(array($first, $second), $a);
        $this->associate(array($second), $b);

        $this->assertSame(2, $this->rankOf($second, $a));
        $this->assertSame(1, $this->rankOf($second, $b));
    }

    /** [ERR] An already-associated pair is skipped: no duplicate row, rank untouched. */
    public function testAnExistingPairIsSkippedAndKeepsItsRank(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-existing');
        $first = $this->fixture->createTestImage()['id'];
        $second = $this->fixture->createTestImage()['id'];

        $this->associate(array($first), $album);
        $this->assertSame(1, $this->rankOf($first, $album));

        // The second call names both, so the skip and the insert happen together.
        $this->associate(array($first, $second), $album);

        $this->assertSame(
            1,
            (int)$this->db->scalar(
                "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $first AND category_id = $album"
            ),
            'a repeated association must not duplicate the link row'
        );
        $this->assertSame(1, $this->rankOf($first, $album), 'the existing pair keeps its rank');
        $this->assertSame(2, $this->rankOf($second, $album), 'the new pair takes ++max_rank');
    }

    // ── the storage-link guard ────────────────────────────────────────────

    /** [ERR] dissociate breaks a virtual link but never the storage one. */
    public function testDissociateLeavesTheStorageLinkIntact(): void
    {
        $storage = $this->fixture->createTestAlbum('provenance-char-storage');
        $virtual = $this->fixture->createTestAlbum('provenance-char-virtual');
        $image = $this->fixture->createTestImage()['id'];

        $this->db->query("UPDATE `piwigo_images` SET storage_category_id = $storage WHERE id = $image");
        $this->associate(array($image), $storage);
        $this->associate(array($image), $virtual);
        $this->assertSame(2, $this->linkCount($image), 'anti-vacuity: both links must exist before dissociating');

        $this->setCategory(array($image), $virtual, 'dissociate');
        $this->assertTrue($this->linked($image, $virtual) === false, 'the virtual link is broken');

        $this->setCategory(array($image), $storage, 'dissociate');
        $this->assertTrue($this->linked($image, $storage), 'the storage link survives dissociate');
    }

    /** [ERR] move drops every other link except the storage one, then associates the destination. */
    public function testMoveKeepsTheStorageLinkAndAddsTheDestination(): void
    {
        $storage = $this->fixture->createTestAlbum('provenance-char-move-storage');
        $virtual = $this->fixture->createTestAlbum('provenance-char-move-virtual');
        $target = $this->fixture->createTestAlbum('provenance-char-move-target');
        $image = $this->fixture->createTestImage()['id'];

        $this->db->query("UPDATE `piwigo_images` SET storage_category_id = $storage WHERE id = $image");
        $this->associate(array($image), $storage);
        $this->associate(array($image), $virtual);
        $this->assertSame(2, $this->linkCount($image), 'anti-vacuity: both links must exist before moving');

        $this->setCategory(array($image), $target, 'move');

        $this->assertTrue($this->linked($image, $storage), 'the storage link survives a move');
        $this->assertFalse($this->linked($image, $virtual), 'the virtual link is dropped by a move');
        $this->assertTrue($this->linked($image, $target), 'the destination link is created');
    }

    // ── the filesystem sync ───────────────────────────────────────────────

    /** [ERR] A synced photo gets its link and a non-NULL storage_category_id. */
    public function testFilesystemSyncInsertsALinkCarryingTheStorageCategory(): void
    {
        $album = $this->fixture->createPhysicalAlbum('provenance-char-sync-' . bin2hex(random_bytes(4)));
        $file = $this->fixture->placePhotoInPhysicalAlbum($album['id']);

        $this->sync($album['id']);

        $id = $this->db->scalar(
            "SELECT id FROM `piwigo_images` WHERE storage_category_id = {$album['id']} AND file = '" .
            $this->db->escape($file) . "'"
        );
        $this->assertNotNull($id, 'the sync did not insert the placed photo');

        $this->assertSame(
            1,
            (int)$this->db->scalar(
                "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = " . (int)$id .
                " AND category_id = {$album['id']}"
            ),
            'the sync inserts exactly one link to the storage album'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function associate(array $imageIds, int $catId): void
    {
        $this->setCategory($imageIds, $catId, 'associate');
    }

    private function setCategory(array $imageIds, int $catId, string $action): void
    {
        $res = $this->ws->call('pwg.images.setCategory', array(
            'image_id' => $imageIds,
            'category_id' => $catId,
            'action' => $action,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** Drives admin/site_update.php over one album only, never the whole gallery. */
    private function sync(int $catId): void
    {
        $res = $this->ws->postPage('/admin.php?page=site_update&site=1', array(
            'sync' => 'files',
            'cat' => $catId,
            'subcats-included' => '1',
            'privacy_level' => '0',
            'sync_meta' => '0',
            'display_info' => '0',
            'simulate' => '0',
            'submit' => 'Submit',
            ));

        $this->assertSame(200, $res['http_code'], 'the synchronization page did not answer');
        $this->assertStringNotContainsString('Hacking attempt', (string)$res['body']);
    }

    private function rankOf(int $imageId, int $catId): int
    {
        $rank = $this->db->scalar(
            "SELECT `rank` FROM `piwigo_image_category` WHERE image_id = $imageId AND category_id = $catId"
        );
        $this->assertNotNull($rank, "photo $imageId is not linked to album $catId");
        return (int)$rank;
    }

    private function linked(int $imageId, int $catId): bool
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId AND category_id = $catId"
        ) > 0;
    }

    private function linkCount(int $imageId): int
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId"
        );
    }
}
