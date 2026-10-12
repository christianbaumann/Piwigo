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

    /** On the visible photo only: proves a list that leaves out JANE is not empty. */
    private const JOHN = 'Persons Visibility John';

    /** The names start with this; the list's search narrows to the fixtures by it. */
    private const PREFIX = 'Persons Visibility ';

    /** A rendered page shorter than this is an error page or a redirect, not an admin screen. */
    private const MIN_PAGE_BYTES = 2000;

    /** Seeded region boxes need some size; these are not under test. */
    private const BOX = array('x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2);

    /** An id far past anything this install holds, checked in setUp. */
    private int $absentImageId;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $hidden;
    private array $visible;
    private int $privateAlbum;
    private int $publicAlbum;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        PiwigoRuntime::loadPlugin();

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->hidden = $this->fixture->createTestImage();
        $this->visible = $this->fixture->createTestImage();

        $this->privateAlbum = $this->fixture->createTestAlbum('Persons private fixture', 'private');
        $this->publicAlbum = $this->fixture->createTestAlbum('Persons public fixture');
        $this->fixture->attachImage((int)$this->hidden['id'], $this->privateAlbum);
        $this->fixture->attachImage((int)$this->visible['id'], $this->publicAlbum);

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
        $this->fixture->destroyPersons(array(self::JANE, self::JOHN));
        $this->fixture->invalidateUserCache();
        PiwigoRuntime::resetRequestCaches();
    }

    /** [HAPPY] The same account succeeds on a photo it is allowed to see. */
    public function testANormalUserReadsRegionsOfAVisibleImage(): void
    {
        $res = $this->ws->call('pwg.persons.getRegions', array('image_id' => $this->visible['id']));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame((int)$this->visible['id'], (int)$res['json']['result']['image_id']);
    }

    /**
     * [NEG] [ECP] A photo in a private album the account has no access to
     * answers 404 - to a normal and a generic user, and to an administrator,
     * whom core's album rule binds too (decision 0061).
     */
    #[PHPUnit\Framework\Attributes\DataProvider('ungrantedRoles')]
    public function testAnUngrantedAccountGets404ForAnImageInAForbiddenAlbum(string $role): void
    {
        $res = $this->as($role)->call('pwg.persons.getRegions', array('image_id' => $this->hidden['id']));

        $this->assertSame('fail', $res['json']['stat'], $res['body']);
        $this->assertSame(404, (int)$res['json']['err']);
    }

    /** [NEG] [ECP] And so does a write to it - the gate is not read-only. */
    #[PHPUnit\Framework\Attributes\DataProvider('ungrantedRoles')]
    public function testAnUngrantedAccountCannotWriteARegionOnAForbiddenImage(string $role): void
    {
        $ws = $this->as($role);
        $res = $ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->hidden['id'],
            'name' => self::JANE,
            'x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $ws->token(),
            ));

        $this->assertSame(404, (int)$res['json']['err'], $res['body']);
        $this->assertNull(
            $this->db->scalar("SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::JANE) . "'"),
            'a refused write still created the person'
            );
    }

    /**
     * [NEG] [ECP] Deleting a region on a hidden photo answers like an unknown region,
     * not with the 403 of a region the caller did not draw (decision 0065):
     * that 403 would confirm the region and its photo exist.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('ungrantedRoles')]
    public function testDeletingARegionOnAForbiddenImageAnswersLikeAnUnknownOne(string $role): void
    {
        $this->seed($this->hidden, self::JANE);
        $regionId = (int)$this->db->scalar('SELECT id FROM piwigo_person_region WHERE image_id = ' . (int)$this->hidden['id']);
        $this->assertGreaterThan(0, $regionId, 'anti-vacuity: the hidden photo carries no region');

        $ws = $this->as($role);
        $forbidden = $ws->call('pwg.persons.deleteRegion', array('region_id' => $regionId, 'pwg_token' => $ws->token()));
        $absent = $ws->call('pwg.persons.deleteRegion', array(
            'region_id' => (int)$this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1000 FROM piwigo_person_region'),
            'pwg_token' => $ws->token(),
            ));

        $this->assertSame(404, (int)($forbidden['json']['err'] ?? 0), $forbidden['body']);
        $this->assertSame($absent['json']['message'] ?? null, $forbidden['json']['message'] ?? null);
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

    /**
     * [NEG] [ECP] A person whose only region is on a photo the caller cannot see is
     * not in the list. Without image_id, the call the finding was about.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('ungrantedRoles')]
    public function testAPersonOnlyOnAHiddenPhotoIsNotListed(string $role): void
    {
        $this->seedJaneHiddenAndJohnVisible();

        $names = $this->listedNames($this->as($role), array());

        $this->assertContains(self::JOHN, $names, 'anti-vacuity: the person on the visible photo is missing too');
        $this->assertNotContains(self::JANE, $names);
    }

    /**
     * [NEG] The picker's call, with image_id, leaves them out as well. It is the
     * only call the plugin's own page makes, so a filter on the bare call alone
     * would leave the same names one parameter away.
     */
    public function testThePickerDoesNotOfferAPersonOnlyOnAHiddenPhoto(): void
    {
        $this->seedJaneHiddenAndJohnVisible();
        $untagged = $this->untaggedVisibleImage();

        $names = $this->listedNames($this->ws, array('image_id' => $untagged));

        $this->assertContains(self::JOHN, $names, 'anti-vacuity: the picker offers nobody at all');
        $this->assertNotContains(self::JANE, $names);
    }

    /**
     * [ECP] A person with no region left is on no hidden photo, so the list
     * still offers them: the picker's "tagged elsewhere, removed again" state.
     */
    public function testAPersonWithNoRegionLeftIsStillListed(): void
    {
        $this->seedJaneHiddenAndJohnVisible();
        $johnId = (int)$this->db->scalar(
            "SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::JOHN) . "'");
        $this->db->query('DELETE FROM piwigo_person_region WHERE person_id = ' . $johnId);
        $this->assertSame(0, (int)$this->db->scalar(
            'SELECT COUNT(*) FROM piwigo_person_region WHERE person_id = ' . $johnId),
            'precondition: JOHN must have no region left');

        $names = $this->listedNames($this->ws, array());

        $this->assertContains(self::JOHN, $names);
        $this->assertNotContains(self::JANE, $names);
    }

    /**
     * [ST] Granting the account the private album puts the person in the list
     * and in the picker: the filter is the album permission, nothing else.
     */
    public function testAPersonOnAHiddenPhotoIsListedOnceTheAlbumIsGranted(): void
    {
        $this->seedJaneHiddenAndJohnVisible();
        $untagged = $this->untaggedVisibleImage();
        $this->assertNotContains(self::JANE, $this->listedNames($this->ws, array()),
            'precondition: JANE must start out hidden, or the grant proves nothing');

        $this->fixture->grantAlbumTo($this->privateAlbum, Config::normalUsername());
        $this->fixture->invalidateUserCache();

        $this->assertContains(self::JANE, $this->listedNames($this->ws, array()));
        $this->assertContains(self::JANE, $this->listedNames($this->ws, array('image_id' => $untagged)));
    }

    /**
     * [NEG] An administrator without a grant to the private album gets no
     * regions on the admin tagging screen for its photo, only a message. The
     * region methods would answer 404 on every save there.
     */
    public function testTheAdminTaggingScreenShowsNoRegionsOfAPhotoTheAdministratorCannotSee(): void
    {
        $this->seedJaneHiddenAndJohnVisible();
        $admin = $this->administrator();

        $body = $this->tagScreen($admin, (int)$this->hidden['id']);

        $this->assertStringContainsString('class="errors"', $body);
        $this->assertStringNotContainsString('id="persons-stage"', $body);
        $this->assertStringNotContainsString(self::JANE, $body);
    }

    /**
     * [ST] The same administrator, granted the album, gets the screen with the
     * region on it: the refusal above is the album permission, not a broken screen.
     */
    public function testTheAdminTaggingScreenShowsTheRegionsOnceTheAlbumIsGranted(): void
    {
        $this->seedJaneHiddenAndJohnVisible();
        $admin = $this->administrator();
        $this->fixture->grantAlbumTo($this->privateAlbum, Config::adminUsername());
        $this->fixture->invalidateUserCache();
        $this->rebuildPermissions($admin);

        $body = $this->tagScreen($admin, (int)$this->hidden['id']);

        $this->assertStringContainsString('id="persons-stage"', $body);
        $this->assertStringContainsString(self::JANE, $body);
        $this->assertStringNotContainsString('class="errors"', $body);
    }

    /** Every logged-in status without a grant to the private album. */
    public static function ungrantedRoles(): array
    {
        return array('normal' => array('normal'), 'generic' => array('generic'), 'administrator' => array('admin'));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** The normal client of setUp, or a freshly logged-in generic or administrator one. */
    private function as(string $role): WsClient
    {
        if ($role === 'normal')
        {
            return $this->ws;
        }

        if ($role === 'admin')
        {
            return $this->administrator();
        }

        $client = new WsClient();
        $client->login(Config::genericUsername(), Config::genericPassword());

        return $client;
    }

    /** JANE on the hidden photo, JOHN on the visible one, written into the files and indexed. */
    private function seedJaneHiddenAndJohnVisible(): void
    {
        $this->seed($this->hidden, self::JANE);
        $this->seed($this->visible, self::JOHN);
    }

    /** Writes one face into the file with a plain exiftool call, then indexes it. */
    private function seed(array $image, string $name): void
    {
        $this->fixture->writeRegionsWithExiftool($image, array(self::BOX + array('name' => $name)), null, null);

        $outcome = persons_reindex_image((int)$image['id'], $image['file']);
        if (!$outcome['ok'] || $outcome['regions'] !== 1)
        {
            throw new RuntimeException("fixture could not index $name: " . json_encode($outcome));
        }
    }

    /** A second public photo with nobody on it, so the picker excludes no fixture. */
    private function untaggedVisibleImage(): int
    {
        $image = $this->fixture->createTestImage();
        $this->fixture->attachImage((int)$image['id'], $this->publicAlbum);
        $this->fixture->invalidateUserCache();

        return (int)$image['id'];
    }

    private function administrator(): WsClient
    {
        $admin = new WsClient();
        $admin->login(Config::adminUsername(), Config::adminPassword());
        $this->rebuildPermissions($admin);

        return $admin;
    }

    /**
     * Makes core rebuild this account's cached album permissions.
     *
     * An admin page, and a ws call whose Referer is one, reads
     * piwigo_user_cache without rebuilding it (include/user.inc.php,
     * user_use_cache). After invalidateUserCache() the admin screen would
     * otherwise run on the list from before the fixture album existed, and the
     * [NEG] case would fail for a reason that is not the gate. One request from
     * outside the admin rebuilds it, as a real administrator's gallery visit does.
     */
    private function rebuildPermissions(WsClient $client): void
    {
        $res = $client->call('pwg.session.getStatus');
        $this->assertSame('ok', $res['json']['stat'] ?? '', $res['body']);
    }

    private function tagScreen(WsClient $client, int $imageId): string
    {
        $res = $client->fetchPage('/admin.php?page=plugin-persons&image_id=' . $imageId);

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($res['body']),
            'anti-vacuity: too little was returned to have rendered an admin screen');

        return $res['body'];
    }

    /** @return string[] the fixture names the list returns for these parameters */
    private function listedNames(WsClient $client, array $params): array
    {
        $res = $client->call('pwg.persons.getList',
            $params + array('q' => self::PREFIX, 'per_page' => PERSONS_SEARCH_MAX_RESULTS));
        $this->assertSame('ok', $res['json']['stat'] ?? '', $res['body']);

        return array_map(function ($person)
        {
            return $person['name'];
        }, $res['json']['result']['persons']);
    }
}
