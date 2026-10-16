<?php
use PHPUnit\Framework\TestCase;

/**
 * A person always has exactly its own tag: the tag of its name, found by name
 * only, re-created when it was deleted, and removed with the person.
 *
 * Core's tag_id_from_tag_name() falls back to the URL name, so `Name` used to
 * resolve to a marker tag `Name ?` (SES-2026-10-13-03 F1), and a person whose
 * tag was deleted kept a link to a page that answered 404 (SES-2026-10-15-01 F2).
 */
final class PersonTagTest extends TestCase
{
    /** A rendered page shorter than this is an error page. */
    private const MIN_PAGE_BYTES = 2000;

    private const NAME = 'Persons Tag Ida';
    /** A typetags-style marker tag whose URL name equals NAME's. */
    private const MARKER = 'Persons Tag Ida ?';

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private array $image;
    private int $album;
    /** @var int[] tag rows this test inserted itself */
    private array $tags = array();

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
        $this->album = $this->fixture->createTestAlbum('Persons tag fixture');
        $this->fixture->attachImage((int)$this->image['id'], $this->album);
        $this->fixture->invalidateUserCache();

        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->fixture->destroyPersons(array(self::NAME));
        foreach ($this->tags as $id)
        {
            $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . $id);
            $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . $id);
        }
    }

    /** [NEG] A new person is not given a tag that only shares its URL name. */
    public function testANewPersonGetsATagOfItsOwnNameNotAMarkerTag(): void
    {
        $marker = $this->createMarkerTag();

        $this->mark(0.25);

        $this->assertSame(self::NAME, $this->personTagName());
        $this->assertNotSame($marker, $this->personTagId());
        $this->assertFalse($this->photoCarries($marker), 'the marker tag was put on the photo');
    }

    /** [ST] A person linked to another tag gets its own at the next mark; that tag stays. */
    public function testAPersonLinkedToAMarkerTagGetsItsOwnAtTheNextMark(): void
    {
        $this->mark(0.25);
        $marker = $this->createMarkerTag();
        $this->linkPersonTo($marker);

        $this->mark(0.75);

        $this->assertSame(self::NAME, $this->personTagName());
        $this->assertTrue($this->photoCarries($this->personTagId()));
        $this->assertFalse($this->photoCarries($marker));
        $this->assertSame(self::MARKER, $this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $marker));
    }

    /**
     * [ST] With its tag row deleted the person has no link; the next mark
     * re-creates the tag, and the link then answers 200.
     */
    public function testADeletedTagIsRecreatedByTheNextMarkAndItsLinkAnswers(): void
    {
        $this->mark(0.25);
        $old = $this->personTagId();
        $this->assertGreaterThan(0, $old, 'anti-vacuity: the person was never mirrored as a tag');
        // What core's delete_tags() does to the rows.
        $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . $old);
        $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . $old);

        $this->assertSame(array(), $this->boxLinks(), 'a person whose tag is gone still links to it');

        $this->mark(0.75);

        $tag = $this->personTagId();
        $this->assertSame(self::NAME, $this->personTagName(), 'the tag was not re-created');
        $this->assertTrue($this->photoCarries($tag));

        $links = $this->boxLinks();
        $this->assertCount(2, $links, 'each box links to the tag page');
        $this->assertStringContainsString('tags/' . $tag . '-', $links[0]);
        $res = $this->ws->fetchPage('/' . preg_replace('#^\./#', '', $links[0]));
        $this->assertSame(200, $res['http_code'], $links[0]);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$res['body']));
        $this->assertStringContainsString(self::NAME, (string)$res['body']);
    }

    /** [HAPPY] A delete removes the person's tag row and the tag from every photo. */
    public function testDeletingAPersonRemovesItsTag(): void
    {
        $this->mark(0.25);
        $tag = $this->personTagId();
        $byHand = $this->fixture->createTestImage();
        $this->fixture->attachImage((int)$byHand['id'], $this->album);
        $this->db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ({$byHand['id']}, $tag)");
        $this->assertTrue($this->photoCarries($tag), 'anti-vacuity: the mark did not tag the photo');

        $this->delete();

        $this->assertSame(0, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_tags WHERE id = ' . $tag));
        $this->assertSame(0, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_image_tag WHERE tag_id = ' . $tag));
    }

    /** [NEG] Deleting a person linked to a tag of another name leaves that tag on its photos. */
    public function testDeletingAPersonLinkedToAMarkerTagLeavesTheMarker(): void
    {
        $this->mark(0.25);
        $own = $this->personTagId();
        $marker = $this->createMarkerTag();
        $this->db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ({$this->image['id']}, $marker)");
        $this->linkPersonTo($marker);

        $this->delete();

        $this->assertTrue($this->photoCarries($marker), 'the marker tag was taken off the photo');
        $this->assertSame(self::MARKER, $this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $marker));
        $this->assertSame(0, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_tags WHERE id = ' . $own));
    }

    private function mark(float $x): void
    {
        $res = $this->ws->call('pwg.persons.addRegion', array(
            'image_id' => $this->image['id'],
            'name' => self::NAME,
            'x' => $x, 'y' => 0.25, 'w' => 0.2, 'h' => 0.2,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function delete(): void
    {
        $res = $this->ws->call('pwg.persons.delete', array(
            'person_id' => (int)$this->db->scalar(
                "SELECT id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::NAME) . "'"),
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** A tag named MARKER whose URL name is the one core derives for NAME. */
    private function createMarkerTag(): int
    {
        $urlName = str2url(self::NAME);
        $this->assertSame($urlName, str2url(self::MARKER), 'anti-vacuity: the URL-name fallback would not match');

        $this->db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('"
            . $this->db->escape(self::MARKER) . "', '" . $this->db->escape($urlName) . "')");
        $id = $this->db->insertId();
        $this->tags[] = $id;
        return $id;
    }

    /** What core's URL-name fallback used to leave behind. */
    private function linkPersonTo(int $tagId): void
    {
        $this->db->query('UPDATE piwigo_persons SET tag_id = ' . $tagId
            . " WHERE name = '" . $this->db->escape(self::NAME) . "'");
        $this->assertSame($tagId, $this->personTagId(), 'anti-vacuity: the link was not made');
    }

    private function personTagId(): int
    {
        return (int)$this->db->scalar(
            "SELECT tag_id FROM piwigo_persons WHERE name = '" . $this->db->escape(self::NAME) . "'");
    }

    private function personTagName(): ?string
    {
        $name = $this->db->scalar('SELECT t.name FROM piwigo_tags AS t JOIN piwigo_persons AS p ON p.tag_id = t.id'
            . " WHERE p.name = '" . $this->db->escape(self::NAME) . "'");
        return $name === null ? null : (string)$name;
    }

    private function photoCarries(int $tagId): bool
    {
        return (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = '
            . (int)$this->image['id'] . ' AND tag_id = ' . $tagId) > 0;
    }

    /** @return string[] the hrefs of the box labels on the picture page */
    private function boxLinks(): array
    {
        $res = $this->ws->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $this->album);
        $body = (string)$res['body'];
        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the picture page did not render');
        $this->assertGreaterThan(0, substr_count($body, 'person-box-label'), 'anti-vacuity: no box was rendered');

        preg_match_all('#<a class="person-box-label" href="([^"]+)"#', $body, $m);
        return array_map('html_entity_decode', $m[1]);
    }
}
