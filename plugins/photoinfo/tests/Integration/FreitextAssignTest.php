<?php
use PHPUnit\Framework\TestCase;

/**
 * A tag typed in while tagging a photo lands in the Freitext group before the
 * photo's file is written. Each tagging path over its real boundary, the file
 * read back with a plain exiftool call.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", Phase 5 and its
 * Desired End State 2.
 *
 * One case deactivates photoinfo and reactivates it in tearDown, another
 * renames the install's Freitext group away and back. A run killed in between
 * leaves photoinfo off, or the group renamed ("Freitext hidden ..."), which
 * the next run refuses to start over; put either back by hand.
 */
final class FreitextAssignTest extends TestCase
{
    /** A rendered admin page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private int $albumId;
    private array $image;
    private string $suffix;
    private array $freitext;
    private bool $photoinfoOff = false;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('typetags');

        $this->suffix = bin2hex(random_bytes(4));
        $this->albumId = $this->fixture->createTestAlbum('photoinfo-freitext-' . $this->suffix);
        $this->image = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->fixture->attachImage($this->image['id'], $this->albumId);
        $this->freitext = $this->fixture->freitextGroup();

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);
    }

    protected function tearDown(): void
    {
        if ($this->photoinfoOff)
        {
            $this->performPluginAction('activate', 'photoinfo');
            $this->fixture->assertPluginActive('photoinfo');
        }
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] A name typed into the photo properties tag field. */
    public function testANameTypedOnThePropertiesScreenGoesToFreitext(): void
    {
        $name = $this->tagName('Kirmes');

        $this->postProperties(array('tags' => array($name)));

        $this->assertInFreitextAndFile($name);
    }

    /** [HAPPY] A name typed into the Batch Manager's add tags field. */
    public function testANameTypedInTheBatchManagerGoesToFreitext(): void
    {
        $name = $this->tagName('Kirmes');

        $this->postBatch(array('selectAction' => 'add_tags', 'add_tags' => array($name)));

        $this->assertInFreitextAndFile($name);
    }

