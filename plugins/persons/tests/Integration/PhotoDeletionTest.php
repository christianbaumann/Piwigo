<?php
use PHPUnit\Framework\TestCase;

/**
 * A deleted photo takes its region rows with it.
 *
 * Core's delete_elements() clears piwigo_image_tag itself, so the mirrored tag
 * assignment goes without help. The region rows are this plugin's own table and
 * core knows nothing about it: without a delete_elements handler they outlive
 * the photo, and every count on the persons admin screen is then wrong by
 * however many photos have ever been deleted.
 *
 * Driven over HTTP through pwg.images.delete rather than by calling the handler
 * in-process: what is under test is as much the registration in main.inc.php as
 * the handler body, and only a real request loads the plugins.
 */
final class PhotoDeletionTest extends TestCase
{
    private const NAME = 'Persons Deletion Jane';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $admin;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Persons deletion fixture');
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->admin = new WsClient();
        $this->admin->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::NAME));
    }

    /** [HAPPY] Deleting the photo leaves no region row behind. */
    public function testDeletingAPhotoRemovesItsRegionRows(): void
    {
        $imageId = (int)$this->image['id'];
        $this->addRegion();

        $this->assertSame(1, $this->regionCount($imageId),
            'anti-vacuity: nothing was indexed, so a deletion could not remove anything');

        $res = $this->admin->call('pwg.images.delete', array(
            'image_id' => $imageId,
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertNull($this->db->scalar('SELECT id FROM piwigo_images WHERE id = ' . $imageId),
            'the photo itself survived, so the deletion never ran');

        $this->assertSame(0, $this->regionCount($imageId));
    }

    /**
     * [NEG] Only the deleted photo's rows go.
     *
     * The handler is handed a list of ids; one that deleted by person, or with
     * no WHERE at all, would pass the test above and empty the whole index.
     */
    public function testDeletingOnePhotoLeavesAnotherPhotosRegionsAlone(): void
    {
        $survivor = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Persons deletion survivor');
        $this->fixture->attachImage((int)$survivor['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->addRegion();
        $this->addRegion((int)$survivor['id']);

        $this->assertSame(1, $this->regionCount((int)$survivor['id']),
            'anti-vacuity: the survivor was never indexed');

        $res = $this->admin->call('pwg.images.delete', array(
            'image_id' => (int)$this->image['id'],
            'pwg_token' => $this->admin->token(),
            ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(1, $this->regionCount((int)$survivor['id']));
    }

    private function addRegion(?int $imageId = null): void
    {
        $res = $this->admin->call('pwg.persons.addRegion', array(
            'image_id' => $imageId ?? (int)$this->image['id'],
            'name' => self::NAME,
            'x' => 0.25, 'y' => 0.25, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->admin->token(),
            ));

        if (($res['json']['stat'] ?? '') !== 'ok')
        {
            throw new RuntimeException('could not seed a region: ' . $res['body']);
        }
    }

    private function regionCount(int $imageId): int
    {
        return (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE image_id = ' . $imageId
        );
    }
}
