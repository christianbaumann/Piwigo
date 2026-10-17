<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for core's unused-tag cleanup: get_orphan_tags() and
 * delete_orphan_tags() in admin/include/functions.php, reached over HTTP through
 * the three places that call them (admin.php?page=tags&action=delete_orphans,
 * and the maintenance page's actions and env tabs), plus what admin/tags.php and
 * tags.tpl hand to the confirm dialog.
 *
 * Every case is [ERR]: the oracle is the current implementation, not a
 * requirement. Nothing promises that a tag is spared for a day after its last
 * change; these record that it does today. They report a change; they prove
 * nothing right. A coloured tag and a person's tag are swept up with the plain
 * ones today.
 *
 * They pass on their first run, so each was watched go red by breaking the
 * behaviour it pins (docs/agents/TESTING.md).
 *
 * The cleanup is global: it sweeps every unused tag of the install, not only this
 * test's. setUp() therefore snapshots the unused tags that already exist and
 * tearDown() puts back any the run swept away.
 */
final class CoreOrphanTagsCharacterizationTest extends TestCase
{
    private const OLD_ENOUGH_DAYS = 2;
    private const TOO_YOUNG_DAYS = 0;
    private const MIN_PAGE_BYTES = 2000;

    private const PATHS = array(
        'tags page' => '/admin.php?page=tags&action=delete_orphans',
        'maintenance actions tab' => '/admin.php?page=maintenance&action=delete_orphan_tags',
        'maintenance env tab' => '/admin.php?page=maintenance&tab=env&action=delete_orphan_tags',
    );

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;

    /** Unused tags the install held before the run, put back in tearDown. */
    private array $existingOrphans = array();
    private array $testTagIds = array();
    private array $testPersonIds = array();
    private array $testColorIds = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);

        $result = $this->db->query(
            'SELECT t.* FROM `piwigo_tags` t LEFT JOIN `piwigo_image_tag` i ON t.id = i.tag_id WHERE i.tag_id IS NULL'
        );
        $this->existingOrphans = $result->fetch_all(MYSQLI_ASSOC);
    }

    protected function tearDown(): void
    {
        foreach ($this->testPersonIds as $id)
        {
            $this->db->query('DELETE FROM `piwigo_persons` WHERE id = ' . (int)$id);
        }
        foreach ($this->testTagIds as $id)
        {
            $this->db->query('DELETE FROM `piwigo_image_tag` WHERE tag_id = ' . (int)$id);
            $this->db->query('DELETE FROM `piwigo_tags` WHERE id = ' . (int)$id);
        }
        foreach ($this->testColorIds as $id)
        {
            $this->db->query('DELETE FROM `piwigo_typetags` WHERE id = ' . (int)$id);
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
                'INSERT IGNORE INTO `piwigo_tags` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')'
            );
        }
        $this->testTagIds = $this->testPersonIds = $this->testColorIds = $this->existingOrphans = array();

        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
        $this->ws->logout();
    }

    /** [HAPPY] [ECP] Each of the three cleanup paths removes an unused tag. */
    public function testEveryPathRemovesAnUnusedPlainTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $id = $this->makeTag('plain-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
            $this->assertTrue($this->tagExists($id), 'anti-vacuity: the tag must exist before the cleanup');

            $this->runCleanup($url);

            $this->assertFalse($this->tagExists($id), $path . ' removes an unused plain tag');
        }
    }

    /** [NEG] [ECP] A tag linked to a photo is not unused. */
    public function testATagOnAPhotoSurvives(): void
    {
        $id = $this->makeTag('used-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);
        $imageId = $this->fixture->createTestImage()['id'];
        $this->db->query("INSERT INTO `piwigo_image_tag` (image_id, tag_id) VALUES ($imageId, $id)");

        $this->runCleanup(self::PATHS['tags page']);

        $this->assertTrue($this->tagExists($id));
    }

    /** [BVA] A tag changed today is spared, one changed two days ago is not. */
    public function testATagChangedWithinTheLastDayIsSpared(): void
    {
        $young = $this->makeTag('young-' . bin2hex(random_bytes(3)), self::TOO_YOUNG_DAYS);
        $old = $this->makeTag('old-' . bin2hex(random_bytes(3)), self::OLD_ENOUGH_DAYS);

        $this->runCleanup(self::PATHS['tags page']);

        $this->assertTrue($this->tagExists($young), 'a tag younger than a day stays');
        $this->assertFalse($this->tagExists($old), 'anti-vacuity: the older tag goes, so the cleanup did run');
    }

    /** [ERR] An unused coloured tag is swept up with the plain ones. */
    public function testEveryPathRemovesAnUnusedColouredTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $id = $this->makeColouredTag('coloured-' . bin2hex(random_bytes(3)));
            $this->assertTrue($this->tagExists($id), 'anti-vacuity: the tag must exist before the cleanup');

            $this->runCleanup($url);

            $this->assertFalse($this->tagExists($id), $path . ' removes an unused coloured tag');
        }
    }

    /** [ERR] An unused person tag is swept up with the plain ones. */
    public function testEveryPathRemovesAnUnusedPersonTag(): void
    {
        foreach (self::PATHS as $path => $url)
        {
            $id = $this->makePersonTag('person-' . bin2hex(random_bytes(3)));
            $this->assertTrue($this->tagExists($id), 'anti-vacuity: the tag must exist before the cleanup');

            $this->runCleanup($url);

            $this->assertFalse($this->tagExists($id), $path . ' removes an unused person tag');
        }
    }

    /** [ERR] The tags page offers the unused tags' names to the confirm dialog, joined by the script. */
    public function testTheTagsPageListsTheUnusedNamesForTheConfirm(): void
    {
        $name = 'listed-' . bin2hex(random_bytes(3));
        $this->makeTag($name, self::OLD_ENOUGH_DAYS);

        $page = $this->ws->fetchPage('/admin.php?page=tags');

        $this->assertSame(200, $page['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$page['body']));
        $this->assertSame(1, preg_match('~var orphan_tag_names = \[([^\]]*)\];~', $page['body'], $m), 'the name list var');
        $this->assertStringContainsString('"' . $name . '"', $m[1]);
    }

    /** [ERR] The confirm and the tag menu strings as tags.tpl emits them: the count untranslated, the confirm in core's German. */
    public function testTheTagsPageEmitsTheCountAndConfirmStrings(): void
    {
        $page = $this->ws->fetchPage('/admin.php?page=tags');

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen((string)$page['body']));
        $this->assertStringNotContainsString('var str_number_photo =', $page['body']);
        $this->assertStringContainsString("var str_number_photos = '%d photos'", $page['body']);
        $this->assertStringContainsString("var str_orphan_tags = 'Sie haben %s1 verwaiste : %s2';", $page['body']);
    }

    private function runCleanup(string $url): void
    {
        $response = $this->ws->fetchPage($url . '&pwg_token=' . $this->ws->token());
        $this->assertSame(200, $response['http_code'], 'the cleanup page answers');
    }

    private function makeTag(string $name, int $ageDays): int
    {
        $escaped = $this->db->escape($name);
        $this->db->query(
            "INSERT INTO `piwigo_tags` (name, url_name, lastmodified) VALUES ('$escaped', '$escaped', NOW() - INTERVAL $ageDays DAY)"
        );
        $id = $this->db->insertId();
        $this->testTagIds[] = $id;

        return $id;
    }

    private function makeColouredTag(string $name): int
    {
        $this->db->query("INSERT INTO `piwigo_typetags` (name, color) VALUES ('" . $this->db->escape($name) . "', '#123456')");
        $colorId = $this->db->insertId();
        $this->testColorIds[] = $colorId;

        $id = $this->makeTag($name, self::OLD_ENOUGH_DAYS);
        $this->db->query(
            "UPDATE `piwigo_tags` SET id_typetags = $colorId, lastmodified = NOW() - INTERVAL " . self::OLD_ENOUGH_DAYS . " DAY WHERE id = $id"
        );
        $this->assertSame($colorId, (int)$this->db->scalar("SELECT id_typetags FROM `piwigo_tags` WHERE id = $id"), 'fixture colour did not take');

        return $id;
    }

    private function makePersonTag(string $name): int
    {
        $id = $this->makeTag($name, self::OLD_ENOUGH_DAYS);
        $escaped = $this->db->escape($name);
        $this->db->query("INSERT INTO `piwigo_persons` (name, url_name, tag_id) VALUES ('$escaped', '$escaped', $id)");
        $this->testPersonIds[] = $this->db->insertId();

        return $id;
    }

    private function tagExists(int $id): bool
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `piwigo_tags` WHERE id = $id") === 1;
    }
}
