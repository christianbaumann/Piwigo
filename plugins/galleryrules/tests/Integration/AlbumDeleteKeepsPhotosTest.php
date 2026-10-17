<?php
use PHPUnit\Framework\TestCase;

/**
 * Deleting an album with "keep photos" (pwg.categories.delete, photo_deletion_mode
 * no_delete) is refused while it would leave a photo in no album. Both screens
 * that delete an album (the album's properties page and Alben > Verwaltung) call
 * that method. Photos deleted with the album (delete_orphans, force_delete) and
 * photos with another album are unaffected.
 *
 * Fixture, all this suite's own: generated PNGs in throwaway albums. Runs as the
 * webmaster.
 */
final class AlbumDeleteKeepsPhotosTest extends TestCase
{
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
        $this->albumId = $this->fixture->createTestAlbum('galleryrules-album-delete-' . bin2hex(random_bytes(4)));
        $this->otherAlbumId = $this->fixture->createTestAlbum('galleryrules-album-delete-other-' . bin2hex(random_bytes(4)));

        $this->client = new WsClient();
        list($user, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->client->login($user, $password);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [NEG][BVA] The photo's only album, kept photos: refused, naming the photo, album and link stay. */
    public function testDeletingTheOnlyAlbumOfAPhotoKeepingPhotosIsRefused(): void
    {
        $photo = $this->photoIn($this->albumId);

        $res = $this->deleteAlbum($this->albumId, 'no_delete');

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'not refused: ' . $res['body']);
        $this->assertSame(409, (int)$res['json']['err']);
        $this->assertSame(sprintf(self::germanMessage('photo '), "#$photo"), $res['json']['message']);
        $this->assertSame(1, $this->albumCount($this->albumId));
        $this->assertSame(array($this->albumId), $this->albumsOf($photo));
    }

    /** [ECP] Every photo left in none is named, in the plural; a photo with another album is not. */
    public function testEveryPhotoLeftInNoneIsNamed(): void
    {
        $first = $this->photoIn($this->albumId);
        $second = $this->photoIn($this->albumId);
        $safe = $this->photoIn($this->albumId);
        $this->fixture->attachImage($safe, $this->otherAlbumId);

        $res = $this->deleteAlbum($this->albumId, 'no_delete');

        $this->assertSame(409, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(sprintf(self::germanMessage('photos '), "#$first, #$second"), $res['json']['message']);
        $this->assertSame(1, $this->albumCount($this->albumId));
    }

    /** [HAPPY] Every photo has another album: the album goes, the photos stay in the other. */
    public function testDeletingAnAlbumWhosePhotosAreElsewhereWorks(): void
    {
        $photo = $this->photoIn($this->albumId);
        $this->fixture->attachImage($photo, $this->otherAlbumId);

        $res = $this->deleteAlbum($this->albumId, 'no_delete');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, $this->albumCount($this->albumId));
        $this->assertSame(array($this->otherAlbumId), $this->albumsOf($photo));
    }

    /** [HAPPY] An empty album goes. */
    public function testDeletingAnEmptyAlbumWorks(): void
    {
        $res = $this->deleteAlbum($this->albumId, 'no_delete');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, $this->albumCount($this->albumId));
    }

    /** [ST] A photo of a sub-album is deleted with its parent: refused too. */
    public function testASubAlbumsOnlyPhotoCountsForTheParent(): void
    {
        $child = $this->fixture->createTestAlbum('galleryrules-album-delete-child-' . bin2hex(random_bytes(4)), $this->albumId);
        $photo = $this->photoIn($child);

        $res = $this->deleteAlbum($this->albumId, 'no_delete');

        $this->assertSame(409, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertStringContainsString("#$photo", $res['json']['message']);
        $this->assertSame(1, $this->albumCount($child));
    }

    /** [ST] A photo linked to the parent and its sub-album, nowhere else, is left in none too. */
    public function testAPhotoInParentAndChildOnlyIsRefused(): void
    {
        $child = $this->fixture->createTestAlbum('galleryrules-album-delete-child-' . bin2hex(random_bytes(4)), $this->albumId);
        $photo = $this->photoIn($this->albumId);
        $this->fixture->attachImage($photo, $child);

        $res = $this->deleteAlbum($this->albumId, 'no_delete');

        $this->assertSame(409, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(1, $this->albumCount($this->albumId));
    }

    /** [DT] Deleting the photos with the album (orphans only, or all) is the user's choice and passes. */
    public function testDeletingThePhotosWithTheAlbumPasses(): void
    {
        $orphan = $this->photoIn($this->albumId);

        $res = $this->deleteAlbum($this->albumId, 'delete_orphans');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, $this->albumCount($this->albumId));
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = $orphan"));
    }

    /** [DT] force_delete passes. */
    public function testForceDeletePasses(): void
    {
        $photo = $this->photoIn($this->albumId);
        $this->fixture->attachImage($photo, $this->otherAlbumId);

        $res = $this->deleteAlbum($this->albumId, 'force_delete');

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(0, $this->albumCount($this->albumId));
    }

    /** [NEG] A wrong token is core's 403, not this rule's refusal. */
    public function testAWrongTokenIsCoresRefusal(): void
    {
        $this->photoIn($this->albumId);

        $res = $this->client->call('pwg.categories.delete', array(
            'category_id' => $this->albumId,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => 'wrong',
            ));

        $this->assertSame(403, (int)($res['json']['err'] ?? 0), $res['body']);
        $this->assertSame(1, $this->albumCount($this->albumId));
    }

    private static function germanMessage(string $form): string
    {
        $lang = array();
        include GALLERYRULES_PATH . 'language/de_DE/plugin.lang.php';
        $german = array_values(array_filter(
            $lang,
            fn ($key) => str_starts_with($key, 'Nothing was deleted: ' . $form),
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

    /** @return int[] */
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

    private function albumCount(int $albumId): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_categories WHERE id = $albumId");
    }

    private function deleteAlbum(int $albumId, string $mode): array
    {
        return $this->client->call('pwg.categories.delete', array(
            'category_id' => $albumId,
            'photo_deletion_mode' => $mode,
            'pwg_token' => $this->client->token(),
            ));
    }
}
