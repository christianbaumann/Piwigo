<?php
use PHPUnit\Framework\TestCase;

/**
 * The change-log entries photoinfo writes for a photo's date and info text,
 * per edit path. Requirement: design "What gets a row", "Several Info saves
 * become one entry". The 10-minute window is not waited for: the previous
 * entry's occured_on is moved back instead.
 */
final class HistoryRecordingTest extends TestCase
{
    private const MIN_PAGE_BYTES = 2000;
    private const MERGE_WINDOW = 600;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $webmaster;
    private WsClient $normal;
    private int $albumId;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->albumId = $this->fixture->createTestAlbum('photoinfo-history-' . bin2hex(random_bytes(4)));
        $this->image = $this->fixture->createTestImage();
        $this->fixture->attachImage($this->image['id'], $this->albumId);

        $this->webmaster = $this->clientAs(TestUsers::WEBMASTER);
        $this->normal = $this->clientAs(TestUsers::NORMAL);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] A date saved on the picture page is one date entry naming the account, and the answer carries its id. */
    public function testADateSaveWritesOneEntryNamingTheAccount(): void
    {
        $res = $this->setDate($this->webmaster, '1965', '3', '14');

        $rows = $this->entries('date');
        $this->assertCount(1, $rows);
        $this->assertSame($rows[0]['id'], $res['json']['result']['history_id'], $res['body']);
        $this->assertNull($rows[0]['old_value']);
        $this->assertSame('14. März 1965 (1965-03-14)', $rows[0]['new_value']);
        $this->assertSame('picture_page', $rows[0]['source']);
        $this->assertSame($this->userId(TestUsers::WEBMASTER), $rows[0]['performed_by']);
    }

    /** [ST] A qualified date keeps the old value in the shape undo reads back. */
    public function testALaterDateSaveKeepsTheOldValueWithItsEdtf(): void
    {
        $this->setDate($this->webmaster, '1965', '', '', 'circa');
        $this->setDate($this->webmaster, '1966', '', '');

        $rows = $this->entries('date');
        $this->assertCount(2, $rows);
        $this->assertSame('ca. 1965 (1965~)', $rows[1]['old_value']);
        $this->assertSame('1966 (1966)', $rows[1]['new_value']);
    }

    /** [NEG] The same date saved again changes nothing and writes nothing. */
    public function testAnUnchangedDateWritesNothing(): void
    {
        $this->setDate($this->webmaster, '1965', '3', '14');
        $res = $this->setDate($this->webmaster, '1965', '3', '14');

        $this->assertCount(1, $this->entries('date'));
        $this->assertNull($res['json']['result']['history_id']);
    }

    /** [HAPPY] Two info saves 3 minutes apart are one entry that began at the text before the first. */
    public function testTwoInfoSavesWithinTheWindowAreOneEntry(): void
    {
        $this->fixture->setComment($this->image['id'], 'Vorher');
        $first = $this->setInfo($this->webmaster, 'Zwischen');
        $this->ageEntries(180);
        $second = $this->setInfo($this->webmaster, 'Nachher');

        $rows = $this->entries('info');
        $this->assertCount(1, $rows);
        $this->assertSame('Vorher', $rows[0]['old_value']);
        $this->assertSame('Nachher', $rows[0]['new_value']);
        $this->assertSame($first['json']['result']['history_id'], $second['json']['result']['history_id']);
    }

    /** [BVA] A save past the window is a new entry. */
    public function testASaveAfterTheWindowIsANewEntry(): void
    {
        $this->setInfo($this->webmaster, 'Eins');
        $this->ageEntries(self::MERGE_WINDOW + 5);
        $this->setInfo($this->webmaster, 'Zwei');

        $this->assertCount(2, $this->entries('info'));
    }

    /** [NEG] Another account's save in between keeps the first account's saves apart. */
    public function testAnotherAccountInBetweenPreventsTheMerge(): void
    {
        $this->setInfo($this->webmaster, 'Eins');
        $this->setInfo($this->normal, 'Zwei');
        $this->setInfo($this->webmaster, 'Drei');

        $rows = $this->entries('info');
        $this->assertCount(3, $rows);
        $this->assertSame('Zwei', $rows[2]['old_value']);
    }

    /** [NEG] A date entry in between prevents the merge. */
    public function testADateEntryInBetweenPreventsTheMerge(): void
    {
        $this->setInfo($this->webmaster, 'Eins');
        $this->setDate($this->webmaster, '1965', '', '');
        $this->setInfo($this->webmaster, 'Zwei');

        $this->assertCount(2, $this->entries('info'));
    }

    /** [ST] A merge that brings the text back to where the entry began leaves no entry. */
    public function testAMergeBackToTheStartRemovesTheEntry(): void
    {
        $this->fixture->setComment($this->image['id'], 'Start');
        $this->setInfo($this->webmaster, 'Anders');
        $res = $this->setInfo($this->webmaster, 'Start');

        $this->assertSame(array(), $this->entries('info'));
        $this->assertNull($res['json']['result']['history_id']);
    }

    /** [NEG] The same info text saved again writes nothing. */
    public function testAnUnchangedInfoWritesNothing(): void
    {
        $this->setInfo($this->webmaster, 'Gleich');
        $this->ageEntries(30);
        $this->setInfo($this->webmaster, 'Gleich');

        $this->assertCount(1, $this->entries('info'));
    }

    /** [NEG] A rescan reads values from the file and records nothing. */
    public function testARescanWritesNothing(): void
    {
        $this->setInfo($this->webmaster, 'Im File');
        $this->db->query('UPDATE piwigo_images SET comment = NULL WHERE id = ' . $this->image['id']);
        $before = $this->entries();
        $this->assertNotEmpty($before, 'anti-vacuity: the save must have written an entry');

        $res = $this->webmaster->call('pwg.photoinfo.rescan', array(
            'image_ids' => (string)$this->image['id'],
            'pwg_token' => $this->webmaster->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame('Im File', $this->fixture->imageRow($this->image['id'])['comment'], 'anti-vacuity: the rescan must have restored the text');

        $this->assertEquals($before, $this->entries());
    }

    /** [HAPPY] The admin photo screen records date and info with its own source. */
    public function testThePropertiesScreenRecordsWithItsSource(): void
    {
        $row = $this->fixture->imageRow($this->image['id']);
        $this->webmaster->postPage('/admin.php?page=photo-' . $this->image['id'] . '-properties', array(
            'name' => (string)$row['name'], 'author' => '', 'comment' => 'Vom Admin', 'date_creation' => '1970-02-03',
            'level' => 0, 'associate' => array($this->albumId),
            'pwg_token' => $this->webmaster->token(), 'submit' => 1,
        ));

        $this->assertSame(array('admin_photo'), array_column($this->entries('date'), 'source'));
        $this->assertSame(array('admin_photo'), array_column($this->entries('info'), 'source'));
        $this->assertSame('Vom Admin', $this->entries('info')[0]['new_value']);
    }

    /** [HAPPY] The Batch Manager's global date records the date it replaced. */
    public function testTheBatchDateRecordsTheOldDate(): void
    {
        $this->setDate($this->webmaster, '1965', '', '', 'circa');
        $this->webmaster->postPage('/admin.php?page=batch_manager&mode=global', array(
            'pwg_token' => $this->webmaster->token(), 'selection' => array($this->image['id']),
            'selectAction' => 'date_creation', 'date_creation' => '1970-02-03', 'submit' => 1,
        ));

        $rows = $this->entries('date');
        $this->assertCount(2, $rows);
        $this->assertSame('batch_manager', $rows[1]['source']);
        $this->assertSame('ca. 1965 (1965~)', $rows[1]['old_value']);
        $this->assertSame('3. Februar 1970 (1970-02-03)', $rows[1]['new_value']);
    }

    /** [HAPPY] pwg.images.setInfo without a Batch Manager referer is the API. */
    public function testSetInfoThroughTheApiRecordsTheApiSource(): void
    {
        $res = $this->webmaster->call('pwg.images.setInfo', array(
            'image_id' => $this->image['id'], 'single_value_mode' => 'replace', 'comment' => 'Per API',
            'pwg_token' => $this->webmaster->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $rows = $this->entries('info');
        $this->assertCount(1, $rows);
        $this->assertSame('api', $rows[0]['source']);
        $this->assertSame('Per API', $rows[0]['new_value']);
    }

    /** [HAPPY] pwg.images.setInfo called from the Batch Manager's page (unit mode) records batch_manager. */
    public function testSetInfoFromTheBatchManagerPageRecordsItsSource(): void
    {
        $res = $this->webmaster->call('pwg.images.setInfo', array(
            'image_id' => $this->image['id'], 'single_value_mode' => 'replace', 'comment' => 'Aus dem Batch Manager',
            'pwg_token' => $this->webmaster->token(),
        ), Config::baseUrl() . '/admin.php?page=batch_manager&mode=unit');
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $rows = $this->entries('info');
        $this->assertCount(1, $rows);
        $this->assertSame('batch_manager', $rows[0]['source']);
        $this->assertSame('Aus dem Batch Manager', $rows[0]['new_value']);
    }

    /**
     * [HAPPY] The Batch Manager's global date over a whole set (setSelected / whole_set) records
     * one date entry per photo of the set, each with that photo's own old value.
     */
    public function testTheBatchDateOverAWholeSetRecordsEveryPhoto(): void
    {
        $other = $this->fixture->createTestImage();
        $this->fixture->attachImage($other['id'], $this->albumId);
        $this->setDate($this->webmaster, '1965', '', '', 'circa');
        $this->setDateOf($this->webmaster, $other['id'], '1980', '6', '');

        $this->webmaster->postPage('/admin.php?page=batch_manager&mode=global', array(
            'pwg_token' => $this->webmaster->token(),
            'setSelected' => 1, 'whole_set' => $this->image['id'] . ',' . $other['id'],
            'selectAction' => 'date_creation', 'date_creation' => '1970-02-03', 'submit' => 1,
        ));

        $mine = $this->entries('date');
        $this->assertCount(2, $mine, 'anti-vacuity: the action must have recorded for the first photo');
        $this->assertSame('ca. 1965 (1965~)', $mine[1]['old_value']);
        $this->assertSame('3. Februar 1970 (1970-02-03)', $mine[1]['new_value']);
        $this->assertSame('batch_manager', $mine[1]['source']);

        $theirs = $this->entriesOf($other['id'], 'date');
        $this->assertCount(2, $theirs);
        $this->assertStringEndsWith('(1980-06)', $theirs[1]['old_value']);
        $this->assertSame('3. Februar 1970 (1970-02-03)', $theirs[1]['new_value']);
        $this->assertSame('batch_manager', $theirs[1]['source']);
    }

    private function clientAs(string $role): WsClient
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials($role);
        $client->login($username, $password);
        return $client;
    }

    private function userId(string $role): int
    {
        list($username) = Config::credentials($role);
        return (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape($username) . "'");
    }

    private function setDate(WsClient $client, string $year, string $month, string $day, string $qualifier = ''): array
    {
        return $this->setDateOf($client, $this->image['id'], $year, $month, $day, $qualifier);
    }

    private function setDateOf(WsClient $client, int $imageId, string $year, string $month, string $day, string $qualifier = ''): array
    {
        $res = $client->call('pwg.photoinfo.setDate', array(
            'image_id' => $imageId, 'qualifier' => $qualifier,
            'year' => $year, 'month' => $month, 'day' => $day,
            'end_year' => '', 'end_month' => '', 'end_day' => '',
            'pwg_token' => $client->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        return $res;
    }

    private function setInfo(WsClient $client, string $info): array
    {
        $res = $client->call('pwg.photoinfo.setInfo', array(
            'image_id' => $this->image['id'], 'info' => $info, 'pwg_token' => $client->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        return $res;
    }

    /** Moves every entry of the photo back in time, as if the previous save was that long ago. */
    private function ageEntries(int $seconds): void
    {
        $this->db->query('UPDATE piwigo_provenance_history SET occured_on = occured_on - INTERVAL ' . $seconds
            . " SECOND WHERE object = 'photo' AND object_id = " . $this->image['id']);
    }

    /** @return array the photo's entries, oldest first, optionally of one field */
    private function entries(?string $field = null): array
    {
        return $this->entriesOf($this->image['id'], $field);
    }

    private function entriesOf(int $imageId, ?string $field): array
    {
        $result = $this->db->query("SELECT id, field, old_value, new_value, source, performed_by FROM piwigo_provenance_history"
            . " WHERE object = 'photo' AND object_id = " . $imageId
            . ($field === null ? '' : " AND field = '" . $field . "'") . ' ORDER BY id');
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
