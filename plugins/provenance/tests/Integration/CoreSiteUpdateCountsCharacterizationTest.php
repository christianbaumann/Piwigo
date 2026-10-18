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
 * never the whole gallery. Cases marked DEFECT record a counter that disagrees
 * with the page or the database; they are expected to go red when the change
 * lands and are replaced by the requirement tests in SiteUpdateCountsTest.
 */
final class CoreSiteUpdateCountsCharacterizationTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixture;
    /** @var array<int, array{id:int,path:string}> */
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

    /** [ERR] [ST] DEFECT, recorded: a removed folder deletes its photos (real run) and counts none. */
    public function testARemovedFolderDeletesItsPhotosAndCountsNone(): void
    {
        $sub = $this->syncedSubFolderWithOnePhoto();
        $this->removeFolder($sub);

        $real = $this->summary($this->sync($sub['parent'], false));

        $this->assertSame(1, $real['deleted_albums']);
        $this->assertSame(0, $real['deleted_photos']);
        $this->assertSame(0, $this->photosOf($sub['id']), 'anti-vacuity: the photo really went');
    }

    /** [ERR] [ST] DEFECT, recorded: a simulation of a removed folder counts none of its photos. */
    public function testASimulatedRemovedFolderCountsNoPhotos(): void
    {
        $sub = $this->syncedSubFolderWithOnePhoto();
        $this->removeFolder($sub);

        $simulated = $this->summary($this->sync($sub['parent'], true));

        $this->assertSame(1, $simulated['deleted_albums']);
        $this->assertSame(0, $simulated['deleted_photos']);
        $this->assertSame(1, $this->photosOf($sub['id']), 'a simulation deletes nothing');
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

    /** [ERR] [ST] DEFECT, recorded: an error of the metadata pass is listed but not counted. */
    public function testAnErrorOfTheMetadataPassIsListedButNotCounted(): void
    {
        $album = $this->syncedAlbumWithOnePhoto();
        $this->removeFiles($album);

        $summary = $this->summary($this->sync($album['id'], true, true));

        $this->assertSame(1, $summary['listed_errors'], 'anti-vacuity: the page lists the unreadable file');
        $this->assertSame(0, $summary['errors']);
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
        $this->assertTrue(rename($parent['path'] . $name, $path . $name));
        $this->sync($parent['id'], false);

        $id = (int)$this->db->scalar("SELECT id FROM piwigo_categories WHERE id_uppercat = {$parent['id']} AND dir = 'sub'");
        $this->assertGreaterThan(0, $id, 'anti-vacuity: the first sync registered the sub folder');
        $this->assertSame(1, $this->photosOf($id), 'anti-vacuity: the first sync registered the photo');
        $this->subFolders[] = array('id' => $id, 'path' => $path);

        return array('id' => $id, 'parent' => $parent['id'], 'path' => $path);
    }

    /** Whatever a case left of the sub folders: rows, files, folder. */
    private function destroySubFolders(): void
    {
        foreach ($this->subFolders as $sub)
        {
            $this->db->query("DELETE FROM piwigo_image_category WHERE category_id = {$sub['id']}");
            $this->db->query("DELETE FROM piwigo_image_category WHERE image_id IN (SELECT id FROM piwigo_images WHERE storage_category_id = {$sub['id']})");
            $this->db->query("DELETE FROM piwigo_images WHERE storage_category_id = {$sub['id']}");
            $this->db->query("DELETE FROM piwigo_categories WHERE id = {$sub['id']}");
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
