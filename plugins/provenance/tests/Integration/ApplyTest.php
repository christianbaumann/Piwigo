<?php
use PHPUnit\Framework\TestCase;

/**
 * The copy-down apply across its real boundaries: pwg.provenance.applyToPhotos
 * over ws.php into MariaDB.
 *
 * Two facts this suite exists to hold down. The photo's own provenance_note is
 * never written by an album operation - the reason the schema carries two note
 * columns at all - and no image file is touched: write-back is a separate,
 * explicit operation (decision C2).
 */
final class ApplyTest extends TestCase
{
    private const METHOD = 'pwg.provenance.applyToPhotos';
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    /** The album values every test applies unless it says otherwise. */
    private const ALBUM = array(
        'provenance_physical_album' => 'Oma Müllers Fotoalbum',
        'provenance_owner' => 'Anna Müller',
        'provenance_scanned_on' => '2026-04-19',
        'provenance_note' => "geliehen von Anna\nRückseiten beschriftet",
        );

    /** The photo's own note, which no apply may ever overwrite. */
    private const PHOTO_NOTE = 'auf der Rückseite: Sommer 1972';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private int $catId;
    /** @var int[] */
    private array $photoIds;
    private int $baselineHistoryId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->recordAllProvenance();

        $this->catId = $this->fixture->anyAlbumId();

        // Asserts the 1:1 photo-album assumption rather than hoping for it.
        $this->photoIds = $this->fixture->photoIdsInAlbum($this->catId);
        $this->assertGreaterThan(
            0,
            count($this->photoIds),
            'anti-vacuity: an album with no photos would make every apply assertion below vacuous'
        );

        $this->fixture->albumProvenance($this->catId, self::ALBUM);
        $this->fixture->clearImageProvenance($this->photoIds);

        $this->baselineHistoryId = (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    protected function tearDown(): void
    {
        $this->fixture->clearImageProvenance($this->photoIds);
        $this->fixture->albumProvenance($this->catId, array());
        $this->fixture->restore();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->ws->logout();
    }

    // ── the copy-down ─────────────────────────────────────────────────────

    /** [HAPPY] The four album-sourced columns land on every photo in the chunk. */
    public function testApplyWritesTheFourAlbumSourcedColumns(): void
    {
        $ids = $this->chunk();

        $res = $this->apply($ids);

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(count($ids), $res['json']['result']['applied']);

        foreach ($ids as $id)
        {
            $this->assertSame(
                array(
                    'provenance_physical_album' => self::ALBUM['provenance_physical_album'],
                    'provenance_owner' => self::ALBUM['provenance_owner'],
                    'provenance_scanned_on' => self::ALBUM['provenance_scanned_on'],
                    'provenance_album_note' => self::ALBUM['provenance_note'],
                    'provenance_note' => null,
                ),
                $this->fixture->readImageProvenance($id),
                "photo $id did not receive the album's provenance"
            );
        }
    }

    /**
     * [NEG] The photo's own note survives an apply untouched.
     *
     * This is the decision the two-note schema exists for: the album's free text
     * lands in provenance_album_note, and what somebody wrote about this one
     * photo is never overwritten by an album-level operation.
     */
    public function testApplyNeverTouchesThePhotosOwnNote(): void
    {
        $ids = $this->chunk();
        foreach ($ids as $id)
        {
            $this->fixture->imageProvenance($id, array('provenance_note' => self::PHOTO_NOTE));
        }

        $this->apply($ids);

        foreach ($ids as $id)
        {
            $photo = $this->fixture->readImageProvenance($id);
            $this->assertSame(self::PHOTO_NOTE, $photo['provenance_note'], "photo $id lost its own note");
            $this->assertSame(self::ALBUM['provenance_note'], $photo['provenance_album_note']);
        }
    }

    /** [ST] A second apply after an album edit overwrites the copied values. */
    public function testSecondApplyOverwritesTheCopiedValues(): void
    {
        $ids = $this->chunk();
        $this->apply($ids);
        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($ids[0])['provenance_owner'],
            'anti-vacuity: the first apply wrote nothing, so the second cannot overwrite it'
        );

        $this->fixture->albumProvenance($this->catId, array_merge(self::ALBUM, array('provenance_owner' => 'Berta Schmidt')));
        $this->apply($ids);

        foreach ($ids as $id)
        {
            $this->assertSame('Berta Schmidt', $this->fixture->readImageProvenance($id)['provenance_owner']);
        }
    }

