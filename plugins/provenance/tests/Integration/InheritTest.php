<?php
use PHPUnit\Framework\TestCase;

/**
 * A photo that joins a provenance-carrying album afterwards inherits its values.
 *
 * The two core trigger_notify() calls Phase 7 adds are what make this reachable:
 * associate_images_to_categories() covers everything that funnels through it
 * (the API, the Batch Manager, upload), and admin/site_update.php covers the
 * filesystem sync, which inserts its links directly without calling the helper.
 *
 * The rule the whole schema exists for is asserted here too: an album operation
 * never writes the photo's own provenance_note.
 */
final class InheritTest extends TestCase
{
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** The album values a joining photo is expected to inherit. */
    private const ALBUM = array(
        'provenance_physical_album' => 'Opa Baumanns Album',
        'provenance_owner' => 'Christian Baumann',
        'provenance_scanned_on' => '2026-03-07',
        'provenance_note' => "geliehen im März\nzwei Bände",
        );

    /** The photo's own note, which no inheritance may overwrite. */
    private const PHOTO_NOTE = 'Rückseite: Ostern 1968';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $baselineHistoryId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->recordAllProvenance();

        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPhysicalAlbums();
        $this->fixture->restore();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->ws->logout();
    }

    /** [HAPPY] All four album-sourced values land on a photo that joins later. */
    public function testAPhotoAssociatedAfterwardsInheritsAllFourValues(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-happy');
        $this->fixture->albumProvenance($album, self::ALBUM);
        $image = $this->fixture->createTestImage()['id'];

        $this->associate(array($image), $album);

        $actual = $this->fixture->readImageProvenance($image);
        $this->assertSame(self::ALBUM['provenance_physical_album'], $actual['provenance_physical_album']);
        $this->assertSame(self::ALBUM['provenance_owner'], $actual['provenance_owner']);
        $this->assertSame(self::ALBUM['provenance_scanned_on'], $actual['provenance_scanned_on']);
        $this->assertSame(self::ALBUM['provenance_note'], $actual['provenance_album_note']);
    }

    /** [HAPPY] Every inherited value is in the audit trail, attributed to 'inherit'. */
    public function testInheritanceIsRecordedInTheAuditTrail(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-history');
        $this->fixture->albumProvenance($album, self::ALBUM);
        $image = $this->fixture->createTestImage()['id'];

        $this->associate(array($image), $album);

        $fields = $this->newHistoryFields($image, 'inherit');
        $this->assertSame(
            array('provenance_album_note', 'provenance_owner', 'provenance_physical_album', 'provenance_scanned_on'),
            $fields
        );
    }

    /** [NEG] The photo's own note is never touched by inheritance. */
    public function testInheritanceDoesNotOverwriteThePhotosOwnNote(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-note');
        $this->fixture->albumProvenance($album, self::ALBUM);
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->imageProvenance($image, array('provenance_note' => self::PHOTO_NOTE));

        $this->associate(array($image), $album);

        $actual = $this->fixture->readImageProvenance($image);
        $this->assertSame(self::PHOTO_NOTE, $actual['provenance_note'], 'the photo keeps its own note');
        $this->assertSame(
            self::ALBUM['provenance_note'],
            $actual['provenance_album_note'],
            'anti-vacuity: the inheritance must actually have run for the assertion above to mean anything'
        );
    }

    /** [NEG] An album with no provenance writes nothing and logs nothing. */
    public function testAnAlbumWithNoProvenanceWritesNothing(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-empty');
        $this->fixture->albumProvenance($album, array());
        $image = $this->fixture->createTestImage()['id'];

        $this->associate(array($image), $album);

        $actual = $this->fixture->readImageProvenance($image);
        foreach (FixtureBuilder::imageColumns() as $column)
        {
            $this->assertNull($actual[$column], "$column must stay empty");
        }
        $this->assertSame(array(), $this->newHistoryFields($image, 'inherit'));
    }

    /** [ST] A photo moved to a second provenance-carrying album takes the new values. */
    public function testMovingToAnotherAlbumReplacesTheInheritedValues(): void
    {
        $first = $this->fixture->createTestAlbum('provenance-inherit-first');
        $this->fixture->albumProvenance($first, self::ALBUM);
        $second = $this->fixture->createTestAlbum('provenance-inherit-second');
        $this->fixture->albumProvenance($second, array(
            'provenance_physical_album' => 'Tante Idas Kasten',
            'provenance_owner' => 'Ida Schmitz',
            'provenance_scanned_on' => '2026-05-02',
            'provenance_note' => 'Schuhkarton, unsortiert',
            ));
        $image = $this->fixture->createTestImage()['id'];

        $this->associate(array($image), $first);
        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($image)['provenance_owner'],
            'anti-vacuity: the first inheritance must have landed before the move is meaningful'
        );

        $this->setCategory(array($image), $second, 'move');

        $actual = $this->fixture->readImageProvenance($image);
        $this->assertSame('Tante Idas Kasten', $actual['provenance_physical_album']);
        $this->assertSame('Ida Schmitz', $actual['provenance_owner']);
        $this->assertSame('2026-05-02', $actual['provenance_scanned_on']);
        $this->assertSame('Schuhkarton, unsortiert', $actual['provenance_album_note']);
    }

    /** [HAPPY] A photo discovered by the filesystem sync inherits its storage album's values. */
    public function testAPhotoDiscoveredByFilesystemSyncInherits(): void
    {
        $album = $this->fixture->createPhysicalAlbum('provenance-inherit-sync-' . bin2hex(random_bytes(4)));
        $this->fixture->albumProvenance($album['id'], self::ALBUM);
        $file = $this->fixture->placePhotoInPhysicalAlbum($album['id']);

        $this->sync($album['id']);

        $id = $this->db->scalar(
            "SELECT id FROM `piwigo_images` WHERE storage_category_id = {$album['id']} AND file = '" .
            $this->db->escape($file) . "'"
        );
        $this->assertNotNull($id, 'anti-vacuity: the sync did not insert the placed photo');

        $actual = $this->fixture->readImageProvenance((int)$id);
        $this->assertSame(self::ALBUM['provenance_physical_album'], $actual['provenance_physical_album']);
        $this->assertSame(self::ALBUM['provenance_owner'], $actual['provenance_owner']);
        $this->assertSame(self::ALBUM['provenance_scanned_on'], $actual['provenance_scanned_on']);
        $this->assertSame(self::ALBUM['provenance_note'], $actual['provenance_album_note']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function associate(array $imageIds, int $catId): void
    {
        $this->setCategory($imageIds, $catId, 'associate');
    }

    private function setCategory(array $imageIds, int $catId, string $action): void
    {
        $res = $this->ws->call('pwg.images.setCategory', array(
            'image_id' => $imageIds,
            'category_id' => $catId,
            'action' => $action,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** Drives admin/site_update.php over one album only, never the whole gallery. */
    private function sync(int $catId): void
    {
        $res = $this->ws->postPage('/admin.php?page=site_update&site=1', array(
            'sync' => 'files',
            'cat' => $catId,
            'subcats-included' => '1',
            'privacy_level' => '0',
            'sync_meta' => '0',
            'display_info' => '0',
            'simulate' => '0',
            'submit' => 'Submit',
            ));

        $this->assertSame(200, $res['http_code'], 'the synchronization page did not answer');
    }

    /** The fields this test's own history rows name, sorted so the assertion is stable. */
    private function newHistoryFields(int $imageId, string $source): array
    {
        $result = $this->db->query(
            'SELECT field FROM ' . self::HISTORY_TABLE .
            " WHERE id > {$this->baselineHistoryId} AND object = 'photo' AND object_id = $imageId" .
            " AND source = '" . $this->db->escape($source) . "'"
        );

        $fields = array();
        while ($row = $result->fetch_assoc())
        {
            $fields[] = $row['field'];
        }
        sort($fields);

        return $fields;
    }
}
