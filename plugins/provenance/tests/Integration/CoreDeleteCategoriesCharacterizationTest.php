<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the third core file Phase 9 patches:
 * delete_categories() in admin/include/functions.php.
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a 'no_delete' album removal leaves its
 * photos behind with no album at all, or that deleting a parent takes its
 * children with it - these record that it does today, so the trigger this phase
 * inserts says so if it changes any of it. They report a change; they do not
 * prove the behaviour right.
 *
 * They land and pass before the patch is written, which is normally the tell
 * that a test recorded code rather than drove it. Here that is the point, so
 * each was watched go red by breaking the behaviour it claims to watch.
 *
 * Drives the real boundary: pwg.categories.delete over ws.php, which is what
 * both admin delete prompts POST.
 */
final class CoreDeleteCategoriesCharacterizationTest extends TestCase
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
        $this->ws->logout();
    }

    /** [ERR] 'no_delete' removes the album and the link, and leaves the photo row. */
    public function testNoDeleteLeavesThePhotoBehindWithNoAlbum(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-del-nodelete');
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $album);

        $this->deleteAlbum($album, 'no_delete');

        $this->assertFalse($this->albumExists($album), 'the album itself must be gone');
        $this->assertTrue($this->imageExists($image), 'the photo row survives the album');
        $this->assertSame(0, $this->albumCountOf($image), 'its only link went with the album');
    }

    /** [ERR] 'delete_orphans' deletes a photo left in no other album. */
    public function testDeleteOrphansDeletesAPhotoThatIsInNoOtherAlbum(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-del-orphan');
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $album);

        $this->deleteAlbum($album, 'delete_orphans');

        $this->assertFalse($this->imageExists($image), 'a photo in no other album is an orphan');
    }

    /** [ERR] 'delete_orphans' spares a photo that is still linked elsewhere. */
    public function testDeleteOrphansSparesAPhotoThatIsStillLinkedElsewhere(): void
    {
        $doomed = $this->fixture->createTestAlbum('provenance-char-del-orphan-a');
        $keeper = $this->fixture->createTestAlbum('provenance-char-del-orphan-b');
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $doomed);
        $this->fixture->attachImage($image, $keeper);
        $this->assertSame(2, $this->albumCountOf($image), 'anti-vacuity: the photo must start in both albums');

        $this->deleteAlbum($doomed, 'delete_orphans');

        $this->assertTrue($this->imageExists($image), 'a photo with another album is not an orphan');
        $this->assertSame(1, $this->albumCountOf($image));
    }

    /** [ERR] 'force_delete' deletes the photo even though another album holds it. */
    public function testForceDeleteRemovesAPhotoThatIsStillLinkedElsewhere(): void
    {
        $doomed = $this->fixture->createTestAlbum('provenance-char-del-force-a');
        $keeper = $this->fixture->createTestAlbum('provenance-char-del-force-b');
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $doomed);
        $this->fixture->attachImage($image, $keeper);
        $this->assertSame(2, $this->albumCountOf($image), 'anti-vacuity: the photo must start in both albums');

        $this->deleteAlbum($doomed, 'force_delete');

        $this->assertFalse($this->imageExists($image), 'force_delete ignores the other album');
        $this->assertTrue($this->albumExists($keeper), 'only the named album is deleted');
    }

    /** [ERR] The id list is expanded: deleting a parent deletes its children too. */
    public function testDeletingAParentAlbumDeletesItsChildren(): void
    {
        $parent = $this->fixture->createTestAlbum('provenance-char-del-parent');
        $child = $this->fixture->createTestAlbum('provenance-char-del-child');
        $this->reparent($child, $parent);
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $child);

        $this->deleteAlbum($parent, 'no_delete');

        $this->assertFalse($this->albumExists($parent));
        $this->assertFalse($this->albumExists($child), 'get_subcat_ids() pulls the child in');
        $this->assertSame(0, $this->albumCountOf($image), "the child's links went with it");
    }

    /** [ERR] An unknown photo_deletion_mode is refused before anything is deleted. */
    public function testAnUnknownDeletionModeIsRefusedAndDeletesNothing(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-del-badmode');

        $res = $this->ws->call('pwg.categories.delete', array(
            'category_id' => $album,
            'photo_deletion_mode' => 'incinerate',
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertTrue($this->albumExists($album), 'a refused call must delete nothing');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function deleteAlbum(int $catId, string $mode): void
    {
        $res = $this->ws->call('pwg.categories.delete', array(
            'category_id' => $catId,
            'photo_deletion_mode' => $mode,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** Makes one fixture album the child of another, the way core stores it. */
    private function reparent(int $child, int $parent): void
    {
        $this->db->query(
            "UPDATE `piwigo_categories` SET id_uppercat = $parent, uppercats = '$parent,$child'" .
            " WHERE id = $child"
        );

        $actual = (string)$this->db->scalar("SELECT uppercats FROM `piwigo_categories` WHERE id = $child");
        if ($actual !== "$parent,$child")
        {
            throw new RuntimeException("fixture album $child did not become a child of $parent: '$actual'");
        }
    }

    private function albumExists(int $catId): bool
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_categories` WHERE id = $catId") === 1;
    }

    private function imageExists(int $imageId): bool
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_images` WHERE id = $imageId") === 1;
    }

    private function albumCountOf(int $imageId): int
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId"
        );
    }
}
