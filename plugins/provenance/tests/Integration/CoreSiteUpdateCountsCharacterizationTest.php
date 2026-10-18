<?php
use PHPUnit\Framework\TestCase;

/**
 * The regression net for the counters of core's filesystem synchronization
 * (admin/site_update.php) that task 36 changes: the result summary's "N errors",
 * and "N photos deleted from the database".
 *
 * Every case here is [ERR]: the oracle is the current implementation, not a
 * requirement. They report a change; they prove nothing correct.
 *
 * They land and pass before the change, which is normally the tell that a test
 * recorded code rather than drove it. Here that is the point, so each was watched
 * go red by breaking the behaviour it claims to watch.
 *
 * Every sync is limited to the throwaway folder of one fixture album (`cat`),
 * never the whole gallery. Cases marked [REQ] are requirements: the counters of
 * a removed folder's photos and of the metadata pass's errors disagreed with the
 * page and the database (task 36, fixed in admin/site_update.php). They were [ERR]
 * cases recording the defect until the fix.
 */
final class CoreSiteUpdateCountsCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    /** @var array<int, array{parent:int,path:string}> registered before the first sync, so a failing case still cleans up */
    private array $subFolders = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
        $this->fixture = new FixtureBuilder($this->db);
    }

    protected function tearDown(): void
    {
        $this->destroySubFolders();
        $this->fixture->destroyPhysicalAlbums();
        $this->ws->logout();
    }

    /** [ERR] [HAPPY] A photo whose file is gone is counted as deleted, simulated or not. */
    public function testARemovedFileIsCountedAsDeleted(): void
    {
        $album = $this->syncedAlbumWithOnePhoto();
        $this->removeFiles($album);

        $simulated = $this->summary($this->sync($album['id'], true));
        $this->assertSame(1, $simulated['deleted_photos']);
        $this->assertSame(1, $this->photosOf($album['id']), 'a simulation deletes nothing');

        $real = $this->summary($this->sync($album['id'], false));
        $this->assertSame(1, $real['deleted_photos']);
        $this->assertSame(0, $this->photosOf($album['id']));
    }

    /** [REQ] [ST] A removed folder deletes its photos (real run) and counts them. */
    public function testARemovedFolderCountsItsPhotosAsDeleted(): void
    {
        $sub = $this->syncedSubFolderWithOnePhoto();
        $this->removeFolder($sub);

        $real = $this->summary($this->sync($sub['parent'], false));

        $this->assertSame(1, $real['deleted_albums']);
        $this->assertSame(1, $real['deleted_photos']);
        $this->assertSame(0, $this->photosOf($sub['id']), 'anti-vacuity: the photo really went');
    }

    /** [REQ] [ST] A simulation of a removed folder counts its photos and deletes none. */
    public function testASimulatedRemovedFolderCountsItsPhotos(): void
    {
        $sub = $this->syncedSubFolderWithOnePhoto();
        $this->removeFolder($sub);

        $simulated = $this->summary($this->sync($sub['parent'], true));

        $this->assertSame(1, $simulated['deleted_albums']);
        $this->assertSame(1, $simulated['deleted_photos']);
        $this->assertSame(1, $this->photosOf($sub['id']), 'a simulation deletes nothing');
    }

    /** [REQ] [ECP] A removed folder's photos and a removed file of the synced album add up. */
    public function testARemovedFolderAndARemovedFileAreBothCounted(): void
    {
        $sub = $this->syncedSubFolderWithOnePhoto();
        $parent = array('id' => $sub['parent'], 'path' => dirname($sub['path']) . '/');
        $this->fixture->placePhotoInPhysicalAlbum($parent['id']);
        $this->sync($parent['id'], false);
        $this->assertSame(1, $this->photosOf($parent['id']), 'anti-vacuity: the album holds a photo of its own');
        foreach (glob($parent['path'] . '*.png') as $file)
        {
            unlink($file);
        }
        $this->removeFolder($sub);

        $summary = $this->summary($this->sync($parent['id'], false));

        $this->assertSame(2, $summary['deleted_photos']);
    }

    /** [ERR] [HAPPY] An error found while reading the folder (a file name core refuses) is counted and listed. */
    public function testAnErrorOfTheFilePassIsCountedAndListed(): void
    {
        $album = $this->fixture->createPhysicalAlbum('provenance-sync-' . bin2hex(random_bytes(4)));
        $name = $this->fixture->placePhotoInPhysicalAlbum($album['id']);
        rename($album['path'] . $name, $album['path'] . 'not allowed ' . $name);

        $summary = $this->summary($this->sync($album['id'], false));

        $this->assertSame(1, $summary['listed_errors'], 'anti-vacuity: the page lists the refused file');
        $this->assertSame(1, $summary['errors']);
    }

    /** [REQ] [ST] An error of the metadata pass is counted as well as listed. */
    public function testAnErrorOfTheMetadataPassIsCounted(): void
    {
        $album = $this->syncedAlbumWithOnePhoto();
        $this->removeFiles($album);

        $summary = $this->summary($this->sync($album['id'], true, true));

        $this->assertSame(1, $summary['listed_errors'], 'anti-vacuity: the page lists the unreadable file');
        $this->assertSame(1, $summary['errors']);
    }

    /**
     * [ERR] [ECP] A photo stored in a kept album but linked only into a removed
     * folder is not deleted with it, so it is not counted.
     *
     * Oracle: the sync calls delete_categories() in its default 'no_delete' mode
     * (admin/site_update.php), which deletes the photos stored in the removed
     * folders and only drops the links of the others. No requirement confirms
     * this; task 47 assumed the opposite and this records what core does.
     */
    public function testAPhotoStoredElsewhereSurvivesAndIsNotCounted(): void
    {
        $sub = $this->syncedSubFolderBesideAKeptPhoto();
        $this->db->query("DELETE FROM piwigo_image_category WHERE image_id = {$sub['kept']}");
        $this->db->query("INSERT INTO piwigo_image_category (image_id, category_id) VALUES ({$sub['kept']}, {$sub['id']})");
        $this->removeFolder($sub);

        $simulated = $this->summary($this->sync($sub['parent'], true));
        $this->assertSame(1, $simulated['deleted_photos'], 'only the photo stored in the folder');

        $real = $this->summary($this->sync($sub['parent'], false));

        $this->assertSame(1, $real['deleted_albums']);
        $this->assertSame(1, $real['deleted_photos'], 'only the photo stored in the folder');
        $this->assertSame(
            1,
            (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = {$sub['kept']}"),
            'the photo stored in the kept album is still there'
        );
    }

    /** [ERR] [ECP] A photo stored in the removed folder and linked elsewhere too is deleted and counted. */
    public function testAPhotoStoredInTheFolderAndLinkedElsewhereIsDeletedAndCounted(): void
    {
        $sub = $this->syncedSubFolderBesideAKeptPhoto();
        $stored = (int)$this->db->scalar("SELECT id FROM piwigo_images WHERE storage_category_id = {$sub['id']}");
        $this->assertGreaterThan(0, $stored, 'anti-vacuity: the folder stores a photo');
        $this->db->query("INSERT INTO piwigo_image_category (image_id, category_id) VALUES ($stored, {$sub['parent']})");
        $this->removeFolder($sub);

        $real = $this->summary($this->sync($sub['parent'], false));

        $this->assertSame(1, $real['deleted_photos']);
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE id = $stored"));
    }

    private function syncedAlbumWithOnePhoto(): array
    {
        $album = $this->fixture->createPhysicalAlbum('provenance-sync-' . bin2hex(random_bytes(4)));
        $this->fixture->placePhotoInPhysicalAlbum($album['id']);
        $this->sync($album['id'], false);
        $this->assertSame(1, $this->photosOf($album['id']), 'anti-vacuity: the first sync registered the photo');

        return $album;
    }

    /**
     * A throwaway folder inside a throwaway album's folder, synced so core
     * registers it as an album of its own holding one photo. A sync limited to
     * an album never deletes that album itself, only what is below it.
     *
     * @return array{id:int,parent:int,path:string}
     */
    private function syncedSubFolderWithOnePhoto(): array
    {
        $parent = $this->fixture->createPhysicalAlbum('provenance-sync-' . bin2hex(random_bytes(4)));
        $name = $this->fixture->placePhotoInPhysicalAlbum($parent['id']);
        $path = $parent['path'] . 'sub/';
        $this->assertTrue(mkdir($path, 0755), 'anti-vacuity: the sub folder exists');
        $this->subFolders[] = array('parent' => $parent['id'], 'path' => $path);
        $this->assertTrue(rename($parent['path'] . $name, $path . $name));
        $this->sync($parent['id'], false);

        $id = (int)$this->db->scalar("SELECT id FROM piwigo_categories WHERE id_uppercat = {$parent['id']} AND dir = 'sub'");
        $this->assertGreaterThan(0, $id, 'anti-vacuity: the first sync registered the sub folder');
        $this->assertSame(1, $this->photosOf($id), 'anti-vacuity: the first sync registered the photo');

        return array('id' => $id, 'parent' => $parent['id'], 'path' => $path);
    }

    /**
     * A parent album storing one photo of its own (`kept`) and a sub folder
     * storing another, both registered by one sync.
     *
     * @return array{id:int,parent:int,path:string,kept:int}
     */
    private function syncedSubFolderBesideAKeptPhoto(): array
    {
        $parent = $this->fixture->createPhysicalAlbum('provenance-sync-' . bin2hex(random_bytes(4)));
        $this->fixture->placePhotoInPhysicalAlbum($parent['id']);
        $moved = $this->fixture->placePhotoInPhysicalAlbum($parent['id']);
        $path = $parent['path'] . 'sub/';
        $this->assertTrue(mkdir($path, 0755), 'anti-vacuity: the sub folder exists');
        $this->subFolders[] = array('parent' => $parent['id'], 'path' => $path);
        $this->assertTrue(rename($parent['path'] . $moved, $path . $moved));
        $this->sync($parent['id'], false);

        $id = (int)$this->db->scalar("SELECT id FROM piwigo_categories WHERE id_uppercat = {$parent['id']} AND dir = 'sub'");
        $this->assertGreaterThan(0, $id, 'anti-vacuity: the first sync registered the sub folder');
        $this->assertSame(1, $this->photosOf($id), 'anti-vacuity: the folder stores one photo');
        $kept = (int)$this->db->scalar("SELECT id FROM piwigo_images WHERE storage_category_id = {$parent['id']}");
        $this->assertGreaterThan(0, $kept, 'anti-vacuity: the parent stores a photo');

        return array('id' => $id, 'parent' => $parent['id'], 'path' => $path, 'kept' => $kept);
    }

    /** Whatever a case left of the sub folders: rows, files, folder. */
    private function destroySubFolders(): void
    {
        foreach ($this->subFolders as $sub)
        {
            $id = (int)$this->db->scalar(
                "SELECT id FROM piwigo_categories WHERE id_uppercat = {$sub['parent']} AND dir = 'sub'"
            );
            if ($id > 0)
            {
                $this->db->query("DELETE FROM piwigo_image_category WHERE category_id = $id");
                $this->db->query("DELETE FROM piwigo_image_category WHERE image_id IN (SELECT id FROM piwigo_images WHERE storage_category_id = $id)");
                $this->db->query("DELETE FROM piwigo_images WHERE storage_category_id = $id");
                $this->db->query("DELETE FROM piwigo_categories WHERE id = $id");
            }
            foreach (glob($sub['path'] . '*') ?: array() as $file)
            {
                @unlink($file);
            }
            @rmdir($sub['path']);
        }
        $this->subFolders = array();
    }

    private function removeFiles(array $album): void
    {
        $files = glob($album['path'] . '*');
        $this->assertNotEmpty($files, 'anti-vacuity: there is a file to remove');
        foreach ($files as $file)
        {
            unlink($file);
        }
    }

    private function removeFolder(array $album): void
    {
        $this->removeFiles($album);
        $this->assertTrue(rmdir($album['path']));
    }

    private function photosOf(int $catId): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_images WHERE storage_category_id = $catId");
    }

    private function sync(int $catId, bool $simulate, bool $withMetadata = false): string
    {
        $params = array(
            'sync' => 'files',
            'cat' => $catId,
            'subcats-included' => '1',
            'privacy_level' => '0',
            'display_info' => '0',
            'simulate' => $simulate ? '1' : '0',
            'submit' => 'Submit',
            );
        if ($withMetadata)
        {
            $params['sync_meta'] = '1';
        }
        $res = $this->ws->postPage('/admin.php?page=site_update&site=1', $params);
        $this->assertSame(200, $res['http_code'], 'the synchronization page did not answer');
        $this->assertStringNotContainsString('Hacking attempt', (string)$res['body']);

        return (string)$res['body'];
    }

    /** @return array{deleted_albums:int,deleted_photos:int,errors:int,listed_errors:int} what the result page says */
    private function summary(string $page): array
    {
        $this->assertGreaterThan(2000, strlen($page), 'anti-vacuity: a result page, not an error page');
        preg_match_all('~<li class="update_summary_del">(\d+) ~', $page, $del);
        $this->assertCount(2, $del[1], 'anti-vacuity: both deletion counters are on the page');
        $this->assertSame(1, preg_match('~<li class="update_summary_err">(\d+) ~', $page, $err));
        preg_match('~<div class="errors">\s*<ul>(.*?)</ul>~s', $page, $list);

        return array(
            'deleted_albums' => (int)$del[1][0],
            'deleted_photos' => (int)$del[1][1],
            'errors' => (int)$err[1],
            'listed_errors' => isset($list[1]) ? substr_count($list[1], '<li>') : 0,
        );
    }
}
