<?php
use PHPUnit\Framework\TestCase;

/**
 * How core resolves a typed tag name to a tag: get_tag_ids() ->
 * tag_id_from_tag_name(), reached here through pwg.images.setInfo's tag_list
 * (the Batch Manager's unit mode). It looks by name under the column's
 * utf8mb3_general_ci collation, then by url_name, then creates the tag.
 *
 * The case and url_name cases are [ERR]: the oracle is the current
 * implementation. No requirement says a typed name may land on a tag of
 * another spelling; they record what core does today, so a change to it shows
 * up here. The decomposed-name case is the fork's requirement (NFC).
 */
final class CoreTypedTagNameCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private string $suffix;

    /** Tag names this test created or caused to be created, removed in teardown. */
    private array $testTagNames = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->testTagNames as $name)
        {
            $ids = $this->tagIdsByName($name);
            foreach ($ids as $id)
            {
                $this->db->query('DELETE FROM `piwigo_image_tag` WHERE tag_id = ' . $id);
                $this->db->query('DELETE FROM `piwigo_tags` WHERE id = ' . $id);
            }
        }
        $this->fixture->destroyTestImages();
        $this->ws->logout();
    }

    /** [ERR] A name that differs only in case and accents links the existing tag. */
    public function testACaseAndAccentVariantLinksTheExistingTag(): void
    {
        $existing = $this->addTag('hauser ortsansichten ' . $this->suffix);
        $image = $this->fixture->createTestImage()['id'];

        $this->typeTags($image, array('Häuser Ortsansichten ' . $this->suffix));

        $this->assertSame(array($existing), $this->tagsOfPhoto($image));
    }

    /** [ERR] A name no tag has, but a tag's url_name equals, links that tag. */
    public function testANameMatchingOnlyAUrlNameLinksThatTag(): void
    {
        $marker = $this->addTag('provchar-name ' . $this->suffix . ' ?');
        $typed = 'provchar-name ' . $this->suffix;
        $this->testTagNames[] = $typed;
        $this->assertSame(array(), $this->tagIdsByName($typed), 'anti-vacuity: no tag may carry the typed name');
        $image = $this->fixture->createTestImage()['id'];

        $this->typeTags($image, array($typed));

        $this->assertSame(array($marker), $this->tagsOfPhoto($image));
        $this->assertSame(array(), $this->tagIdsByName($typed), 'no tag of the typed name was created');
    }

    /**
     * [ECP] A name typed in decomposed form (a + U+0308) links the composed
     * tag of the same visible name; no second tag. Not [ERR]: requirement of
     * change 2026-10-15-exploratory-testing-answers, "Typed tag names are NFC"
     * (fork-local get_tag_ids()). It replaced the [ERR] case that recorded a
     * second tag being created.
     */
    public function testADecomposedNameLinksTheComposedTag(): void
    {
        $composed = "H\u{00E4}user " . $this->suffix;
        $decomposed = "Ha\u{0308}user " . $this->suffix;
        $existing = $this->addTag($composed);
        $this->testTagNames[] = $decomposed;
        $image = $this->fixture->createTestImage()['id'];

        $this->typeTags($image, array($decomposed));

        $this->assertSame(array(), $this->tagIdsByName($decomposed), 'a second tag of the same visible name was created');
        $this->assertSame(array($existing), $this->tagsOfPhoto($image));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function typeTags(int $imageId, array $names): void
    {
        $res = $this->ws->call('pwg.images.setInfo', array(
            'image_id' => $imageId,
            'tag_list' => $names,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    private function addTag(string $name): int
    {
        $res = $this->ws->call('pwg.tags.add', array('name' => $name));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $id = (int)($res['json']['result']['id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the call returned no tag id: ' . $res['body']);
        $this->testTagNames[] = $name;

        return $id;
    }

    /** Ids of tags whose name is these exact bytes, not a collation match. @return int[] */
    private function tagIdsByName(string $name): array
    {
        return $this->ids(
            "SELECT id FROM `piwigo_tags` WHERE BINARY name = '" . $this->db->escape($name) . "' ORDER BY id"
        );
    }

    /** @return int[] */
    private function tagsOfPhoto(int $imageId): array
    {
        return $this->ids('SELECT tag_id FROM `piwigo_image_tag` WHERE image_id = ' . $imageId . ' ORDER BY tag_id');
    }

    /** @return int[] */
    private function ids(string $query): array
    {
        $result = $this->db->query($query);
        $ids = array();
        while ($row = $result->fetch_row())
        {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }
}
