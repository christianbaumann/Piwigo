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
 * Two cases recorded the payload naming pairs that already existed. Narrowing
 * the trigger to inserted pairs replaced them with requirement tests in
 * InheritTest: testOnlyThePhotoNewToTheAlbumInherits and
 * testAPhotoWithoutHerkunftTakesTheNewlyLinkedAlbumsValues.
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
}
