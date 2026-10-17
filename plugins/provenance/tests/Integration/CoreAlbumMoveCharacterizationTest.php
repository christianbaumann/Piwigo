<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core's album move and the album list the parent picker reads:
 * ws_categories_move() and ws_categories_getAdminList() in
 * include/ws_functions/pwg.categories.php, reached through pwg.categories.move and
 * pwg.categories.getAdminList.
 *
 * Every case is [ERR]: the oracle is the current implementation, not a requirement.
 * They record what the album properties screen (cat_modify.js, album_selector.js)
 * depends on: a refused move answers stat "fail" with a message and changes nothing, and
 * the list carries the `uppercats` chain and `nb_categories` count the picker uses to
 * recognise an album's own descendants.
 *
 * They pass on their first run, which is the point here; each was watched go red by
 * breaking the behaviour it claims to watch.
 *
 * Throwaway albums only (FixtureBuilder): a real album is never moved.
 */
final class CoreAlbumMoveCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    /** [ERR] Moving an album into its own child is refused with a message and moves nothing. */
    public function testMovingAnAlbumIntoItsOwnChildIsRefusedAndMovesNothing(): void
    {
        $parent = $this->fixture->createTestAlbum('provenance-char-move-parent');
        $child = $this->fixture->createTestSubAlbum('provenance-char-move-child', $parent);

        $res = $this->move($parent, $child);

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNotSame('', (string)($res['json']['message'] ?? ''), 'a refusal carries the reason');
        $this->assertNull($this->column($parent, 'id_uppercat'), 'the refused album stayed at the root');
        $this->assertSame((string)$parent, $this->column($parent, 'uppercats'));
    }

    /** [ERR] [BVA] The same holds one level deeper: a grandchild is a sub-album too. */
    public function testMovingAnAlbumIntoItsGrandchildIsRefused(): void
    {
        $parent = $this->fixture->createTestAlbum('provenance-char-move-gp');
        $child = $this->fixture->createTestSubAlbum('provenance-char-move-gc', $parent);
        $grandchild = $this->fixture->createTestSubAlbum('provenance-char-move-ggc', $child);

        $res = $this->move($parent, $grandchild);

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNull($this->column($parent, 'id_uppercat'));
    }

    /** [ERR] [BVA] Moving an album into itself is refused too. */
    public function testMovingAnAlbumIntoItselfIsRefused(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-move-self');

        $res = $this->move($album, $album);

        $this->assertSame('fail', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNull($this->column($album, 'id_uppercat'));
    }

    /** [ERR] [HAPPY] A move into an unrelated album succeeds and rewrites the chain. */
    public function testMovingAnAlbumIntoASiblingRewritesParentAndChain(): void
    {
        $album = $this->fixture->createTestAlbum('provenance-char-move-a');
        $target = $this->fixture->createTestAlbum('provenance-char-move-b');

        $res = $this->move($album, $target);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNotSame('', (string)($res['json']['result']['new_ariane_string'] ?? ''));
        $this->assertSame((string)$target, $this->column($album, 'id_uppercat'));
        $this->assertSame("$target,$album", $this->column($album, 'uppercats'));
    }

    /**
     * [ERR] The picker's two inputs: the list of a parent carries each row's
     * uppercats chain and its sub-album count.
     */
    public function testTheAdminListCarriesTheChainAndTheChildCountThePickerUses(): void
    {
        $parent = $this->fixture->createTestAlbum('provenance-char-list-parent');
        $child = $this->fixture->createTestSubAlbum('provenance-char-list-child', $parent);
        $this->fixture->createTestSubAlbum('provenance-char-list-grandchild', $child);

        $rows = $this->adminList(array('cat_id' => $parent, 'recursive' => 'false'));

        $this->assertArrayHasKey($child, $rows, 'the child is listed under its parent');
        $this->assertSame("$parent,$child", $rows[$child]['uppercats']);
        $this->assertSame(1, (int)$rows[$child]['nb_categories'], 'the child reports its one sub-album');
    }

    /** [ERR] A recursive search lists every descendant, each with a chain naming the root album. */
    public function testASearchListsDescendantsWithTheirChain(): void
    {
        $parent = $this->fixture->createTestAlbum('provenance-char-search-root');
        $child = $this->fixture->createTestSubAlbum('provenance-char-search-mid', $parent);
        $grandchild = $this->fixture->createTestSubAlbum('provenance-char-search-leaf', $child);

        $rows = $this->adminList(array('cat_id' => 0, 'recursive' => 'true', 'search' => 'provenance-char-search-'));

        $this->assertCount(3, $rows, 'anti-vacuity: the three fixture albums are what the search finds');
        $this->assertSame("$parent,$child,$grandchild", $rows[$grandchild]['uppercats']);
    }

    /** @return array<int, array> rows by id */
    private function adminList(array $params): array
    {
        $res = $this->ws->call('pwg.categories.getAdminList', $params);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $rows = array();
        foreach ($res['json']['result']['categories'] as $row)
        {
            $rows[(int)$row['id']] = $row;
        }

        return $rows;
    }

    private function move(int $albumId, int $parentId): array
    {
        return $this->ws->call('pwg.categories.move', array(
            'category_id' => $albumId,
            'parent' => $parentId,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function column(int $catId, string $column): mixed
    {
        return $this->db->scalar("SELECT `$column` FROM `piwigo_categories` WHERE id = " . $catId);
    }
}
