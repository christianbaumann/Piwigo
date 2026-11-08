<?php
use PHPUnit\Framework\TestCase;

/**
 * The change-log entries photoinfo writes for regions a user adds or deletes
 * (persons announces them). Requirement: design "photoinfo writes the rows;
 * persons announces region changes" and "What gets a row".
 */
final class RegionRecordingTest extends TestCase
{
    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('persons');

        $this->suffix = bin2hex(random_bytes(4));
        $albumId = $this->fixture->createTestAlbum('photoinfo-regions-' . $this->suffix);
        $this->image = $this->fixture->createTestImage();
        $this->fixture->attachImage($this->image['id'], $albumId);
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        // A case that switched photoinfo off must not leave it off for the next suite.
        $this->perform('activate', 'photoinfo');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] Naming a new box writes one region entry with name, box and drawer, and the answer carries its id. */
    public function testAddingARegionWritesOneEntry(): void
    {
        $name = $this->personName('Anna');
        $res = $this->addRegion($name, 0.25);

        $rows = $this->entries();
        $this->assertCount(1, $rows);
        $this->assertSame($rows[0]['id'], $res['json']['result']['history_id'], $res['body']);
        $this->assertNull($rows[0]['old_value']);
        $this->assertSame('picture_page', $rows[0]['source']);
        $this->assertSame($this->webmasterId(), $rows[0]['performed_by']);
        $value = json_decode($rows[0]['new_value'], true);
        $this->assertSame($name, $value['name']);
        $this->assertEqualsWithDelta(array(0.25, 0.5, 0.2, 0.2), $value['box'], 1e-6);
        $this->assertSame($this->webmasterId(), $value['drawn_by']);
        $this->assertArrayHasKey('regions', $res['json']['result'], 'the answer kept its existing keys');
    }

    /** [HAPPY] Deleting a box writes one entry holding the old value and the original drawer. */
    public function testDeletingARegionWritesOneEntryWithTheOldValue(): void
    {
        $name = $this->personName('Bert');
        $this->addRegion($name, 0.25);
        $added = $this->entries();
        $this->assertCount(1, $added, 'anti-vacuity: the add wrote no entry');

        $res = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId($name), 'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $rows = $this->entries();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[1]['id'], $res['json']['result']['history_id'], $res['body']);
        $this->assertNull($rows[1]['new_value']);
        $this->assertSame($added[0]['new_value'], $rows[1]['old_value']);
    }

    /** [ECP] The entry holds the box as stored: one running off the photo's edge is clipped. */
    public function testTheEntryHoldsTheStoredBoxNotTheRequestedOne(): void
    {
        $name = $this->personName('Gerda');
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name,
            'x' => 0.9, 'y' => 0.5, 'w' => 0.4, 'h' => 0.2,
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $stored = $this->db->query('SELECT area_x, area_y, area_w, area_h FROM piwigo_person_region WHERE image_id = '
            . $this->image['id'])->fetch_assoc();
        $box = json_decode($this->entries()[0]['new_value'], true)['box'];
        $this->assertNotEquals(0.4, (float)$stored['area_w'], 'anti-vacuity: the box was not clipped');
        $this->assertEqualsWithDelta(
            array((float)$stored['area_x'], (float)$stored['area_y'], (float)$stored['area_w'], (float)$stored['area_h']),
            $box, 1e-9);
    }

    /** [DT] The source is admin_photo for a request from an admin.php page, picture_page otherwise. */
    public function testTheSourceFollowsTheReferer(): void
    {
        $this->addRegion($this->personName('Hanna'), 0.15, 'http://localhost/admin.php?page=plugin&section=persons');
        $this->addRegion($this->personName('Ida'), 0.55, 'http://localhost/picture.php?/1/category/1');

        $rows = $this->entries();
        $this->assertCount(2, $rows);
        $this->assertSame('admin_photo', $rows[0]['source']);
        $this->assertSame('picture_page', $rows[1]['source']);
    }

    /** [HAPPY] An administrator deleting a region another account drew keeps that drawer in the old value. */
    public function testDeletingAnotherAccountsRegionKeepsItsDrawer(): void
    {
        $normal = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::NORMAL);
        $normal->login($username, $password);
        $name = $this->personName('Jana');
        $res = $normal->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name,
            'x' => 0.25, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2, 'pwg_token' => $normal->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $drawer = (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '"
            . $this->db->escape(Config::credentials(TestUsers::NORMAL)[0]) . "'");
        $this->assertNotSame($this->webmasterId(), $drawer);

        $del = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId($name), 'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $del['json']['stat'] ?? null, $del['body']);

        $rows = $this->entries();
        $this->assertCount(2, $rows);
        $this->assertSame($drawer, json_decode($rows[1]['old_value'], true)['drawn_by']);
        $this->assertSame($this->webmasterId(), $rows[1]['performed_by']);
    }

    /** [NEG] A refused delete (unknown region) writes nothing and carries no history_id. */
    public function testARefusedDeleteWritesNothing(): void
    {
        $res = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => 2000000000, 'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array(), $this->entries());
    }

    /** [NEG] pwg.persons.rescan, which rebuilds the index from the file, writes no entry. */
    public function testARescanWritesNothing(): void
    {
        $this->addRegion($this->personName('Clara'), 0.25);
        $before = $this->entries();
        $this->assertCount(1, $before, 'anti-vacuity: the add wrote no entry');
        $this->db->query('DELETE FROM piwigo_person_region WHERE image_id = ' . $this->image['id']);

        $res = $this->ws->call('pwg.persons.rescan', array(
            'image_ids' => (string)$this->image['id'], 'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . $this->image['id']),
            'anti-vacuity: the rescan did not index the region again');
        $this->assertSame($before, $this->entries());
    }

    /** [NEG] A crop that cuts a region away (photoedit's adjustment) writes no region entry. */
    public function testAPhotoeditCropWritesNoRegionEntry(): void
    {
        $this->fixture->assertPluginActive('photoedit');
        $this->addRegion($this->personName('Dora'), 0.15);
        $this->addRegion($this->personName('Emil'), 0.85);
        $before = $this->entries();
        $this->assertCount(2, $before, 'anti-vacuity: the adds wrote no entries');

        $res = $this->ws->call('pwg.photoedit.apply', array(
            'image_id' => $this->image['id'], 'crop' => '0.5,0,1,1', 'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . $this->image['id']),
            'anti-vacuity: the crop did not remove the cut region');
        $this->assertSame($before, $this->entries());
    }

    /** [NEG] persons adds and deletes regions unchanged, with no history_id, while photoinfo is off. */
    public function testPersonsWorksWithPhotoinfoDeactivated(): void
    {
        $this->assertSame('ok', $this->perform('deactivate', 'photoinfo')['stat']);
        $name = $this->personName('Fritz');

        $added = $this->addRegion($name, 0.25);
        $this->assertCount(1, $added['json']['result']['regions'], 'anti-vacuity: no region came back');
        $this->assertArrayNotHasKey('history_id', $added['json']['result']);

        $deleted = $this->ws->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId($name), 'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $deleted['json']['stat'] ?? null, $deleted['body']);
        $this->assertSame(array(), $deleted['json']['result']['regions']);
        $this->assertArrayNotHasKey('history_id', $deleted['json']['result']);
        $this->assertSame(array(), $this->entries());
    }

    private function perform(string $action, string $plugin): array
    {
        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action, 'plugin' => $plugin, 'pwg_token' => $this->ws->token(),
        ));
        $json = json_decode(substr($res['body'], (int)strpos($res['body'], '{"stat"')), true);
        $this->assertIsArray($json, $res['body']);
        return $json;
    }

    private function personName(string $base): string
    {
        $name = $base . ' ' . $this->suffix;
        $this->fixture->trackPerson($name);
        return $name;
    }

    private function addRegion(string $name, float $x, ?string $referer = null): array
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name,
            'x' => $x, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->ws->token(),
        ), $referer);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        return $res;
    }

    private function regionId(string $name): int
    {
        return (int)$this->db->scalar(
            'SELECT r.id FROM piwigo_person_region AS r JOIN piwigo_persons AS p ON p.id = r.person_id ' .
            "WHERE r.image_id = {$this->image['id']} AND p.name = '" . $this->db->escape($name) . "'"
        );
    }

    private function webmasterId(): int
    {
        list($username) = Config::credentials(TestUsers::WEBMASTER);
        return (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'");
    }

    /** @return array the photo's region entries, oldest first */
    private function entries(): array
    {
        $result = $this->db->query("SELECT id, old_value, new_value, source, performed_by FROM piwigo_provenance_history"
            . " WHERE object = 'photo' AND field = 'region' AND object_id = " . $this->image['id'] . ' ORDER BY id');
        $rows = array();
        while ($row = $result->fetch_assoc())
        {
            $row['id'] = (int)$row['id'];
            $row['performed_by'] = $row['performed_by'] === null ? null : (int)$row['performed_by'];
            $rows[] = $row;
        }
        return $rows;
    }
}
