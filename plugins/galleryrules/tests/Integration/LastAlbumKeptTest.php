<?php
use PHPUnit\Framework\TestCase;

/**
 * A photo's last album cannot be removed (design: "A photo's last album cannot
 * be removed"). Core let three paths take a photo out of every album, after
 * which only administrators could find it: the Batch Manager's "Von Album
 * abtrennen", the properties page and the API (pwg.images.setInfo with
 * categories in replace mode, pwg.images.setCategory dissociate). Each is
 * refused here; moving a photo to another album still works.
 *
 * Fixture, all this suite's own: generated PNGs in two throwaway albums. Runs
 * as the webmaster: every path is admin-only, and the gate is core's.
 */
final class LastAlbumKeptTest extends TestCase
{
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $client;
    private int $albumId;
    private int $otherAlbumId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();
        $this->albumId = $this->fixture->createTestAlbum('galleryrules-last-album-' . bin2hex(random_bytes(4)));
        $this->otherAlbumId = $this->fixture->createTestAlbum('galleryrules-last-album-other-' . bin2hex(random_bytes(4)));

        $this->client = new WsClient();
        list($user, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->client->login($user, $password);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [NEG][BVA] Batch Manager: "Von Album abtrennen" from the only album is refused. */
    public function testBatchDissociateFromTheOnlyAlbumIsRefused(): void
    {
        $photo = $this->photoIn($this->albumId);

        $body = $this->batchDissociate(array($photo), $this->albumId);

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the Batch Manager did not answer');
        $this->assertStringContainsString(sprintf(self::germanMessage('photo '), "#$photo"), $body);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
    }

    /** [NEG][ECP] One photo of the selection would be left in none: nothing of the selection is removed. */
    public function testBatchDissociateRefusesTheWholeSelection(): void
    {
        $alone = $this->photoIn($this->albumId);
        $twice = $this->photoIn($this->albumId);
        $this->fixture->attachImage($twice, $this->otherAlbumId);

        $body = $this->batchDissociate(array($alone, $twice), $this->albumId);

        $this->assertStringContainsString(sprintf(self::germanMessage('photo '), "#$alone"), $body);
        $this->assertSame(array($this->albumId), $this->albumsOf($alone));
        $this->assertSame($this->bothAlbums(), $this->albumsOf($twice));
    }

    /** [HAPPY] A photo in another album too is taken out as before. */
    public function testBatchDissociateWithAnotherAlbumLeftWorks(): void
    {
        $photo = $this->photoIn($this->albumId);
        $this->fixture->attachImage($photo, $this->otherAlbumId);

        $this->batchDissociate(array($photo), $this->albumId);

        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [HAPPY] Batch Manager: moving the photo to another album works. */
    public function testBatchMoveWorks(): void
    {
        $photo = $this->photoIn($this->albumId);

        $this->client->post('/admin.php?page=batch_manager&mode=global', array(
            'pwg_token' => $this->client->token(),
            'selection' => array($photo),
            'selectAction' => 'move',
            'move' => $this->otherAlbumId,
            'submit' => 1,
            ));

        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [NEG][BVA] Properties page: saving with no album keeps the album; the other fields are saved. */
    public function testPropertiesWithNoAlbumKeepTheAlbum(): void
    {
        $photo = $this->photoIn($this->albumId);
        $title = 'galleryrules last album ' . bin2hex(random_bytes(3));

        $body = $this->saveProperties($photo, array(), $title);

        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the properties page did not answer');
        $this->assertStringContainsString(sprintf(self::germanMessage('photo '), "#$photo"), $body);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
        $this->assertSame($title, $this->db->scalar("SELECT name FROM piwigo_images WHERE id = $photo"));
    }

    /** [HAPPY] Properties page: replacing the album with another moves the photo. */
    public function testPropertiesMoveToAnotherAlbumWorks(): void
    {
        $photo = $this->photoIn($this->albumId);

        $body = $this->saveProperties($photo, array($this->otherAlbumId), 'moved');

        $this->assertStringNotContainsString(sprintf(self::germanMessage('photo '), "#$photo"), $body);
        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [NEG][BVA] API: setInfo replacing the albums with none is refused, and nothing of the call is saved. */
    public function testSetInfoReplacingWithNoAlbumIsRefused(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->setInfo($photo, '', 'replace');

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'not refused: ' . $res['body']);
        $this->assertSame(409, (int)$res['json']['err']);
        $this->assertSame(sprintf(self::germanMessage('photo '), "#$photo"), $res['json']['message']);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
        $this->assertNotSame('refused name', $this->db->scalar("SELECT name FROM piwigo_images WHERE id = $photo"));
    }

    /** [NEG][ECP] Only tokens core skips is no album either. */
    public function testSetInfoWithOnlyInvalidTokensIsRefused(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->setInfo($photo, 'abc;', 'replace');

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'not refused: ' . $res['body']);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
    }

    /** [DT] setInfo replace removes even the storage album, so a photo in only that one is refused too. */
    public function testSetInfoRemovingTheStorageAlbumIsRefused(): void
    {
        $photo = $this->photoIn($this->albumId);
        $this->db->query("UPDATE piwigo_images SET storage_category_id = {$this->albumId} WHERE id = $photo");

        $res = $this->setInfo($photo, '', 'replace');

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'not refused: ' . $res['body']);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
    }

    /** [HAPPY] setInfo replacing the album with another moves the photo. */
    public function testSetInfoReplacingWithAnotherAlbumWorks(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->setInfo($photo, (string)$this->otherAlbumId, 'replace');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [ECP] Append mode never removes an album, so no categories at all passes. */
    public function testSetInfoAppendingNothingPasses(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->setInfo($photo, '', 'append');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
    }

    /** [NEG][BVA] API: setCategory dissociate from the only album is refused for all photos of the call. */
    public function testSetCategoryDissociateFromTheOnlyAlbumIsRefused(): void
    {
        $alone = $this->photoIn($this->albumId);
        $twice = $this->photoIn($this->albumId);
        $this->fixture->attachImage($twice, $this->otherAlbumId);

        $res = $this->setCategory(array($alone, $twice), $this->albumId, 'dissociate');

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'not refused: ' . $res['body']);
        $this->assertSame(409, (int)$res['json']['err']);
        $this->assertSame(sprintf(self::germanMessage('photo '), "#$alone"), $res['json']['message']);
        $this->assertSame(array($this->albumId), $this->albumsOf($alone));
        $this->assertSame($this->bothAlbums(), $this->albumsOf($twice));
    }

    /** [ECP] Several photos left in none are all named, in the plural. */
    public function testSetCategoryNamesEveryPhotoLeftInNone(): void
    {
        $first = $this->photoIn($this->albumId);
        $second = $this->photoIn($this->albumId);

        $res = $this->setCategory(array($second, $first), $this->albumId, 'dissociate');

        $this->assertSame(sprintf(self::germanMessage('photos '), "#$first, #$second"), $res['json']['message'] ?? null, $res['body']);
    }

    /** [HAPPY] setCategory dissociate with another album left works. */
    public function testSetCategoryDissociateWithAnotherAlbumLeftWorks(): void
    {
        $photo = $this->photoIn($this->albumId);
        $this->fixture->attachImage($photo, $this->otherAlbumId);

        $res = $this->setCategory(array($photo), $this->albumId, 'dissociate');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [HAPPY] setCategory move works. */
    public function testSetCategoryMoveWorks(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->setCategory(array($photo), $this->otherAlbumId, 'move');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [NEG] A wrong token is still core's refusal, not this rule's. */
    public function testSetCategoryWithAWrongTokenIsCoresRefusal(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->client->call('pwg.images.setCategory', array(
            'image_id' => array($photo),
            'category_id' => $this->albumId,
            'action' => 'dissociate',
            'pwg_token' => 'wrong',
            ));

        $this->assertSame(403, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
    }

    /**
     * The refusal as the plugin's German strings give it; the test accounts run in German.
     *
     * @param string $form 'photo ' or 'photos ', the start of the English key after "removed: "
     */
    private static function germanMessage(string $form): string
    {
        $lang = array();
        include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';
        $german = array_values(array_filter(
            $lang,
            fn ($key) => str_starts_with($key, 'Nothing was removed: ' . $form),
            ARRAY_FILTER_USE_KEY
        ));
        if (count($german) !== 1)
        {
            throw new RuntimeException('expected one German refusal string, found ' . count($german));
        }
        return $german[0];
    }

    private function photoIn(int $albumId): int
    {
        $id = $this->fixture->createTestImage();
        $this->fixture->attachImage($id, $albumId);
        $this->assertSame(array($albumId), $this->albumsOf($id), 'anti-vacuity: the photo starts in one album');
        return $id;
    }

    /** @return int[] the photo's albums, ascending */
    private function albumsOf(int $imageId): array
    {
        $albums = array();
        $result = $this->db->query("SELECT category_id FROM piwigo_image_category WHERE image_id = $imageId ORDER BY category_id");
        while ($row = $result->fetch_assoc())
        {
            $albums[] = (int)$row['category_id'];
        }
        return $albums;
    }

    private function bothAlbums(): array
    {
        $both = array($this->albumId, $this->otherAlbumId);
        sort($both);
        return $both;
    }

    private function batchDissociate(array $imageIds, int $albumId): string
    {
        return $this->client->post('/admin.php?page=batch_manager&mode=global', array(
            'pwg_token' => $this->client->token(),
            'selection' => $imageIds,
            'selectAction' => 'dissociate',
            'dissociate' => $albumId,
            'submit' => 1,
            ))['body'];
    }

    /** The properties page's form, as the browser sends it with these albums. */
    private function saveProperties(int $imageId, array $albums, string $title): string
    {
        $fields = array(
            'name' => $title,
            'author' => '',
            'comment' => '',
            'date_creation' => '',
            'level' => 0,
            'pwg_token' => $this->client->token(),
            'submit' => 1,
            );
        if (count($albums) > 0)
        {
            $fields['associate'] = $albums;
        }
        return $this->client->post('/admin.php?page=photo-' . $imageId . '-properties', $fields)['body'];
    }

    private function setInfo(int $imageId, string $categories, string $mode): array
    {
        return $this->client->call('pwg.images.setInfo', array(
            'image_id' => $imageId,
            'name' => 'refused name',
            'categories' => $categories,
            'multiple_value_mode' => $mode,
            'pwg_token' => $this->client->token(),
            ));
    }

    private function setCategory(array $imageIds, int $albumId, string $action): array
    {
        return $this->client->call('pwg.images.setCategory', array(
            'image_id' => $imageIds,
            'category_id' => $albumId,
            'action' => $action,
            'pwg_token' => $this->client->token(),
            ));
    }
}
