<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pwg.photoinfo.undo: per field the inverse reaches the database and the
 * file and appends an undo entry; refusals change nothing.
 *
 * Requirement: design "Undo runs on the server", "Who may undo", "Conflicts,
 * undo rows, no redo" and "Undone entries".
 */
final class UndoTest extends TestCase
{
    private const ENTRY_INFO = 'Die Taufe im Garten';
    private const NEW_INFO = 'Neu';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private int $albumId;
    private string $suffix;
    /** @var WsClient[] */
    private array $clients = array();
    /** @var string[] */
    private array $typedNames = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');
        $this->fixture->assertPluginActive('typetags');

        $this->suffix = bin2hex(random_bytes(4));
        $this->image = $this->fixture->createTestImage();
        FixtureBuilder::stripRegionsAndKeywords($this->image['file']);
        $this->albumId = $this->fixture->placeInPublicAlbum($this->image);
    }

    protected function tearDown(): void
    {
        foreach ($this->typedNames as $name)
        {
            $this->fixture->trackTagsNamed($name);
        }
        $this->fixture->destroyTestTags();
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [HAPPY] A normal account undoes its own tag add: gone from the photo and its file, an undo entry in the list. */
    public function testOwnTagAddIsUndone(): void
    {
        $tag = $this->fixture->createTag($this->tagName('Kirche'), $this->fixture->createGroup('Feste ' . $this->suffix));
        $this->call(TestUsers::NORMAL, 'typetags.image.addTag', array('image_id' => $this->image['id'], 'tag_id' => $tag));
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']), 'anti-vacuity: the tag was not added');
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_TAG);

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array(), $this->fixture->tagIdsOf($this->image['id']));
        $this->assertSame(array(), FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
        $this->assertTrue($res['json']['result']['tags_written'], $res['body']);
        $undo = $this->latest(PROVENANCE_HISTORY_FIELD_TAG);
        $this->assertNotSame($entry['id'], $undo['id']);
        $this->assertSame((int)$entry['id'], (int)$undo['undoes_id']);
        $this->assertSame(PROVENANCE_HISTORY_SOURCE_UNDO, $undo['source']);
        $this->assertSame($entry['new_value'], $undo['old_value']);
        $this->assertNull($undo['new_value']);
        $this->assertSame($this->userId(TestUsers::NORMAL), (int)$undo['performed_by']);
    }

    /** [HAPPY] Undoing a removal puts the tag back, in the file too. */
    public function testATagRemovalIsUndone(): void
    {
        $name = $this->tagName('Kirche');
        $tag = $this->fixture->createTag($name, $this->fixture->createGroup('Feste ' . $this->suffix));
        $this->fixture->linkTag($this->image['id'], $tag);
        $this->call(TestUsers::NORMAL, 'typetags.image.removeTag', array('image_id' => $this->image['id'], 'tag_id' => $tag));
        $this->assertSame(array(), $this->fixture->tagIdsOf($this->image['id']), 'anti-vacuity: the tag was not removed');

        $res = $this->undo(TestUsers::NORMAL, $this->latest(PROVENANCE_HISTORY_FIELD_TAG)['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array($tag), $this->fixture->tagIdsOf($this->image['id']));
        $this->assertSame(array($name), FixtureBuilder::readKeywords($this->image['file'])['XMP-dc:Subject']);
    }

    /** [NEG] A tag that was removed again by someone else is a conflict, and so is one that is gone. */
    public function testATagChangedSinceIsRefused(): void
    {
        $tag = $this->fixture->createTag($this->tagName('Kirche'), $this->fixture->createGroup('Feste ' . $this->suffix));
        $this->call(TestUsers::NORMAL, 'typetags.image.addTag', array('image_id' => $this->image['id'], 'tag_id' => $tag));
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_TAG);
        $this->call(TestUsers::WEBMASTER, 'typetags.image.removeTag', array('image_id' => $this->image['id'], 'tag_id' => $tag));
        $this->call(TestUsers::WEBMASTER, 'typetags.image.addTag', array('image_id' => $this->image['id'], 'tag_id' => $tag));
        $this->call(TestUsers::WEBMASTER, 'typetags.image.removeTag', array('image_id' => $this->image['id'], 'tag_id' => $tag));
        $entries = $this->entryCount();

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertRefused($res, 409, 'Wurde inzwischen von ' . Config::credentials(TestUsers::WEBMASTER)[0] . ' geändert');
        $this->assertSame($entries, $this->entryCount());
    }

    /** [HAPPY] A date undo restores the dating and writes the file; no date before is no date after. */
    public function testADateIsUndone(): void
    {
        $this->fixture->setDate($this->image['id'], '1965-03-01 00:00:00', 'month');
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setDate', array('image_id' => $this->image['id'], 'qualifier' => 'circa', 'year' => '1971'));
        $this->assertSame('1971-01-01 00:00:00', $this->fixture->imageRow($this->image['id'])['date_creation'], 'anti-vacuity: the date was not saved');
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_DATE);

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertTrue($res['json']['result']['written'], $res['body']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame('1965-03-01 00:00:00', $row['date_creation']);
        $this->assertSame('1965-03', FixtureBuilder::readDateTags($this->image['file'])['XMP-pwginfo:DateEDTF']);
        $undo = $this->latest(PROVENANCE_HISTORY_FIELD_DATE);
        $this->assertSame((int)$entry['id'], (int)$undo['undoes_id']);
        $this->assertSame($entry['new_value'], $undo['old_value']);
        $this->assertSame($entry['old_value'], $undo['new_value']);
    }

    /** [ECP] The photo had no date before: the undo clears it. */
    public function testADateSetOnAPhotoWithoutOneIsUndoneToNone(): void
    {
        $this->fixture->setDate($this->image['id'], null, null);
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setDate', array('image_id' => $this->image['id'], 'year' => '1971'));
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_DATE);
        $this->assertNull($entry['old_value'], 'anti-vacuity: the photo had a date');

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNull($this->fixture->imageRow($this->image['id'])['date_creation']);
    }

    /** [HAPPY] An info undo restores the text in the row and the file. */
    public function testAnInfoIsUndone(): void
    {
        $this->fixture->setComment($this->image['id'], self::ENTRY_INFO);
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => self::NEW_INFO));
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_INFO);

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(self::ENTRY_INFO, $this->fixture->imageRow($this->image['id'])['comment']);
        $this->assertSame(self::ENTRY_INFO, FixtureBuilder::readFileTags($this->image['file'])['XMP-pwginfo:Info']);
    }

    /** [ST] The info save after an undo is an entry of its own, never merged into the undone one. */
    public function testALaterInfoSaveIsNotMergedIntoAnUndoneEntry(): void
    {
        $this->fixture->setComment($this->image['id'], self::ENTRY_INFO);
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => self::NEW_INFO));
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_INFO);
        $this->undo(TestUsers::NORMAL, $entry['id']);
        $this->assertSame(2, $this->entryCount(PROVENANCE_HISTORY_FIELD_INFO), 'anti-vacuity: no undo entry');

        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => 'Dritter Text'));

        $this->assertSame(3, $this->entryCount(PROVENANCE_HISTORY_FIELD_INFO));
        $this->assertSame(self::NEW_INFO, $this->entry($entry['id'])['new_value'], 'the undone entry changed');
        $latest = $this->latest(PROVENANCE_HISTORY_FIELD_INFO);
        $this->assertNull($latest['undoes_id']);
        $this->assertSame(self::ENTRY_INFO, $latest['old_value']);
    }

    /** [NEG] A later change of the same field by another account refuses the undo, naming that account. */
    public function testAnInfoChangedSinceIsRefusedWithTheAccountsName(): void
    {
        $this->fixture->setComment($this->image['id'], self::ENTRY_INFO);
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => self::NEW_INFO));
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_INFO);
        $this->call(TestUsers::WEBMASTER, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => 'Vom Webmaster'));
        $entries = $this->entryCount();

        $res = $this->undo(TestUsers::NORMAL, $entry['id']);

        $this->assertRefused($res, 409, 'Wurde inzwischen von ' . Config::credentials(TestUsers::WEBMASTER)[0] . ' geändert');
        $this->assertSame('Vom Webmaster', $this->fixture->imageRow($this->image['id'])['comment']);
        $this->assertSame($entries, $this->entryCount());
    }

    /** [NEG] A value changed outside the log names nobody in particular. */
    public function testAValueChangedOutsideTheLogIsRefused(): void
    {
        $this->fixture->setComment($this->image['id'], self::ENTRY_INFO);
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => self::NEW_INFO));
        $this->fixture->setComment($this->image['id'], 'Per Rescan geändert');

        $res = $this->undo(TestUsers::NORMAL, $this->latest(PROVENANCE_HISTORY_FIELD_INFO)['id']);

        $this->assertRefused($res, 409, 'Wurde inzwischen von jemand anderem geändert');
    }

    /** [NEG] An entry undone already is refused, and so is the undo entry itself. */
    public function testAnUndoneEntryAndAnUndoEntryAreRefused(): void
    {
        $this->fixture->setComment($this->image['id'], self::ENTRY_INFO);
        $this->call(TestUsers::NORMAL, 'pwg.photoinfo.setInfo', array('image_id' => $this->image['id'], 'info' => self::NEW_INFO));
        $entry = $this->latest(PROVENANCE_HISTORY_FIELD_INFO);
        $this->undo(TestUsers::NORMAL, $entry['id']);
        $undo = $this->latest(PROVENANCE_HISTORY_FIELD_INFO);
        $entries = $this->entryCount();

        $again = $this->undo(TestUsers::NORMAL, $entry['id']);
        $ofUndo = $this->undo(TestUsers::NORMAL, $undo['id']);

        $this->assertRefused($again, 409, 'Wurde schon rückgängig gemacht');
        $this->assertRefused($ofUndo, 403, 'Das darfst du nicht rückgängig machen');
        $this->assertSame($entries, $this->entryCount());
        $this->assertSame(self::ENTRY_INFO, $this->fixture->imageRow($this->image['id'])['comment']);
    }

    /** [NEG] Region entries have no undo yet, and a photo entry never has one. */
    public function testRegionAndPhotoEntriesAreRefused(): void
    {
        $userId = $this->userId(TestUsers::NORMAL);
        foreach (array(PROVENANCE_HISTORY_FIELD_REGION, PROVENANCE_HISTORY_FIELD_PHOTO) as $field)
        {
            $id = $this->insertEntry($field, null, '{"x":1}', $userId);

            $res = $this->undo(TestUsers::NORMAL, $id);

            $this->assertRefused($res, 403, 'Das darfst du nicht rückgängig machen', $field);
        }
    }

    /** @return array[] role => owner of the entry => may undo */
    public static function rights(): array
    {
        $cases = array();
        foreach (array(TestUsers::GENERIC, TestUsers::NORMAL, TestUsers::ADMIN, TestUsers::WEBMASTER) as $role)
        {
            $isAdmin = in_array($role, array(TestUsers::ADMIN, TestUsers::WEBMASTER), true);
            $other = $role === TestUsers::NORMAL ? TestUsers::GENERIC : TestUsers::NORMAL;
            $cases["[HAPPY] $role, own entry"] = array($role, $role, true);
            $cases["[DT] $role, another account's entry" . ($isAdmin ? '' : ' [NEG]')] = array($role, $other, $isAdmin);
        }
        $cases['[BVA] a webmaster, an administrator\'s entry'] = array(TestUsers::WEBMASTER, TestUsers::ADMIN, true);
        $cases['[BVA] an administrator, a webmaster\'s entry'] = array(TestUsers::ADMIN, TestUsers::WEBMASTER, true);

        return $cases;
    }

    #[DataProvider('rights')]
    public function testRights(string $role, string $owner, bool $allowed): void
    {
        $this->fixture->setComment($this->image['id'], self::NEW_INFO);
        $id = $this->insertEntry(PROVENANCE_HISTORY_FIELD_INFO, self::ENTRY_INFO, self::NEW_INFO, $this->userId($owner));
        $before = $this->entryCount();

        $res = $this->undo($role, $id);

        if ($allowed)
        {
            $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
            $this->assertSame(self::ENTRY_INFO, $this->fixture->imageRow($this->image['id'])['comment']);
            $this->assertSame($this->userId($role), (int)$this->latest(PROVENANCE_HISTORY_FIELD_INFO)['performed_by']);
        }
        else
        {
            $this->assertRefused($res, 403, 'Das darfst du nicht rückgängig machen');
            $this->assertSame(self::NEW_INFO, $this->fixture->imageRow($this->image['id'])['comment']);
            $this->assertSame($before, $this->entryCount());
        }
    }

    /** [NEG] A photo the account cannot see is answered like a missing entry. */
    public function testAPrivatePhotoIsNotFound(): void
    {
        $this->fixture->setComment($this->image['id'], self::NEW_INFO);
        $id = $this->insertEntry(PROVENANCE_HISTORY_FIELD_INFO, self::ENTRY_INFO, self::NEW_INFO, $this->userId(TestUsers::NORMAL));
        $this->fixture->makeAlbumPrivate($this->albumId);

        $res = $this->undo(TestUsers::NORMAL, $id);

        $this->assertSame(404, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(self::NEW_INFO, $this->fixture->imageRow($this->image['id'])['comment']);
    }

    /** [NEG] An entry that does not exist, a bad token, and a get request. */
    public function testBadRequests(): void
    {
        $missing = $this->undo(TestUsers::NORMAL, 2147483000);
        $this->assertSame(404, (int)($missing['json']['err'] ?? 0), $missing['body']);

        $badToken = $this->client(TestUsers::NORMAL)->call('pwg.photoinfo.undo', array('history_id' => 1, 'pwg_token' => 'wrong'));
        $this->assertSame(403, (int)($badToken['json']['err'] ?? 0), $badToken['body']);

        $get = $this->client(TestUsers::NORMAL)->callGet('pwg.photoinfo.undo', array('history_id' => 1, 'pwg_token' => $this->client(TestUsers::NORMAL)->token()));
        $this->assertSame('fail', $get['json']['stat'] ?? null, $get['body']);
    }

    /** [HAPPY] The list shows Rückgängig on an entry the viewer may undo, and on no other. */
    public function testThePageOffersUndoToTheRightViewers(): void
    {
        $this->fixture->setComment($this->image['id'], self::NEW_INFO);
        $id = $this->insertEntry(PROVENANCE_HISTORY_FIELD_INFO, self::ENTRY_INFO, self::NEW_INFO, $this->userId(TestUsers::NORMAL));
        $needle = 'data-history-id="' . $id . '"';

        $this->assertStringContainsString($needle, $this->page(TestUsers::NORMAL), 'the owner');
        $this->assertStringContainsString($needle, $this->page(TestUsers::ADMIN), 'an administrator');
        $this->assertStringNotContainsString($needle, $this->page(TestUsers::GENERIC), 'another account');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function assertRefused(array $res, int $code, string $text, string $label = ''): void
    {
        $this->assertSame('fail', $res['json']['stat'] ?? null, $label . ' ' . $res['body']);
        $this->assertSame($code, (int)$res['json']['err'], $label . ' ' . $res['body']);
        $this->assertSame($text, $res['json']['message'], $label);
    }

    private function undo(string $role, int $historyId): array
    {
        return $this->call($role, 'pwg.photoinfo.undo', array('history_id' => $historyId));
    }

    private function call(string $role, string $method, array $params): array
    {
        $client = $this->client($role);
        $res = $client->call($method, $params + array('pwg_token' => $client->token()));
        if (strpos($method, 'undo') === false)
        {
            $this->assertSame('ok', $res['json']['stat'] ?? null, 'setup call ' . $method . ': ' . $res['body']);
        }

        return $res;
    }

    private function client(string $role): WsClient
    {
        if (!isset($this->clients[$role]))
        {
            $client = new WsClient();
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
            $this->clients[$role] = $client;
        }

        return $this->clients[$role];
    }

    private function page(string $role): string
    {
        $page = $this->client($role)->fetchPage('/picture.php?/' . $this->image['id'] . '/category/' . $this->albumId);
        $this->assertStringContainsString('id="PhotoHistory"', $page, "anti-vacuity: $role got no change list");

        return $page;
    }

    private function userId(string $role): int
    {
        $id = (int)$this->db->scalar("SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape(Config::credentials($role)[0]) . "'");
        $this->assertGreaterThan(0, $id, "anti-vacuity: no account for $role");

        return $id;
    }

    /** An entry written straight into the log, as another account would have saved it. */
    private function insertEntry(string $field, ?string $old, ?string $new, int $userId): int
    {
        $quote = fn (?string $value) => $value === null ? 'NULL' : "'" . $this->db->escape($value) . "'";
        $this->db->query('INSERT INTO piwigo_provenance_history (object, object_id, field, old_value, new_value, source, performed_by)'
            . " VALUES ('photo', " . $this->image['id'] . ", '$field', " . $quote($old) . ', ' . $quote($new) . ", 'picture_page', $userId)");

        return $this->db->insertId();
    }

    /** @return array the photo's newest entry of the field */
    private function latest(string $field): array
    {
        $result = $this->db->query("SELECT * FROM piwigo_provenance_history WHERE object = 'photo' AND object_id = "
            . $this->image['id'] . " AND field = '$field' ORDER BY id DESC LIMIT 1");
        $row = $result->fetch_assoc();
        $this->assertNotNull($row, "anti-vacuity: no $field entry");
        $row['id'] = (int)$row['id'];

        return $row;
    }

    private function entry(int $id): array
    {
        return $this->db->query("SELECT * FROM piwigo_provenance_history WHERE id = $id")->fetch_assoc();
    }

    private function entryCount(?string $field = null): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_provenance_history WHERE object = 'photo' AND object_id = "
            . $this->image['id'] . ($field === null ? '' : " AND field = '$field'"));
    }

    private function tagName(string $base): string
    {
        $name = $base . ' ' . $this->suffix;
        $this->typedNames[] = $name;

        return $name;
    }
}