    /** [HAPPY] pwg.images.setInfo's tag_list, which the Batch Manager's unit mode sends. */
    public function testANameInSetInfosTagListGoesToFreitext(): void
    {
        $name = $this->tagName('Kirmes');

        $res = $this->ws->call('pwg.images.setInfo', array(
            'image_id' => $this->image['id'],
            'tag_list' => array($name),
            'single_value_mode' => 'replace',
            'multiple_value_mode' => 'replace',
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $this->assertInFreitextAndFile($name);
    }

    /** [HAPPY] typetags' field on the picture page; the answer carries the Freitext badge. */
    public function testANameTypedOnThePicturePageGoesToFreitext(): void
    {
        $name = $this->tagName('Kirmes');

        $res = $this->addNewTag($name);

        $this->assertInFreitextAndFile($name);
        $answer = $res['json']['result'];
        $this->assertTrue($answer['created']);
        $this->assertSame($this->fixture->tagIdNamed($name), $answer['tag_id']);
        $this->assertNotSame('', $this->freitext['color'], 'anti-vacuity: the Freitext group has a colour');
        $this->assertStringContainsStringIgnoringCase($this->freitext['color'], $answer['style'], 'the answer still styles the tag as ungrouped');
        $this->assertNotSame('', $this->freitext['emoji'], 'anti-vacuity: the Freitext group has an emoji');
        foreach (preg_split('/\s+/', $this->freitext['emoji']) as $codepoint)
        {
            $this->assertStringContainsStringIgnoringCase('&#x' . $codepoint . ';', $answer['emoji_html']);
        }
        $this->assertTrue($answer['tags_written'], $res['body']);
    }

    /** [ECP] An existing name typed on the picture page is linked and keeps its group. */
    public function testAnExistingGroupedNameKeepsItsGroup(): void
    {
        $group = $this->fixture->createGroup('Feste ' . $this->suffix);
        $name = $this->tagName('Kirmes');
        $tag = $this->fixture->createTag($name, $group);

        $res = $this->addNewTag($name);

        $this->assertFalse($res['json']['result']['created']);
        $this->assertSame($group, $this->fixture->groupOf($tag));
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
        $this->assertFileTags(array($name), array('Feste ' . $this->suffix . '|' . $name));
    }

    /** [NEG] An existing ungrouped tag picked by id is not typed in, so it stays out of Freitext. */
    public function testAnExistingUngroupedTagStaysUngrouped(): void
    {
        $name = $this->tagName('Kirmes');
        $tag = $this->fixture->createTag($name);

        $this->postProperties(array('tags' => array('~~' . $tag . '~~')));

        $this->assertNull($this->fixture->groupOf($tag));
        $this->assertFileTags(array($name), array());
    }

    /** [NEG] A tag created on the admin tags page is deliberate and gets its group there. */
    public function testATagAddedOnTheTagsPageStaysUngrouped(): void
    {
        $name = $this->tagName('Kirmes');

        $res = $this->ws->call('pwg.tags.add', array('name' => $name, 'pwg_token' => $this->ws->token()));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);

        $this->assertNull($this->fixture->groupOf($this->fixture->tagIdNamed($name)));
    }

    /** [NEG] Without a Freitext group the typed tag stays ungrouped and the file is still written. */
    public function testWithoutTheGroupTheTagStaysUngroupedAndIsWritten(): void
    {
        $this->fixture->hideFreitextGroup();
        $name = $this->tagName('Kirmes');

        $this->postProperties(array('tags' => array($name)));

        $this->assertNull($this->fixture->groupOf($this->fixture->tagIdNamed($name)));
        $this->assertFileTags(array($name), array());
    }

    /** [NEG] Without photoinfo a name typed on the picture page stays ungrouped and plain, and no file is written. */
    public function testWithoutPhotoinfoATypedTagStaysUngroupedAndPlain(): void
    {
        $this->performPluginAction('deactivate', 'photoinfo');
        $this->photoinfoOff = true;
        $name = $this->tagName('Kirmes');

        $res = $this->addNewTag($name);

        $answer = $res['json']['result'];
        $this->assertTrue($answer['created']);
        $this->assertNull($this->fixture->groupOf($this->fixture->tagIdNamed($name)));
        $this->assertSame('', $answer['style']);
        $this->assertSame('', $answer['emoji_html']);
        $this->assertArrayNotHasKey('tags_written', $answer);
        $this->assertNull(FixtureBuilder::readKeywords($this->image['file'])['XMP-pwginfo:TagsWritten'], 'a file was written');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function performPluginAction(string $action, string $plugin): void
    {
        $res = $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action, 'plugin' => $plugin, 'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
    }

    /** A name unique to this run, so no real tag of the install is touched. */
    private function tagName(string $base): string
    {
        return $base . ' ' . $this->suffix;
    }

    private function assertInFreitextAndFile(string $name): void
    {
        $tag = $this->fixture->tagIdNamed($name);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']), 'the typed tag is not linked');
        $this->assertSame($this->freitext['id'], $this->fixture->groupOf($tag), 'the typed tag is not in Freitext');
        $this->assertFileTags(array($name), array(PHOTOINFO_FREITEXT_GROUP . PHOTOINFO_HIERARCHY_SEPARATOR . $name));
    }

    private function assertFileTags(array $subject, array $hierarchy): void
    {
        $tags = FixtureBuilder::readKeywords($this->image['file']);

        $this->assertSame('1', $tags['XMP-pwginfo:TagsWritten'], 'the marker is missing');
        $this->assertSame($subject, $tags['XMP-dc:Subject'], 'XMP-dc:Subject');
        $this->assertSame($hierarchy, $tags['XMP-lr:HierarchicalSubject'], 'XMP-lr:HierarchicalSubject');
    }

    private function addNewTag(string $name): array
    {
        $res = $this->ws->call('typetags.image.addNewTag', array(
            'image_id' => $this->image['id'],
            'tag_name' => $name,
            'pwg_token' => $this->ws->token(),
            ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        return $res;
    }

    private function postProperties(array $fields): void
    {
        $row = $this->fixture->imageRow($this->image['id']);
        $body = $this->ws->postPage('/admin.php?page=photo-' . $this->image['id'] . '-properties', array_merge(array(
            'name' => (string)$row['name'],
            'author' => (string)$row['author'],
            'comment' => (string)$row['comment'],
            'date_creation' => (string)$row['date_creation'],
            'level' => 0,
            'associate' => array($this->albumId),
            'pwg_token' => $this->ws->token(),
            'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the properties screen did not answer');
        $this->assertStringNotContainsString(PHOTOINFO_ADMIN_ERROR_PREFIX, $body, 'a file write failed');
    }

    private function postBatch(array $fields): void
    {
        $body = $this->ws->postPage('/admin.php?page=batch_manager&mode=global', array_merge(array(
            'pwg_token' => $this->ws->token(),
            'selection' => array($this->image['id']),
            'submit' => 1,
            ), $fields));

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the Batch Manager did not answer');
        $this->assertStringNotContainsString(PHOTOINFO_ADMIN_ERROR_PREFIX, $body, 'a file write failed');
    }
}
