<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoinfo.setInfo across its real boundaries: ws.php, MariaDB and the
 * image file, read back with a plain exiftool call.
 *
 * The provenance text in the caption depends on the install's language, so it
 * is never typed here: a provenance write-back puts it into the file first, and
 * the tests compare against what that wrote.
 */
final class SetInfoTest extends TestCase
{
    private const METHOD = 'pwg.photoinfo.setInfo';
    private const CAPTION_SLOTS = array('XMP-dc:Description', 'IPTC:Caption-Abstract', 'EXIF:ImageDescription');
    private const INFO = "Hochzeit von Anna und Paul\nvor der Kirche, \$5 @Oma \"links\" a\\b";

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private WsClient $ws;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        $this->ws = $this->clientAs(TestUsers::WEBMASTER);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
    }

    /** [HAPPY] The text lands in the database and in front of provenance's caption. */
    public function testTheInfoTextGoesIntoTheRowAndAheadOfTheProvenanceCaption(): void
    {
        $provenance = $this->writeProvenance(array('provenance_owner' => 'Anna Mueller'));

        $res = $this->setInfo($this->ws, self::INFO);

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $this->assertSame(self::INFO, $this->fixture->imageRow($this->image['id'])['comment']);

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        foreach (self::CAPTION_SLOTS as $slot)
        {
            $this->assertSame(self::INFO . "\n\n" . $provenance, $tags[$slot], $slot);
        }
        $this->assertSame(self::INFO, $tags['XMP-pwginfo:Info']);
    }

    /** [ECP] A photo with no provenance gets the info text alone as its caption. */
    public function testWithoutProvenanceTheCaptionIsTheInfoText(): void
    {
        $this->setInfo($this->ws, 'Nur Info');

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        foreach (self::CAPTION_SLOTS as $slot)
        {
            $this->assertSame('Nur Info', $tags[$slot], $slot);
        }
    }

    /** [ST] Clearing removes the text from the row and the file, and leaves provenance's caption. */
    public function testClearingRemovesTheTextFromRowAndFile(): void
    {
        $provenance = $this->writeProvenance(array('provenance_owner' => 'Anna Mueller'));
        $this->setInfo($this->ws, self::INFO);

        $res = $this->setInfo($this->ws, '');

        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['comment']);
        $tags = FixtureBuilder::readFileTags($this->image['file']);
        $this->assertNull($tags['XMP-pwginfo:Info']);
        foreach (self::CAPTION_SLOTS as $slot)
        {
            $this->assertSame($provenance, $tags[$slot], $slot);
        }
    }

    /** [ST] Clearing the only text a photo has leaves no caption at all. */
    public function testClearingTheOnlyTextLeavesNoCaption(): void
    {
        $this->setInfo($this->ws, 'kurz');
        $this->setInfo($this->ws, '');

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        $this->assertSame(array_fill_keys(array_keys(FixtureBuilder::FILE_TAGS), null), $tags);
    }

    /** [HAPPY] A later provenance write-back keeps the info text first. */
    public function testAProvenanceWriteBackKeepsTheInfoTextFirst(): void
    {
        $this->setInfo($this->ws, self::INFO);
        $this->fixture->setProvenance($this->image['id'], array('provenance_owner' => 'Paul Schmidt'));

        $this->assertWriteBack();

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        $this->assertStringStartsWith(self::INFO . "\n\n", $tags['XMP-dc:Description']);
        $this->assertStringContainsString('Paul Schmidt', $tags['XMP-dc:Description']);
        $this->assertSame($tags['XMP-dc:Description'], $tags['EXIF:ImageDescription']);
        $this->assertSame(self::INFO, $tags['XMP-pwginfo:Info'], 'a provenance write-back leaves the info tag alone');
    }

    /**
     * [ERR] A file that cannot be written is reported, and the text stays saved.
     * Requirement: the task's "reports a failed write without losing the saved text".
     */
    public function testAFailedWriteIsReportedAndTheTextStaysSaved(): void
    {
        chmod($this->image['file'], 0444);
        clearstatcache();
        $this->assertFalse(is_writable($this->image['file']), 'anti-vacuity: the file is still writable');

        try
        {
            $res = $this->setInfo($this->ws, 'gespeichert');
        }
        finally
        {
            chmod($this->image['file'], 0644);
        }

        $this->assertFalse($res['json']['result']['written'], $res['body']);
        $this->assertNotSame('', $res['json']['result']['message']);
        $this->assertSame('gespeichert', $this->fixture->imageRow($this->image['id'])['comment']);
        $this->assertNull(FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
    }

    /** [ECP] Markup stays in the stored text and the info tag, and leaves the caption. */
    public function testMarkupStaysOutOfTheCaption(): void
    {
        $this->setInfo($this->ws, 'Oma <b>und</b> Opa');

        $tags = FixtureBuilder::readFileTags($this->image['file']);
        $this->assertSame('Oma <b>und</b> Opa', $this->fixture->imageRow($this->image['id'])['comment']);
        $this->assertSame('Oma <b>und</b> Opa', $tags['XMP-pwginfo:Info']);
        $this->assertSame('Oma und Opa', $tags['XMP-dc:Description']);
    }

    /** [NEG] An info text that is not a string is refused and deletes nothing. */
    public function testAnArrayInfoIsRefused(): void
    {
        $this->fixture->setComment($this->image['id'], 'bleibt');

        $res = $this->ws->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'info' => array('x'),
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame(WS_ERR_INVALID_PARAM, $res['json']['err'] ?? null, $res['body']);
        $this->assertSame('bleibt', $this->fixture->imageRow($this->image['id'])['comment']);
    }

    /** [ECP] An administrator who is not the webmaster may save too. */
    public function testAnAdministratorMaySave(): void
    {
        $res = $this->setInfo($this->clientAs(TestUsers::ADMIN), 'vom Admin');

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame('vom Admin', $this->fixture->imageRow($this->image['id'])['comment']);
    }

    public static function refusedAccounts(): array
    {
        return array(
            'normal user' => array(TestUsers::NORMAL),
            'guest' => array(null),
            );
    }

    /** [NEG] A normal user and a guest are refused, and nothing changes. */
    #[DataProvider('refusedAccounts')]
    public function testOthersAreRefused(?string $role): void
    {
        $this->fixture->setComment($this->image['id'], 'bleibt');
        $client = $this->clientAs($role);

        $res = $client->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'info' => 'überschrieben',
            'pwg_token' => $role === null ? 'none' : $client->token(),
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertSame('bleibt', $this->fixture->imageRow($this->image['id'])['comment']);
        $this->assertNull(FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
    }

    /** [NEG] A wrong token is refused. */
    public function testAWrongTokenIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array('image_id' => $this->image['id'], 'info' => 'x', 'pwg_token' => 'wrong'));

        $this->assertSame(403, $res['json']['err'], $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['comment']);
    }

    /** [NEG] A GET request is refused: the method changes state. */
    public function testAGetRequestIsRefused(): void
    {
        $res = $this->ws->callGet(self::METHOD, array('image_id' => $this->image['id'], 'info' => 'x', 'pwg_token' => $this->ws->token()));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['comment']);
    }

    /** [NEG] An unknown photo is answered with 404. */
    public function testAnUnknownPhotoIsRefused(): void
    {
        $missing = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images') + 1000;

        $res = $this->ws->call(self::METHOD, array('image_id' => $missing, 'info' => 'x', 'pwg_token' => $this->ws->token()));

        $this->assertSame(404, $res['json']['err'], $res['body']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function clientAs(?string $role): WsClient
    {
        $client = new WsClient();
        if ($role !== null)
        {
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
        }
        return $client;
    }

    private function setInfo(WsClient $client, string $info): array
    {
        $res = $client->call(self::METHOD, array(
            'image_id' => $this->image['id'],
            'info' => $info,
            'pwg_token' => $client->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        return $res;
    }

    /** Sets provenance values, writes them back, and returns the caption that put into the file. */
    private function writeProvenance(array $values): string
    {
        $this->fixture->setProvenance($this->image['id'], $values);
        $this->assertWriteBack();

        $caption = FixtureBuilder::readFileTags($this->image['file'])['XMP-dc:Description'];
        $this->assertNotEmpty($caption, 'anti-vacuity: the provenance write-back put no caption into the file');
        $this->assertStringNotContainsString("\n", $caption);

        return $caption;
    }

    private function assertWriteBack(): void
    {
        $res = $this->ws->call('pwg.provenance.writeBack', array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, $res['json']['result']['written'], $res['body']);
    }
}