    /** [BVA] An album field cleared to NULL clears the photo column - to NULL, not to ''. */
    public function testClearingAnAlbumFieldClearsItOnThePhotos(): void
    {
        $ids = $this->chunk();
        $this->apply($ids);
        $this->assertNotNull(
            $this->fixture->readImageProvenance($ids[0])['provenance_owner'],
            'anti-vacuity: nothing was copied down, so nothing can be cleared'
        );

        $this->fixture->albumProvenance($this->catId, array_merge(self::ALBUM, array('provenance_owner' => null)));
        $this->apply($ids);

        foreach ($ids as $id)
        {
            $this->assertNull($this->fixture->readImageProvenance($id)['provenance_owner'], "photo $id kept a cleared value");
        }
    }

    /**
     * [BVA] The bulk path is exercised too.
     *
     * mass_updates() switches from N single statements to a temporary-table join
     * at ten rows, and the two branches build their NULLs differently. A chunk
     * below that threshold would leave the branch the real 76-photo album always
     * takes completely untested.
     */
    public function testTheBulkUpdatePathWritesTheSameValues(): void
    {
        $ids = array_slice($this->photoIds, 0, 12);
        $this->assertGreaterThanOrEqual(10, count($ids), 'anti-vacuity: this chunk does not reach the bulk branch');

        $this->apply($ids);

        foreach ($ids as $id)
        {
            $photo = $this->fixture->readImageProvenance($id);
            $this->assertSame(self::ALBUM['provenance_physical_album'], $photo['provenance_physical_album']);
            $this->assertSame(self::ALBUM['provenance_note'], $photo['provenance_album_note']);
        }
    }

    /** [BVA] An empty chunk is a no-op that succeeds. */
    public function testAnEmptyChunkSucceedsAndWritesNothing(): void
    {
        $res = $this->apply(array());

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(0, $res['json']['result']['applied']);
        $this->assertSame(0, $this->historyCount());
    }

    // ── the history ───────────────────────────────────────────────────────

    /** [DT] A changed field leaves a row; an unchanged one leaves none. */
    public function testHistoryRecordsChangedFieldsOnly(): void
    {
        $ids = $this->chunk();

        $this->apply($ids);
        $this->assertSame(
            4 * count($ids),
            $this->historyCount(),
            'the first apply changes all four fields on every photo'
        );
        $this->assertSame(array('apply'), $this->distinctHistory('source'));
        $this->assertSame(array('photo'), $this->distinctHistory('object'));

        $written = $this->historyCount();
        $this->apply($ids);
        $this->assertSame($written, $this->historyCount(), 'a re-apply of unchanged values must record nothing');

        $mark = $this->lastHistoryId();
        $this->fixture->albumProvenance($this->catId, array_merge(self::ALBUM, array('provenance_owner' => 'Berta Schmidt')));
        $this->apply($ids);

        $this->assertSame(
            $written + count($ids),
            $this->historyCount(),
            'only the one field that really changed may be recorded'
        );
        $this->assertSame(array('provenance_owner'), $this->distinctHistory('field', $mark));
    }

    // ── what apply must not do ────────────────────────────────────────────

    /**
     * [NEG] Applying to photos does not itself touch the files.
     *
     * Write-back is a separate operation, and an apply that quietly rewrote 76
     * image files would be the most expensive defect in this plugin.
     */
    public function testApplyLeavesEveryImageFileUntouched(): void
    {
        $ids = $this->chunk();
        $before = $this->fileStats($ids);
        $this->assertGreaterThan(0, count($before), 'anti-vacuity: no image file was found to watch');

        $this->apply($ids);
        clearstatcache();

        $this->assertSame($before, $this->fileStats($ids));
    }

    // ── the guards ────────────────────────────────────────────────────────

