<?php
use PHPUnit\Framework\TestCase;

/**
 * pwg.persons.getRegions and pwg.persons.deleteRegion - reading back what was
 * written, and removing one box without disturbing the others.
 *
 * Every assertion about the file is made with an exiftool run of this test's
 * own: the plugin's reader agreeing with the plugin's writer would prove
 * nothing.
 */
final class DeleteRegionTest extends TestCase
{
    private const JANE = 'Persons Delete Jane';
    private const JOHN = 'Persons Delete John';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Persons delete fixture');
        $this->fixture->attachImage((int)$this->image['id'], $album);
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

    /** [HAPPY] getRegions returns what was written, with the geometry intact. */
    public function testGetRegionsReturnsWhatWasWritten(): void
    {
        $this->add(self::JANE, 0.25, 0.75, 0.125, 0.25);

        $res = $this->ws->call('pwg.persons.getRegions', array('image_id' => $this->image['id']));
        $this->assertSame('ok', $res['json']['stat'], $res['body']);

        $regions = $res['json']['result']['regions'];
        $this->assertCount(1, $regions, 'anti-vacuity: nothing came back to assert on');

        $region = $regions[0];
        $this->assertSame(self::JANE, $region['name']);
        $this->assertSame('Face', $region['type']);
        $this->assertSame('piwigo', $region['source']);
        $this->assertEqualsWithDelta(0.25, (float)$region['x'], 1e-6);
        $this->assertEqualsWithDelta(0.75, (float)$region['y'], 1e-6);
        $this->assertEqualsWithDelta(0.125, (float)$region['w'], 1e-6);
        $this->assertEqualsWithDelta(0.25, (float)$region['h'], 1e-6);
        $this->assertFalse($region['stale'], 'a region written against this very image is not stale');
    }

    /** [HAPPY] Deleting one of two boxes leaves the other in the file. */
    public function testDeletingOneRegionLeavesTheOther(): void
    {
        $this->add(self::JANE, 0.25, 0.25, 0.2, 0.2);
        $this->add(self::JOHN, 0.75, 0.75, 0.2, 0.2);

        $regionId = $this->regionId(self::JANE);

        $res = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $regionId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertCount(1, $res['json']['result']['regions']);
        $this->assertSame(array(self::JOHN), $this->namesInFile());
    }

    /** [ST] The last region for a person on a photo takes the mirrored tag with it. */
    public function testRemovingTheLastRegionRemovesTheImageTagRow(): void
    {
        $this->add(self::JANE, 0.25, 0.25, 0.2, 0.2);
        $this->assertSame(1, $this->imageTagCount(self::JANE), 'anti-vacuity: the tag was never mirrored');

        $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId(self::JANE),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame(0, $this->imageTagCount(self::JANE));
    }

    /** [ST] One of two boxes of the same person leaves the tag in place. */
    public function testRemovingOneOfTwoRegionsForTheSamePersonKeepsTheImageTagRow(): void
    {
        $this->add(self::JANE, 0.25, 0.25, 0.2, 0.2);
        $this->add(self::JANE, 0.75, 0.75, 0.2, 0.2);
        $this->assertSame(1, $this->imageTagCount(self::JANE));

        $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId(self::JANE),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame(1, $this->imageTagCount(self::JANE),
            'the person is still on the photo, so the tag must stay');
        $this->assertCount(1, $this->namesInFile());
    }

    /** [NEG] A region id that does not exist is refused without saying more. */
    public function testAnUnknownRegionIdIsRefused(): void
    {
        $absent = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1000 FROM piwigo_person_region');

        $res = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $absent,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame(404, (int)$res['json']['err'], $res['body']);
    }

    /** [NEG] A wrong CSRF token deletes nothing. */
    public function testBadTokenIsRejected(): void
    {
        $this->add(self::JANE, 0.25, 0.25, 0.2, 0.2);

        $res = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId(self::JANE),
            'pwg_token' => 'not-the-token',
            ));

        $this->assertSame(403, (int)$res['json']['err'], $res['body']);
        $this->assertSame(array(self::JANE), $this->namesInFile());
    }

    private function add(string $name, float $x, float $y, float $w, float $h): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => $name,
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'pwg_token' => $this->ws->token(),
            ));

        if (($res['json']['stat'] ?? '') !== 'ok')
        {
            throw new RuntimeException("could not seed $name: " . $res['body']);
        }
    }

    private function regionId(string $name): int
    {
        $id = $this->db->scalar(
            'SELECT r.id FROM piwigo_person_region AS r'
            . ' JOIN piwigo_persons AS p ON p.id = r.person_id'
            . " WHERE r.image_id = " . (int)$this->image['id']
            . " AND p.name = '" . $this->db->escape($name) . "' ORDER BY r.id LIMIT 1"
        );

        if ($id === null)
        {
            throw new RuntimeException("no indexed region for $name to delete");
        }

        return (int)$id;
    }

    private function imageTagCount(string $name): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_image_tag AS it'
            . ' JOIN piwigo_persons AS p ON p.tag_id = it.tag_id'
            . ' WHERE it.image_id = ' . (int)$this->image['id']
            . " AND p.name = '" . $this->db->escape($name) . "'"
        );
    }

    /** @return string[] the names the file itself carries */
    private function namesInFile(): array
    {
        $output = array();
        exec('exiftool -json -struct -XMP-mwg-rs:RegionInfo '
            . escapeshellarg($this->image['file']) . ' 2>/dev/null', $output);

        $json = implode('', $output);
        $this->assertGreaterThan(2, strlen($json), 'exiftool returned nothing to read');

        $decoded = json_decode($json, true);
        $names = array();
        foreach ($decoded[0]['RegionInfo']['RegionList'] ?? array() as $region)
        {
            if (isset($region['Name']))
            {
                $names[] = $region['Name'];
            }
        }
        return $names;
    }
}
