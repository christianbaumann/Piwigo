<?php
use PHPUnit\Framework\TestCase;

/**
 * A date changed in core's screens while provenance is off: photoinfo cannot
 * write the file, but its own columns still follow core's date, and a save that
 * changes neither field reports nothing.
 *
 * Deactivates provenance for each case and reactivates it in tearDown. A run
 * killed in between leaves it off; reactivate it by hand.
 */
final class CoreEditWithoutProvenanceTest extends TestCase
{
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private FixtureBuilder $fixture;
    private WsClient $ws;
    private int $albumId;
    private array $image;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->assertPluginActive('photoinfo');

        $this->albumId = $this->fixture->createTestAlbum('photoinfo-core-edit-noprov-' . bin2hex(random_bytes(4)));
        $this->image = $this->fixture->createTestImage();
        $this->fixture->attachImage($this->image['id'], $this->albumId);
        $this->fixture->setDate($this->image['id'], '1965-01-01 00:00:00', 'year');
        $this->fixture->setQualifier($this->image['id'], 'between', '1970-01-01', 'year');

        $this->ws = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::WEBMASTER);
        $this->ws->login($username, $password);

        $this->perform('deactivate', 'provenance');
        $this->assertSame('inactive', (string)$this->db->scalar("SELECT state FROM piwigo_plugins WHERE id = 'provenance'"),
            'anti-vacuity: provenance must be off');
    }

    protected function tearDown(): void
    {
        $this->perform('activate', 'provenance');
        $this->fixture->assertPluginActive('provenance');
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    /** [NEG] The range still ends with a date changed in core, though no file is written. */
    public function testADateChangedInCoreResetsTheColumns(): void
    {
        $res = $this->setInfo(array('date_creation' => '2019-11-02'));

        $this->assertFalse($res['json']['result']['written'], $res['body']);
        $row = $this->fixture->imageRow($this->image['id']);
        $this->assertSame('2019-11-02 00:00:00', $row['date_creation']);
        $this->assertSame('day', $row['photoinfo_date_precision']);
        $this->assertNull($row['photoinfo_date_qualifier']);
        $this->assertNull($row['photoinfo_date_end']);
        $this->assertNull($row['photoinfo_date_end_precision']);
    }

    /** [NEG] A save that changes neither field keeps core's answer and reports nothing. */
    public function testATitleChangeReportsNothing(): void
    {
        $res = $this->setInfo(array('name' => 'Neu'));

        $this->assertNull($res['json']['result'], $res['body']);
        $this->assertSame('Neu', $this->fixture->imageRow($this->image['id'])['name'], 'anti-vacuity: the save must have run');
        $this->assertSame('between', $this->fixture->imageRow($this->image['id'])['photoinfo_date_qualifier']);
    }

    private function setInfo(array $fields): array
    {
        $res = $this->ws->call('pwg.images.setInfo', array_merge(array(
            'image_id' => $this->image['id'],
            'single_value_mode' => 'replace',
            'pwg_token' => $this->ws->token(),
            ), $fields));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        return $res;
    }

    private function perform(string $action, string $plugin): void
    {
        $this->ws->call('pwg.plugins.performAction', array(
            'action' => $action,
            'plugin' => $plugin,
            'pwg_token' => $this->ws->token(),
            ));
    }
}