    /** [NEG] A guest cannot apply. */
    public function testGuestIsRefused(): void
    {
        $guest = new WsClient();
        $res = $guest->call(self::METHOD, array(
            'cat_id' => $this->catId,
            'image_ids' => implode(',', $this->chunk()),
            'pwg_token' => 'irrelevant',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertNull($this->fixture->readImageProvenance($this->photoIds[0])['provenance_owner']);
    }

    /** [NEG] An authenticated non-admin is refused too - the only proof the admin gate exists. */
    public function testAuthenticatedNonAdminIsRefused(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());

        $res = $normal->call(self::METHOD, array(
            'cat_id' => $this->catId,
            'image_ids' => implode(',', $this->chunk()),
            'pwg_token' => $normal->token(),
        ));
        $normal->logout();

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertNull($this->fixture->readImageProvenance($this->photoIds[0])['provenance_owner']);
    }

    /** [NEG] A wrong CSRF token is refused with 403. */
    public function testBadTokenIsRefused(): void
    {
        $res = $this->ws->call(self::METHOD, array(
            'cat_id' => $this->catId,
            'image_ids' => implode(',', $this->chunk()),
            'pwg_token' => 'not-the-token',
        ));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(403, $res['json']['err']);
        $this->assertNull($this->fixture->readImageProvenance($this->photoIds[0])['provenance_owner']);
    }

    /** [NEG] An album that exists in no row is refused with 404. */
    public function testUnknownAlbumIsRefused(): void
    {
        $unknown = (int)$this->db->scalar('SELECT MAX(id) + 1000 FROM piwigo_categories');

        $res = $this->apply($this->chunk(), $unknown);

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    /** [NEG] A malformed id list is refused with 400 and nothing is written. */
    public function testMalformedIdListIsRefused(): void
    {
        $res = $this->applyRaw($this->photoIds[0] . ',x');

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(400, $res['json']['err']);
        $this->assertNull($this->fixture->readImageProvenance($this->photoIds[0])['provenance_owner']);
    }

    /**
     * [NEG] A photo that is not in the album is refused, and the rest of the
     * chunk is not applied either: a half-applied chunk is worse than none.
     */
    public function testAPhotoOutsideTheAlbumIsRefused(): void
    {
        $outsider = (int)$this->db->scalar('SELECT MAX(id) + 1000 FROM piwigo_images');

        $res = $this->applyRaw($this->photoIds[0] . ',' . $outsider);

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(400, $res['json']['err']);
        $this->assertNull($this->fixture->readImageProvenance($this->photoIds[0])['provenance_owner']);
    }

    /** [BVA] A chunk one past the ceiling is refused rather than silently split. */
    public function testAChunkPastTheCeilingIsRefused(): void
    {
        $res = $this->applyRaw(implode(',', range(1, PROVENANCE_APPLY_MAX_CHUNK + 1)));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(400, $res['json']['err']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** A small chunk, below the mass_updates() bulk threshold. */
    private function chunk(): array
    {
        return array_slice($this->photoIds, 0, 3);
    }

    private function apply(array $ids, ?int $catId = null): array
    {
        return $this->applyRaw(implode(',', $ids), $catId);
    }

    private function applyRaw(string $imageIds, ?int $catId = null): array
    {
        return $this->ws->call(self::METHOD, array(
            'cat_id' => $catId ?? $this->catId,
            'image_ids' => $imageIds,
            'pwg_token' => $this->ws->token(),
        ));
    }

    /** mtime and size of each photo's file, so a write-back would be visible. */
    private function fileStats(array $ids): array
    {
        $result = $this->db->query(
            'SELECT id, path FROM piwigo_images WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'
        );

        $stats = array();
        while ($row = $result->fetch_assoc())
        {
            $path = PIWIGO_ROOT . ltrim((string)$row['path'], './');
            if (!is_file($path))
            {
                continue;
            }
            clearstatcache(true, $path);
            $stats[(int)$row['id']] = array(filemtime($path), filesize($path));
        }
        ksort($stats);

        return $stats;
    }

    private function lastHistoryId(): int
    {
        return (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM ' . self::HISTORY_TABLE);
    }

    private function historyCount(?int $sinceId = null): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . ($sinceId ?? $this->baselineHistoryId)
        );
    }

    /** The distinct values of one history column among the rows this test wrote. */
    private function distinctHistory(string $column, ?int $sinceId = null): array
    {
        $result = $this->db->query(
            'SELECT DISTINCT `' . $column . '` FROM ' . self::HISTORY_TABLE .
            ' WHERE id > ' . ($sinceId ?? $this->baselineHistoryId) . ' ORDER BY 1'
        );
        $out = array();
        while ($row = $result->fetch_row())
        {
            $out[] = $row[0];
        }
        return $out;
    }
}
