<?php
use PHPUnit\Framework\TestCase;

/**
 * A user who read the tag list sees a person's tag come and go right after a
 * mark is added or deleted (SES-2026-10-14-22 F1).
 *
 * Core caches the tag list per user in $persistent_cache, keyed by the
 * user_cache row's cache_update_time (get_available_tags()). Nulling
 * nb_available_tags leaves that key, so the list stayed as it was; only
 * invalidate_user_cache() drops it (decision 0071, superseding 0004).
 */
final class TagListFreshnessTest extends TestCase
{
    /** A rendered page shorter than this is an error page. */
    private const MIN_PAGE_BYTES = 2000;
    private const TAG_LIST = '/tags.php';
    private const NAME = 'Persons Fresh Tag Ida';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $webmaster;
    private WsClient $reader;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);

        if (!$this->fixture->tableExists('piwigo_person_region'))
        {
            $this->markTestSkipped('the persons plugin is not installed; activate it first');
        }

        $this->image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Persons tag list fixture');
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->webmaster = new WsClient();
        $this->webmaster->login(Config::username(), Config::password());
        $this->reader = new WsClient();
        $this->reader->login(Config::normalUsername(), Config::normalPassword());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::NAME));
    }

    /** [ST] absent, then listed after the mark, then gone after its delete */
    public function testTheTagListFollowsAMarkAndItsDelete(): void
    {
        $this->assertStringNotContainsString(self::NAME, $this->tagList(), 'precondition: the reader has read a list without the tag');

        $res = $this->webmaster->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::NAME,
            'x' => 0.25, 'y' => 0.25, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->webmaster->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $this->assertStringContainsString(self::NAME, $this->tagList(), 'the list is stale after the mark');

        $res = $this->webmaster->call('pwg.persons.deleteRegion', array(
            'region_id' => $this->regionId(),
            'pwg_token' => $this->webmaster->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $this->assertStringNotContainsString(self::NAME, $this->tagList(), 'the list is stale after the delete');
    }

    private function tagList(): string
    {
        $res = $this->reader->fetchPage(self::TAG_LIST);
        $body = (string)$res['body'];
        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the tag list did not render');
        $this->assertStringContainsString('tags/', $body, 'anti-vacuity: the list holds no tag at all');
        return $body;
    }

    private function regionId(): int
    {
        $id = (int)$this->db->scalar('SELECT r.id FROM piwigo_person_region AS r'
            . ' JOIN piwigo_persons AS p ON p.id = r.person_id'
            . ' WHERE r.image_id = ' . (int)$this->image['id']
            . " AND p.name = '" . $this->db->escape(self::NAME) . "'");
        $this->assertGreaterThan(0, $id, 'anti-vacuity: the mark left no region');
        return $id;
    }
}
