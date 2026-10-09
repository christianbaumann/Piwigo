<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Person regions follow an edit: plugins/persons listens to photoedit's events.
 *
 * The regions are written with a plain exiftool call and indexed with
 * pwg.persons.rescan; what the edit leaves in the file is read back from the
 * raw XMP packet ImageMagick extracts, not through either plugin.
 *
 * The photo is the marked 300x200 PNG. Region values are worked out by hand in
 * pixels of it.
 */
final class ApplyRegionsTest extends TestCase
{
    use ReadsImageFiles;

    private const METHOD = 'pwg.photoedit.apply';

    /** x 45..105, y 70..130 px: wholly in the left half. */
    private const INSIDE = array('name' => 'Photoedit Inside', 'x' => 0.25, 'y' => 0.5, 'w' => 0.2, 'h' => 0.3);
    /** x 105..165 px: centre (135) in the left half, box over its edge. */
    private const PARTLY = array('name' => 'Photoedit Partly', 'x' => 0.45, 'y' => 0.5, 'w' => 0.2, 'h' => 0.3);
    /** x 225..255 px: wholly in the right half. */
    private const OUTSIDE = array('name' => 'Photoedit Outside', 'x' => 0.8, 'y' => 0.5, 'w' => 0.1, 'h' => 0.2);

    private const LEFT_HALF = '0,0,0.5,1';

    private const DELTA = 1e-6;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private WsClient $client;
    private bool $reactivatePersons = false;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
        $this->fixture->assertPluginActive('persons');

