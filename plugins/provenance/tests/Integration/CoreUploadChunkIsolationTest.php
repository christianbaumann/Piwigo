<?php
use PHPUnit\Framework\TestCase;

/**
 * Decision 0079: an upload's chunks never end up in another upload, and
 * Abbrechen removes what was already sent.
 *
 * Fork-local change to ws_images_upload() and pwg.images.uploadCancel
 * (include/ws_functions/pwg.images.php), tested against the requirement, so
 * none of these is [ERR].
 */
final class CoreUploadChunkIsolationTest extends TestCase
{
    private const DAY = 86400;

    private Db $db;
    private WsClient $ws;
    private WsClient $otherAdmin;
    private FixtureBuilder $fixture;
    private int $albumId;
    private array $scratch = array();
    private array $partsBefore = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->otherAdmin = new WsClient();
        $this->otherAdmin->login(Config::adminUsername(), Config::adminPassword());

        $this->partsBefore = glob(PIWIGO_ROOT . 'upload/buffer/*.part');
        $this->fixture = new FixtureBuilder($this->db);
        $this->albumId = $this->fixture->createTestAlbum('provenance-upload-iso-' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        foreach ($this->newParts() as $part)
        {
            @unlink($part);
        }
        $this->db->query('DELETE FROM piwigo_lounge WHERE category_id = ' . $this->albumId);
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
        $this->otherAdmin->logout();
    }

    /** [HAPPY] A cancelled upload, then the same name by another admin: exactly the new bytes. */
    public function testAnotherAdminsUploadOfTheSameNameStoresExactlyItsOwnBytes(): void
    {
        $name = $this->uniqueName();
        $cancelled = $this->bytes();
        $new = $this->bytes();

        $this->send($this->ws, $name, substr($cancelled, 0, 40), 0, 2);
        $this->cancel($this->ws, array($name));

        $id = $this->send($this->otherAdmin, $name, $new, 0, 0);

        $this->assertSame(md5($new), md5_file($this->fileOf($id)));
    }

    /** [ST] The other admin's upload does not wait for a cancel: chunks sent and left behind do not leak. */
    public function testChunksLeftByAnotherAdminDoNotLeakWithoutACancel(): void
    {
        $name = $this->uniqueName();
        $left = $this->bytes();
        $new = $this->bytes();
        [$first, $second] = $this->halves($new);

        $this->send($this->ws, $name, $left, 0, 2);

        $this->send($this->otherAdmin, $name, $first, 0, 2);
        $id = $this->send($this->otherAdmin, $name, $second, 1, 2);

        $this->assertSame(md5($new), md5_file($this->fileOf($id)));
    }

    /** [ST] Two admins sending the same name chunk by chunk, interleaved, each store their own bytes. */
    public function testInterleavedChunksOfTwoAdminsStaySeparate(): void
    {
        $name = $this->uniqueName();
        $mine = $this->bytes();
        $theirs = $this->bytes();
        [$mineFirst, $mineSecond] = $this->halves($mine);
        [$theirsFirst, $theirsSecond] = $this->halves($theirs);

        $this->send($this->ws, $name, $mineFirst, 0, 2);
        $this->send($this->otherAdmin, $name, $theirsFirst, 0, 2);
        $myId = $this->send($this->ws, $name, $mineSecond, 1, 2);
        $theirId = $this->send($this->otherAdmin, $name, $theirsSecond, 1, 2);

        $this->assertSame(md5($mine), md5_file($this->fileOf($myId)));
        $this->assertSame(md5($theirs), md5_file($this->fileOf($theirId)));
    }

    /** [ST] Chunk 0 of the same user starts the buffer again. */
    public function testChunkZeroReplacesAStalePartOfTheSameUser(): void
    {
        $name = $this->uniqueName();
        $new = $this->bytes();
        [$first, $second] = $this->halves($new);

        $this->send($this->ws, $name, $this->bytes(), 0, 2);
        $this->send($this->ws, $name, $first, 0, 2);
        $id = $this->send($this->ws, $name, $second, 1, 2);

        $this->assertSame(md5($new), md5_file($this->fileOf($id)));
    }

