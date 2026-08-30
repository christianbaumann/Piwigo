<?php
use PHPUnit\Framework\TestCase;

/**
 * pwg.persons.addRegion across its real boundaries: a real HTTP call to ws.php
 * as a real logged-in user, a real exiftool write into a real file, and the
 * index and tag rows that follow.
 *
 * The photo is always one this suite created (FixtureBuilder::createTestImage),
 * never a scan of the collection, because every call here rewrites the file.
 */
final class AddRegionTest extends TestCase
{
    /** Names this test writes. Distinctive, so a leftover row is obvious. */
    private const JANE = 'Persons Api Jane Doe';
    private const JOHN = 'Persons Api John Smith';

    /** A box comfortably above PERSONS_MIN_BOX_FRACTION. */
    private const BOX = array('x' => 0.5, 'y' => 0.4, 'w' => 0.2, 'h' => 0.3);

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private int $album;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        // Constants only: this suite talks to ws.php over HTTP, but it names
        // WS_ERR_INVALID_PARAM rather than transcribing 1003.
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
        $this->album = $this->fixture->createTestAlbum('Persons API fixture');
        $this->fixture->attachImage((int)$this->image['id'], $this->album);
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN));
    }

    /**
     * [HAPPY] One call produces the person, the region row, the region in the
     * file and the mirrored image_tag row.
     */
    public function testAddingARegionCreatesThePersonTheRegionAndTheMirroredTag(): void
    {
        $res = $this->addRegion(self::JANE);

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(1, count($res['json']['result']['regions']),
            'anti-vacuity: the call reported no region, so every assertion below would be trivial');

        $personId = $this->personId(self::JANE);
        $this->assertNotNull($personId, 'the person row was not created');

        $this->assertSame(1, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']
            . ' AND person_id = ' . $personId
            ));

        $this->assertSame(1, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_tag AS it'
            . ' JOIN piwigo_persons AS p ON p.tag_id = it.tag_id'
            . ' WHERE it.image_id = ' . (int)$this->image['id'] . ' AND p.id = ' . $personId
            ), 'the person was not mirrored as a tag on the photo');

        $this->assertContains(self::JANE, $this->namesInFile(),
            'the region never reached the image file');
    }

    /** [HAPPY] A second person on the same photo does not displace the first. */
    public function testASecondPersonDoesNotRemoveTheFirst(): void
    {
        $this->addRegion(self::JANE);
        $res = $this->addRegion(self::JOHN, array('x' => 0.2, 'y' => 0.2, 'w' => 0.1, 'h' => 0.1));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(2, count($res['json']['result']['regions']));

        $names = $this->namesInFile();
        $this->assertContains(self::JANE, $names);
        $this->assertContains(self::JOHN, $names);
    }

    /** [NEG] A guest may not write regions at all. */
    public function testGuestIsRejected(): void
    {
        $guest = new WsClient();
        $res = $guest->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::JANE,
            'pwg_token' => 'anything',
            ) + self::BOX);

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, (int)$res['json']['err']);
        $this->assertNull($this->personId(self::JANE), 'a refused call still wrote a person');
    }

    /** [NEG] A wrong CSRF token is refused with 403. */
    public function testBadTokenIsRejected(): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::JANE,
            'pwg_token' => 'not-the-token',
            ) + self::BOX);

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(403, (int)$res['json']['err']);
        $this->assertNull($this->personId(self::JANE));
    }

    /** [NEG] An empty token never reaches the handler: the dispatcher calls it missing. */
    public function testEmptyTokenIsRejectedByTheDispatcher(): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::JANE,
            'pwg_token' => '',
            ) + self::BOX);

        $this->assertSame(WS_ERR_MISSING_PARAM, (int)$res['json']['err'], $res['body']);
    }

    /** [NEG] So does an absent one. */
    public function testMissingTokenIsRejectedByTheDispatcher(): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::JANE,
            ) + self::BOX);

        $this->assertSame(WS_ERR_MISSING_PARAM, (int)$res['json']['err'], $res['body']);
    }

    /**
     * [BVA] [NEG] image_id is a WS_TYPE_ID, so the two values just outside the
     * domain are refused as invalid rather than looked up.
     *
     */
    #[PHPUnit\Framework\Attributes\DataProvider('outOfDomainImageIds')]
    public function testAnImageIdOutsideTheDomainIsRejected(string $imageId): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $imageId,
            'name' => self::JANE,
            'pwg_token' => $this->ws->token(),
            ) + self::BOX);

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
    }

    public static function outOfDomainImageIds(): array
    {
        return array('zero' => array('0'), 'negative' => array('-1'));
    }

    /** [BVA] [NEG] A box below the minimum is refused, and nothing is written. */
    public function testABoxBelowTheMinimumIsRejected(): void
    {
        $tiny = PERSONS_MIN_BOX_FRACTION / 2;

        $res = $this->addRegion(self::JANE, array('x' => 0.5, 'y' => 0.5, 'w' => $tiny, 'h' => $tiny));

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
        $this->assertNull($this->personId(self::JANE));
        $this->assertNotContains(self::JANE, $this->namesInFile());
    }

    /** [BVA] [NEG] A coordinate outside [0..1] is refused. */
    public function testACoordinateOutsideTheUnitSquareIsRejected(): void
    {
        $res = $this->addRegion(self::JANE, array('x' => 1.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2));

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
        $this->assertNull($this->personId(self::JANE));
    }

    /** [BVA] [NEG] A name of nothing but whitespace leaves no usable name. */
    public function testAWhitespaceOnlyNameIsRejected(): void
    {
        $res = $this->addRegion('   ');

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
    }

    /** [NEG] A region type outside the MWG schema is refused. */
    public function testAnUnknownRegionTypeIsRejected(): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::JANE,
            'type' => 'Sandwich',
            'pwg_token' => $this->ws->token(),
            ) + self::BOX);

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
    }

    private function addRegion(string $name, ?array $box = null): array
    {
        return $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => $name,
            'pwg_token' => $this->ws->token(),
            ) + ($box ?? self::BOX));
    }

    private function personId(string $name): ?int
    {
        $id = $this->db->scalar(
            "SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape($name) . "'"
        );
        return $id === null ? null : (int)$id;
    }

    /**
     * The names the image file itself carries, read by an exiftool run of this
     * test's own - never by the plugin, which could only agree with itself.
     */
    private function namesInFile(): array
    {
        $output = array();
        $status = 1;
        exec('exiftool -json -struct -XMP-mwg-rs:RegionInfo '
            . escapeshellarg($this->image['file']) . ' 2>/dev/null', $output, $status);

        $json = implode('', $output);
        $this->assertGreaterThan(2, strlen($json), 'exiftool returned nothing to read');

        $decoded = json_decode($json, true);
        $list = $decoded[0]['RegionInfo']['RegionList'] ?? array();

        $names = array();
        foreach ($list as $region)
        {
            if (isset($region['Name']))
            {
                $names[] = $region['Name'];
            }
        }
        return $names;
    }
}
