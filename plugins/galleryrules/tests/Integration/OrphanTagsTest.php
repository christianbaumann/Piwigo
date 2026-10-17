<?php
use PHPUnit\Framework\TestCase;

/**
 * The unused-tag cleanup leaves coloured and person tags alone (design: "The
 * unused-tag cleanup leaves coloured and person tags alone"). Core's
 * get_orphan_tags() feeds all three cleanup paths and the confirm dialog's
 * name list; galleryrules filters its result through a fork-local trigger.
 *
 * The cleanup is global: it sweeps every unused tag of the install. setUp()
 * snapshots the unused tags that already exist and tearDown() puts back any the
 * run swept away. The tags and the person rows are this suite's own.
 */
final class OrphanTagsTest extends TestCase
{
    private const OLD_ENOUGH_DAYS = 2;
    private const MIN_PAGE_BYTES = 2000;
    private const REDIRECT_STATUS = 302;

    private const PATHS = array(
        'tags page' => '/admin.php?page=tags&action=delete_orphans',
        'maintenance actions tab' => '/admin.php?page=maintenance&action=delete_orphan_tags',
        'maintenance env tab' => '/admin.php?page=maintenance&tab=env&action=delete_orphan_tags',
    );

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    private array $existingOrphans = array();
    private array $testTagIds = array();
    private array $testPersonIds = array();
    private array $testColorIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
        $this->fixture->assertPluginActive('typetags');
        $this->fixture->assertPluginActive('persons');

        $this->ws = new WsClient();
        [$user, $password] = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($user, $password);

        $result = $this->db->query(
            'SELECT t.* FROM piwigo_tags t LEFT JOIN piwigo_image_tag i ON t.id = i.tag_id WHERE i.tag_id IS NULL'
        );
        $this->existingOrphans = $result->fetch_all(MYSQLI_ASSOC);
    }

    protected function tearDown(): void
    {
        foreach ($this->testPersonIds as $id)
        {
            $this->db->query('DELETE FROM piwigo_persons WHERE id = ' . (int)$id);
        }
        foreach ($this->testTagIds as $id)
        {
            $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . (int)$id);
            $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . (int)$id);
        }
        foreach ($this->testColorIds as $id)
        {
            $this->db->query('DELETE FROM piwigo_typetags WHERE id = ' . (int)$id);
        }
        foreach ($this->existingOrphans as $row)
        {
            $columns = array();
            $values = array();
            foreach ($row as $column => $value)
            {
                $columns[] = '`' . $column . '`';
                $values[] = $value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'";
            }
            $this->db->query(
                'INSERT IGNORE INTO piwigo_tags (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')'
            );
        }
        $this->testTagIds = $this->testPersonIds = $this->testColorIds = $this->existingOrphans = array();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] [ECP] Each path still removes an unused plain tag. */
    public function testEveryPathRemovesAnUnusedPlainTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $id = $this->makeTag('plain-' . bin2hex(random_bytes(3)));
            $this->assertTrue($this->tagExists($id), 'anti-vacuity: the tag must exist before the cleanup');

            $this->runCleanup($url);

            $this->assertFalse($this->tagExists($id), $path . ' removes an unused plain tag');
        }
    }

    /** [NEG] [ECP] Each path leaves an unused coloured tag, and a plain one beside it goes. */
    public function testEveryPathKeepsAnUnusedColouredTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $coloured = $this->makeColouredTag('coloured-' . bin2hex(random_bytes(3)));
            $plain = $this->makeTag('plain-' . bin2hex(random_bytes(3)));

            $this->runCleanup($url);

            $this->assertTrue($this->tagExists($coloured), $path . ' keeps an unused coloured tag');
            $this->assertFalse($this->tagExists($plain), 'anti-vacuity: the cleanup ran on ' . $path);
        }
    }

    /** [NEG] [ECP] Each path leaves an unused person tag, and a plain one beside it goes. */
    public function testEveryPathKeepsAnUnusedPersonTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $person = $this->makePersonTag('person-' . bin2hex(random_bytes(3)));
            $plain = $this->makeTag('plain-' . bin2hex(random_bytes(3)));

            $this->runCleanup($url);

            $this->assertTrue($this->tagExists($person), $path . ' keeps an unused person tag');
            $this->assertFalse($this->tagExists($plain), 'anti-vacuity: the cleanup ran on ' . $path);
        }
    }

    /** [ECP] The confirm dialog lists the plain unused tag and neither of the others. */
    public function testTheConfirmListsOnlyThePlainTag(): void
    {
        $plainName = 'plain-' . bin2hex(random_bytes(3));
        $colouredName = 'coloured-' . bin2hex(random_bytes(3));
        $personName = 'person-' . bin2hex(random_bytes(3));
        $this->makeTag($plainName);
        $this->makeColouredTag($colouredName);
        $this->makePersonTag($personName);

        $page = $this->ws->get('/admin.php?page=tags');

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$page['body']));
        $this->assertSame(1, preg_match('~var orphan_tag_names = \[([^\]]*)\];~', $page['body'], $m), 'the name list var');
        $this->assertStringContainsString('"' . $plainName . '"', $m[1]);
        $this->assertStringNotContainsString($colouredName, $m[1]);
        $this->assertStringNotContainsString($personName, $m[1]);
    }

    private function runCleanup(string $url): void
    {
        $response = $this->ws->get($url . '&pwg_token=' . $this->ws->token());
        $this->assertContains($response['status'], array(200, self::REDIRECT_STATUS), 'the cleanup page answers');
    }

    private function makeTag(string $name): int
    {
        $escaped = $this->db->escape($name);
        $this->db->query(
            "INSERT INTO piwigo_tags (name, url_name, lastmodified) VALUES ('$escaped', '$escaped', NOW() - INTERVAL " . self::OLD_ENOUGH_DAYS . ' DAY)'
        );
        $id = $this->db->insertId();
        $this->testTagIds[] = $id;

        return $id;
    }

    private function makeColouredTag(string $name): int
    {
        $this->db->query("INSERT INTO piwigo_typetags (name, color) VALUES ('" . $this->db->escape($name) . "', '#123456')");
        $colorId = $this->db->insertId();
        $this->testColorIds[] = $colorId;

        $id = $this->makeTag($name);
        $this->db->query(
            "UPDATE piwigo_tags SET id_typetags = $colorId, lastmodified = NOW() - INTERVAL " . self::OLD_ENOUGH_DAYS . " DAY WHERE id = $id"
        );
        $this->assertSame($colorId, (int)$this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $id"), 'fixture colour did not take');

        return $id;
    }

    private function makePersonTag(string $name): int
    {
        $id = $this->makeTag($name);
        $escaped = $this->db->escape($name);
        $this->db->query("INSERT INTO piwigo_persons (name, url_name, tag_id) VALUES ('$escaped', '$escaped', $id)");
        $this->testPersonIds[] = $this->db->insertId();

        return $id;
    }

    private function tagExists(int $id): bool
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_tags WHERE id = $id") === 1;
    }
}
