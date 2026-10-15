<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core's album lock: set_cat_visible() in
 * admin/include/functions.php, reached through its two callers - pwg.categories.setInfo
 * (the album properties page's Geschlossenes Album, cat_modify.js) and the album options
 * page (admin/cat_options.php, section=visible).
 *
 * Every case is [ERR]: the oracle is the current implementation, not a requirement.
 * Nothing promises that a lock cascades down and an unlock up; these record that it
 * does today. They report a change; they do not prove the behaviour right. Each was
 * watched go red by breaking the cascade it claims to watch.
 *
 * Fixture, created through pwg.categories.add so core computes uppercats:
 *
 *   P
 *   ├ C
 *   │ └ G
 *   └ S
 */
final class CoreAlbumLockCharacterizationTest extends TestCase
{
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

        $this->ids['P'] = $this->addAlbum('P', null);
        $this->ids['C'] = $this->addAlbum('C', $this->ids['P']);
        $this->ids['G'] = $this->addAlbum('G', $this->ids['C']);
        $this->ids['S'] = $this->addAlbum('S', $this->ids['P']);
        $this->assertSame(array('true', 'true', 'true', 'true'), $this->visible(array('P', 'C', 'G', 'S')), 'a new album starts unlocked');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestAlbums();
        $this->db->query("UPDATE piwigo_user_cache SET need_update = 'true'");
        $this->ws->logout();
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return array(
            'album properties (pwg.categories.setInfo)' => array('setInfo'),
            'album options page (cat_options.php)' => array('cat_options'),
            );
    }

    /** [ERR] Locking an album locks every album below it, and no other. */
    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function testLockingAnAlbumLocksItsSubAlbums(string $path): void
    {
        $this->setLocked($path, 'C', true);

        $this->assertSame(
            array('P' => 'true', 'C' => 'false', 'G' => 'false', 'S' => 'true'),
            array_combine(array('P', 'C', 'G', 'S'), $this->visible(array('P', 'C', 'G', 'S')))
            );
    }

    /** [ERR] Unlocking an album unlocks every album above it; its locked sub-albums and siblings stay locked. */
    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function testUnlockingAnAlbumUnlocksItsParents(string $path): void
    {
        $this->setLocked($path, 'P', true);
        $this->assertSame(array('false', 'false', 'false', 'false'), $this->visible(array('P', 'C', 'G', 'S')), 'precondition: the whole tree is locked');

        $this->setLocked($path, 'C', false);

        $this->assertSame(
            array('P' => 'true', 'C' => 'true', 'G' => 'false', 'S' => 'false'),
            array_combine(array('P', 'C', 'G', 'S'), $this->visible(array('P', 'C', 'G', 'S')))
            );
    }

    private function setLocked(string $path, string $label, bool $locked): void
    {
        $id = $this->ids[$label];
        if ($path === 'setInfo')
        {
            $result = $this->ws->call('pwg.categories.setInfo', array(
                'category_id' => $id,
                'visible' => $locked ? 'false' : 'true',
                'pwg_token' => $this->ws->token(),
                ));
            $this->assertSame('ok', $result['json']['stat'] ?? null, 'setInfo failed: ' . $result['body']);
            return;
        }

        $params = array('pwg_token' => $this->ws->token());
        if ($locked)
        {
            $params['falsify'] = '1';
            $params['cat_true'] = array($id);
        }
        else
        {
            $params['trueify'] = '1';
            $params['cat_false'] = array($id);
        }
        $response = $this->ws->postPage('/admin.php?page=cat_options&section=visible', $params);
        $this->assertSame(200, $response['http_code'], 'the album options page did not answer');
    }

    /** @return string[] categories.visible of the given albums, in order */
    private function visible(array $labels): array
    {
        $values = array();
        foreach ($labels as $label)
        {
            $values[] = (string)$this->db->scalar('SELECT visible FROM piwigo_categories WHERE id = ' . $this->ids[$label]);
        }

        return $values;
    }

    private function addAlbum(string $label, ?int $parent): int
    {
        $params = array('name' => "album-lock-$label-{$this->suffix}", 'status' => 'public');
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
}
