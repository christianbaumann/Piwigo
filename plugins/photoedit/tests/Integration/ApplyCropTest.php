<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoedit.apply over HTTP, cropping the generated 300x200 PNG with its
 * red 30 px marker in the top-left corner.
 *
 * The validation and pixel math are the unit suite's (CropTest); this covers
 * what reaches the file and the row, read back with ImageMagick.
 */
final class ApplyCropTest extends TestCase
{
    use ReadsImageFiles;

    private const METHOD = 'pwg.photoedit.apply';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private ?string $expectedFile = null;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $this->image = $this->fixture->createMarkedImage();
    }

    protected function tearDown(): void
    {
        if ($this->expectedFile !== null && is_file($this->expectedFile))
        {
            unlink($this->expectedFile);
        }
        $this->fixture->restoreConfig();
        $this->fixture->destroyTestImages();
    }

    private function apply(array $params): array
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $client->login($username, $password);

        return $client->call(self::METHOD, $params + array(
            'image_id' => $this->image['id'],
            'pwg_token' => $client->token(),
            ));
    }

    private function assertOk(array $res): mixed
    {
        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        return $res['json']['result'];
    }

    public static function libraries(): array
    {
        return array(
            '[ECP] external ImageMagick' => array('ext_imagick'),
            '[ECP] Imagick extension' => array('imagick'),
            '[ECP] GD' => array('gd'),
            );
    }

    /**
     * [HAPPY] Cropping to the left half halves the file's width and keeps the
     * marker in its corner. The page geometry catches a crop that only moved
     * the visible window over a canvas still the old size (Imagick keeps one
     * unless told otherwise).
     */
    #[DataProvider('libraries')]
    public function testTheLeftHalfIsWrittenIntoTheFileAndTheRow(string $library): void
    {
        $this->fixture->setConfig('graphics_library', $library);
        $this->assertSame(array('top-left'), $this->redCorners(), 'anti-vacuity: the marker is not where the fixture put it');

        $result = $this->assertOk($this->apply(array('turns' => 0, 'crop' => '0,0,0.5,1')));

        $half = FixtureBuilder::MARKED_WIDTH / 2;
        $this->assertSame(array($half, FixtureBuilder::MARKED_HEIGHT), $this->identify());
        $this->assertSame($half . 'x' . FixtureBuilder::MARKED_HEIGHT . '+0+0', $this->pageGeometry());
        $this->assertSame(array('top-left'), $this->redCorners());
        $this->assertSame($half, $result['width']);
        $this->assertSame(FixtureBuilder::MARKED_HEIGHT, $result['height']);

        clearstatcache();
        $row = $this->fixture->imageRow((int)$this->image['id']);
        $this->assertSame((string)$half, $row['width']);
        $this->assertSame((string)FixtureBuilder::MARKED_HEIGHT, $row['height']);
        $this->assertSame((string)floor(filesize($this->image['file']) / 1024), $row['filesize']);
        $this->assertSame(md5_file($this->image['file']), $row['md5sum']);
    }

    public static function turnsAndCrops(): array
    {
        // The crop is drawn on the turned view. After one turn the 300x200
        // file is a 200x300 view with the marker top-right; the frame is that
        // view's top-right quarter, so the marker stays in the cut.
        $cases = array(
            'no turn, the bottom-right part' => array(0, '0.4,0.25,1,1', '180x150+120+50'),
            'a quarter turn, the top-right part' => array(1, '0.5,0,1,0.5', '100x150+100+0'),
            'three quarters, a middle band' => array(3, '0,0.2,1,0.7', '200x150+0+60'),
            );

        $all = array();
        foreach (self::libraries() as $libraryName => list($library))
        {
            foreach ($cases as $caseName => $case)
            {
                $all["[DT] $libraryName, $caseName"] = array_merge(array($library), $case);
            }
        }
        return $all;
    }

    /**
     * [DT] Turn x crop: the file holds exactly the pixels ImageMagick produces
     * turning the original and then cropping it by hand.
     */
    #[DataProvider('turnsAndCrops')]
    public function testTheFileHoldsTheTurnedThenCroppedPixels(string $library, int $turns, string $crop, string $geometry): void
    {
        $this->fixture->setConfig('graphics_library', $library);
        $this->expectedFile = sys_get_temp_dir() . '/photoedit-expected-' . bin2hex(random_bytes(4)) . '.png';
        FixtureBuilder::run(sprintf(
            'convert %s -rotate %d -crop %s +repage %s',
            escapeshellarg($this->image['file']), $turns * 90, $geometry, escapeshellarg('PNG24:' . $this->expectedFile)
        ));

        $this->assertOk($this->apply(array('turns' => $turns, 'crop' => $crop)));

        // compare exits 0 only for identical images (1: different, 2: error)
        // and prints the number of differing pixels.
        $output = array();
        $status = -1;
        exec(sprintf(
            'compare -metric AE %s %s null: 2>&1',
            escapeshellarg($this->image['file']), escapeshellarg($this->expectedFile)
        ), $output, $status);
        $this->assertSame(0, $status, 'pixels differing from turn-then-crop by hand: ' . implode(' ', $output));
    }

    public static function cois(): array
    {
        // 'fakj' is l 0.2, t 0, r 0.4, b 0.36: x 60..120, y 0..72
        return array(
            '[HAPPY] inside the crop it moves' => array('0,0,0.5,1', 'kauj'),
            '[ECP] outside the crop it is dropped' => array('0.5,0,1,1', null),
            );
    }

    /** The centre of interest is cut to the crop with the same math as the file. */
    #[DataProvider('cois')]
    public function testTheCentreOfInterestFollowsTheCrop(string $crop, ?string $expected): void
    {
        $this->assertSame(FixtureBuilder::COI, $this->fixture->imageRow((int)$this->image['id'])['coi'],
            'anti-vacuity: the fixture has no centre of interest');

        $this->assertOk($this->apply(array('turns' => 0, 'crop' => $crop)));

        $this->assertSame($expected, $this->fixture->imageRow((int)$this->image['id'])['coi']);
    }

    public static function refusedCrops(): array
    {
        return array(
            '[BVA] the whole photo and no turn is nothing to do' => array('0,0,1,1', 'nothing to do'),
            '[BVA] one pixel wide' => array('0,0,' . (1 / 300) . ',1', (string)PHOTOEDIT_MIN_CROP_PX),
            '[BVA] one below the minimum' => array('0,0,' . ((PHOTOEDIT_MIN_CROP_PX - 1) / 300) . ',1', (string)PHOTOEDIT_MIN_CROP_PX),
            '[BVA] l >= r' => array('0.5,0,0.5,1', 'crop'),
            '[BVA] outside 0..1' => array('0,0,1.01,1', 'crop'),
            );
    }

    /** [BVA] Refused crops change nothing. */
    #[DataProvider('refusedCrops')]
    public function testARefusedCropChangesNothing(string $crop, string $message): void
    {
        $before = $this->fixture->imageRow((int)$this->image['id']);

        $res = $this->apply(array('turns' => 0, 'crop' => $crop));

        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
        $this->assertStringContainsString($message, $res['json']['message']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
        $this->assertSame($before, $this->fixture->imageRow((int)$this->image['id']));
    }

    /** [HAPPY] A dry run with a crop reports and writes nothing. */
    public function testADryRunWithACropChangesNothing(): void
    {
        $this->assertOk($this->apply(array('turns' => 0, 'crop' => '0,0,0.5,1', 'dry_run' => 'true')));

        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }
}
