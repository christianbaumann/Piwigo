<?php
use PHPUnit\Framework\TestCase;

/**
 * The persons list screen: what it shows, what it filters, and who may see it.
 *
 * The counts are the point. They are the only place in the gallery that says
 * how much of the index a person accounts for, and they come from a GROUP BY
 * over two tables - a join that silently multiplies rows would show a plausible
 * wrong number that nothing else would contradict.
 *
 * Rename and delete are not exercised here: they are the web-service methods
 * PersonAdminApiTest already covers, and this screen only calls them.
 */
final class AdminPersonsScreenTest extends TestCase
{
    private const NAME = 'Persons Screen Jane';

    /** A rendered page shorter than this is an error page or a redirect, not an admin screen. */
    private const MIN_PAGE_BYTES = 2000;

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
        $album = $this->fixture->createTestAlbum('Persons screen fixture');
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

    /**
     * [HAPPY] A person on one photo with two regions is listed as one photo and
     * two regions.
     *
     * Two regions on one photo is the case that separates the two counts: a
     * COUNT(*) used for both, or a COUNT(DISTINCT) used for both, gets one of
     * them wrong and nothing else would say so.
     */
    public function testAPersonIsListedWithTheirPhotoAndRegionCounts(): void
    {
        $this->addRegion(0.25, 0.25);
        $this->addRegion(0.75, 0.75);

        $personId = $this->personId();
        $this->assertNotNull($personId, 'anti-vacuity: nothing was indexed, so the list has nothing to show');

        $body = $this->fetchScreen();
        $row = $this->personRowIn($body);

        $this->assertSame((string)$personId, $row['person']);
        $this->assertSame('1', $row['photos']);
        $this->assertSame('2', $row['regions']);

        // The summary is a two-argument sprintf through a Smarty modifier, and a
        // modifier that dropped the second argument would leave a literal %d on
        // an otherwise perfect page.
        $this->assertMatchesRegularExpression(
            '/<p id="persons-admin-summary">\s*\d+ [^<%]*\d+[^<%]*</',
            $body,
            'the summary line still carries an unsubstituted placeholder'
        );
    }

    /** [ECP] The search filters the list by a substring of the name. */
    public function testTheSearchNarrowsTheListToMatchingNames(): void
    {
        $this->addRegion();

        $matching = $this->fetchScreen('&q=' . urlencode('Screen Jane'));
        $this->assertStringContainsString(self::NAME, $matching);

        $missing = $this->fetchScreen('&q=' . urlencode('Nobody By That Name At All'));
        $this->assertStringNotContainsString(self::NAME, $missing,
            'the search returned a person whose name does not contain the query');
    }

    /** [HAPPY] The screen offers the rescan, with the ids it will run over. */
    public function testTheScreenCarriesTheRescanButtonAndThePhotoIds(): void
    {
        $body = $this->fetchScreen();

        $this->assertStringContainsString('id="persons-rescan"', $body);
        $this->assertStringContainsString('persons_photo_ids', $body);
        $this->assertMatchesRegularExpression(
            '/persons_photo_ids\s*=\s*\'[0-9,]*' . (int)$this->image['id'] . '[0-9,]*\'/',
            $body,
            'the fixture photo is not among the ids the rescan would cover'
        );
    }

    /**
     * [NEG] A logged-in non-administrator does not reach the screen.
     *
     * The list names every person in the gallery and offers to delete them, so
     * the gate is the same ACCESS_ADMINISTRATOR one the tagging screen has.
     */
    public function testANormalUserDoesNotReachTheScreen(): void
    {
        $this->addRegion();

        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());

        try
        {
            $res = $normal->fetchPage('/admin.php?page=plugin-persons');

            $this->assertStringNotContainsString('id="persons-table"', $res['body']);
            $this->assertStringNotContainsString(self::NAME, $res['body'],
                'a normal account was shown the person list');
        }
        finally
        {
            $normal->logout();
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function fetchScreen(string $extra = ''): string
    {
        $res = $this->admin->fetchPage('/admin.php?page=plugin-persons' . $extra);

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($res['body']),
            'anti-vacuity: too little was returned to have rendered an admin screen'
        );

        return $res['body'];
    }

    /** The fixture person's row attributes, read off the rendered table. */
    private function personRowIn(string $body): array
    {
        $pattern = '/<tr data-person="(\d+)" data-person-name="' . preg_quote(self::NAME, '/')
            . '" data-person-photos="(\d+)" data-person-regions="(\d+)"/';

        $this->assertSame(1, preg_match($pattern, $body, $m),
            'the fixture person has no row on the rendered screen');

        return array('person' => $m[1], 'photos' => $m[2], 'regions' => $m[3]);
    }

    private function addRegion(float $x = 0.25, float $y = 0.25): void
    {
        $res = $this->admin->call('pwg.persons.addRegion', array(
            'image_id' => (int)$this->image['id'],
            'name' => self::NAME,
            'x' => $x, 'y' => $y, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->admin->token(),
            ));

        if (($res['json']['stat'] ?? '') !== 'ok')
        {
            throw new RuntimeException('could not seed a region: ' . $res['body']);
        }
    }

    private function personId(): ?int
    {
        $id = $this->db->scalar(
            "SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::NAME) . "'"
        );
        return $id === null ? null : (int)$id;
    }
}
