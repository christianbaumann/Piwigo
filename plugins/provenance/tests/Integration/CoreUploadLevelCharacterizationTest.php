<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the privacy level the four upload methods store
 * (pwg.images.upload, addSimple, add, uploadAsync), which task 47 changes.
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a level between the offered ones (3, 5) is
 * stored; these record that it is today. They report a change; they prove
 * nothing correct.
 *
 * They land and pass before the change, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was
 * watched go red by breaking the behaviour it claims to watch.
 *
 * `ws.php` registers `level` with `maxValue` = the highest offered level, so a
 * value above 8 never reaches a handler and 3, 5, 6 and 7 are the ones that can.
 */
final class CoreUploadLevelCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $albumId;
    private array $scratchFiles = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);
        $this->albumId = $this->fixture->createTestAlbum('provenance-char-level-' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file)
        {
            @unlink($file);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    /** @return array<string, array{string, int}> */
    public static function storedLevels(): array
    {
        $rows = array();
        foreach (array('upload', 'addSimple', 'add', 'uploadAsync') as $method)
        {
            foreach (array(0, 4, 8, 3, 5) as $level)
            {
                $rows["$method level $level"] = array($method, $level);
            }
        }

        return $rows;
    }

    /** [ERR] [ECP] A level, offered or not, is stored as sent. */
    #[DataProvider('storedLevels')]
    public function testTheLevelSentIsStored(string $method, int $level): void
    {
        $id = $this->send($method, $level);

        $this->assertGreaterThan(0, $id, "the $method call created no photo");
        $this->assertSame(
            $level,
            (int)$this->db->scalar("SELECT level FROM `piwigo_images` WHERE id = $id")
        );
    }

    /** Sends one upload of a fresh photo and hands back the new id (0 when none was created). */
    private function send(string $method, int $level): int
    {
        $file = $this->uniqueFile();
        $name = 'provenance-level-' . bin2hex(random_bytes(4)) . '.png';
        $md5 = md5_file($file);

        switch ($method)
        {
            case 'upload':
                $res = $this->ws->upload('pwg.images.upload', $file, 'file', array(
                    'name' => $name,
                    'category' => $this->albumId,
                    'level' => $level,
                    'pwg_token' => $this->ws->token(),
                    ));
                break;
            case 'addSimple':
                $res = $this->ws->upload('pwg.images.addSimple', $file, 'image', array(
                    'category' => $this->albumId,
                    'level' => $level,
                    ));
                break;
            case 'uploadAsync':
                $res = $this->ws->upload('pwg.images.uploadAsync', $file, 'file', array(
                    'chunk' => 0,
                    'chunks' => 1,
                    'chunk_sum' => $md5,
                    'original_sum' => $md5,
                    'filename' => $name,
                    'category' => $this->albumId,
                    'level' => $level,
                    ));
                break;
            case 'add':
                $chunk = $this->ws->call('pwg.images.addChunk', array(
                    'data' => base64_encode((string)file_get_contents($file)),
                    'original_sum' => $md5,
                    'type' => 'file',
                    'position' => 0,
                    ));
                $this->assertSame('ok', $chunk['json']['stat'] ?? null, $chunk['body']);
                $res = $this->ws->call('pwg.images.add', array(
                    'original_sum' => $md5,
                    'original_filename' => $name,
                    'check_uniqueness' => 'false',
                    'categories' => $this->albumId,
                    'level' => $level,
                    ));
                break;
            default:
                throw new InvalidArgumentException($method);
        }

        $result = $res['json']['result'] ?? array();
        $id = (int)($result['image_id'] ?? $result['id'] ?? 0);
        if ($id > 0)
        {
            $this->fixture->adoptImage($id);
        }

        return $id;
    }

    /** A copy of the fixture photo with random bytes after its end, so its md5 is this call's alone. */
    private function uniqueFile(): string
    {
        $source = $this->fixture->createTestImage();
        $file = tempnam(sys_get_temp_dir(), 'provenance_level_');
        $this->scratchFiles[] = $file;
        file_put_contents($file, file_get_contents($source['file']) . random_bytes(16));
        $this->assertGreaterThan(filesize($source['file']), filesize($file), 'anti-vacuity: the upload must carry bytes');

        return $file;
    }
}
