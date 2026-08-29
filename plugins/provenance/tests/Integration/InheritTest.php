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

    /** The destination album of every move case, so replace has something to take. */
    private const SECOND_ALBUM = array(
        'provenance_physical_album' => 'Tante Idas Kasten',
        'provenance_owner' => 'Ida Schmitz',
        'provenance_scanned_on' => '2026-05-02',
        'provenance_note' => 'Schuhkarton, unsortiert',
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

    /**
     * [ST] [DT] An unattended move leaves the provenance the photo already has.
     *
     * Replaces testMovingToAnotherAlbumReplacesTheInheritedValues, which
     * recorded the Phase 7 behaviour. Phase 9 deliberately reverses it: a move
     * carries no statement about where a scan came from, so it must not rewrite
     * one. The destination album's values arrive when an administrator asks for
     * them - through apply, or through the move prompt's replace.
     */
    public function testAnUnattendedMoveKeepsTheProvenanceThePhotoAlreadyHas(): void
    {
        list($image, $second) = $this->photoMovedFromOneAlbumToAnother(null);

        $actual = $this->fixture->readImageProvenance($image);
        $this->assertSame(self::ALBUM['provenance_physical_album'], $actual['provenance_physical_album']);
        $this->assertSame(self::ALBUM['provenance_owner'], $actual['provenance_owner']);
        $this->assertSame(self::ALBUM['provenance_scanned_on'], $actual['provenance_scanned_on']);
        $this->assertSame(self::ALBUM['provenance_note'], $actual['provenance_album_note']);
        $this->assertSame(array(), $this->newHistoryFields($image, PROVENANCE_HISTORY_SOURCE_MOVE));
    }

    /** [ST] An explicit keep does the same thing as sending nothing. */
    public function testAnExplicitKeepBehavesLikeNoParameterAtAll(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother(PROVENANCE_MODE_KEEP);

        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($image)['provenance_owner']
        );
    }

    /** [ST] Replace takes the destination album's values. */
    public function testAMoveWithReplaceTakesTheDestinationAlbumsValues(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother(PROVENANCE_MODE_REPLACE);

        $actual = $this->fixture->readImageProvenance($image);
        $this->assertSame(self::SECOND_ALBUM['provenance_physical_album'], $actual['provenance_physical_album']);
        $this->assertSame(self::SECOND_ALBUM['provenance_owner'], $actual['provenance_owner']);
        $this->assertSame(self::SECOND_ALBUM['provenance_scanned_on'], $actual['provenance_scanned_on']);
        $this->assertSame(self::SECOND_ALBUM['provenance_note'], $actual['provenance_album_note']);
    }

    /** [ST] Clear empties the four album-sourced columns. */
    public function testAMoveWithClearEmptiesTheFourAlbumSourcedColumns(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother(PROVENANCE_MODE_CLEAR);

        $actual = $this->fixture->readImageProvenance($image);
        foreach (provenance_copy_down_map() as $column)
        {
            $this->assertNull($actual[$column], "$column must have been cleared");
        }
    }

    /** [NEG] Clear never reaches the photo's own note. */
    public function testAMoveWithClearLeavesThePhotosOwnNote(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother(PROVENANCE_MODE_CLEAR, self::PHOTO_NOTE);

        $this->assertSame(
            self::PHOTO_NOTE,
            $this->fixture->readImageProvenance($image)['provenance_note']
        );
    }

    /** [DT] Clear is attributed to the move path, not to inheritance. */
    public function testAMoveWithClearIsRecordedAgainstTheMoveSource(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother(PROVENANCE_MODE_CLEAR);

        $this->assertSame(
            array('provenance_album_note', 'provenance_owner', 'provenance_physical_album', 'provenance_scanned_on'),
            $this->newHistoryFields($image, PROVENANCE_HISTORY_SOURCE_MOVE)
        );
    }

    /** [DT] Replace is attributed to the move path too. */
    public function testAMoveWithReplaceIsRecordedAgainstTheMoveSource(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother(PROVENANCE_MODE_REPLACE);

        $this->assertSame(
            array('provenance_album_note', 'provenance_owner', 'provenance_physical_album', 'provenance_scanned_on'),
            $this->newHistoryFields($image, PROVENANCE_HISTORY_SOURCE_MOVE)
        );
    }

    /**
     * [DT] Keep still fills a photo that carries no provenance at all.
     *
     * Keep is about not overwriting, not about not writing: a photo joining its
     * first provenance-carrying album has nothing to protect.
     */
    public function testKeepStillFillsAPhotoThatCarriesNoProvenance(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-keep-empty');
        $this->fixture->albumProvenance($album, self::ALBUM);
        $image = $this->fixture->createTestImage()['id'];

        $this->setCategory(array($image), $album, 'move', PROVENANCE_MODE_KEEP);

        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($image)['provenance_owner']
        );
    }

    /**
     * [NEG] A photo carrying only one of the four is still carrying provenance.
     *
     * Keep leaves it whole rather than filling the gaps from a different album:
     * a half-and-half record would say a scan came from two places at once.
     */
    public function testKeepDoesNotFillTheGapsOfAPartiallyFilledPhoto(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-keep-partial');
        $this->fixture->albumProvenance($album, self::ALBUM);
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->imageProvenance($image, array('provenance_owner' => 'Ida Schmitz'));

        $this->setCategory(array($image), $album, 'move', PROVENANCE_MODE_KEEP);

        $actual = $this->fixture->readImageProvenance($image);
        $this->assertSame('Ida Schmitz', $actual['provenance_owner']);
        $this->assertNull($actual['provenance_physical_album'], 'the gap must not be filled from another album');
    }

    /** [NEG] A value the resolver cannot use falls back to keep, never to clear. */
    public function testAnUnknownModeFallsBackToKeep(): void
    {
        list($image) = $this->photoMovedFromOneAlbumToAnother('incinerate');

        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($image)['provenance_owner']
        );
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

    // ── the upload path ───────────────────────────────────────────────────

    /**
     * [HAPPY] A photo uploaded into the album arrives with its values.
     *
     * The path the administrator actually uses. It reaches
     * associate_images_to_categories() twice over: directly when the lounge is
     * off, and through empty_lounge() when it is on - which is the case on this
     * install, and the reason the sequence below ends with uploadCompleted.
     */
    public function testAnUploadedPhotoInheritsTheAlbumsProvenance(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-inherit-upload');
        $this->fixture->albumProvenance($album, self::ALBUM);

        $id = $this->upload($album);
        $this->uploadCompleted($id, $album);

        $actual = $this->fixture->readImageProvenance($id);
        $this->assertSame(self::ALBUM['provenance_physical_album'], $actual['provenance_physical_album']);
        $this->assertSame(self::ALBUM['provenance_owner'], $actual['provenance_owner']);
        $this->assertSame(self::ALBUM['provenance_scanned_on'], $actual['provenance_scanned_on']);
        $this->assertSame(self::ALBUM['provenance_note'], $actual['provenance_album_note']);
    }

    /**
     * [ST] With the lounge on, the values arrive when the lounge is emptied.
     *
     * Not a detail: an upload does not associate the photo at all while the
     * lounge holds it, so a handler hung on the upload rather than on the
     * association would find no album and write nothing. This records where in
     * the sequence the values really appear.
     */
    public function testWithTheLoungeOnTheValuesArriveWhenTheLoungeIsEmptied(): void
    {
        if ((string)$this->db->scalar(
            "SELECT value FROM `piwigo_config` WHERE param = 'lounge_active'"
        ) !== 'true')
        {
            $this->markTestSkipped('the lounge is off on this install, so an upload associates immediately');
        }

        $album = $this->fixture->createTestAlbum('provenance-inherit-lounge');
        $this->fixture->albumProvenance($album, self::ALBUM);

        $id = $this->upload($album);

        $this->assertNull(
            $this->fixture->readImageProvenance($id)['provenance_owner'],
            'the photo is still in the lounge, so it is in no album and inherits nothing yet'
        );

        $this->uploadCompleted($id, $album);

        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($id)['provenance_owner'],
            'emptying the lounge associates the photo, which is what makes it inherit'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The arrangement every move case shares: a photo carrying the first
     * album's provenance, moved into a second album that carries its own.
     *
     * Asserts the first inheritance landed before the move, so a case that
     * checks what survived the move cannot pass over a photo that never had
     * anything to lose.
     *
     * @param string|null $mode sent as the move-mode parameter, or omitted
     * @param string|null $photoNote the photo's own note, set before the move
     * @return array image id, destination album id
     */
    private function photoMovedFromOneAlbumToAnother(?string $mode, ?string $photoNote = null): array
    {
        $first = $this->fixture->createTestAlbum('provenance-inherit-first');
        $this->fixture->albumProvenance($first, self::ALBUM);
        $second = $this->fixture->createTestAlbum('provenance-inherit-second');
        $this->fixture->albumProvenance($second, self::SECOND_ALBUM);
        $image = $this->fixture->createTestImage()['id'];

        $this->associate(array($image), $first);
        $this->assertSame(
            self::ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($image)['provenance_owner'],
            'anti-vacuity: the first inheritance must have landed before the move is meaningful'
        );

        if ($photoNote !== null)
        {
            $this->fixture->imageProvenance($image, array('provenance_note' => $photoNote));
        }

        $this->setCategory(array($image), $second, 'move', $mode);

        return array($image, $second);
    }

    private function associate(array $imageIds, int $catId): void
    {
        $this->setCategory($imageIds, $catId, 'associate');
    }

    private function setCategory(array $imageIds, int $catId, string $action, ?string $mode = null): void
    {
        $params = array(
            'image_id' => $imageIds,
            'category_id' => $catId,
            'action' => $action,
            'pwg_token' => $this->ws->token(),
            );

        // pwg.images.setCategory is a core method the plugin cannot add a
        // parameter to; the mode rides along in the same POST and the web
        // service layer ignores what it does not declare.
        if ($mode !== null)
        {
            $params[PROVENANCE_MOVE_MODE_PARAM] = $mode;
        }

        $res = $this->ws->call('pwg.images.setCategory', $params);

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

    /**
     * Uploads one real photo into the album and hands back its new id.
     *
     * The file posted is a copy this fixture made, so nothing in the collection
     * is read or moved. The row the upload creates is adopted, so teardown
     * removes it and its file like any other fixture photo.
     */
    private function upload(int $catId): int
    {
        $source = $this->fixture->createTestImage();

        $res = $this->ws->upload('pwg.images.upload', $source['file'], 'file', array(
            'name' => 'provenance-upload-' . bin2hex(random_bytes(4)) . '.png',
            'category' => $catId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $id = (int)($res['json']['result']['image_id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the upload returned no image id');
        $this->fixture->adoptImage($id);

        return $id;
    }

    /** What the upload screen sends once its queue is done; this is what empties the lounge. */
    private function uploadCompleted(int $imageId, int $catId): void
    {
        $res = $this->ws->call('pwg.images.uploadCompleted', array(
            'image_id' => array($imageId),
            'category_id' => $catId,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
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
