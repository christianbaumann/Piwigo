<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal pwg.photoedit.apply gives a logged-in account is in the
 * account's language (task 56). The accounts of this suite are German; one
 * case switches an account to English and puts it back.
 *
 * "No image library" cannot be forced over HTTP (core falls back to another
 * library), so its message is checked on the translator in GermanMessageTest.
 */
final class GermanErrorsTest extends TestCase
{
    private const METHOD = 'pwg.photoedit.apply';

    /** Seconds the outside writer holds the lock: longer than PHOTOEDIT_LOCK_TIMEOUT_SECONDS. */
    private const HOLD_SECONDS = 34;

    /** Attempts, 100 ms apart, at seeing the outside writer's lock taken. */
    private const HOLD_POLLS = 50;

    private const READ_ONLY = 0555;
    private const WRITABLE = 0755;

    private Db $db;
    private FixtureBuilder $fixture;
    private array $image;
    private ?string $savedLanguage = null;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixture = new FixtureBuilder($this->db);
        $this->fixture->assertPluginActive();

        $this->image = $this->fixture->createMarkedImage();
        $this->fixture->showInTestAlbum($this->image);
    }

    protected function tearDown(): void
    {
        chmod(dirname($this->image['file']), self::WRITABLE);
        if ($this->savedLanguage !== null)
        {
            $this->setLanguage($this->savedLanguage);
        }
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    private function setLanguage(string $language): void
    {
        $this->db->query(
            "UPDATE piwigo_user_infos SET language = '" . $this->db->escape($language) . "' WHERE user_id = (" .
            "SELECT id FROM piwigo_users WHERE username = '" . $this->db->escape(Config::credentials(TestUsers::NORMAL)[0]) . "')"
        );
    }

    private function apply(array $params): array
    {
        $client = new WsClient();
        list($username, $password) = Config::credentials(TestUsers::NORMAL);
        $client->login($username, $password);

        $res = $client->call(self::METHOD, $params + array(
            'image_id' => $this->image['id'],
            'pwg_token' => $client->token(),
            ));
        $this->assertIsArray($res['json'], 'not JSON: ' . $res['body']);
        $this->assertSame('fail', $res['json']['stat'], 'anti-vacuity: the call was not refused: ' . $res['body']);

        return $res['json'];
    }

    public static function refusals(): array
    {
        return array(
            '[ECP] crop too small' => array(array('turns' => 0, 'crop' => '0,0,0.01,1'), 'mindestens ' . PHOTOEDIT_MIN_CROP_PX . ' Pixel'),
            '[ECP] nothing to do' => array(array('turns' => 0), 'Nichts zu tun'),
            '[ECP] turns out of range' => array(array('turns' => 4), 'Vierteldrehungen'),
            '[ECP] crop malformed' => array(array('turns' => 1, 'crop' => '0,0,1'), 'vier Anteilen'),
            );
    }

    #[DataProvider('refusals')]
    public function testARefusedRequestIsAnsweredInGerman(array $params, string $germanFragment): void
    {
        $json = $this->apply($params);

        $this->assertStringContainsString($germanFragment, $json['message']);
    }

    /** [ECP] The same refusal, for an account that reads English, stays English. */
    public function testAnEnglishAccountGetsTheEnglishText(): void
    {
        $this->savedLanguage = (string)$this->db->scalar(
            "SELECT language FROM piwigo_user_infos WHERE user_id = (SELECT id FROM piwigo_users WHERE username = '" .
            $this->db->escape(Config::credentials(TestUsers::NORMAL)[0]) . "')"
        );
        $this->assertSame('de_DE', $this->savedLanguage, 'anti-vacuity: the account is not German to begin with');
        $this->setLanguage('en_UK');

        $json = $this->apply(array('turns' => 0));

        $this->assertStringContainsString('nothing to do', $json['message']);
    }

    /** [ECP] A photo the caller may not see: the same answer as a missing one, in German. */
    public function testAnUnknownPhotoIsAnsweredInGerman(): void
    {
        $json = $this->apply(array('turns' => 1, 'image_id' => 2147483000));

        $this->assertStringContainsString('Kein Foto', $json['message']);
    }

    /** [ECP] A file type the editor does not handle. */
    public function testAnUnsupportedTypeIsAnsweredInGerman(): void
    {
        $this->image = $this->fixture->createGif();
        $this->fixture->showInTestAlbum($this->image);

        $json = $this->apply(array('turns' => 1));

        $this->assertStringContainsString('können bearbeitet werden', $json['message']);
    }

    /** [ECP] A folder the rename cannot write into. */
    public function testAFolderThatCannotBeWrittenIsAnsweredInGerman(): void
    {
        chmod(dirname($this->image['file']), self::READ_ONLY);
        clearstatcache();
        $this->assertFalse(is_writable(dirname($this->image['file'])), 'anti-vacuity: the folder is still writable');

        $json = $this->apply(array('turns' => 1));

        $this->assertStringContainsString('nicht beschreibbar', $json['message']);
    }

    /** [ECP] Another write holds the photo for longer than an edit waits. */
    public function testARunningEditIsAnsweredInGerman(): void
    {
        $lock = PIWIGO_ROOT . '_data/photoedit/locks/' . sha1($this->image['db_path']) . '.lock';
        if (!is_dir(dirname($lock)))
        {
            mkdir(dirname($lock), self::WRITABLE, true);
        }
        exec(sprintf('flock %s sleep %d > /dev/null 2>&1 &', escapeshellarg($lock), self::HOLD_SECONDS));
        $polls = 0;
        while (!$this->isHeld($lock) and ++$polls < self::HOLD_POLLS)
        {
            usleep(100000);
        }
        $this->assertTrue($this->isHeld($lock), 'anti-vacuity: the outside writer never took the lock');

        $json = $this->apply(array('turns' => 1));

        $this->assertStringContainsString('läuft noch', $json['message']);
        $this->assertSame($this->image['md5'], md5_file($this->image['file']));
    }

    private function isHeld(string $lock): bool
    {
        exec('flock -n -E 9 ' . escapeshellarg($lock) . ' true 2>&1', $output, $status);
        return $status === 9;
    }
}
