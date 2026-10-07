<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Info row in the picture page's source, per account.
 *
 * Server-rendered markup only: whether a click opens the editor is the E2E
 * suite's to witness. Absence of the form in the source is the whole [NEG]
 * fact - an account that never receives it cannot reveal it.
 */
final class PicturePageSourceTest extends TestCase
{
    /** A picture page shorter than this is an error page, on which "absent" says nothing. */
    private const MIN_PAGE_BYTES = 5000;

    private const ROW = 'id="PhotoInfo"';
    private const FORM = 'id="photoinfo-info-form"';
    private const CORE_DESCRIPTION = 'class="imageComment"';
    private const COMMENT = 'Die Taufe im Garten';

    private FixtureBuilder $fixture;
    private array $image;
    private string $picturePath;

    protected function setUp(): void
    {
        $this->fixture = new FixtureBuilder(new Db());
        $this->fixture->assertPluginActive('photoinfo');

        $this->image = $this->fixture->createTestImage();
        $album = $this->fixture->createTestAlbum('Photoinfo test ' . bin2hex(random_bytes(4)));
        $this->fixture->attachImage((int)$this->image['id'], $album);
        $this->fixture->invalidateUserCache();

        $this->picturePath = '/picture.php?/' . $this->image['id'] . '/category/' . $album;
    }

    protected function tearDown(): void
    {
        $this->fixture->destroyTestImages();
        $this->fixture->destroyTestAlbums();
    }

    public static function allAccounts(): array
    {
        return array(
            'webmaster' => array(TestUsers::WEBMASTER, true),
            'administrator' => array(TestUsers::ADMIN, true),
            'normal user' => array(TestUsers::NORMAL, false),
            'guest' => array(null, false),
            );
    }

    /**
     * [DT] Everyone sees the description in the row and not above the photo;
     * only administrators get the editor.
     */
    #[DataProvider('allAccounts')]
    public function testTheDescriptionShowsInTheRowOnly(?string $role, bool $editable): void
    {
        $this->fixture->setComment($this->image['id'], self::COMMENT);

        $page = $this->pageAs($role);
        $row = $this->rowOf($page);

        $this->assertStringContainsString(self::COMMENT, $row);
        $this->assertStringNotContainsString(self::CORE_DESCRIPTION, $page);
        $this->assertSame($editable ? 1 : 0, substr_count($page, self::FORM));
    }

    public static function readers(): array
    {
        return array(
            'normal user' => array(TestUsers::NORMAL),
            'guest' => array(null),
            );
    }

    /** [ECP] With no description, a reader gets no row at all. */
    #[DataProvider('readers')]
    public function testNoDescriptionNoRowForAReader(?string $role): void
    {
        $this->assertSame(0, substr_count($this->pageAs($role), self::ROW));
    }

    /** [BVA] A description of "0" is none to core, so a reader gets no row either. */
    public function testADescriptionOfZeroGivesAReaderNoRow(): void
    {
        $this->fixture->setComment($this->image['id'], '0');

        $this->assertSame(0, substr_count($this->pageAs(null), self::ROW));
    }

    /** [ECP] With no description, an administrator gets the row to add one. */
    public function testNoDescriptionStillARowForAnAdministrator(): void
    {
        $page = $this->pageAs(TestUsers::ADMIN);

        $this->assertSame(1, substr_count($page, self::ROW));
        $this->assertSame(1, substr_count($page, self::FORM));
    }

    /** [HAPPY] The row renders the description the way core rendered it above the photo. */
    public function testTheRowRendersMarkupLikeCore(): void
    {
        $this->fixture->setComment($this->image['id'], 'Oma <b>und</b> Opa');

        $this->assertStringContainsString('Oma <b>und</b> Opa', $this->rowOf($this->pageAs(null)));
    }

    /** [HAPPY] The editor's textarea holds the raw text, escaped. */
    public function testTheTextareaHoldsTheRawTextEscaped(): void
    {
        $this->fixture->setComment($this->image['id'], 'Oma <b>und</b> Opa');

        $this->assertStringContainsString(
            '<textarea name="info" rows="5">Oma &lt;b&gt;und&lt;/b&gt; Opa</textarea>',
            $this->pageAs(TestUsers::ADMIN)
        );
    }

    /** [NEG] The provenance row stays provenance-only on a photo with a description. */
    public function testTheProvenanceRowDoesNotCarryTheDescription(): void
    {
        $this->fixture->setComment($this->image['id'], self::COMMENT);
        $this->fixture->setProvenance($this->image['id'], array('provenance_owner' => 'Anna Mueller'));

        $page = $this->pageAs(null);
        $this->assertSame(1, preg_match('~<div id="Provenance" class="imageInfo">(.*?)</div>~s', $page, $m), 'anti-vacuity: no provenance row');
        $this->assertStringContainsString('Anna Mueller', $m[1]);
        $this->assertStringNotContainsString(self::COMMENT, $m[1]);
    }

    private function pageAs(?string $role): string
    {
        $client = new WsClient();
        if ($role !== null)
        {
            list($username, $password) = Config::credentials($role);
            $client->login($username, $password);
        }

        $page = $client->fetchPage($this->picturePath);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($page), 'anti-vacuity: not a picture page');
        $this->assertStringContainsString('id="theMainImage"', $page, 'anti-vacuity: the photo is not on the page');

        return $page;
    }

    /** The Info row's markup, up to the next row. */
    private function rowOf(string $page): string
    {
        $this->assertSame(1, substr_count($page, self::ROW), 'expected exactly one Info row');

        $start = strpos($page, self::ROW);
        $end = strpos($page, 'id="datepost"', $start);
        $this->assertNotFalse($end, 'anti-vacuity: the Info row does not sit before the "Posted on" row');

        return substr($page, $start, $end - $start);
    }
}
