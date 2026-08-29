<?php
use PHPUnit\Framework\TestCase;

/**
 * The audit trail across its real boundary: the recorder writing through core's
 * mass_inserts() into MariaDB, and pwg.provenance.getHistory reading the rows
 * back over HTTP.
 *
 * The write half runs through PiwigoRuntime, which boots the production database
 * layer without the session machinery a CLI process cannot start. The read half
 * goes through ws.php exactly as an administrator's browser would, so the admin
 * gate is asserted where it is actually enforced.
 *
 * Every test cleans up only the rows it inserted (id above the high-water mark
 * taken in setUp), so a real install's trail is left untouched.
 */
final class HistoryTest extends TestCase
{
    private const TABLE = 'piwigo_provenance_history';

    /** The object the rows in this suite are about. */
    private const OBJECT = 'photo';

    private Db $db;
    private WsClient $ws;
    private int $baselineId;
    private int $objectId;
    private int $actorId;

    protected function setUp(): void
    {
        PiwigoRuntime::boot();

        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->actorId = (int)$this->db->scalar(
            "SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape(Config::username()) . "'"
        );
        $this->assertGreaterThan(0, $this->actorId, 'the webmaster account must exist for attribution to mean anything');
        PiwigoRuntime::actAs($this->actorId);

        // A real photo id, so a recorded row refers to something that exists.
        $this->objectId = (int)$this->db->scalar('SELECT MIN(id) FROM piwigo_images');
        $this->assertGreaterThan(0, $this->objectId, 'this install has no photo to record history against');

        $this->baselineId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::TABLE);
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM ' . self::TABLE . ' WHERE id > ' . $this->baselineId);
        $this->ws->logout();
    }

    /**
     * [HAPPY] A recorded change comes back through the web service with both
     * values intact - including one far longer than piwigo_activity.details
     * could hold, which is the reason this table exists at all.
     */
    public function testRecordedChangeIsReadableBackIncludingALongValue(): void
    {
        $long = str_repeat('Ω über lange Notiz. ', 400);
        $this->assertGreaterThan(
            255,
            strlen($long),
            'anti-vacuity: the value must exceed the varchar(255) this table replaces'
        );

        $written = provenance_record_change(
            self::OBJECT, $this->objectId, 'provenance_note', 'kurz', $long, 'photo_edit'
        );
        $this->assertSame(1, $written);

        $rows = $this->history();

        $this->assertCount(1, $rows);
        $this->assertSame('provenance_note', $rows[0]['field']);
        $this->assertSame('kurz', $rows[0]['old_value']);
        $this->assertSame($long, $rows[0]['new_value']);
        $this->assertSame('photo_edit', $rows[0]['source']);
        $this->assertSame(self::OBJECT, $rows[0]['object']);
        $this->assertSame($this->objectId, $rows[0]['object_id']);
    }

    /**
     * [HAPPY] The row is attributed to the acting user and stamped with a time,
     * so "who changed this, and when" is answerable from the row alone.
     */
    public function testRowCarriesTheActingUserAndATimestamp(): void
    {
        $before = (string)$this->db->scalar('SELECT NOW()');
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'photo_edit');
        $after = (string)$this->db->scalar('SELECT NOW()');

        $row = $this->db->query(
            'SELECT performed_by, occured_on FROM ' . self::TABLE . ' WHERE id > ' . $this->baselineId
        )->fetch_assoc();

        $this->assertSame($this->actorId, (int)$row['performed_by']);
        $this->assertGreaterThanOrEqual($before, $row['occured_on']);
        $this->assertLessThanOrEqual($after, $row['occured_on']);

        $this->assertSame($this->actorId, $this->history()[0]['performed_by']);
    }

    /** [NEG] An unattributed write - filesystem sync, a CLI run - records a NULL actor. */
    public function testUnattributedWriteRecordsNoUser(): void
    {
        PiwigoRuntime::actAs(null);
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'inherit');
        PiwigoRuntime::actAs($this->actorId);

        $this->assertSame(1, $this->rowsWritten());
        $this->assertNull($this->history()[0]['performed_by']);
    }

    /** [NEG] A save that changed nothing writes no row. */
    public function testUnchangedValueWritesNoRow(): void
    {
        $written = provenance_record_change(
            self::OBJECT, $this->objectId, 'provenance_owner', 'Anna', 'Anna', 'album_edit'
        );

        $this->assertSame(0, $written);
        $this->assertSame(0, $this->rowsWritten());
    }

    /**
     * [DT] A batch writes one row per changed field and nothing for the rest -
     * the shape the apply operation uses over a whole album.
     */
    public function testBatchWritesOnlyTheChangedFields(): void
    {
        $written = provenance_record_changes(array(
            array('object' => self::OBJECT, 'object_id' => $this->objectId, 'field' => 'provenance_owner',
                  'old' => 'Anna', 'new' => 'Bernd', 'source' => 'apply'),
            array('object' => self::OBJECT, 'object_id' => $this->objectId, 'field' => 'provenance_physical_album',
                  'old' => 'Rot', 'new' => 'Rot', 'source' => 'apply'),
            array('object' => self::OBJECT, 'object_id' => $this->objectId, 'field' => 'provenance_scanned_on',
                  'old' => null, 'new' => '2026-08-29', 'source' => 'apply'),
        ));

        $this->assertSame(2, $written);
        $this->assertSame(2, $this->rowsWritten());

        $fields = array_column($this->history(), 'field');
        sort($fields);
        $this->assertSame(array('provenance_owner', 'provenance_scanned_on'), $fields);
    }

    /**
     * [NEG] A value carrying quotes and a backslash survives the round trip.
     *
     * mass_inserts() applies no escaping of its own, so this is the assertion
     * that says the recorder does it - and does it exactly once.
     */
    public function testValueWithQuotesAndBackslashesRoundTrips(): void
    {
        $value = 'O\'Brien "Nachlass" \\ 100%';
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_note', null, $value, 'photo_edit');

        $this->assertSame($value, $this->history()[0]['new_value']);
    }

    /** [ECP] The trail of one object never leaks rows belonging to another. */
    public function testHistoryIsScopedToOneObject(): void
    {
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Mine', 'photo_edit');
        provenance_record_change(self::OBJECT, $this->objectId + 1, 'provenance_owner', null, 'Theirs', 'photo_edit');
        provenance_record_change('album', $this->objectId, 'provenance_owner', null, 'Album', 'album_edit');

        $this->assertSame(3, $this->rowsWritten(), 'anti-vacuity: all three rows must exist for the filter to prove anything');

        $rows = $this->history();
        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['new_value']);
    }

    /** [BVA] per_page caps how many rows come back, newest first. */
    public function testPerPageCapsTheResultNewestFirst(): void
    {
        foreach (array('one', 'two', 'three') as $i => $value)
        {
            provenance_record_change(
                self::OBJECT, $this->objectId, 'provenance_note', 'x' . $i, $value, 'photo_edit'
            );
        }
        $this->assertSame(3, $this->rowsWritten());

        $res = $this->call(array('per_page' => 2));

        $this->assertSame(2, $res['paging']['count']);
        $this->assertSame(3, $res['paging']['total_count']);
        $this->assertSame('three', $res['histories'][0]['new_value']);
    }

    /** [HAPPY] With no per_page given, the documented default is what is applied. */
    public function testPerPageDefaultsToTheDeclaredValue(): void
    {
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'photo_edit');

        $this->assertSame(PROVENANCE_HISTORY_PER_PAGE_DEFAULT, $this->call()['paging']['per_page']);
    }

    /** [BVA] A request above the ceiling is clamped to it rather than honoured. */
    public function testPerPageAboveTheCeilingIsClamped(): void
    {
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'photo_edit');

        $res = $this->call(array('per_page' => PROVENANCE_HISTORY_PER_PAGE_MAX + 1));

        $this->assertSame(PROVENANCE_HISTORY_PER_PAGE_MAX, $res['paging']['per_page']);
    }

    /** [BVA] date_min excludes what happened before it and keeps what happened after. */
    public function testDateBoundsFilterTheTrail(): void
    {
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'photo_edit');
        $this->assertSame(1, $this->rowsWritten());

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->assertCount(1, $this->call(array('date_min' => $yesterday))['histories']);
        $this->assertCount(0, $this->call(array('date_min' => $tomorrow))['histories']);
    }

    /** [NEG] An object outside the enum is refused rather than queried for. */
    public function testUnknownObjectIsRejected(): void
    {
        $res = $this->ws->call('pwg.provenance.getHistory', array(
            'object' => 'category',
            'object_id' => $this->objectId,
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err']);
    }

    /** [NEG] A malformed date bound is refused. */
    public function testMalformedDateIsRejected(): void
    {
        $res = $this->ws->call('pwg.provenance.getHistory', array(
            'object' => self::OBJECT,
            'object_id' => $this->objectId,
            'date_min' => '29.08.2026',
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(WS_ERR_INVALID_PARAM, (int)$res['json']['err']);
    }

    /** [NEG] An authenticated non-admin cannot read the trail. */
    public function testNormalUserCannotReadTheHistory(): void
    {
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'photo_edit');

        $this->ws->logout();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        $res = $this->ws->call('pwg.provenance.getHistory', array(
            'object' => self::OBJECT,
            'object_id' => $this->objectId,
        ));

        $this->ws->logout();
        $this->ws->login(Config::username(), Config::password());

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, (int)$res['json']['err']);
        $this->assertStringNotContainsString('Anna', $res['body'], 'the refusal must not leak the row it refused');
    }

    /** [NEG] Nor can a guest. */
    public function testGuestCannotReadTheHistory(): void
    {
        provenance_record_change(self::OBJECT, $this->objectId, 'provenance_owner', null, 'Anna', 'photo_edit');

        $this->ws->logout();
        $res = $this->ws->call('pwg.provenance.getHistory', array(
            'object' => self::OBJECT,
            'object_id' => $this->objectId,
        ));
        $this->ws->login(Config::username(), Config::password());

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, (int)$res['json']['err']);
        $this->assertStringNotContainsString('Anna', $res['body']);
    }

    /**
     * [HAPPY] The enum the recorder validates against is the enum the column
     * accepts. A source in the shared list but missing from the column would be
     * stored as '' with nothing but a warning.
     */
    public function testColumnEnumsMatchTheSharedLists(): void
    {
        $this->assertSame(provenance_history_objects(), $this->columnEnum('object'));
        $this->assertSame(provenance_history_sources(), $this->columnEnum('source'));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** The rows this test wrote, as the web service returns them. */
    private function history(): array
    {
        return $this->call()['histories'];
    }

    private function call(array $params = array()): array
    {
        $res = $this->ws->call('pwg.provenance.getHistory', array_merge(array(
            'object' => self::OBJECT,
            'object_id' => $this->objectId,
        ), $params));

        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);

        return $res['json']['result'];
    }

    private function rowsWritten(): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE id > ' . $this->baselineId
        );
    }

    private function columnEnum(string $column): array
    {
        $row = $this->db->query(
            'SHOW COLUMNS FROM ' . self::TABLE . " LIKE '" . $this->db->escape($column) . "'"
        )->fetch_assoc();

        $this->assertNotNull($row, "column $column is missing");
        $this->assertSame(1, preg_match('/^enum\((.*)\)$/', $row['Type'], $m), "column $column is not an enum");

        return array_map(fn($v) => trim($v, "'"), explode(',', $m[1]));
    }
}
