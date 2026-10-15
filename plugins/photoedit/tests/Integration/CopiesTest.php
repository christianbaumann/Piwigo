<?php
use PHPUnit\Framework\TestCase;

/**
 * Every overwritten or deleted state of a photo stays under
 * _data/photoedit/originals/ (design of change
 * 2026-10-15-exploratory-testing-answers: "A restore through replace keeps a
 * copy of the edited state", "Copies of a deleted photo move aside",
 * "_original sidecars move aside on delete").
 *
 * Over HTTP, through the methods Fotos hinzufügen and the photo page use:
 * pwg.images.upload in update mode, pwg.images.delete.
 */
final class CopiesTest extends TestCase
{
    private const ORIGINALS_DIR = PIWIGO_ROOT . '_data/photoedit/originals/';

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private int $album;
    private ?string $replacement = null;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $this->image = $this->fixture->createMarkedImage();
        $this->album = $this->fixture->showInTestAlbum($this->image);
    }

    protected function tearDown(): void
    {
        if ($this->replacement !== null)
        {
            @unlink($this->replacement);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    private function webmaster(): WsClient
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $client->login($username, $password);
        return $client;
    }

    private function assertOk(array $res): mixed
    {
        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        return $res['json']['result'];
    }

    /** @return string[] the photo's copies under their live names */
    private function liveCopies(): array
    {
        return glob(self::ORIGINALS_DIR . $this->image['id'] . '-*.png');
    }

    /** [HAPPY] A replace leaves a copy of the file as it was just before. */
    public function testAReplaceLeavesACopyOfTheFileBefore(): void
    {
        $this->assertSame(array(), $this->liveCopies(), 'anti-vacuity: the photo already has a copy');

        $this->replacement = sys_get_temp_dir() . '/photoedit_replace_' . bin2hex(random_bytes(6)) . '.png';
        FixtureBuilder::run('convert -size 120x80 xc:#00ff00 ' . escapeshellarg('PNG:' . $this->replacement));
        $this->assertNotSame($this->image['md5'], md5_file($this->replacement), 'anti-vacuity: the replacement is the same file');

        $client = $this->webmaster();
        $this->assertOk($client->upload('pwg.images.upload', array(
            'name' => basename($this->image['file']),
            'category' => $this->album,
            'update_mode' => 'true',
            'chunk' => 0,
            'chunks' => 1,
            'pwg_token' => $client->token(),
            ), 'file', $this->replacement));

        clearstatcache();
        $this->assertSame(md5_file($this->replacement), md5_file($this->image['file']), 'the replace did not overwrite the photo');
        $copies = $this->liveCopies();
        $this->assertCount(1, $copies);
        $this->assertSame($this->image['md5'], md5_file($copies[0]));
    }

    /** [HAPPY] A delete moves the photo's copies and exiftool's sidecar into one folder under deleted/. */
    public function testADeleteMovesCopiesAndSidecarAside(): void
    {
        $client = $this->webmaster();
        $this->assertOk($client->call('pwg.photoedit.apply', array(
            'image_id' => $this->image['id'],
            'turns' => 1,
            'pwg_token' => $client->token(),
            )));
        $copies = $this->liveCopies();
        $this->assertCount(1, $copies, 'anti-vacuity: the edit left no copy');
        $copyMd5 = md5_file($copies[0]);

        // What exiftool leaves beside a file it wrote without -overwrite_original.
        $sidecar = $this->image['file'] . '_original';
        copy($this->image['file'], $sidecar);
        $sidecarMd5 = md5_file($sidecar);

        $this->assertOk($client->call('pwg.images.delete', array(
            'image_id' => $this->image['id'],
            'pwg_token' => $client->token(),
            )));

        clearstatcache();
        $this->assertFileDoesNotExist($this->image['file'], 'anti-vacuity: the delete left the photo');
        $this->assertSame(array(), $this->liveCopies());
        $this->assertFileDoesNotExist($sidecar);

        $folders = glob(self::ORIGINALS_DIR . 'deleted/' . $this->image['id'] . '-*', GLOB_ONLYDIR);
        $this->assertCount(1, $folders);
        $moved = array();
        foreach (glob($folders[0] . '/*') as $file)
        {
            $moved[basename($file)] = md5_file($file);
        }
        $this->assertSame(array(
            basename($copies[0]) => $copyMd5,
            basename($sidecar) => $sidecarMd5,
            ), $moved);
    }

    /**
     * [NEG] A photo deleted without its file (the filesystem sync, an album
     * delete) keeps the sidecar beside the file; its copies still move, since
     * its id may name a later photo. In-process: no ws method deletes a row
     * and keeps the file.
     */
    public function testADeleteThatKeepsTheFileKeepsItsSidecar(): void
    {
        PiwigoRuntime::loadPipeline();
        $id = (int)$this->image['id'];

        $copy = self::ORIGINALS_DIR . photoedit_copy_name($id, 'png', time(), 1);
        copy($this->image['file'], $copy);
        $sidecar = $this->image['file'] . '_original';
        copy($this->image['file'], $sidecar);

        photoedit_begin_delete_elements(array($id));
        photoedit_delete_elements(array($id));

        clearstatcache();
        $this->assertFileExists($this->image['file'], 'anti-vacuity: the file must stay for this case');
        $this->assertFileExists($sidecar);
        $this->assertFileDoesNotExist($copy);
        $folders = glob(self::ORIGINALS_DIR . 'deleted/' . $id . '-*', GLOB_ONLYDIR);
        $this->assertCount(1, $folders);
        $this->assertSame(array(basename($copy)), array_map('basename', glob($folders[0] . '/*')));
    }
}
