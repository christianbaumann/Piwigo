<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for two core web service answers task 36 changes:
 * pwg.categories.getList (include/ws_functions/pwg.categories.php, the album
 * thumbnail block) and pwg.images.setInfo (include/ws_functions/pwg.images.php).
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. They report a change; they prove nothing correct.
 *
 * They land and pass before the change, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was watched
 * go red by breaking the behaviour it claims to watch.
 *
 * Two cases record the defects themselves (the hidden id in getList, a level
 * outside available_permission_levels stored by setInfo). They are expected to
 * go red when the change lands and are replaced by the requirement tests in
 * CoreApiAnswersTest.
 */
final class CoreApiAnswersCharacterizationTest extends TestCase
{
    private const HIDDEN_LEVEL = 8;

    private Db $db;
    private WsClient $admin;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->admin = new WsClient();
        $this->admin->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->db->query("UPDATE piwigo_user_cache SET need_update = 'true'");
        $this->db->query('DELETE FROM piwigo_user_cache_categories WHERE cat_id NOT IN (SELECT id FROM piwigo_categories)');
        $this->admin->logout();
    }

    /** [ERR] [HAPPY] A representative photo the viewer may see is returned as it is, and its thumbnail follows. */
    public function testAVisibleRepresentativeIsReturnedWithItsThumbnail(): void
    {
        $f = $this->albumWithRepresentative(false);

        $cat = $this->listedAs($this->normal(), $f['album']);

        $this->assertSame((string)$f['hidden'], (string)$cat['representative_picture_id']);
        $this->assertStringContainsString(basename($f['hiddenFile'], '.png'), $cat['tn_url']);
    }

    /** [ERR] [ST] Once the representative is above the viewer's level, the thumbnail is the visible photo's. */
    public function testAHiddenRepresentativeIsReplacedInTheThumbnail(): void
    {
        $f = $this->albumWithRepresentative(true);

        $cat = $this->listedAs($this->normal(), $f['album']);

        $this->assertStringContainsString(basename($f['visibleFile'], '.png'), $cat['tn_url']);
        $this->assertStringNotContainsString(basename($f['hiddenFile'], '.png'), $cat['tn_url']);
    }

    /** [ERR] [ST] DEFECT, recorded: the substitute is used for the thumbnail, but the hidden photo's id is what is returned. */
    public function testTheHiddenRepresentativesIdIsStillReturned(): void
    {
        $f = $this->albumWithRepresentative(true);

        $cat = $this->listedAs($this->normal(), $f['album']);

        $this->assertSame((string)$f['hidden'], (string)$cat['representative_picture_id']);
    }

    /** [ERR] [HAPPY] setInfo with a level the install offers stores it. */
    public function testSetInfoStoresAnAvailableLevel(): void
    {
        $id = $this->fixture->createTestImage()['id'];

        $answer = $this->admin->call('pwg.images.setInfo', array('image_id' => $id, 'level' => 4));

        $this->assertSame('ok', $answer['json']['stat'] ?? null, $answer['body']);
        $this->assertSame('4', (string)$this->db->scalar("SELECT level FROM piwigo_images WHERE id = $id"));
    }

    /** [ERR] [BVA] DEFECT, recorded: a level between two offered ones (3) is accepted and stored. */
    public function testSetInfoStoresALevelOutsideTheAvailableOnes(): void
    {
        $id = $this->fixture->createTestImage()['id'];

        $answer = $this->admin->call('pwg.images.setInfo', array('image_id' => $id, 'level' => 3));

        $this->assertSame('ok', $answer['json']['stat'] ?? null, $answer['body']);
        $this->assertSame('3', (string)$this->db->scalar("SELECT level FROM piwigo_images WHERE id = $id"));
    }

    /** [ERR] [ECP] A call without a level leaves the stored level alone. */
    public function testSetInfoWithoutALevelKeepsTheStoredOne(): void
    {
        $id = $this->fixture->createTestImage()['id'];
        $this->db->query("UPDATE piwigo_images SET level = 2 WHERE id = $id");

        $answer = $this->admin->call('pwg.images.setInfo', array('image_id' => $id, 'name' => 'provenance-char-name'));

        $this->assertSame('ok', $answer['json']['stat'] ?? null, $answer['body']);
        $this->assertSame('2', (string)$this->db->scalar("SELECT level FROM piwigo_images WHERE id = $id"));
    }

    /** [ERR] [BVA] pwg.images.setPrivacyLevel, the method setInfo should agree with, refuses 3. */
    public function testSetPrivacyLevelRefusesALevelOutsideTheAvailableOnes(): void
    {
        $id = $this->fixture->createTestImage()['id'];

        $answer = $this->admin->call('pwg.images.setPrivacyLevel', array('image_id' => $id, 'level' => 3));

        $this->assertSame('fail', $answer['json']['stat'] ?? null, $answer['body']);
        $this->assertSame('0', (string)$this->db->scalar("SELECT level FROM piwigo_images WHERE id = $id"));
    }

    /**
     * An album with a visible photo and a second one as its representative. The
     * second is raised above a normal account's level through setPrivacyLevel
     * when $hide, as an administrator would, which invalidates the user cache.
     *
     * @return array{album:int,hidden:int,visible:int,hiddenFile:string,visibleFile:string}
     */
    private function albumWithRepresentative(bool $hide): array
    {
        $album = $this->fixture->createTestAlbum('provenance-char-api-' . bin2hex(random_bytes(4)));
        $visible = $this->fixture->createTestImage();
        $other = $this->fixture->createTestImage();
        $this->fixture->attachImage($visible['id'], $album);
        $this->fixture->attachImage($other['id'], $album);
        $this->db->query("UPDATE piwigo_categories SET representative_picture_id = {$other['id']} WHERE id = $album");
        $this->db->query("UPDATE piwigo_user_cache SET need_update = 'true'");

        if ($hide)
        {
            $answer = $this->admin->call('pwg.images.setPrivacyLevel', array(
                'image_id' => $other['id'],
                'level' => self::HIDDEN_LEVEL,
            ));
            $this->assertSame('ok', $answer['json']['stat'] ?? null, $answer['body']);
        }
        $this->assertSame(
            $hide ? (string)self::HIDDEN_LEVEL : '0',
            (string)$this->db->scalar("SELECT level FROM piwigo_images WHERE id = {$other['id']}"),
            'anti-vacuity: the representative has the level the case needs'
        );

        return array(
            'album' => $album,
            'hidden' => $other['id'],
            'visible' => $visible['id'],
            'hiddenFile' => $other['file'],
            'visibleFile' => $visible['file'],
        );
    }

    private function normal(): WsClient
    {
        $client = new WsClient();
        $client->login(Config::normalUsername(), Config::normalPassword());

        return $client;
    }

    /** @return array the album's entry in pwg.categories.getList as this client sees it */
    private function listedAs(WsClient $client, int $album): array
    {
        $answer = $client->call('pwg.categories.getList', array(
            'cat_id' => $album,
            'recursive' => 'true',
            'thumbnail_size' => 'square',
        ));
        $this->assertSame('ok', $answer['json']['stat'] ?? null, $answer['body']);
        foreach ($answer['json']['result']['categories'] as $cat)
        {
            if ((int)$cat['id'] === $album)
            {
                return $cat;
            }
        }
        $this->fail("album $album is not in the list: " . $answer['body']);
    }
}
