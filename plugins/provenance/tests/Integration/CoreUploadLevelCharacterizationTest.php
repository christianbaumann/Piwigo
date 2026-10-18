<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The privacy level the four upload methods store (pwg.images.upload, addSimple,
 * add, uploadAsync), task 47.
 *
 * Cases marked [REQ] are requirements: a level the install does not offer
 * (`$conf['available_permission_levels']`) is refused with 'Invalid level',
 * as pwg.images.setInfo does, and nothing is stored. The [ERR] case records that
 * an offered level is stored as sent, which is core's behaviour and unchanged.
 * Until task 47 a level between the offered ones was stored as well; that was an
 * [ERR] case recording the defect.
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
    public static function offeredLevels(): array
    {
        return self::rows(array(0, 4, 8));
    }

    /** @return array<string, array{string, int}> */
    public static function unofferedLevels(): array
    {
        return self::rows(array(3, 5));
    }

    private static function rows(array $levels): array
    {
        $rows = array();
        foreach (array('upload', 'addSimple', 'add', 'uploadAsync') as $method)
        {
            foreach ($levels as $level)
            {
                $rows["$method level $level"] = array($method, $level);
            }
        }

        return $rows;
    }

    /** [ERR] [ECP] An offered level is stored as sent. */
    #[DataProvider('offeredLevels')]
    public function testAnOfferedLevelIsStored(string $method, int $level): void
    {
        $sent = $this->send($method, $level);
        $id = $sent['id'];

        $this->assertGreaterThan(0, $id, "the $method call created no photo");
        $this->assertSame(
            $sent['md5'],
            (string)$this->db->scalar("SELECT md5sum FROM `piwigo_images` WHERE id = $id"),
            'anti-vacuity: the refusal case looks a photo up by this checksum'
        );
        $this->assertSame(
            $level,
            (int)$this->db->scalar("SELECT level FROM `piwigo_images` WHERE id = $id")
        );
    }

    /** [REQ] [ECP] A level the install does not offer is refused and nothing is stored. */
    #[DataProvider('unofferedLevels')]
    public function testAnUnofferedLevelIsRefusedAndNothingIsStored(string $method, int $level): void
    {
        $sent = $this->send($method, $level);

        $this->assertSame('fail', $sent['json']['stat'] ?? null, $sent['body']);
        $this->assertStringContainsString('Invalid level', (string)$sent['body']);
        $this->assertSame(
            0,
            (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_images` WHERE md5sum = '{$sent['md5']}'"),
            'a refused upload must create no photo row'
        );
        if ($method === 'uploadAsync')
        {
            $this->assertSame(
                array(),
                glob(PIWIGO_ROOT . 'upload/buffer/' . $sent['md5'] . '-u*'),
                'a refused chunk must leave no buffer file'
            );
        }
    }

    /** @return array{id:int,json:?array,body:string,md5:string} the new photo's id (0 when none was created) and the answer */
    private function send(string $method, int $level): array
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
                // core merges and removes the chunk only when pwg.images.add accepts the photo
                $this->scratchFiles[] = PIWIGO_ROOT . 'upload/buffer/' . $md5 . '-file-00000.block';
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

        return array('id' => $id, 'json' => $res['json'], 'body' => (string)$res['body'], 'md5' => $md5);
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
