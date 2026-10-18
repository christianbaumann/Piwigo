<?php
use PHPUnit\Framework\TestCase;

/**
 * [ERR] What a replace through pwg.images.upload does to a photo's info text,
 * date and tags today. The oracle is the current implementation, no
 * requirement confirms it: the gallery keeps its values and the new file
 * carries none, or loses none of its own. UploadMergeTest (task 32 of change
 * 2026-10-15-exploratory-testing-answers) replaces this class and states the
 * requirement.
 */
final class UploadReplaceCharacterizationTest extends TestCase
{
    private const INFO = 'Gallery info text';
    private const TAG = 'Photoinfo Upload Char Tag';

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
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->album = $this->fixture->placeInPublicAlbum($this->image);
        $this->fixture->setComment($this->image['id'], self::INFO);
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->linkTag($this->image['id'], $this->fixture->createTag(self::TAG));

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file)
        {
            @unlink($file);
        }
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [ERR] A bare scan replaces the file: the gallery keeps its values, the file has none. */
    public function testABareReplaceLeavesTheGalleryValuesOutOfTheFile(): void
    {
        $this->replaceWith($this->bareFile());

        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame(self::INFO, $row['comment']);
        $this->assertSame('year', $row['photoinfo_date_precision']);
        $this->assertCount(1, $this->fixture->tagIdsOf($this->image['id']));

        $this->assertNull(FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
        $this->assertNull(FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
        $this->assertSame(array(), FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
    }

    /** [ERR] A replacement that carries an info text of its own does not change the gallery's. */
    public function testAReplacementsOwnInfoTextIsNotTaken(): void
    {
        $file = $this->bareFile();
        FixtureBuilder::run('exiftool -config ' . escapeshellarg(PHOTOINFO_PATH . 'exiftool/pwginfo.config')
            . ' -q -overwrite_original -XMP-pwginfo:Info=' . escapeshellarg('Text from the file') . ' ' . escapeshellarg($file));
        $this->assertSame('Text from the file', FixtureBuilder::readFileTags($file)['XMP-pwginfo:Info'], 'anti-vacuity: no info text in the replacement');

        $this->replaceWith($file);

        $this->assertSame(self::INFO, $this->fixture->imageRow($this->image['id'])['comment']);
    }

    private function bareFile(): string
    {
        $file = sys_get_temp_dir() . '/photoinfo_upload_' . bin2hex(random_bytes(6)) . '.png';
        $img = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 255, 0));
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
        $this->assertSame(md5_file($file), md5_file($this->image['file']), 'anti-vacuity: the replace did not overwrite the photo');
    }
}