        $this->image = $this->fixture->createMarkedImage();
        $this->client = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->client->login($username, $password);
    }

    protected function tearDown(): void
    {
        if ($this->reactivatePersons)
        {
            $this->plugin('activate');
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyPersons(array(self::INSIDE['name'], self::PARTLY['name'], self::OUTSIDE['name']));
    }

    private function call(string $method, array $params): mixed
    {
        $res = $this->client->call($method, $params + array('pwg_token' => $this->client->token()));
        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        return $res['json']['result'] ?? array();
    }

    private function apply(array $params): array
    {
        return $this->call(self::METHOD, $params + array('image_id' => $this->image['id']));
    }

    private function plugin(string $action): void
    {
        $this->call('pwg.plugins.performAction', array('action' => $action, 'plugin' => 'persons'));
    }

    /** Seeds the regions into the file and has persons index them. */
    private function seedRegions(array $regions): void
    {
        $this->fixture->writeRegions($this->image, $regions);
        $this->call('pwg.persons.rescan', array('image_ids' => (string)$this->image['id']));
        $this->assertCount(count($regions), $this->indexedNames(), 'anti-vacuity: persons indexed none of the seeded regions');
    }

    private function assertRegion(array $expected, array $actual, string $name): void
    {
        foreach (array('x', 'y', 'w', 'h') as $i => $key)
        {
            $this->assertEqualsWithDelta($expected[$i], $actual[$i], self::DELTA, "$name: $key");
        }
    }

    /** @return string[] the names persons indexed on the photo, sorted */
    private function indexedNames(): array
    {
        $result = $this->db->query(
            'SELECT p.name FROM piwigo_person_region r JOIN piwigo_persons p ON p.id = r.person_id WHERE r.image_id = '
            . (int)$this->image['id'] . ' ORDER BY p.name'
        );
        return array_column($result->fetch_all(MYSQLI_ASSOC), 'name');
    }

    /** @return string[] the names of the tags on the photo, sorted */
    private function tagNames(): array
    {
        $result = $this->db->query(
            'SELECT t.name FROM piwigo_image_tag it JOIN piwigo_tags t ON t.id = it.tag_id WHERE it.image_id = '
            . (int)$this->image['id'] . ' ORDER BY t.name'
        );
        return array_column($result->fetch_all(MYSQLI_ASSOC), 'name');
    }

    /**
     * [HAPPY] A quarter turn moves the region with the face.
     *
     * The box x 45..105, y 70..130 of 300x200: a clockwise turn maps a point
     * (px, py) to (200 - py, px), so it lands on x 70..130, y 45..105 of
     * 200x300 - centre (100, 75), 60x60.
     */
    public function testAfterAQuarterTurnTheRegionSitsOnTheTurnedFace(): void
    {
        $this->seedRegions(array(self::INSIDE));

        $this->apply(array('turns' => 1));

        $file = $this->regionsInFile();
        $this->assertSame(array(200, 300), $file['']);
        $this->assertRegion(array(100 / 200, 75 / 300, 60 / 200, 60 / 300), $file[self::INSIDE['name']], 'turned');
    }

    /**
     * [ECP] Cropping to the left half keeps the region inside (moved and
     * scaled), clips the one over the edge, and removes the one outside, from
     * the file, the index and the tags.
     */
    public function testACropMovesClipsAndRemovesRegions(): void
    {
        $this->seedRegions(array(self::INSIDE, self::PARTLY, self::OUTSIDE));
        $this->assertContains(self::OUTSIDE['name'], $this->tagNames(), 'anti-vacuity: no mirrored tag to remove');

        $this->apply(array('turns' => 0, 'crop' => self::LEFT_HALF));

        $file = $this->regionsInFile();
        $this->assertSame(array(150, 200), $file['']);
        $this->assertSame(array('', self::INSIDE['name'], self::PARTLY['name']), $this->sortedKeys($file));
        // x 75 of 150, w 60 of 150
        $this->assertRegion(array(0.5, 0.5, 0.4, 0.3), $file[self::INSIDE['name']], 'inside');
        // x 105..165 clipped to 105..150: centre 127.5, w 45
        $this->assertRegion(array(127.5 / 150, 0.5, 45 / 150, 0.3), $file[self::PARTLY['name']], 'partly');

        $this->assertSame(array(self::INSIDE['name'], self::PARTLY['name']), $this->indexedNames());
        $this->assertSame(array(self::INSIDE['name'], self::PARTLY['name']), $this->tagNames());
    }

    /**
     * [ST] persons rewrites the file in photoedit_end, after the edit stored
     * the file's checksum and version; both still match the file afterwards.
     */
    public function testACropThatMovesRegionsLeavesTheChecksumAndVersionCurrent(): void
    {
        $this->seedRegions(array(self::INSIDE, self::OUTSIDE));
        $this->assertNotEmpty($this->db->scalar('SELECT md5sum FROM piwigo_images WHERE id = ' . (int)$this->image['id']),
            'anti-vacuity: the row has no md5sum to keep current');

        $this->apply(array('turns' => 0, 'crop' => self::LEFT_HALF));

        $this->assertSame(array(self::INSIDE['name']), $this->indexedNames(), 'anti-vacuity: the crop moved no region');
        clearstatcache();
        $md5 = md5_file($this->image['file']);
        $this->assertSame($md5, $this->db->scalar('SELECT md5sum FROM piwigo_images WHERE id = ' . (int)$this->image['id']));
        $versions = json_decode((string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'photoedit_versions'"), true);
        $this->assertSame(substr($md5, 0, 12), $versions[$this->image['id']] ?? null);
    }

    private function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);
        return $keys;
    }

    /** [HAPPY] The dry run names exactly the regions the write then removes. */
    public function testTheDryRunNamesWhatTheWriteRemoves(): void
    {
        $this->seedRegions(array(self::INSIDE, self::PARTLY, self::OUTSIDE));
        $before = $this->indexedNames();

        $preview = $this->apply(array('turns' => 0, 'crop' => self::LEFT_HALF, 'dry_run' => 'true'));
        $this->assertSame(array(self::OUTSIDE['name']), $preview['lost_regions']);

        $this->apply(array('turns' => 0, 'crop' => self::LEFT_HALF));
        $this->assertSame($preview['lost_regions'], array_values(array_diff($before, $this->indexedNames())));
    }

    /** [ECP] A turn alone loses nobody, so the dry run names nobody. */
    public function testATurnLosesNobody(): void
    {
        $this->seedRegions(array(self::INSIDE, self::OUTSIDE));

        $this->assertSame(array(), $this->apply(array('turns' => 1, 'dry_run' => 'true'))['lost_regions']);
    }

    /**
     * [NEG] With persons deactivated the edit still works, and the regions
     * stay in the file as exiftool copied them: unmoved, still claiming the
     * old dimensions.
     */
    public function testWithoutPersonsTheRegionsAreCopiedUnmoved(): void
    {
        $this->seedRegions(array(self::INSIDE));
        $this->plugin('deactivate');
        $this->reactivatePersons = true;
        $this->assertSame('inactive', $this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = 'persons'"));

        $result = $this->apply(array('turns' => 1));

        $this->assertSame(FixtureBuilder::MARKED_HEIGHT, $result['width'], 'the turn did not happen');
        $file = $this->regionsInFile();
        $this->assertSame(array(FixtureBuilder::MARKED_WIDTH, FixtureBuilder::MARKED_HEIGHT), $file['']);
        $this->assertRegion(array(self::INSIDE['x'], self::INSIDE['y'], self::INSIDE['w'], self::INSIDE['h']),
            $file[self::INSIDE['name']], 'copied');
    }
}
