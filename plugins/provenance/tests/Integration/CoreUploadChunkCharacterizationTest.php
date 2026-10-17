<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the chunk buffer of core's pwg.images.upload
 * (include/ws_functions/pwg.images.php, ws_images_upload()): where a chunk
 * lands and what a later upload of the same file name finds there.
 *
 * Every case is [ERR]: the oracle is the current implementation. Nothing
 * promises that the buffer is named after the file name alone or that chunk 0
 * appends to what is there; they record that it does today. They report a
 * change; they do not prove the behaviour right.
 *
 * They pass on their first run, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 */
final class CoreUploadChunkCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;

    /** Temp files this test wrote, removed again in teardown. */
    private array $scratch = array();

    /** The .part files that were in the buffer before the test. */
    private array $partsBefore = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->partsBefore = glob(PIWIGO_ROOT . 'upload/buffer/*.part');
        $this->fixture = new FixtureBuilder($this->db);
        $this->albumId = $this->fixture->createTestAlbum('provenance-char-chunk-' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        foreach (array_diff(glob(PIWIGO_ROOT . 'upload/buffer/*.part'), $this->partsBefore) as $part)
        {
            @unlink($part);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    /** [HAPPY] Two chunks of one file store the file's own bytes as one photo. */
    public function testTwoChunksAssembleIntoOnePhotoWithTheOriginalBytes(): void
    {
        $bytes = $this->pngBytes();
        $name = $this->uniqueName();
        [$first, $second] = $this->halves($bytes);

        $this->sendChunk($this->ws, $name, $first, 0, 2);
        $id = $this->sendChunk($this->ws, $name, $second, 1, 2);

        $this->assertGreaterThan(0, $id, 'the last chunk must create the photo');
        $this->assertSame(md5($bytes), md5_file($this->fileOf($id)), 'the stored photo must hold the original bytes');
    }

    /** [ERR] A file sent in one request ignores a stale .part of the same name. */
    public function testAnUnchunkedUploadOverwritesAStalePart(): void
    {
        $bytes = $this->pngBytes();
        $name = $this->uniqueName();
        [$first] = $this->halves($bytes);

        $this->sendChunk($this->ws, $name, $first, 0, 2);

        $id = $this->sendChunk($this->ws, $name, $bytes, 0, 0);

        $this->assertSame(md5($bytes), md5_file($this->fileOf($id)));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function pngBytes(): string
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
        return 'provenance-chunk-' . bin2hex(random_bytes(6)) . '.png';
    }

    /** @return int the photo id the request created, 0 when it created none */
    private function sendChunk(WsClient $ws, string $name, string $bytes, int $chunk, int $chunks): int
    {
        $file = tempnam(sys_get_temp_dir(), 'provenance_chunk_');
        $this->scratch[] = $file;
        file_put_contents($file, $bytes);

        $params = array(
            'name' => $name,
            'category' => $this->albumId,
            'pwg_token' => $ws->token(),
        );
        if ($chunks > 0)
        {
            $params['chunk'] = $chunk;
            $params['chunks'] = $chunks;
        }
        $res = $ws->upload('pwg.images.upload', $file, 'file', $params);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $last = $chunks === 0 || $chunk === $chunks - 1;
        $id = (int)($res['json']['result']['image_id'] ?? 0);
        if ($last)
        {
            $this->assertGreaterThan(0, $id, $res['body']);
            $this->fixture->adoptImage($id);
        }

        return $id;
    }

    private function fileOf(int $imageId): string
    {
        return PIWIGO_ROOT . ltrim((string)$this->db->scalar("SELECT path FROM piwigo_images WHERE id = $imageId"), './');
    }
}
