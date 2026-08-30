<?php
use PHPUnit\Framework\TestCase;

/**
 * pwg.persons.getList - the picker's two modes: recent-first with no query, and
 * a substring match with one.
 *
 * The search is server-side by design: persons are personal data and the list is
 * not bounded the way core's tag list is, so it is never shipped whole to a
 * browser.
 */
final class SearchTest extends TestCase
{
    private const ALPHA = 'Persons Search Aaronson';
    private const BETA = 'Persons Search Bakerman';
    private const GAMMA = 'Persons Search Carlyle';

    /** The substring that matches BETA in the middle of a word, and nothing else. */
    private const MIDDLE = 'akerma';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private int $album;

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
        $this->album = $this->fixture->createTestAlbum('Persons search fixture');
        $this->fixture->attachImage((int)$this->image['id'], $this->album);
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        // Written oldest first, one second apart, so "most recently used" is a
        // fact about the data and not about the order rows happen to come back.
        foreach (array(self::ALPHA, self::BETA, self::GAMMA) as $name)
        {
            $this->addRegionFor($name);
        }
        $this->ageBySeconds(self::ALPHA, 120);
        $this->ageBySeconds(self::BETA, 60);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::ALPHA, self::BETA, self::GAMMA));
    }

    /** [HAPPY] No query: the most recently used persons, newest first. */
    public function testGetListWithNoQueryReturnsRecentPersonsMostRecentFirst(): void
    {
        // per_page at the cap rather than the default: the three fixtures have
        // to be inside the window for their order to say anything.
        $names = $this->names($this->ws->call('pwg.persons.getList',
            array('per_page' => PERSONS_SEARCH_MAX_RESULTS)));

        $this->assertGreaterThan(0, count($names), 'anti-vacuity: the picker returned nobody');

        $positions = array();
        foreach (array(self::GAMMA, self::BETA, self::ALPHA) as $name)
        {
            $position = array_search($name, $names, true);
            $this->assertNotFalse($position, "$name is missing from the recent list");
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the three fixtures came back out of recency order');
    }

    /**
     * [ST] Tagging somebody who already exists moves them to the front.
     *
     * The distinction this catches: piwigo_persons.lastmodified is
     * ON UPDATE CURRENT_TIMESTAMP, so it records when a person was *created*
     * unless something updates the row when the person is used again. Without
     * that, "most recently used" silently means "most recently added" - and the
     * other recency test cannot see the difference, because it forces the column
     * with SQL rather than earning the value through a real write.
     */
    public function testTaggingAnExistingPersonAgainMovesThemToTheFrontOfTheRecentList(): void
    {
        $before = $this->names($this->ws->call('pwg.persons.getList',
            array('per_page' => PERSONS_SEARCH_MAX_RESULTS)));
        $this->assertSame(self::ALPHA, end($before),
            'anti-vacuity: ALPHA must start out last, or moving to the front proves nothing');

        // A second box for a person the gallery already knows - the picker's
        // whole point is that this counts as using them.
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::ALPHA,
            'x' => 0.8, 'y' => 0.8, 'w' => 0.1, 'h' => 0.1,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'], $res['body']);

        $after = $this->names($this->ws->call('pwg.persons.getList',
            array('per_page' => PERSONS_SEARCH_MAX_RESULTS)));

        $this->assertSame(self::ALPHA, $after[0],
            'using an existing person again did not make them the most recently used');
    }

    /** [HAPPY] A query matches inside a name, not just at its start. */
    public function testGetListWithAPartialQueryMatchesInTheMiddleOfAName(): void
    {
        $names = $this->names($this->ws->call('pwg.persons.getList', array('q' => self::MIDDLE)));

        $this->assertSame(array(self::BETA), $names);
    }

    /** [NEG] A query nothing matches returns an empty list, not an error. */
    public function testAQueryThatMatchesNobodyReturnsAnEmptyList(): void
    {
        $res = $this->ws->call('pwg.persons.getList', array('q' => 'Persons Search Nobody At All'));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(array(), $res['json']['result']['persons']);
    }

    /**
     * [NEG] A LIKE wildcard typed by the user is a literal, not a wildcard.
     *
     * Without escaping, '%' would match every person in the gallery - which is
     * exactly the unbounded list the server-side search exists to avoid.
     */
    public function testAPercentSignIsSearchedForLiterally(): void
    {
        $res = $this->ws->call('pwg.persons.getList', array('q' => '%'));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(array(), $res['json']['result']['persons']);
    }

    /** [BVA] per_page is capped, so no caller can ask for the whole table. */
    public function testPerPageIsCappedAtTheSearchMaximum(): void
    {
        $res = $this->ws->call('pwg.persons.getList',
            array('q' => 'Persons Search', 'per_page' => PERSONS_SEARCH_MAX_RESULTS + 500));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertLessThanOrEqual(PERSONS_SEARCH_MAX_RESULTS, count($res['json']['result']['persons']));
    }

    /** [NEG] A guest may not read the list of people in the gallery. */
    public function testAGuestIsRejected(): void
    {
        $guest = new WsClient();
        $res = $guest->call('pwg.persons.getList');

        $this->assertSame(401, (int)$res['json']['err'], $res['body']);
    }

    /**
     * [ECP] With image_id, the picker leaves out whoever is already on the photo.
     */
    public function testPersonsAlreadyOnThePhotoAreNotOffered(): void
    {
        $names = $this->names($this->ws->call('pwg.persons.getList',
            array('q' => 'Persons Search', 'image_id' => $this->image['id'])));

        $this->assertSame(array(), $names,
            'all three fixtures are already tagged on this photo, so none may be offered');
    }

    private function addRegionFor(string $name): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => $name,
            'x' => 0.5, 'y' => 0.5, 'w' => 0.1, 'h' => 0.1,
            'pwg_token' => $this->ws->token(),
            ));

        if (($res['json']['stat'] ?? '') !== 'ok')
        {
            throw new RuntimeException("could not seed $name: " . $res['body']);
        }
    }

    /** Forces one person's lastmodified back, so recency ordering is testable. */
    private function ageBySeconds(string $name, int $seconds): void
    {
        $escaped = $this->db->escape($name);
        $this->db->query(
            "UPDATE piwigo_persons SET lastmodified = DATE_SUB(NOW(), INTERVAL $seconds SECOND)"
            . " WHERE name = '$escaped'"
        );

        $rows = (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_persons WHERE name = '$escaped'");
        if ($rows !== 1)
        {
            throw new RuntimeException("expected exactly one row for $name, found $rows");
        }
    }

    /** @return string[] the names in the result, in the order they arrived */
    private function names(array $res): array
    {
        $this->assertSame('ok', $res['json']['stat'] ?? '', $res['body']);

        $names = array();
        foreach ($res['json']['result']['persons'] as $person)
        {
            $names[] = $person['name'];
        }

        // Other suites' leftovers are not this test's business.
        return array_values(array_filter($names, function ($name)
        {
            return strpos($name, 'Persons Search ') === 0;
        }));
    }
}
