<?php
use PHPUnit\Framework\TestCase;

/**
 * The per-image visibility gate: a photo the caller is not allowed to see is
 * not a photo they may read or write regions on.
 *
 * This is the check decision 0005 left open for typetags, so there is no
 * precedent in the repo to characterise - every assertion here is against the
 * requirement in the plan, not against existing behaviour.
 */
final class VisibilityTest extends TestCase
{
    private const JANE = 'Persons Visibility Jane';

    /** An id far past anything this install holds, checked in setUp. */
    private int $absentImageId;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $hidden;
    private array $visible;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::boot();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->hidden = $this->fixture->createTestImage();
        $this->visible = $this->fixture->createTestImage();

        $private = $this->fixture->createTestAlbum('Persons private fixture', 'private');
        $public = $this->fixture->createTestAlbum('Persons public fixture');
        $this->fixture->attachImage((int)$this->hidden['id'], $private);
        $this->fixture->attachImage((int)$this->visible['id'], $public);

        // The gate reads $user['forbidden_categories'], which is cached per user.
        // Without this the private album would still be missing from the cached
        // list and the [NEG] case below would pass for the wrong reason.
        $this->fixture->invalidateUserCache();

        $this->absentImageId = (int)$this->db->scalar('SELECT MAX(id) + 1000 FROM piwigo_images');

        $this->ws = new WsClient();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::JANE));
        $this->fixture->invalidateUserCache();
    }

    /** [HAPPY] The same account succeeds on a photo it is allowed to see. */
    public function testANormalUserReadsRegionsOfAVisibleImage(): void
    {
        $res = $this->ws->call('pwg.persons.getRegions', array('image_id' => $this->visible['id']));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame((int)$this->visible['id'], (int)$res['json']['result']['image_id']);
    }

    /** [NEG] A photo in a private album the user has no access to answers 404. */
    public function testANormalUserGets404ForAnImageInAForbiddenAlbum(): void
    {
        $res = $this->ws->call('pwg.persons.getRegions', array('image_id' => $this->hidden['id']));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(404, (int)$res['json']['err']);
    }

    /** [NEG] And so does a write to it - the gate is not read-only. */
    public function testANormalUserCannotWriteARegionOnAForbiddenImage(): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->hidden['id'],
            'name' => self::JANE,
            'x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame(404, (int)$res['json']['err'], $res['body']);
        $this->assertNull(
            $this->db->scalar("SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::JANE) . "'"),
            'a refused write still created the person'
            );
    }

    /**
     * [NEG] The refusal says nothing about whether the photo exists.
     *
     * A 403 on a hidden photo and a 404 on an absent one would let anyone
     * enumerate the gallery. Faces are personal data, so the two answers are
     * deliberately indistinguishable - code and message both.
     */
    public function testTheRefusalDoesNotRevealWhetherTheImageExists(): void
    {
        $forbidden = $this->ws->call('pwg.persons.getRegions', array('image_id' => $this->hidden['id']));
        $absent = $this->ws->call('pwg.persons.getRegions', array('image_id' => $this->absentImageId));

        $this->assertSame('fail', $forbidden['json']['stat'], $forbidden['body']);
        $this->assertSame('fail', $absent['json']['stat'], $absent['body']);
        $this->assertSame((int)$forbidden['json']['err'], (int)$absent['json']['err']);
        $this->assertSame($forbidden['json']['message'], $absent['json']['message']);
    }

    /** [NEG] A guest gets no further than the first gate. */
    public function testAGuestIsRejectedBeforeTheVisibilityCheck(): void
    {
        $guest = new WsClient();
        $res = $guest->call('pwg.persons.getRegions', array('image_id' => $this->visible['id']));

        $this->assertSame(401, (int)$res['json']['err'], $res['body']);
    }
}
