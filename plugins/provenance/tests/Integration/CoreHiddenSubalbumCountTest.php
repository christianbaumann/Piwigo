<?php
use PHPUnit\Framework\TestCase;

/**
 * An album thumbnail's "N albums" must equal the album thumbnails shown inside it.
 *
 * Reported on the remote: "Dusarts Monika" said "8 Alben" to the webmaster and
 * listed 6. The two others had no photos. The listing hides albums without photos
 * (include/category_cats.inc.php), but an administrator's user cache keeps them
 * (include/functions_user.inc.php, feature 1053), and the thumbnail counted from
 * that cache. A normal user's cache drops them, so a normal user never saw it.
 *
 * Scope: page source of the modus theme, which renders both counters server-side -
 * nb_categories as the visible "N albums", count_categories in its title.
 *
 * Fixture, all created through pwg.categories.add so core computes uppercats:
 *
 *   G
 *   └ P              <- the thumbnail under test, shown on G's page
 *     ├ A  (1 photo)
 *     │ └ A2 (1 photo)
 *     └ E  (empty)
 *       └ E2 (empty)
 *
 * Shown inside P: A only. Sub-albums with photos: A and A2. So 1 album, 2 sub-albums;
 * the admin cache before the fix says 2 and 4.
 */
final class CoreHiddenSubalbumCountTest extends TestCase
{
    /** A page shorter than this is an error page, not an album listing. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private string $suffix;
    private array $ids = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);
        $this->suffix = bin2hex(random_bytes(4));

        $this->ids['G'] = $this->addAlbum('G', null);
        $this->ids['P'] = $this->addAlbum('P', $this->ids['G']);
        $this->ids['A'] = $this->addAlbum('A', $this->ids['P']);
        $this->ids['A2'] = $this->addAlbum('A2', $this->ids['A']);
        $this->ids['E'] = $this->addAlbum('E', $this->ids['P']);
        $this->ids['E2'] = $this->addAlbum('E2', $this->ids['E']);

        foreach (array('A', 'A2') as $album)
        {
            $imageId = $this->fixture->createTestImage()['id'];
            $this->fixture->attachImage($imageId, $this->ids[$album]);
            // The listing skips an album with no representative; core sets one
            // when a photo arrives through its own paths, the direct link does not.
            $this->db->query(
                "UPDATE piwigo_categories SET representative_picture_id = $imageId WHERE id = " . $this->ids[$album]
            );
        }

        // The links above are direct SQL; core recomputes every user's cache on
        // the next request once it is marked stale.
        $this->db->query("UPDATE piwigo_user_cache SET need_update = 'true'");
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->db->query("UPDATE piwigo_user_cache SET need_update = 'true'");
    }

    /** [HAPPY] The case reported: an administrator, whose cache keeps the empty albums. */
    public function testTheWebmasterSeesTheNumberOfAlbumsListedInside(): void
    {
        $this->assertCountersMatchTheListing($this->ws);
    }

    /** [ECP] A normal user's cache never held the empty albums; the fix must not change that. */
    public function testANormalUserSeesTheNumberOfAlbumsListedInside(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());

        $this->assertCountersMatchTheListing($normal);
    }

    private function assertCountersMatchTheListing(WsClient $client): void
    {
        $listed = $this->albumNamesListedOn($client, $this->ids['P']);
        $this->assertSame(array($this->albumName('A')), $listed, 'the listing itself hides the empty album');

        $counters = $this->countersOfThumbnail($client, $this->ids['G'], $this->albumName('P'));
        $this->assertSame(count($listed), $counters['nb_categories'], 'albums on the thumbnail vs. albums listed inside');
        $this->assertSame(2, $counters['count_categories'], 'sub-albums with photos: A and A2');
    }

    /** @return string[] the album names shown as thumbnails on one album's page */
    private function albumNamesListedOn(WsClient $client, int $catId): array
    {
        $body = $this->page($client, $catId);
        preg_match_all('~<li><a href="[^"]*"><img class=albImg[^>]*><div class=albLegend><h4>([^<]*)</h4>~', $body, $m);

        return $m[1];
    }

    /** @return array{nb_categories:int,count_categories:int} read off one modus thumbnail */
    private function countersOfThumbnail(WsClient $client, int $onCatId, string $albumName): array
    {
        $body = $this->page($client, $onCatId);
        $pattern = '~<h4>' . preg_quote($albumName, '~') . '</h4>.*?<span title="\d+ \D+ (\d+) [^"]*">(\d+) ~s';
        $this->assertSame(1, preg_match($pattern, $body, $m), "no album counter on the thumbnail of $albumName");

        return array('nb_categories' => (int)$m[2], 'count_categories' => (int)$m[1]);
    }

    private function page(WsClient $client, int $catId): string
    {
        $response = $client->fetchPage('/index.php?/category/' . $catId);
        $this->assertSame(200, $response['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$response['body']));

        return (string)$response['body'];
    }

    private function addAlbum(string $label, ?int $parent): int
    {
        $params = array('name' => $this->albumName($label), 'status' => 'public');
        if ($parent !== null)
        {
            $params['parent'] = $parent;
        }
        $result = $this->ws->call('pwg.categories.add', $params);
        $id = (int)($result['json']['result']['id'] ?? 0);
        $this->assertGreaterThan(0, $id, "album $label was not created: " . json_encode($result));
        $this->fixture->adoptAlbum($id);

        return $id;
    }

    private function albumName(string $label): string
    {
        return "subalbum-count-$label-{$this->suffix}";
    }
}
