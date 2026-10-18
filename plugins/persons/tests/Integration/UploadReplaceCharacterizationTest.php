<?php
use PHPUnit\Framework\TestCase;

/**
 * [ERR] What a replace through pwg.images.upload does to the person index
 * today. The oracle is the current implementation, no requirement confirms it:
 * the index is left as it was, whatever the new file holds. UploadMergeTest
 * (task 32 of change 2026-10-15-exploratory-testing-answers) replaces this
 * class and states the requirement.
 */
final class UploadReplaceCharacterizationTest extends TestCase
{
    private const JANE = 'Persons Upload Char Jane';
    private const JOHN = 'Persons Upload Char John';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private int $album;
    private array $scratch = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        $this->image = $this->fixture->createMarkedImage();
        $this->album = $this->fixture->createTestAlbum('Persons upload characterization');
        $this->fixture->attachImage((int)$this->image['id'], $this->album);
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN));
    }

    /** [ERR] A bare scan replaces the file: the index keeps the mark, the file has none. */
    public function testABareReplaceLeavesTheIndexAndEmptiesTheFile(): void
    {
        $this->addRegion(self::JANE);
        $this->assertSame(1, $this->regionCount(), 'anti-vacuity: no mark to begin with');

        $this->replaceWith($this->bareFile(300, 200));

        $this->assertSame(1, $this->regionCount());
        $this->assertSame(array(), $this->namesInFile($this->image['file']));
    }

    /** [ERR] A replacement that carries a mark is not read: the index keeps what it had. */
    public function testAReplacementsOwnMarkIsNotIndexed(): void
    {
        $this->addRegion(self::JANE);
        $other = $this->bareFile(300, 200);
        $this->fixture->writeRegionsWithExiftool(array('file' => $other),
            array(array('name' => self::JOHN, 'x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2)), 300, 200);
        $this->assertSame(array(self::JOHN), $this->namesInFile($other), 'anti-vacuity: the replacement carries no mark');

        $this->replaceWith($other);

        $this->assertSame(array(self::JANE), $this->indexedNames());
    }

    private function addRegion(string $name): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name, 'pwg_token' => $this->ws->token(),
            'x' => 0.5, 'y' => 0.4, 'w' => 0.2, 'h' => 0.3,
            ));
        $this->assertSame('ok', $res['json']['stat'], $res['body']);
    }

    private function bareFile(int $w, int $h): string
    {
        $file = sys_get_temp_dir() . '/persons_upload_' . bin2hex(random_bytes(6)) . '.png';
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 255, 0));
        imagepng($img, $file);
        $this->scratch[] = $file;
        return $file;
    }

    private function replaceWith(string $file): void
    {
        $res = $this->ws->upload('pwg.images.upload', $file, 'file', array(
            'name' => basename($this->image['file']),
            'category' => $this->album,
            'update_mode' => 'true',
            'chunk' => 0,
            'chunks' => 1,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        clearstatcache();
        $this->assertSame(md5_file($file), md5_file($this->image['file']), 'anti-vacuity: the replace did not overwrite the photo');
    }

    private function regionCount(): int
    {
        return (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']);
    }

    private function indexedNames(): array
    {
        $names = array();
        $result = $this->db->query('SELECT p.name FROM piwigo_person_region r JOIN piwigo_persons p ON p.id = r.person_id'
            . ' WHERE r.image_id = ' . (int)$this->image['id'] . ' ORDER BY r.id');
        while ($row = $result->fetch_assoc())
        {
            $names[] = $row['name'];
        }
        return $names;
    }

    private function namesInFile(string $file): array
    {
        $out = array();
        exec('exiftool -s3 -XMP-mwg-rs:RegionName ' . escapeshellarg($file) . ' 2>&1', $out);
        $names = array_filter(array_map('trim', explode(',', implode(',', $out))));
        sort($names);
        return array_values($names);
    }
}