    /** [BVA] A .part older than one day goes on the next upload; one younger stays. */
    public function testOnlyAPartOlderThanOneDayIsRemoved(): void
    {
        $dir = PIWIGO_ROOT . 'upload/buffer/';
        $old = $dir . 'provenance-old-' . bin2hex(random_bytes(4)) . '.part';
        $young = $dir . 'provenance-young-' . bin2hex(random_bytes(4)) . '.part';
        $this->scratch[] = $old;
        $this->scratch[] = $young;
        file_put_contents($old, 'old');
        file_put_contents($young, 'young');
        touch($old, time() - self::DAY - 60);
        touch($young, time() - self::DAY + 3600);
        $this->assertFileExists($old);
        $this->assertFileExists($young);

        $this->send($this->ws, $this->uniqueName(), $this->bytes(), 0, 0);

        $this->assertFileDoesNotExist($old, 'a .part older than a day must go');
        $this->assertFileExists($young, 'a .part younger than a day must stay');
    }

    /** [HAPPY] Abbrechen removes the chunks sent and creates no photo. */
    public function testCancelRemovesTheSentChunks(): void
    {
        $name = $this->uniqueName();
        $bytes = $this->bytes();
        $this->send($this->ws, $name, $bytes, 0, 2);
        $this->assertCount(1, $this->newParts(), 'precondition: chunk 0 left one .part');

        $res = $this->cancel($this->ws, array($name));

        $this->assertSame(1, $res['removed_parts']);
        $this->assertSame(array(), $this->newParts(), 'no .part may remain after Abbrechen');
        $this->assertSame(
            0,
            (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE file = '" . $this->db->escape($name) . "'"),
            'a cancelled chunked upload must create no photo'
        );
    }

    /** [NEG] Abbrechen leaves another admin's chunks of the same file name alone. */
    public function testCancelLeavesAnotherAdminsChunks(): void
    {
        $name = $this->uniqueName();
        $this->send($this->otherAdmin, $name, $this->bytes(), 0, 2);
        $this->assertCount(1, $this->newParts());

        $res = $this->cancel($this->ws, array($name));

        $this->assertSame(0, $res['removed_parts']);
        $this->assertCount(1, $this->newParts(), "the other admin's .part must stay");
    }

    /** [HAPPY] A photo of a cancelled file still waiting in the lounge is removed with its file; another stays. */
    public function testCancelRemovesAHalfCreatedPhotoFromTheLounge(): void
    {
        $this->requireLounge();
        $name = $this->uniqueName();
        $kept = $this->uniqueName();

        $gone = $this->send($this->ws, $name, $this->bytes(), 0, 0);
        $stays = $this->send($this->ws, $kept, $this->bytes(), 0, 0);
        $file = $this->fileOf($gone);
        $this->assertFileExists($file);
        $this->assertSame(
            2,
            (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_lounge WHERE image_id IN ($gone, $stays)"),
            'precondition: both photos wait in the lounge'
        );

        $res = $this->cancel($this->ws, array($name));

        $this->assertSame(1, $res['removed_photos']);
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = $gone"));
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_lounge WHERE image_id = $gone"));
        $this->assertFileDoesNotExist($file);
        $this->assertSame(1, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = $stays"));
    }

    /** [NEG] Another admin's lounge photo of the same file name is not touched. */
    public function testCancelLeavesAnotherAdminsLoungePhoto(): void
    {
        $this->requireLounge();
        $name = $this->uniqueName();
        $theirs = $this->send($this->otherAdmin, $name, $this->bytes(), 0, 0);

        $res = $this->cancel($this->ws, array($name));

        $this->assertSame(0, $res['removed_photos']);
        $this->assertSame(1, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = $theirs"));
    }

    /** [NEG] A photo of the same file name that already left the lounge is a real photo and stays. */
    public function testCancelLeavesAPhotoThatAlreadyLeftTheLounge(): void
    {
        $name = $this->uniqueName();
        $older = $this->send($this->ws, $name, $this->bytes(), 0, 0);
        $this->db->query("DELETE FROM piwigo_lounge WHERE image_id = $older");
        $file = $this->fileOf($older);
        $this->assertFileExists($file);

        $res = $this->cancel($this->ws, array($name));

        $this->assertSame(0, $res['removed_photos']);
        $this->assertSame(1, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = $older"));
        $this->assertFileExists($file);
    }

    /** [NEG] A file name is never a path: a traversal name removes nothing outside the caller's own .part. */
    public function testCancelWithATraversalNameTouchesNoFile(): void
    {
        $decoy = PIWIGO_ROOT . 'upload/buffer/provenance-decoy-' . bin2hex(random_bytes(4)) . '.part';
        $this->scratch[] = $decoy;
        file_put_contents($decoy, 'decoy');
        $this->assertFileExists($decoy);

        $res = $this->cancel($this->ws, array('../buffer/' . basename($decoy, '.part'), '../../index.php', "x' OR '1'='1"));

        $this->assertSame(0, $res['removed_parts']);
        $this->assertSame(0, $res['removed_photos']);
        $this->assertFileExists($decoy);
        $this->assertFileExists(PIWIGO_ROOT . 'index.php');
    }

    /** [NEG] Without a valid token nothing is removed. */
    public function testCancelNeedsAToken(): void
    {
        $name = $this->uniqueName();
        $this->send($this->ws, $name, $this->bytes(), 0, 2);

        $res = $this->ws->call('pwg.images.uploadCancel', array(
            'name' => array($name),
            'category_id' => $this->albumId,
            'pwg_token' => 'wrong',
        ));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertCount(1, $this->newParts());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function requireLounge(): void
    {
        if ((string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'lounge_active'") !== 'true')
        {
            $this->markTestSkipped('the lounge is off on this install, so an upload never waits there');
        }
    }

    /** @return string[] the .part files this test added to the buffer */
    private function newParts(): array
    {
        return array_values(array_diff(glob(PIWIGO_ROOT . 'upload/buffer/*.part'), $this->partsBefore));
    }

    private function bytes(): string
    {
        $bytes = (string)file_get_contents($this->fixture->createTestImage()['file']);
        $this->assertGreaterThan(100, strlen($bytes), 'anti-vacuity: the file to upload must carry bytes');

        return $bytes . random_bytes(16);
    }

    /** @return string[] the bytes cut in two */
    private function halves(string $bytes): array
    {
        $cut = intdiv(strlen($bytes), 2);

        return array(substr($bytes, 0, $cut), substr($bytes, $cut));
    }

    private function uniqueName(): string
    {
        return 'provenance-iso-' . bin2hex(random_bytes(6)) . '.png';
    }

    /** @return int the photo id the request created, 0 when it created none */
    private function send(WsClient $ws, string $name, string $bytes, int $chunk, int $chunks): int
    {
        $file = tempnam(sys_get_temp_dir(), 'provenance_chunk_');
        $this->scratch[] = $file;
        file_put_contents($file, $bytes);

        $params = array('name' => $name, 'category' => $this->albumId, 'pwg_token' => $ws->token());
        if ($chunks > 0)
        {
            $params['chunk'] = $chunk;
            $params['chunks'] = $chunks;
        }
        $res = $ws->upload('pwg.images.upload', $file, 'file', $params);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $id = (int)($res['json']['result']['image_id'] ?? 0);
        if ($chunks === 0 or $chunk === $chunks - 1)
        {
            $this->assertGreaterThan(0, $id, $res['body']);
            $this->fixture->adoptImage($id);
        }

        return $id;
    }

    private function cancel(WsClient $ws, array $names): array
    {
        $res = $ws->call('pwg.images.uploadCancel', array(
            'name' => $names,
            'category_id' => $this->albumId,
            'pwg_token' => $ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        return $res['json']['result'];
    }

    private function fileOf(int $imageId): string
    {
        return PIWIGO_ROOT . ltrim((string)$this->db->scalar("SELECT path FROM piwigo_images WHERE id = $imageId"), './');
    }
}
