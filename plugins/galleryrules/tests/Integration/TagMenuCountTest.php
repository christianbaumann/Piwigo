<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tag screen's menu says "1 Foto" for one photo and "n Fotos" for more, in the
 * markup the server renders (tags.tpl's tagContent). The boxes tags.js builds are the
 * E2E spec's: a page-source test cannot witness runtime DOM.
 */
final class TagMenuCountTest extends TestCase
{
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private array $tagIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $this->ws = new WsClient();
        [$user, $password] = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($user, $password);
    }

    protected function tearDown(): void
    {
        foreach ($this->tagIds as $id)
        {
            $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$id);
            $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$id);
        }
        $this->tagIds = array();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    public static function counts(): array
    {
        return array(
            'one photo' => array(1, '1 Foto'),
            'two photos' => array(2, '2 Fotos'),
            );
    }

    /** [ECP] [BVA] One photo is singular, two are plural. */
    #[DataProvider('counts')]
    public function testTheServerRenderedMenuCount(int $photos, string $expected): void
    {
        $tagId = $this->makeTagOnPhotos($photos);

        $body = $this->ws->get('/admin.php?page=tags')['body'];

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body));
        $this->assertSame(
            1,
            preg_match("~<div class='tag-box' data-id='" . $tagId . "'.*?<i>([^<]*)</i>~s", $body, $m),
            'the tag box must be on the first page'
        );
        $this->assertSame($expected, trim($m[1]));
    }

    private function makeTagOnPhotos(int $photos): int
    {
        $name = 'galleryrules-count-' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('$name', '$name')");
        $tagId = $this->db->insertId();
        $this->tagIds[] = $tagId;
        for ($i = 0; $i < $photos; $i++)
        {
            $imageId = $this->fixture->createTestImage();
            $this->db->query("INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ($imageId, $tagId)");
        }
        $this->assertSame($photos, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_image_tag WHERE tag_id = $tagId"));

        return $tagId;
    }
}
