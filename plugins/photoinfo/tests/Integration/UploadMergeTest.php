<?php
use PHPUnit\Framework\TestCase;

/**
 * A replace or an upload merges the new file and the gallery field by field:
 * a field the file carries wins and is read into the photo, a field it lacks
 * receives the gallery's value (design of change
 * 2026-10-15-exploratory-testing-answers, "An upload or replace merges file
 * and gallery field by field"; decision 0084).
 *
 * Over HTTP, through pwg.images.upload in update mode and pwg.images.addSimple,
 * as the webmaster; the file is read back with a plain exiftool call.
 */
final class UploadMergeTest extends TestCase
{
    private const GALLERY_INFO = 'Info text of the gallery';
    private const FILE_INFO = 'Info text of the file';
    private const GALLERY_TAG = 'Photoinfo Upload Merge Gallery Tag';
    private const FILE_TAG = 'Photoinfo Upload Merge File Tag';
    private const PERSON = 'Photoinfo Upload Merge Person';
    private const GALLERY_EDTF = '1965';
    private const FILE_EDTF = '1971-05';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private int $album;
    private int $galleryTag;
    private array $scratch = array();
    private array $uploadedIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->album = $this->fixture->placeInPublicAlbum($this->image);
        $this->fixture->setComment($this->image['id'], self::GALLERY_INFO);
        $this->fixture->setDate($this->image['id'], self::GALLERY_EDTF . '-01-01 00:00:00', 'year');
        $this->galleryTag = $this->fixture->createTag(self::GALLERY_TAG);
        $this->fixture->linkTag($this->image['id'], $this->galleryTag);

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        if (count($this->uploadedIds) > 0)
        {
            $this->ws->call('pwg.images.delete', array(
                'image_id' => implode(',', $this->uploadedIds), 'pwg_token' => $this->ws->token(),
                ));
        }
        $this->db->query("DELETE FROM piwigo_config WHERE param = 'provenance_exiftool_path'");
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        $this->fixture->trackTagsNamed(self::FILE_TAG);
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] A bare scan replaces the photo: info text, date and tags are written into the new file, and the gallery keeps them. */
    public function testABareReplaceWritesTheGalleryValuesIntoTheFile(): void
    {
        $this->replaceWith($this->bareFile());

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        $this->assertSame(self::GALLERY_INFO, $tags['XMP-pwginfo:Info']);
        $this->assertSame(self::GALLERY_EDTF, FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
        $keywords = FixtureBuilder::readKeywords($this->image['file']);
        $this->assertSame(array(self::GALLERY_TAG), $keywords['XMP-dc:Subject']);
        $this->assertSame('1', $keywords['XMP-pwginfo:TagsWritten']);

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame(self::GALLERY_INFO, $row['comment']);
        $this->assertSame('year', $row['photoinfo_date_precision']);
        $this->assertSame(array($this->galleryTag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [ST] A marked person survives a bare replace as a region and as a keyword in the new file (persons runs first). */
    public function testAPersonMarkedInTheGalleryReachesTheNewFileAsRegionAndKeyword(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->fixture->trackPerson(self::PERSON);
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => self::PERSON, 'pwg_token' => $this->ws->token(),
            'x' => 0.5, 'y' => 0.4, 'w' => 0.2, 'h' => 0.3,
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $this->replaceWith($this->bareFile());

        $this->assertSame(self::PERSON, trim(FixtureBuilder::run('exiftool -s3 -XMP-mwg-rs:RegionName ' . escapeshellarg($this->image['file']))));
        $this->assertContains(self::PERSON, FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
        $this->assertContains(self::GALLERY_TAG, FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
    }

    /** [NEG] A replacement that carries a keyword marker but not the person's keyword must not take the marked person's tag off the photo (task 18's guard). */
    public function testAMarkedKeywordFileDoesNotTakeAMarkedPersonsTagOff(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->fixture->trackPerson(self::PERSON);
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => self::PERSON, 'pwg_token' => $this->ws->token(),
            'x' => 0.5, 'y' => 0.4, 'w' => 0.2, 'h' => 0.3,
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $personTags = array_values(array_diff($this->fixture->tagIdsOf($this->image['id']), array($this->galleryTag)));
        $this->assertCount(1, $personTags, 'anti-vacuity: the mark gave the photo no person tag');

        $file = $this->bareFile();
        FixtureBuilder::writeKeywords($file, array(self::FILE_TAG), array(), true);
        $this->replaceWith($file);

        $this->assertContains($personTags[0], $this->fixture->tagIdsOf($this->image['id']),
            'the person is still marked on the photo, but its tag was taken off');
        $this->assertContains(self::PERSON, FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
        $this->assertContains(self::FILE_TAG, FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
    }

    /** [ERR] Without exiftool the upload stands, the gallery keeps its values and the new file is not half-written. */
    public function testTheUploadStandsWhenExiftoolIsMissing(): void
    {
        $this->db->query("INSERT INTO piwigo_config (param, value) VALUES ('provenance_exiftool_path', '/nonexistent-photoinfo-test/')");
        $this->assertSame('/nonexistent-photoinfo-test/', $this->db->scalar(
            "SELECT value FROM piwigo_config WHERE param = 'provenance_exiftool_path'"), 'the config row was not written');
        $file = $this->bareFile();
        $sent = md5_file($file);

        $this->replaceWith($file);

        $this->assertSame($sent, md5_file($this->image['file']), 'the stored file is not the uploaded one');
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame(self::GALLERY_INFO, $row['comment']);
        $this->assertSame(array($this->galleryTag), $this->fixture->tagIdsOf($this->image['id']));
    }

    /** [HAPPY] A file that carries info text, date and keywords wins over the gallery's values. */
    public function testAFileThatCarriesAllThreeWins(): void
    {
        $file = $this->bareFile();
        $this->writeInfo($file, self::FILE_INFO);
        FixtureBuilder::writeFileDate($file, self::FILE_EDTF, null);
        FixtureBuilder::writeKeywords($file, array(self::FILE_TAG), array(), true);

        $this->replaceWith($file);

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame(self::FILE_INFO, $row['comment']);
        $this->assertSame('month', $row['photoinfo_date_precision']);
        $this->assertStringStartsWith('1971-05', $row['date_creation']);
        $this->fixture->trackTagsNamed(self::FILE_TAG);
        $this->assertSame(array($this->fixture->tagIdNamed(self::FILE_TAG)), $this->fixture->tagIdsOf($this->image['id']),
            'the gallery tag stayed, or the file tag is missing');
        $this->assertSame(self::FILE_INFO, FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
    }

    /** [DT] A file that carries only an info text: the text is the file's, date and tags come from the gallery. */
    public function testAFileWithOnlyAnInfoTextKeepsTheGalleryDateAndTags(): void
    {
        $file = $this->bareFile();
        $this->writeInfo($file, self::FILE_INFO);

        $this->replaceWith($file);

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame(self::FILE_INFO, $row['comment']);
        $this->assertSame('year', $row['photoinfo_date_precision']);
        $this->assertSame(array($this->galleryTag), $this->fixture->tagIdsOf($this->image['id']));

        $this->assertSame(self::FILE_INFO, FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
        $this->assertSame(self::GALLERY_EDTF, FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
        $this->assertSame(array(self::GALLERY_TAG), FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
    }

    /** [DT] A file that carries only a date: the date is the file's, info text and tags come from the gallery. */
    public function testAFileWithOnlyADateKeepsTheGalleryInfoAndTags(): void
    {
        $file = $this->bareFile();
        FixtureBuilder::writeFileDate($file, self::FILE_EDTF, null);

        $this->replaceWith($file);

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame(self::GALLERY_INFO, $row['comment']);
        $this->assertSame('month', $row['photoinfo_date_precision']);
        $this->assertSame(array($this->galleryTag), $this->fixture->tagIdsOf($this->image['id']));

        $this->assertSame(self::GALLERY_INFO, FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
        $this->assertSame(self::FILE_EDTF, FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
    }

    /** [HAPPY] A new upload that carries info text and a date has them in its row. */
    public function testAnUploadedFilesInfoTextAndDateReachTheRow(): void
    {
        $file = $this->bareFile();
        $this->writeInfo($file, self::FILE_INFO);
        FixtureBuilder::writeFileDate($file, self::FILE_EDTF, null);

        $id = $this->addSimple($file);

        $row = $this->fixture->imageRow($id);
        $this->assertSame(self::FILE_INFO, $row['comment']);
        $this->assertSame('month', $row['photoinfo_date_precision']);
    }

    /** [NEG] A new upload with nothing in its file and nothing in the gallery gets no photoinfo tags written. */
    public function testABareNewUploadIsLeftAlone(): void
    {
        $file = $this->bareFile();
        $id = $this->addSimple($file);

        $row = $this->fixture->imageRow($id);
        $this->assertNotSame('', trim((string)$row['path']), 'anti-vacuity: no photo path');
        $stored = PIWIGO_ROOT . ltrim($row['path'], './');
        $this->assertFileExists($stored);
        $this->assertNull(FixtureBuilder::readFileTags($stored)['XMP-pwginfo:Info']);
        $this->assertNull(FixtureBuilder::readKeywords($stored)['XMP-pwginfo:TagsWritten']);
    }

    private function writeInfo(string $file, string $info): void
    {
        FixtureBuilder::run('exiftool -config ' . escapeshellarg(PHOTOINFO_PATH . 'exiftool/pwginfo.config')
            . ' -q -overwrite_original ' . escapeshellarg('-XMP-pwginfo:Info=' . $info) . ' ' . escapeshellarg($file));
        $this->assertSame($info, FixtureBuilder::readFileTags($file)['XMP-pwginfo:Info'], 'anti-vacuity: no info text in the file');
    }

    private function bareFile(): string
    {
        $file = sys_get_temp_dir() . '/photoinfo_upload_' . bin2hex(random_bytes(6)) . '.png';
        $img = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 255, random_int(0, 255)));
        imagepng($img, $file);
        $this->scratch[] = $file;
        return $file;
    }

    private function replaceWith(string $file): void
    {
        $res = $this->ws->upload('pwg.images.upload', array(
            'name' => basename($this->image['file']),
            'category' => $this->album,
            'update_mode' => 'true',
            'chunk' => 0,
            'chunks' => 1,
            'pwg_token' => $this->ws->token(),
            ), 'file', $file);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        clearstatcache();
        $this->assertGreaterThan(0, filesize($this->image['file']));
    }

    private function addSimple(string $file): int
    {
        $res = $this->ws->upload('pwg.images.addSimple', array(
            'category[0]' => $this->album,
            'name' => 'Photoinfo upload merge ' . bin2hex(random_bytes(3)),
            ), 'image', $file);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)$res['json']['result']['image_id'];
        $this->assertGreaterThan(0, $id, 'anti-vacuity: no photo was created');
        $this->uploadedIds[] = $id;
        return $id;
    }
}
