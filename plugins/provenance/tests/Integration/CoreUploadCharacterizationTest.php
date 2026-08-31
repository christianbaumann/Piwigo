<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the core upload the handbook documents: the
 * pwg.images.upload / pwg.images.uploadCompleted pair the upload screen issues
 * (photos_add_direct.js:106 and :437), landing in add_uploaded_file().
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a photo sits in no album until the lounge
 * is emptied, or that a non-image file is refused with that message rather than
 * stored - these record that it does today. They report a change; they do not
 * prove the behaviour right.
 *
 * They land and pass on their first run, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 *
 * Browser coverage of the same screen is deliberately absent: what plupload adds
 * on top is chunking, core's code on a screen no plugin touches
 * (docs/agents/TESTING.md:434).
 */
final class CoreUploadCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;

    /** Files this test wrote outside the fixture, removed again in teardown. */
    private array $scratchFiles = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->albumId = $this->fixture->createTestAlbum('provenance-char-upload-' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file)
        {
            @unlink($file);
        }
        $this->scratchFiles = array();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    /** [HAPPY] The upload screen's two calls leave the photo linked to the album. */
    public function testUploadFollowedByUploadCompletedLinksThePhotoToTheAlbum(): void
    {
        $id = $this->upload();
        $this->uploadCompleted($id);

        $this->assertSame(
            1,
            (int)$this->db->scalar(
                "SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $id AND category_id = {$this->albumId}"
            ),
            'the uploaded photo is not in the album it was uploaded into'
        );
    }

    /**
     * [ST] With the lounge on, the album link appears only once it is emptied.
     *
     * Not a detail for the handbook: between the two calls the photo exists as a
     * row and is in no album at all, which is what a reader sees if the upload
     * is interrupted halfway.
     */
    public function testTheLinkMaterialisesOnlyAfterTheLoungeIsEmptied(): void
    {
        if ((string)$this->db->scalar(
            "SELECT value FROM `piwigo_config` WHERE param = 'lounge_active'"
        ) !== 'true')
        {
            $this->markTestSkipped('the lounge is off on this install, so an upload associates immediately');
        }

        $id = $this->upload();

        $this->assertSame(
            0,
            $this->albumCountOf($id),
            'the photo is still in the lounge, so it is in no album yet'
        );

        $this->uploadCompleted($id);

        $this->assertSame(1, $this->albumCountOf($id), 'emptying the lounge associates the photo');
    }

    /**
     * [NEG] [ECP] A file that is not one of the picture types is refused.
     *
     * $conf['picture_ext'] is jpg, jpeg, png, gif and webp, and
     * $conf['upload_form_all_types'] is false, so add_uploaded_file() reaches
     * neither branch and aborts.
     */
    public function testAnUnsupportedExtensionIsRefused(): void
    {
        $before = (int)$this->db->scalar('SELECT COUNT(*) FROM `piwigo_images`');

        $file = tempnam(sys_get_temp_dir(), 'provenance_upload_');
        $this->scratchFiles[] = $file;
        file_put_contents($file, "keine Bilddatei\n");
        $this->assertGreaterThan(0, filesize($file), 'anti-vacuity: the refused upload must carry bytes');

        $res = $this->ws->upload('pwg.images.upload', $file, 'file', array(
            'name' => 'provenance-upload-' . bin2hex(random_bytes(4)) . '.txt',
            'category' => $this->albumId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertStringContainsString('forbidden file type', (string)$res['body'], $res['body']);
        $this->assertSame(
            $before,
            (int)$this->db->scalar('SELECT COUNT(*) FROM `piwigo_images`'),
            'a refused upload must create no photo row'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Uploads one real photo into the fixture album and hands back its new id.
     *
     * The file posted is a copy the fixture made, so nothing in the collection
     * is read or moved. The row the upload creates is adopted, so teardown
     * removes it and its file like any other fixture photo.
     */
    private function upload(): int
    {
        $source = $this->fixture->createTestImage();

        $res = $this->ws->upload('pwg.images.upload', $source['file'], 'file', array(
            'name' => 'provenance-upload-' . bin2hex(random_bytes(4)) . '.png',
            'category' => $this->albumId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $id = (int)($res['json']['result']['image_id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the upload returned no image id');
        $this->fixture->adoptImage($id);

        return $id;
    }

    /** What the upload screen sends once its queue is done; this is what empties the lounge. */
    private function uploadCompleted(int $imageId): void
    {
        $res = $this->ws->call('pwg.images.uploadCompleted', array(
            'image_id' => array($imageId),
            'category_id' => $this->albumId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function albumCountOf(int $imageId): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId");
    }
}
