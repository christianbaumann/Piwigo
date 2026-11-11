<?php
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoinfo.undo on region entries: persons removes or restores the box,
 * photoinfo writes the one undo entry. Requirement: design "Undo runs on the
 * server", "Who may undo" and "Conflicts, undo rows, no redo".
 *
 * Deactivates persons for one case and reactivates it in tearDown; a run
 * killed in between leaves it off, so reactivate it by hand.
 */
final class UndoRegionTest extends TestCase
{
    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private string $suffix;
    /** @var WsClient[] */
    private array $clients = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('persons');

        $this->suffix = bin2hex(random_bytes(4));
        $albumId = $this->fixture->createTestAlbum('photoinfo-undo-regions-' . $this->suffix);
        $this->image = $this->fixture->createTestImage();
        $this->fixture->attachImage($this->image['id'], $albumId);
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->fixture->invalidateUserCache();
        foreach (array(TestUsers::NORMAL, TestUsers::WEBMASTER) as $role)
        {
            $this->call($role, 'pwg.persons.getRegions', array('image_id' => $this->image['id']));
        }
    }

    protected function tearDown(): void
    {
        $this->perform('activate', 'persons');
        $this->fixture->assertPluginActive('persons');
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] Undoing an add removes the box from the file and the index and writes one undo entry. */
    public function testUndoOfAnAddRemovesTheBox(): void
    {
        $name = $this->personName('Anna');
        $this->addRegion(TestUsers::NORMAL, $name);
        $entry = $this->latest();
        $this->assertSame(1, $this->regionCount(), 'anti-vacuity: no box in the index');
        $this->assertNotSame(array(), $this->fileRegionNames(), 'anti-vacuity: no box in the file');
        $entries = $this->entryCount();

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, $this->regionCount());
        $this->assertSame(array(), $this->fileRegionNames());
        $this->assertSame($entries + 1, $this->entryCount(), 'exactly one entry: the undo, no region entry of its own');
        $undo = $this->latest();
        $this->assertSame($entry['id'], (int)$undo['undoes_id']);
        $this->assertSame('undo', $undo['source']);
        $this->assertSame($entry['new_value'], $undo['old_value']);
        $this->assertNull($undo['new_value']);
        $this->assertSame($undo['id'], $res['json']['result']['history_id']);
    }

    /** [HAPPY] Undoing a delete restores the box with its drawer, who can delete it again (decision 0065). */
    public function testUndoOfADeleteRestoresTheBoxAndItsDrawer(): void
    {
        $name = $this->personName('Bert');
        $this->addRegion(TestUsers::NORMAL, $name);
        $drawer = $this->userId(TestUsers::NORMAL);
        $this->call(TestUsers::NORMAL, 'pwg.persons.deleteRegion', array('region_id' => $this->regionId($name)));
        $entry = $this->latest();
        $this->assertNull($entry['new_value'], 'anti-vacuity: the latest entry is not the delete');
        $this->assertSame(0, $this->regionCount());
        $entries = $this->entryCount();

        $res = $this->undo(TestUsers::WEBMASTER, $entry['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, $this->regionCount());
        $this->assertContains($name, $this->fileRegionNames());
        $this->assertSame($drawer, (int)$this->db->scalar('SELECT drawn_by FROM piwigo_person_region WHERE image_id = ' . $this->image['id']));
        $this->assertSame($entries + 1, $this->entryCount());
        $this->assertSame($entry['id'], (int)$this->latest()['undoes_id']);

        $again = $this->call(TestUsers::NORMAL, 'pwg.persons.deleteRegion', array('region_id' => $this->regionId($name)));
        $this->assertSame('ok', $again['json']['stat'] ?? null, 'the drawer deletes it again: ' . $again['body']);
    }

    /** [NEG] A box that is gone, renamed or moved since is a conflict naming the account that changed the photo. */
    public function testAConflictIsRefusedWithTheAccountsName(): void
    {
        $name = $this->personName('Clara');
        $this->addRegion(TestUsers::NORMAL, $name);
        $entry = $this->latest();
        $this->call(TestUsers::WEBMASTER, 'pwg.persons.deleteRegion', array('region_id' => $this->regionId($name)));
        $entries = $this->entryCount();

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertRefused($res, 409, 'Wurde inzwischen von ' . Config::credentials(TestUsers::WEBMASTER)[0] . ' geändert');
        $this->assertSame($entries, $this->entryCount());
    }

    /** [NEG] A deleted box that is back again is a conflict too, and a box moved in the index counts as gone. */
    public function testARestoreOverAnExistingBoxAndAMovedBoxAreRefused(): void
    {
        $name = $this->personName('Dora');
        $this->addRegion(TestUsers::NORMAL, $name);
        $add = $this->latest();
        $this->call(TestUsers::NORMAL, 'pwg.persons.deleteRegion', array('region_id' => $this->regionId($name)));
        $delete = $this->latest();
        $this->addRegion(TestUsers::NORMAL, $name);
        $entries = $this->entryCount();

        $restore = $this->undo(TestUsers::NORMAL, $delete['id']);
        $this->assertRefused($restore, 409, 'Wurde inzwischen von ' . Config::credentials(TestUsers::NORMAL)[0] . ' geändert', 'already present');

        $this->db->query('UPDATE piwigo_person_region SET area_x = area_x + 0.1 WHERE image_id = ' . $this->image['id']);
        $moved = $this->undo(TestUsers::NORMAL, $add['id']);
        $this->assertRefused($moved, 409, 'Wurde inzwischen von ' . Config::credentials(TestUsers::NORMAL)[0] . ' geändert', 'moved');
        $this->assertSame($entries, $this->entryCount());
        $this->assertSame(1, $this->regionCount());
    }

    /** [NEG] Decision 0065 on top: a box whose index row names another drawer is not removed by a normal account. */
    public function testANormalAccountCannotRemoveABoxItDidNotDraw(): void
    {
        $name = $this->personName('Emma');
        $this->addRegion(TestUsers::NORMAL, $name);
        $entry = $this->latest();
        $this->db->query('UPDATE piwigo_person_region SET drawn_by = NULL WHERE image_id = ' . $this->image['id']);
        $entries = $this->entryCount();

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertRefused($res, 403, 'Das darfst du nicht rückgängig machen');
        $this->assertSame(1, $this->regionCount());
        $this->assertSame($entries, $this->entryCount());
    }

    /** [NEG] With persons off the undo is refused with its own message and changes nothing. */
    public function testWithPersonsDeactivatedTheUndoIsRefused(): void
    {
        $name = $this->personName('Fritz');
        $this->addRegion(TestUsers::NORMAL, $name);
        $entry = $this->latest();
        $entries = $this->entryCount();
        $this->assertSame('ok', $this->perform('deactivate', 'persons')['stat']);

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertRefused($res, 409, 'Personen ist ausgeschaltet, das lässt sich nicht rückgängig machen');
        $this->assertSame($entries, $this->entryCount());
        $this->assertSame(1, $this->regionCount());
    }

    private function assertRefused(array $res, int $code, string $text, string $label = ''): void
    {
        $this->assertSame('fail', $res['json']['stat'] ?? null, $label . ' ' . $res['body']);
        $this->assertSame($code, (int)$res['json']['err'], $label . ' ' . $res['body']);
        $this->assertSame($text, $res['json']['message'], $label);
    }

    private function undo(string $role, int $historyId): array
    {
        return $this->call($role, 'pwg.photoinfo.undo', array('history_id' => $historyId));
    }

    private function addRegion(string $role, string $name): void
    {
        $res = $this->call($role, 'pwg.persons.addRegion', array(
            'image_id' => $this->image['id'], 'name' => $name, 'x' => 0.25, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function call(string $role, string $method, array $params): array
    {
        if (!isset($this->clients[$role]))
        {
            $client = new WsClient();
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
            $this->clients[$role] = $client;
        }
        $client = $this->clients[$role];

        return $client->call($method, $params + array('pwg_token' => $client->token()));
    }

    private function perform(string $action, string $plugin): array
    {
        $ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $ws->login($username, $password);
        $res = $ws->call('pwg.plugins.performAction', array('action' => $action, 'plugin' => $plugin, 'pwg_token' => $ws->token()));
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

    private function regionId(string $name): int
    {
        return (int)$this->db->scalar(
            'SELECT r.id FROM piwigo_person_region AS r JOIN piwigo_persons AS p ON p.id = r.person_id ' .
            "WHERE r.image_id = {$this->image['id']} AND p.name = '" . $this->db->escape($name) . "'"
        );
    }

    private function regionCount(): int
    {
        return (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . $this->image['id']);
    }

    /** @return string[] the names of the regions in the file, read with a plain exiftool call */
    private function fileRegionNames(): array
    {
        $json = json_decode((string)shell_exec('exiftool -j -struct -RegionInfo ' . escapeshellarg($this->image['file']) . ' 2>/dev/null'), true);
        $list = $json[0]['RegionInfo']['RegionList'] ?? array();

        return array_column(isset($list['Name']) ? array($list) : $list, 'Name');
    }

    private function userId(string $role): int
    {
        return (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape(Config::credentials($role)[0]) . "'");
    }

    /** @return array the photo's newest entry of any field */
    private function latest(): array
    {
        $row = $this->db->query("SELECT * FROM piwigo_provenance_history WHERE object = 'photo' AND object_id = "
            . $this->image['id'] . ' ORDER BY id DESC LIMIT 1')->fetch_assoc();
        $this->assertNotNull($row, 'anti-vacuity: no entry');
        $row['id'] = (int)$row['id'];

        return $row;
    }

    private function entryCount(): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_provenance_history WHERE object = 'photo' AND object_id = "
            . $this->image['id'] . " AND field = 'region'");
    }
}
