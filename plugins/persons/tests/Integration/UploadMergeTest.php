<?php
use PHPUnit\Framework\TestCase;

/**
 * A replace or an upload merges the new file and the gallery, region by
 * region of the field: a file that carries marks is read into the index, a
 * file that carries none receives the gallery's (design of change
 * 2026-10-15-exploratory-testing-answers, "An upload or replace merges file
 * and gallery field by field"; decision 0084).
 *
 * Over HTTP, through pwg.images.upload in update mode (Fotos hinzufügen's
 * replace) and pwg.images.addSimple (a new photo), as the webmaster.
 */
final class UploadMergeTest extends TestCase
{
    private const JANE = 'Persons Upload Merge Jane';
    private const JOHN = 'Persons Upload Merge John';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private int $album;
    private array $scratch = array();
    private array $uploadedIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        $this->image = $this->fixture->createMarkedImage();
        $this->album = $this->fixture->createTestAlbum('Persons upload merge');
        $this->fixture->attachImage((int)$this->image['id'], $this->album);
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        if (count($this->uploadedIds) > 0)
        {
            $this->ws->call('pwg.images.delete', array(
                'image_id' => implode(',', $this->uploadedIds), 'pwg_token' => $this->ws->token(),
                ));
        }
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN));
    }

    /**
     * [HAPPY] Restoring a copy made before an edit: the photo's frame and marks
     * are the copy's, not the edited file's. The index must not stay at the
     * edited frame.
     */
    public function testARestoredPreEditCopyIsIndexedFromItsFile(): void
    {
        $this->addRegion(self::JANE);
        $preEdit = $this->scratchCopy($this->image['file']);
        $this->assertSame(array(self::JANE), $this->namesInFile($preEdit), 'anti-vacuity: the copy carries no mark');

        $edited = $this->bareFile(300, 400);
        $this->fixture->writeRegionsWithExiftool(array('file' => $edited),
            array(array('name' => self::JOHN, 'x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2)), 300, 400);
        copy($edited, $this->image['file']);
        $this->db->query('UPDATE piwigo_images SET width = 300, height = 400 WHERE id = ' . (int)$this->image['id']);
        $this->rescan();
        $this->assertSame(array(self::JOHN), $this->indexedNames(), 'anti-vacuity: the edited state is not indexed');

        $this->replaceWith($preEdit);

        $this->assertSame(array(self::JANE), $this->indexedNames());
        $this->assertSame(array(600, 400), $this->appliedFrame(), 'the index stayed at the edited frame');
        $this->assertSame(array(600, 400), $this->imageSize());
    }

    /**
     * [HAPPY] A bare scan replaces a marked photo: the marks stay in the index
     * and are written into the new file, at the new file's frame; who drew
     * each is kept.
     */
    public function testABareReplaceKeepsTheMarksAndWritesThemIntoTheFile(): void
    {
        $this->addRegion(self::JANE);
        $this->addRegion(self::JOHN, array('x' => 0.2, 'y' => 0.2, 'w' => 0.1, 'h' => 0.1));
        $drawnBy = $this->indexedDrawnBy();
        $this->assertCount(2, $drawnBy, 'anti-vacuity: no marks to begin with');
        $this->assertNotContains(null, $drawnBy, 'anti-vacuity: nobody drew the marks');

        $this->replaceWith($this->bareFile(300, 200));

        $this->assertSame(array(self::JANE, self::JOHN), $this->indexedNames());
        $this->assertSame(array(self::JANE, self::JOHN), $this->namesInFile($this->image['file']));
        $this->assertSame(array(300, 200), $this->appliedFrame());
        $this->assertSame($drawnBy, $this->indexedDrawnBy());
    }

    /** [HAPPY] A new upload whose file already carries a mark is indexed without a rescan. */
    public function testAnUploadedFileWithAMarkCreatesIt(): void
    {
        $file = $this->bareFile(300, 200);
        $this->fixture->writeRegionsWithExiftool(array('file' => $file),
            array(array('name' => self::JOHN, 'x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2)), 300, 200);

        $res = $this->ws->upload('pwg.images.addSimple', $file, 'image', array(
            'category[0]' => $this->album,
            'name' => 'Persons upload merge new',
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)$res['json']['result']['image_id'];
        $this->uploadedIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $this->assertSame(array(self::JOHN), $this->indexedNames($id));
    }

    /** [HAPPY] A new upload whose file carries no mark gets none. */
    public function testAnUploadedFileWithoutAMarkGetsNone(): void
    {
        $res = $this->ws->upload('pwg.images.addSimple', $this->bareFile(300, 200), 'image', array(
            'category[0]' => $this->album,
            'name' => 'Persons upload merge bare',
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)$res['json']['result']['image_id'];
        $this->uploadedIds[] = $id;
        $this->assertGreaterThan(0, $id, 'anti-vacuity: no photo was created');

        $this->assertSame(array(), $this->indexedNames($id));
    }

    private function addRegion(string $name, ?array $box = null): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name, 'pwg_token' => $this->ws->token(),
            ) + ($box ?? array('x' => 0.5, 'y' => 0.4, 'w' => 0.2, 'h' => 0.3)));
        $this->assertSame('ok', $res['json']['stat'], $res['body']);
    }

    private function rescan(): void
    {
        $res = $this->ws->call('pwg.persons.rescan', array(
            'image_ids' => (string)$this->image['id'], 'pwg_token' => $this->ws->token(),
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

    private function scratchCopy(string $file): string
    {
        $copy = sys_get_temp_dir() . '/persons_upload_' . bin2hex(random_bytes(6)) . '.png';
        copy($file, $copy);
        $this->scratch[] = $copy;
        return $copy;
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
        // The merge may write marks into the new file, so its bytes can differ from the upload's.
        $this->assertSame(getimagesize($file)[0], getimagesize($this->image['file'])[0], 'anti-vacuity: the replace did not overwrite the photo');
    }

    /** @return string[] the names indexed for a photo, by region id */
    private function indexedNames(?int $imageId = null): array
    {
        $names = array();
        $result = $this->db->query('SELECT p.name FROM piwigo_person_region r JOIN piwigo_persons p ON p.id = r.person_id'
            . ' WHERE r.image_id = ' . ($imageId ?? (int)$this->image['id']) . ' ORDER BY r.id');
        while ($row = $result->fetch_assoc())
        {
            $names[] = $row['name'];
        }
        return $names;
    }

    private function indexedDrawnBy(): array
    {
        $drawn = array();
        $result = $this->db->query('SELECT drawn_by FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id'] . ' ORDER BY id');
        while ($row = $result->fetch_assoc())
        {
            $drawn[] = $row['drawn_by'] === null ? null : (int)$row['drawn_by'];
        }
        return $drawn;
    }

    /** @return int[] the frame the index says its marks were drawn against */
    private function appliedFrame(): array
    {
        $row = $this->db->query('SELECT DISTINCT applied_w, applied_h FROM piwigo_person_region WHERE image_id = '
            . (int)$this->image['id'])->fetch_assoc();
        $this->assertNotNull($row, 'anti-vacuity: the index holds no mark');
        return array((int)$row['applied_w'], (int)$row['applied_h']);
    }

    private function imageSize(): array
    {
        $row = $this->db->query('SELECT width, height FROM piwigo_images WHERE id = ' . (int)$this->image['id'])->fetch_assoc();
        return array((int)$row['width'], (int)$row['height']);
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
