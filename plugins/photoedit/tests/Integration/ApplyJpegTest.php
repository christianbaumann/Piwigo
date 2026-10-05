<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoedit.apply on a JPEG: the generated 300x200 photo with its red
 * top-left marker, saved at FixtureBuilder::JPEG_QUALITY, with EXIF
 * Orientation 1 (upright) or 6 (shown a quarter turn clockwise, 200x300, the
 * marker top-right; images.rotation 3, as core derives it).
 *
 * Orientation, quality and pixels are read back with ImageMagick.
 */
final class ApplyJpegTest extends TestCase
{
    use ReadsImageFiles;

    private const METHOD = 'pwg.photoedit.apply';

    /** ImageMagick's name for EXIF Orientation 1. */
    private const UPRIGHT = 'TopLeft';

    private const DELTA = 1e-6;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private WsClient $client;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $this->client = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->client->login($username, $password);
    }

    protected function tearDown(): void
    {
        $this->fixture->restoreConfig();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyPersons(array(FixtureBuilder::REGION_KEPT['name']));
    }

    private function apply(array $params): array
    {
        return $this->client->call(self::METHOD, $params + array(
            'image_id' => $this->image['id'],
            'pwg_token' => $this->client->token(),
            ));
    }

    private function assertOk(array $res): mixed
    {
        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        return $res['json']['result'];
    }

    /**
     * [HAPPY] An Orientation-6 JPEG, cropped to the top half of what the page
     * shows, is stored upright: 200x150, Orientation 1, rotation 0, the marker
     * top-right where the page showed it.
     */
    public function testAnOrientation6JpegIsStoredUpright(): void
    {
        $this->image = $this->fixture->createMarkedJpeg(6);
        $this->assertSame('RightTop', $this->orientation(), 'anti-vacuity: the fixture is not Orientation 6');
        $this->assertSame(3, $this->image['rotation']);

        $result = $this->assertOk($this->apply(array('turns' => 0, 'crop' => '0,0,1,0.5')));

        $this->assertSame(array(200, 150), $this->identify());
        $this->assertSame(self::UPRIGHT, $this->orientation());
        $this->assertSame(array('top-right'), $this->redCorners());
        $this->assertSame(array(200, 150), array($result['width'], $result['height']));
        $this->assertTrue($result['lossy']);

        $row = $this->fixture->imageRow((int)$this->image['id']);
        $this->assertSame('0', $row['rotation']);
        $this->assertSame(array('200', '150'), array($row['width'], $row['height']));
    }

    public static function libraries(): array
    {
        return array(
            '[ECP] external ImageMagick' => array('ext_imagick'),
            '[ECP] Imagick extension' => array('imagick'),
            '[ECP] GD' => array('gd'),
            );
    }

    public static function orientationsAndTurns(): array
    {
        $cases = array(
            // upright: turns 0 needs a crop to be an edit; the left half keeps the marker
            'Orientation 1, no turn, left half' => array(1, 0, '0,0,0.5,1', array(150, 200), 'top-left'),
            'Orientation 1, a quarter turn' => array(1, 1, '', array(200, 300), 'top-right'),
            // shown 200x300 with the marker top-right
            'Orientation 6, no turn, top half' => array(6, 0, '0,0,1,0.5', array(200, 150), 'top-right'),
            'Orientation 6, a quarter turn' => array(6, 1, '', array(300, 200), 'bottom-right'),
            );

        $all = array();
        foreach (self::libraries() as $libraryName => list($library))
        {
            foreach ($cases as $caseName => $case)
            {
                $all["$libraryName, $caseName"] = array_merge(array($library), $case);
            }
        }
        return $all;
    }

    /**
     * [ECP] Orientation 1 and 6, each with and without a turn, in every
     * library: the file ends up as the page showed it, turned as asked,
     * upright, at the source's quality.
     */
    #[DataProvider('orientationsAndTurns')]
    public function testTheFileIsWhatThePageShowedTurnedAsAsked(string $library, int $orientation, int $turns, string $crop, array $size, string $corner): void
    {
        $this->fixture->setConfig('graphics_library', $library);
        $this->image = $this->fixture->createMarkedJpeg($orientation);

        $this->assertOk($this->apply(array('turns' => $turns, 'crop' => $crop)));

        $this->assertSame($size, $this->identify());
        $this->assertSame(self::UPRIGHT, $this->orientation());
        $this->assertSame(array($corner), $this->redCorners());
        $this->assertSame(FixtureBuilder::JPEG_QUALITY, $this->jpegQuality());
        $this->assertSame('0', $this->fixture->imageRow((int)$this->image['id'])['rotation']);
    }

    /** [HAPPY] The dry run of a JPEG reports the re-encode and writes nothing. */
    public function testADryRunReportsTheReencode(): void
    {
        $this->image = $this->fixture->createMarkedJpeg(1);

        $result = $this->assertOk($this->apply(array('turns' => 1, 'dry_run' => 'true')));

        $this->assertTrue($result['lossy']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    /**
     * A region on an Orientation-6 JPEG stays on the same spot of the shown
     * photo. Stored in raw coordinates: x 45..105, y 70..130 px of 300x200.
     * The page shows the raw file a quarter turn clockwise ((px, py) ->
     * (200 - py, px)): centre (100, 75), 60x60 px of 200x300. Cropped to the
     * top 270 px, the upright file is 200x270 with the box where it was.
     */
    public function testARegionStaysOnTheSpotThePageShowed(): void
    {
        $this->fixture->assertPluginActive('persons');
        $this->image = $this->fixture->createMarkedJpeg(6);
        $this->fixture->writeRegions($this->image, array(FixtureBuilder::REGION_KEPT));
        $this->assertOk($this->client->call('pwg.persons.rescan', array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->client->token(),
            )));
        $this->assertSame('3', $this->rotationAtWrite(), 'anti-vacuity: persons did not index the region on the rotated photo');

        $this->assertOk($this->apply(array('turns' => 0, 'crop' => '0,0,1,0.9')));

        $file = $this->regionsInFile();
        $this->assertSame(array(200, 270), $file['']);
        $expected = array(100 / 200, 75 / 270, 60 / 200, 60 / 270);
        foreach (array('x', 'y', 'w', 'h') as $i => $key)
        {
            $this->assertEqualsWithDelta($expected[$i], $file[FixtureBuilder::REGION_KEPT['name']][$i], self::DELTA, $key);
        }
        $this->assertSame('0', $this->rotationAtWrite());
    }

    private function rotationAtWrite(): ?string
    {
        return $this->db->scalar('SELECT rotation_at_write FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']);
    }

    /** [NEG] A type that is neither PNG nor JPEG is refused, and the page's button says why. */
    public function testAGifIsRefusedAndTheButtonSaysWhy(): void
    {
        $this->image = $this->fixture->createGif();
        $album = $this->fixture->createTestAlbum('Photoedit GIF ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $res = $this->apply(array('turns' => 1));
        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));

        $page = $this->client->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $album);
        $this->assertMatchesRegularExpression('/id="photoedit-toggle"[^>]*photoedit-disabled/', $page);
        $this->assertMatchesRegularExpression('/data-unavailable="[^"]+"/', $page);
        $this->fixture->destroyTestAlbums();
    }
}
