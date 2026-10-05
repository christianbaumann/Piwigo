<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoedit.apply over HTTP, turning a generated PNG.
 *
 * What landed in the file is read back with ImageMagick - a reader that is
 * neither this plugin nor exiftool, which the pipeline itself uses to copy the
 * metadata back.
 */
final class ApplyTurnTest extends TestCase
{
    private const METHOD = 'pwg.photoedit.apply';

    /** Pixels in from each corner where the marker colour is sampled. */
    private const SAMPLE_INSET = 5;

    /** The derivative size the derivative-deletion case asks i.php for. */
    private const DERIVATIVE = 'sq';

    /** Enough bytes to be a picture page rather than an error. */
    private const MIN_PAGE_BYTES = 5000;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private int $album;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $this->image = $this->fixture->createMarkedImage();
        $this->album = $this->fixture->createTestAlbum('Photoedit apply ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$this->image['id'], $this->album);
        $this->fixture->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        $this->fixture->restoreConfig();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    private function client(string $role): WsClient
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials($role);
        $client->login($username, $password);
        return $client;
    }

    private function apply(WsClient $client, array $params): array
    {
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

    /** Refused with exactly this error code: a refusal for another reason proves nothing. */
    private function assertRefused(array $res, int $code): void
    {
        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame($code, (int)$res['json']['err'], $res['body']);
    }

    /** The file's size, as ImageMagick reads it. */
    private function identify(): array
    {
        $out = FixtureBuilder::run('identify -format "%w %h" ' . escapeshellarg($this->image['file']));
        return array_map('intval', explode(' ', trim($out)));
    }

    /** The colour of one pixel, as ImageMagick reads it, normalised to srgb(r,g,b). */
    private function pixel(int $x, int $y): string
    {
        $out = FixtureBuilder::run(sprintf(
            'convert %s -format "%%[pixel:p{%d,%d}]" info:',
            escapeshellarg($this->image['file']), $x, $y
        ));
        return trim($out);
    }

    /** Which corners are red, sampled SAMPLE_INSET pixels in from each. */
    private function redCorners(): array
    {
        list($w, $h) = $this->identify();
        $corners = array(
            'top-left' => array(self::SAMPLE_INSET, self::SAMPLE_INSET),
            'top-right' => array($w - 1 - self::SAMPLE_INSET, self::SAMPLE_INSET),
            'bottom-right' => array($w - 1 - self::SAMPLE_INSET, $h - 1 - self::SAMPLE_INSET),
            'bottom-left' => array(self::SAMPLE_INSET, $h - 1 - self::SAMPLE_INSET),
            );

        $found = array();
        foreach ($corners as $name => list($x, $y))
        {
            if (preg_match('/^s?rgba?\(255,0,0/', str_replace(' ', '', $this->pixel($x, $y))))
            {
                $found[] = $name;
            }
        }
        return $found;
    }

    /** [HAPPY] One turn swaps the file's sides and the row follows; nothing else is lost. */
    public function testAQuarterTurnIsWrittenIntoTheFileAndTheRow(): void
    {
        $this->assertSame(array(FixtureBuilder::MARKED_WIDTH, FixtureBuilder::MARKED_HEIGHT), $this->identify(),
            'anti-vacuity: the fixture is not landscape');

        $result = $this->assertOk($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => 1)));

        $this->assertSame(array(FixtureBuilder::MARKED_HEIGHT, FixtureBuilder::MARKED_WIDTH), $this->identify());
        $this->assertSame(FixtureBuilder::MARKED_HEIGHT, $result['width']);
        $this->assertSame(FixtureBuilder::MARKED_WIDTH, $result['height']);

