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

    /** The upload_detect_duplicate row as it was before a test forced it; null when untouched. */
    private ?string $originalDetectDuplicate = null;

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
        if ($this->originalDetectDuplicate !== null)
        {
            $this->setDetectDuplicate($this->originalDetectDuplicate);
            $this->originalDetectDuplicate = null;
        }

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

    /**
     * [ERR] Uploading a file whose bytes match an existing photo adds no second
     * photo: the upload is discarded and the existing photo is linked to the
     * album it was uploaded into as well.
     *
     * Oracle: add_uploaded_file() (admin/include/functions_upload.inc.php:160-183)
     * looks the new file's md5 up in piwigo_images.md5sum while
     * $conf['upload_detect_duplicate'] is on, and returns the id it finds. No
     * requirement confirms this; it records what core does today.
     */
    public function testAByteIdenticalUploadLinksTheExistingPhotoInsteadOfAddingOne(): void
    {
        $this->forceDetectDuplicate();

        $file = $this->uniqueUploadFile();
        $md5 = md5_file($file);
        $this->assertSame(
            0,
            $this->imageCountWithMd5($md5),
            'precondition: no photo may carry these bytes before the first upload'
        );

        $secondAlbumId = $this->fixture->createTestAlbum('provenance-char-upload-dup-' . bin2hex(random_bytes(4)));

        $first = $this->upload($file, $this->albumId);
        $this->uploadCompleted($first, $this->albumId);
        $second = $this->upload($file, $secondAlbumId);
        $this->uploadCompleted($second, $secondAlbumId);

        $this->assertSame(1, $this->imageCountWithMd5($md5), 'the same bytes uploaded twice must leave one photo');
        $this->assertSame($first, $second, 'the second upload must hand back the existing photo');
        $this->assertSame(
            array($this->albumId, $secondAlbumId),
            $this->albumIdsOf($first),
            'the existing photo must be in both albums it was uploaded into'
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
    private function upload(?string $file = null, ?int $albumId = null): int
    {
        $file ??= $this->fixture->createTestImage()['file'];

        $res = $this->ws->upload('pwg.images.upload', $file, 'file', array(
            'name' => 'provenance-upload-' . bin2hex(random_bytes(4)) . '.png',
            'category' => $albumId ?? $this->albumId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $id = (int)($res['json']['result']['image_id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the upload returned no image id');
        $this->fixture->adoptImage($id);

        return $id;
    }

    /** What the upload screen sends once its queue is done; this is what empties the lounge. */
    private function uploadCompleted(int $imageId, ?int $albumId = null): void
    {
        $res = $this->ws->call('pwg.images.uploadCompleted', array(
            'image_id' => array($imageId),
            'category_id' => $albumId ?? $this->albumId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /**
     * A copy of the fixture photo with random bytes after its end, so its md5 is
     * this run's alone: a photo left behind by an earlier upload of the plain
     * copy would otherwise be found as the duplicate. PNG readers stop at IEND.
     */
    private function uniqueUploadFile(): string
    {
        $source = $this->fixture->createTestImage();
        $file = tempnam(sys_get_temp_dir(), 'provenance_upload_');
        $this->scratchFiles[] = $file;
        file_put_contents($file, file_get_contents($source['file']) . random_bytes(16));
        $this->assertGreaterThan(filesize($source['file']), filesize($file), 'anti-vacuity: the upload must carry bytes');

        return $file;
    }

    /** Turns duplicate detection on for this test, remembering the row so teardown puts it back. */
    private function forceDetectDuplicate(): void
    {
        $current = $this->db->scalar("SELECT value FROM `piwigo_config` WHERE param = 'upload_detect_duplicate'");
        $this->assertNotNull($current, 'the install has no upload_detect_duplicate row to force');

        if ((string)$current !== 'true')
        {
            $this->originalDetectDuplicate = (string)$current;
            $this->setDetectDuplicate('true');
        }

        $this->assertSame(
            'true',
            (string)$this->db->scalar("SELECT value FROM `piwigo_config` WHERE param = 'upload_detect_duplicate'"),
            'the fixture did not take effect: duplicate detection is off'
        );
    }

    private function setDetectDuplicate(string $value): void
    {
        $this->db->query(
            "UPDATE `piwigo_config` SET value = '" . $this->db->escape($value) . "' WHERE param = 'upload_detect_duplicate'"
        );
    }

    private function imageCountWithMd5(string $md5): int
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM `piwigo_images` WHERE md5sum = '" . $this->db->escape($md5) . "'"
        );
    }

    /** @return int[] the albums a photo is linked to, ascending */
    private function albumIdsOf(int $imageId): array
    {
        $result = $this->db->query(
            "SELECT category_id FROM `piwigo_image_category` WHERE image_id = $imageId ORDER BY category_id"
        );
        return array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'category_id'));
    }

    private function albumCountOf(int $imageId): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = $imageId");
    }
}
