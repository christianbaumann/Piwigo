<?php
use PHPUnit\Framework\TestCase;

/**
 * What the fork-local associate_images_to_categories trigger hands its listener
 * (admin/include/functions.php), read through the only listener there is:
 * provenance's inheritance in keep mode.
 *
 * Every case is [ERR]: the oracle is the current implementation, not a
 * requirement. They record which photo-album pairs reach the listener today,
 * so a change to the payload reports itself. They passed on their first run;
 * each was watched go red by breaking the behaviour it records.
 *
 * A pair inserted behind core's back (FixtureBuilder::attachImage()) fires no
 * trigger, so "already linked" is set up without an inheritance of its own, and
 * the album's values are written only afterwards.
 */
final class CoreAssociationTriggerCharacterizationTest extends TestCase
{
    private const HISTORY_TABLE = 'piwigo_provenance_history';

    private const FIRST_ALBUM = array(
        'provenance_physical_album' => 'Erstes Album',
        'provenance_owner' => 'Erste Eigentümerin',
        'provenance_scanned_on' => '2026-01-11',
        'provenance_note' => 'erste Albumnotiz',
        );

    private const SECOND_ALBUM = array(
        'provenance_physical_album' => 'Zweites Album',
        'provenance_owner' => 'Zweiter Eigentümer',
        'provenance_scanned_on' => '2026-02-22',
        'provenance_note' => 'zweite Albumnotiz',
        );

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
        $this->fixture->restore();
        $this->db->query('DELETE FROM ' . self::HISTORY_TABLE . ' WHERE id > ' . $this->baselineHistoryId);
        $this->ws->logout();
    }

    /** [ERR] A call that inserts no pair fires no trigger. */
    public function testACallThatInsertsNoPairPassesNothing(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-trigger-none');
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $album);
        $this->fixture->albumProvenance($album, self::FIRST_ALBUM);

        $this->setCategory(array($image), $album, 'associate');

        $this->assertNull($this->fixture->readImageProvenance($image)['provenance_owner']);
    }

    /** [ERR] When one named photo is new to the album, every named photo is passed. */
    public function testEveryNamedPhotoIsPassedWhenOneOfThemIsNew(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-trigger-photos');
        $linked = $this->fixture->createTestImage()['id'];
        $new = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($linked, $album);
        $this->fixture->albumProvenance($album, self::FIRST_ALBUM);

        $this->setCategory(array($linked, $new), $album, 'associate');

        $this->assertSame(
            self::FIRST_ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($new)['provenance_owner'],
            'anti-vacuity: the new pair must have reached the listener'
        );
        $this->assertSame(
            self::FIRST_ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($linked)['provenance_owner'],
            'the already-linked photo is passed as well'
        );
    }

    /**
     * [ERR] When one named album is new to the photo, every named album is
     * passed, in the order given: the photo takes the album it was already in.
     *
     * Driven through the photo properties form, whose "Linked albums" control
     * hands its whole selection to move_images_to_categories().
     */
    public function testEveryNamedAlbumIsPassedWhenOneOfThemIsNew(): void
    {
        $first = $this->fixture->createTestAlbum('provenance-char-trigger-first');
        $second = $this->fixture->createTestAlbum('provenance-char-trigger-second');
        $image = $this->fixture->createTestImage()['id'];
        $this->fixture->attachImage($image, $first);
        $this->fixture->albumProvenance($first, self::FIRST_ALBUM);
        $this->fixture->albumProvenance($second, self::SECOND_ALBUM);

        $this->savePhotoAlbums($image, array($first, $second));

        $this->assertSame(
            self::FIRST_ALBUM['provenance_owner'],
            $this->fixture->readImageProvenance($image)['provenance_owner']
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

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

    /** Posts the photo properties form with the given album selection. */
    private function savePhotoAlbums(int $imageId, array $albumIds): void
    {
        $res = $this->ws->postPage('/admin.php?page=photo-' . $imageId . '-properties', array(
            'level' => 0,
            'submit' => 1,
            'pwg_token' => $this->ws->token(),
            'associate' => $albumIds,
            ));

        $this->assertSame(200, $res['http_code'], 'the properties screen did not answer');

        $linked = (int)$this->db->scalar(
            'SELECT COUNT(*) FROM `piwigo_image_category` WHERE image_id = ' . $imageId .
            ' AND category_id IN (' . implode(',', $albumIds) . ')'
        );
        $this->assertSame(count($albumIds), $linked, 'anti-vacuity: the save must have linked every selected album');
    }
}