        clearstatcache();
        $row = $this->fixture->imageRow((int)$this->image['id']);
        $this->assertSame((string)FixtureBuilder::MARKED_HEIGHT, $row['width']);
        $this->assertSame((string)FixtureBuilder::MARKED_WIDTH, $row['height']);
        $this->assertSame((string)floor(filesize($this->image['file']) / 1024), $row['filesize']);
        $this->assertSame(md5_file($this->image['file']), $row['md5sum']);
        $this->assertNotSame($this->image['md5'], $row['md5sum'], 'the file did not change');
        $this->assertSame('0', $row['rotation']);
    }

    /**
     * [HAPPY] The centre of interest turns with the photo.
     *
     * 'fakj' is l 0.2, t 0, r 0.4, b 0.36. A clockwise quarter turn maps it to
     * l = 1 - b = 0.64 ('q'), t = l = 0.2 ('f'), r = 1 - t = 1 ('z'), b = r = 0.4 ('k').
     */
    public function testTheCentreOfInterestTurns(): void
    {
        $this->assertOk($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => 1)));

        $this->assertSame('qfzk', $this->fixture->imageRow((int)$this->image['id'])['coi']);
    }

    /**
     * Core's three image libraries (admin/include/image.class.php). Each turns
     * and writes differently: ImageMagick keeps a PNG's metadata by itself, GD
     * drops all of it, so only the GD case proves the exiftool copy-back runs.
     */
    public static function libraries(): array
    {
        return array(
            '[ECP] external ImageMagick' => array('ext_imagick'),
            '[ECP] Imagick extension' => array('imagick'),
            '[ECP] GD' => array('gd'),
            );
    }

    /** [HAPPY] The XMP caption survives the re-encode, read from the raw packet. */
    #[DataProvider('libraries')]
    public function testTheCaptionIsStillInTheFile(string $library): void
    {
        $this->fixture->setConfig('graphics_library', $library);

        $this->assertOk($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => 1)));

        $xmp = FixtureBuilder::run('convert ' . escapeshellarg($this->image['file']) . ' xmp:-');
        $this->assertStringContainsString(FixtureBuilder::CAPTION, $xmp);
    }

    /** [HAPPY] The original bytes are kept under _data/photoedit/originals/. */
    public function testTheOriginalIsBackedUp(): void
    {
        $this->assertOk($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => 1)));

        $backups = glob(PIWIGO_ROOT . '_data/photoedit/originals/' . $this->image['id'] . '-*.png');
        $this->assertCount(1, $backups);
        $this->assertSame($this->image['md5'], md5_file($backups[0]));
    }

    /** [HAPPY] A derivative made before the edit is gone after it. */
    public function testOldDerivativesAreDeleted(): void
    {
        $client = $this->client(TestUsers::WEBMASTER);
        $relative = substr(ltrim($this->image['db_path'], './'), 0, -strlen('.png'));
        $client->fetchPage('/i.php?/' . $relative . '-' . self::DERIVATIVE . '.png');
        $derivatives = glob(PIWIGO_ROOT . '_data/i/' . $relative . '-*');
        $this->assertNotEmpty($derivatives, 'anti-vacuity: i.php made no derivative to delete');

        $this->assertOk($this->apply($client, array('turns' => 1)));

        clearstatcache();
        $this->assertSame(array(), glob(PIWIGO_ROOT . '_data/i/' . $relative . '-*'));
    }

    public static function turnsAndCorners(): array
    {
        $corners = array(
            'a quarter turn' => array(1, 'top-right'),
            'a half turn' => array(2, 'bottom-right'),
            'three quarters' => array(3, 'bottom-left'),
            );

        $cases = array();
        foreach (self::libraries() as $libraryName => list($library))
        {
            foreach ($corners as $turnName => list($turns, $corner))
            {
                $cases["$libraryName, $turnName"] = array($library, $turns, $corner);
            }
        }
        return $cases;
    }

    /** [ECP] The red marker in the top-left corner lands where the turn takes it, whichever library turns it. */
    #[DataProvider('turnsAndCorners')]
    public function testTheMarkerLandsInTheTurnedCorner(string $library, int $turns, string $corner): void
    {
        $this->fixture->setConfig('graphics_library', $library);
        $this->assertSame(array('top-left'), $this->redCorners(), 'anti-vacuity: the marker is not where the fixture put it');

        $this->assertOk($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => $turns)));

        $this->assertSame(array($corner), $this->redCorners());
    }

    /** The photo's entry in the photoedit_versions config row, or null. */
    private function storedVersion(): ?string
    {
        $versions = json_decode((string)$this->db->scalar(
            "SELECT value FROM piwigo_config WHERE param = 'photoedit_versions'"
        ), true);
        return is_array($versions) && isset($versions[$this->image['id']]) ? $versions[$this->image['id']] : null;
    }

    /**
     * [HAPPY] After an edit the page links the photo with its file's version,
     * so no browser reuses what it cached under the old URL.
     */
    public function testTheEditedPhotosUrlsCarryTheNewVersion(): void
    {
        $client = $this->client(TestUsers::WEBMASTER);
        $this->assertOk($this->apply($client, array('turns' => 1)));

        $version = $this->storedVersion();
        $this->assertSame(substr(md5_file($this->image['file']), 0, 12), $version);

        $page = $client->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $this->album);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $file = basename($this->image['file']);
        $this->assertStringContainsString($file . '?v=' . $version, $page);
    }

    /** [HAPPY] Deleting an edited photo drops its version, so the row does not grow forever. */
    public function testDeletingThePhotoForgetsItsVersion(): void
    {
        $client = $this->client(TestUsers::WEBMASTER);
        $this->assertOk($this->apply($client, array('turns' => 1)));
        $this->assertNotNull($this->storedVersion(), 'anti-vacuity: the edit recorded no version');

        $this->assertOk($client->call('pwg.images.delete', array(
            'image_id' => $this->image['id'],
            'pwg_token' => $client->token(),
            )));

        $this->assertNull($this->storedVersion());
    }

    /** [HAPPY] A dry run reports and writes nothing. */
    public function testADryRunChangesNothing(): void
    {
        $before = $this->fixture->imageRow((int)$this->image['id']);

        $result = $this->assertOk($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => 1, 'dry_run' => 'true')));

        $this->assertSame(array(), $result['lost_regions']);
        $this->assertFalse($result['lossy']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
        $this->assertSame($before, $this->fixture->imageRow((int)$this->image['id']));
        $this->assertSame(array(), glob(PIWIGO_ROOT . '_data/photoedit/originals/' . $this->image['id'] . '-*'));
    }

    public static function refusedCallers(): array
    {
        return array(
            // the handler's is_webmaster() check
            '[NEG] administrator, not webmaster' => array(TestUsers::ADMIN, 403),
            // core's admin_only, before the handler runs
            '[NEG] normal user' => array(TestUsers::NORMAL, 401),
            );
    }

    /** [NEG] Only a webmaster may write. */
    #[DataProvider('refusedCallers')]
    public function testOtherAccountsAreRefused(string $role, int $code): void
    {
        $this->assertRefused($this->apply($this->client($role), array('turns' => 1)), $code);

        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    /** [NEG] A GET request is refused, even from the webmaster with a valid token. */
    public function testAGetRequestIsRefused(): void
    {
        $client = $this->client(TestUsers::WEBMASTER);

        $this->assertRefused($client->callGet(self::METHOD, array(
            'image_id' => $this->image['id'],
            'turns' => 1,
            'pwg_token' => $client->token(),
            )), 405);

        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    public static function badTokens(): array
    {
        return array(
            // a required parameter: core refuses before the handler runs
            '[NEG] missing' => array(null, WS_ERR_MISSING_PARAM),
            // the handler's own check
            '[NEG] wrong' => array('0123456789abcdef0123456789abcdef', 403),
            );
    }

    /** [NEG] A missing or wrong token is refused. */
    #[DataProvider('badTokens')]
    public function testABadTokenIsRefused(?string $token, int $code): void
    {
        $client = $this->client(TestUsers::WEBMASTER);
        $params = array('image_id' => $this->image['id'], 'turns' => 1);
        if ($token !== null)
        {
            $params['pwg_token'] = $token;
        }

        $this->assertRefused($client->call(self::METHOD, $params), $code);

        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    public static function refusedTurns(): array
    {
        return array(
            '[BVA] below the range' => array('-1'),
            '[BVA] above the range' => array('4'),
            '[BVA] nothing to do' => array('0'),
            );
    }

    /** [BVA] The web service applies the request validation. */
    #[DataProvider('refusedTurns')]
    public function testTurnsOutsideTheRangeAreRefused(string $turns): void
    {
        $this->assertRefused($this->apply($this->client(TestUsers::WEBMASTER), array('turns' => $turns)), WS_ERR_INVALID_PARAM);

        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    /** [NEG] Without exiftool the write is refused and the editor says why. */
    public function testWithoutExiftoolTheEditIsRefused(): void
    {
        $this->fixture->setConfig('photoedit_exiftool_path', '/nonexistent/');
        $client = $this->client(TestUsers::WEBMASTER);

        $res = $this->apply($client, array('turns' => 1));
        $this->assertRefused($res, 500);
        $this->assertStringContainsString('exiftool', $res['json']['message']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));

        $page = $client->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $this->album);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertMatchesRegularExpression('/id="photoedit-toggle"[^>]*photoedit-disabled/', $page);
        $this->assertMatchesRegularExpression('/data-unavailable="[^"]*exiftool/', $page);
    }
}
