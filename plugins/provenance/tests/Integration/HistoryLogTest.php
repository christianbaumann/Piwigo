<?php
use PHPUnit\Framework\TestCase;

/**
 * The history table as the picture-page change log: the schema step that adds
 * undoes_id to an install of the previous plugin version, an undo entry read
 * back with its target marked undone, and who may read which object's history.
 *
 * The album, photo and history rows are this suite's own.
 */
final class HistoryLogTest extends TestCase
{
    private const TABLE = 'piwigo_provenance_history';
    private const PLUGIN_ROW = 'piwigo_plugins';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $webmaster;
    private int $baselineId;
    private int $catId;
    private int $imageId;

    protected function setUp(): void
    {
        PiwigoRuntime::boot();

        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->baselineId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::TABLE);

        $this->catId = $this->fixture->createTestAlbum('provenance history log');
        $this->imageId = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($this->imageId, $this->catId);
        $this->fixture->invalidateUserCache();

        $this->webmaster = new WsClient();
        $this->webmaster->login(Config::username(), Config::password());
        PiwigoRuntime::actAs((int)$this->db->scalar(
            "SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape(Config::username()) . "'"
        ));
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->invalidateUserCache();
        $this->db->query('DELETE FROM ' . self::TABLE . ' WHERE id > ' . $this->baselineId);
        $this->webmaster->logout();
    }

    /**
     * [ST] An install of the previous plugin version (no undoes_id, plugin row at
     * 1.0.0) gets the column and its index from core's autoupdate on the next
     * request, and keeps the rows it had.
     */
    public function testTheUpdateAddsUndoesIdAndKeepsExistingRows(): void
    {
        $this->db->query('INSERT INTO ' . self::TABLE
            . " (object, object_id, field, old_value, new_value, source) VALUES ('photo', {$this->imageId}, 'info', NULL, 'alt', 'photo_edit')");
        $kept = (int)$this->db->insertId();
        $fileVersion = $this->fileVersion();

        $this->db->query('ALTER TABLE ' . self::TABLE . ' DROP KEY `undoes_lookup`, DROP COLUMN `undoes_id`');
        $this->db->query("UPDATE " . self::PLUGIN_ROW . " SET version = '1.0.0' WHERE id = 'provenance'");
        $this->assertFalse($this->fixture->columnExists(self::TABLE, 'undoes_id'), 'the old shape was not forced');

        $res = $this->webmaster->call('pwg.provenance.getHistory', array('object' => 'photo', 'object_id' => $this->imageId));

        $this->assertTrue($this->fixture->columnExists(self::TABLE, 'undoes_id'));
        $this->assertSame(1, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . self::TABLE . "' AND INDEX_NAME = 'undoes_lookup'"
        ));
        $this->assertSame($fileVersion, $this->db->scalar("SELECT version FROM " . self::PLUGIN_ROW . " WHERE id = 'provenance'"));
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertSame('alt', $this->db->scalar('SELECT new_value FROM ' . self::TABLE . " WHERE id = $kept"));
    }

    /** [HAPPY] An entry with undoes_id is written and read back; the target reads as undone. */
    public function testAnUndoEntryMarksItsTargetUndone(): void
    {
        provenance_record_change('photo', $this->imageId, PROVENANCE_HISTORY_FIELD_TAG, null,
            provenance_history_encode_tag(5, 'Kirche'), PROVENANCE_HISTORY_SOURCE_PICTURE_PAGE);
        $targetId = $this->lastId();
        $this->assertGreaterThan($this->baselineId, $targetId);

        $before = $this->rowsById($this->history());
        $this->assertFalse($before[$targetId]['undone']);
        $this->assertNull($before[$targetId]['undoes_id']);

        provenance_record_changes(array(array(
            'object' => 'photo', 'object_id' => $this->imageId, 'field' => PROVENANCE_HISTORY_FIELD_TAG,
            'old' => provenance_history_encode_tag(5, 'Kirche'), 'new' => null,
            'source' => PROVENANCE_HISTORY_SOURCE_UNDO, 'undoes_id' => $targetId,
        )));
        $undoId = $this->lastId();

        $after = $this->rowsById($this->history());
        $this->assertTrue($after[$targetId]['undone']);
        $this->assertSame($targetId, $after[$undoId]['undoes_id']);
        $this->assertFalse($after[$undoId]['undone'], 'an undo entry is not itself undone');
        $this->assertSame(PROVENANCE_HISTORY_SOURCE_UNDO, $after[$undoId]['source']);
    }

    /** [HAPPY] The source column accepts every edit-path source. */
    public function testTheSourceColumnAcceptsTheEditPathSources(): void
    {
        foreach (array(
            PROVENANCE_HISTORY_SOURCE_PICTURE_PAGE, PROVENANCE_HISTORY_SOURCE_ADMIN_PHOTO,
            PROVENANCE_HISTORY_SOURCE_BATCH_MANAGER, PROVENANCE_HISTORY_SOURCE_API, PROVENANCE_HISTORY_SOURCE_UNDO,
        ) as $source)
        {
            provenance_record_change('photo', $this->imageId, PROVENANCE_HISTORY_FIELD_INFO, null, "via $source", $source);
            $this->assertSame($source, $this->db->scalar(
                'SELECT source FROM ' . self::TABLE . ' WHERE id = ' . $this->lastId()
            ));
        }
    }

    /** [NEG] [HAPPY] A guest is refused; a normal and a generic account read a photo and album they see. */
    public function testGetHistoryPerRole(): void
    {
        provenance_record_change('photo', $this->imageId, PROVENANCE_HISTORY_FIELD_INFO, null, 'sichtbar', PROVENANCE_HISTORY_SOURCE_API);

        $guest = new WsClient();
        $this->assertSame('fail', $guest->call('pwg.provenance.getHistory', $this->photoParams())['json']['stat'] ?? null);

        foreach (array(
            array(Config::normalUsername(), Config::normalPassword()),
            array(Config::genericUsername(), Config::genericPassword()),
        ) as $account)
        {
            $client = $this->loggedIn($account[0], $account[1]);
            $res = $client->call('pwg.provenance.getHistory', $this->photoParams());
            $this->assertSame('ok', $res['json']['stat'] ?? null, $account[0] . ': ' . $res['body']);
            $this->assertSame('sichtbar', $res['json']['result']['histories'][0]['new_value']);
            $client->logout();
        }
    }

    /** [NEG] [BVA] A photo in a private album is refused without a grant and read with one. */
    public function testAPrivateAlbumNeedsAGrant(): void
    {
        provenance_record_change('photo', $this->imageId, PROVENANCE_HISTORY_FIELD_INFO, null, 'privat', PROVENANCE_HISTORY_SOURCE_API);
        $normal = $this->loggedIn(Config::normalUsername(), Config::normalPassword());
        $this->assertSame('ok', $normal->call('pwg.provenance.getHistory', $this->photoParams())['json']['stat'] ?? null);

        $this->fixture->makeAlbumPrivate($this->catId);
        $res = $normal->call('pwg.provenance.getHistory', $this->photoParams());
        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertStringNotContainsString('privat', (string)$res['body']);
        $album = $normal->call('pwg.provenance.getHistory', array('object' => 'album', 'object_id' => $this->catId));
        $this->assertSame('fail', $album['json']['stat'] ?? null, $album['body']);

        $this->fixture->grantAlbumTo($this->catId, 'provenance_normal');
        $this->assertSame('ok', $normal->call('pwg.provenance.getHistory', $this->photoParams())['json']['stat'] ?? null);
        $normal->logout();
    }

    /** [NEG] A non-admin never reads exiftool's server detail; an administrator does. */
    public function testAFileErrorIsGenericForANormalAccount(): void
    {
        $message = 'Error opening file - /var/www/html/galleries/x.jpg';
        provenance_record_change('photo', $this->imageId, PROVENANCE_HISTORY_FIELD_FILE_ERROR, null, $message, 'writeback');

        $normal = $this->loggedIn(Config::normalUsername(), Config::normalPassword());
        $body = (string)$normal->call('pwg.provenance.getHistory', $this->photoParams())['body'];
        $normal->logout();
        $this->assertStringNotContainsString('galleries', $body);
        $this->assertStringContainsString('konnte nicht beschrieben werden', $body);

        $this->assertStringContainsString('galleries', (string)$this->webmaster->call('pwg.provenance.getHistory', $this->photoParams())['body']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** The newest row; the recorder writes on its own connection, so insert_id is not ours. */
    private function lastId(): int
    {
        return (int)$this->db->scalar('SELECT MAX(id) FROM ' . self::TABLE);
    }

    private function photoParams(): array
    {
        return array('object' => 'photo', 'object_id' => $this->imageId);
    }

    private function history(): array
    {
        $res = $this->webmaster->call('pwg.provenance.getHistory', $this->photoParams());
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        return $res['json']['result']['histories'];
    }

    private function rowsById(array $rows): array
    {
        $byId = array();
        foreach ($rows as $row)
        {
            $byId[(int)$row['id']] = $row;
        }
        return $byId;
    }

    private function loggedIn(string $username, string $password): WsClient
    {
        $client = new WsClient();
        $client->login($username, $password);
        return $client;
    }

    private function fileVersion(): string
    {
        preg_match('/^Version:\s*(\S+)/m', file_get_contents(PIWIGO_ROOT . 'plugins/provenance/main.inc.php'), $m);
        return $m[1];
    }
}
