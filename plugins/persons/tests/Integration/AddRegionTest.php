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
    /** Quotes, an ampersand and an umlaut: everything core's addslashes() touches or a file mangles. */
    private const QUOTED = 'Persons Api D\'Arcy "R" & Töchter';

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
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN, self::QUOTED, addslashes(self::QUOTED)));
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

    /**
     * [ST] Adding a region keeps the ids of those already on the photo.
     *
     * An open page holds them, and deletes by them. Exactly one id in the
     * answer is new, and that one is the region just added.
     */
    public function testAddingARegionKeepsTheIdsOfThoseAlreadyThere(): void
    {
        $first = $this->addRegion(self::JANE);
        $this->assertSame('ok', $first['json']['stat'], $first['body']);
        $before = array_column($first['json']['result']['regions'], 'id');
        $this->assertCount(1, $before, 'anti-vacuity: the first call indexed no region');

        $second = $this->addRegion(self::JOHN, array('x' => 0.2, 'y' => 0.2, 'w' => 0.1, 'h' => 0.1));
        $this->assertSame('ok', $second['json']['stat'], $second['body']);

        $after = array();
        foreach ($second['json']['result']['regions'] as $region)
        {
            $after[$region['name']] = $region['id'];
        }

        $this->assertSame($before[0], $after[self::JANE], 'the region already on the photo was renumbered');
        $this->assertNotContains($after[self::JOHN], $before);
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

    /**
     * [HAPPY] A name with quotes saves twice as one person, and the file holds
     * it as typed. Core slashes every request value; a name stored slashed in
     * the file was resolved again on the next save and inserted a second time.
     */
    public function testANameWithQuotesSavesTwiceAsOnePerson(): void
    {
        $first = $this->addRegion(self::QUOTED);
        $this->assertSame('ok', $first['json']['stat'], $first['body']);

        $second = $this->addRegion(self::QUOTED, array('x' => 0.2, 'y' => 0.2, 'w' => 0.1, 'h' => 0.1));
        $this->assertSame('ok', $second['json']['stat'], $second['body']);

        $this->assertSame(1, (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_persons WHERE name LIKE 'Persons Api D%'"
            ), 'the name must resolve to one person row');
        $this->assertNotNull($this->personId(self::QUOTED), 'the person row holds the name slashed');

        $this->assertSame(array(self::QUOTED, self::QUOTED), $this->namesInFile());
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
     * [NEG] A name with nothing left after cleaning is refused, and a German
     * account reads why in German: the message goes through l10n() and the
     * fork's override translates it (design "German messages give the reason").
     */
    public function testANamelessRegionIsRefusedInTheAccountsLanguage(): void
    {
        $this->assertSame('de_DE', $this->db->scalar(
            "SELECT ui.language FROM piwigo_user_infos ui JOIN piwigo_users u ON u.id = ui.user_id"
            . " WHERE u.username = '" . $this->db->escape(Config::normalUsername()) . "'"
        ), 'precondition: the test account reads the gallery in German');
        $lang = array();
        require PIWIGO_ROOT . 'local/language/de_DE.lang.php';
        $this->assertArrayHasKey(PERSONS_NAME_REQUIRED_MESSAGE, $lang, 'anti-vacuity: no German text to expect');

        $res = $this->addRegion('   ');

        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err'], $res['body']);
        $this->assertSame($lang[PERSONS_NAME_REQUIRED_MESSAGE], $res['json']['message'], $res['body']);
        $this->assertSame(0, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . (int)$this->image['id']
        ), 'the refused region was stored anyway');
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

    /**
     * [HAPPY] SES-2026-10-15-03: a box inside the frame is read back exactly as
     * sent, through the answer and through the index.
     */
    public function testABoxInsideTheFrameIsReadBackAsSent(): void
    {
        $res = $this->addRegion(self::JANE, self::BOX);

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertCount(1, $res['json']['result']['regions'], 'anti-vacuity: no region came back');
        $this->assertBoxEquals(self::BOX, $res['json']['result']['regions'][0]);
        $this->assertBoxEquals(self::BOX, $this->indexedBox());
    }

    /**
     * [BVA] SES-2026-10-15-03: the one rule that changes a frame is MWG clipping
     * of a box whose centre is inside but whose edge is not (decision 0077).
     * y 0.1 / h 0.3 overruns the top edge by 0.05 and comes back as 0.125 / 0.25,
     * on any photo size: the frame is normalized.
     */
    public function testABoxOverrunningTheTopEdgeIsStoredClipped(): void
    {
        $res = $this->addRegion(self::JANE, array('x' => 0.5, 'y' => 0.1, 'w' => 0.2, 'h' => 0.3));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertCount(1, $res['json']['result']['regions'], 'anti-vacuity: no region came back');
        $expected = array('x' => 0.5, 'y' => 0.125, 'w' => 0.2, 'h' => 0.25);
        $this->assertBoxEquals($expected, $res['json']['result']['regions'][0]);
        $this->assertBoxEquals($expected, $this->indexedBox());
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

    private function assertBoxEquals(array $expected, array $actual): void
    {
        foreach (array('x', 'y', 'w', 'h') as $key)
        {
            $this->assertEqualsWithDelta($expected[$key], (float)$actual[$key], 1e-6, $key);
        }
    }

    private function indexedBox(): array
    {
        $row = $this->db->query(
            'SELECT area_x AS x, area_y AS y, area_w AS w, area_h AS h FROM piwigo_person_region'
            . ' WHERE image_id = ' . (int)$this->image['id']
        )->fetch_assoc();
        $this->assertNotNull($row, 'anti-vacuity: the index holds no region');
        return $row;
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
