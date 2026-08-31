<?php
use PHPUnit\Framework\TestCase;

/**
 * The admin tagging screen's refusal branches.
 *
 * What the screen does with a real photo is an E2E concern - the editor is a
 * mouse drag - and admin.spec.js owns it. What lives here is the other half:
 * the two ways of reaching the URL with nothing to show, both of which have to
 * answer with a rendered page rather than a PHP error.
 *
 * They are worth their own test because the failure is quiet in the worst way:
 * an administrator following a stale link gets a fatal error on an admin
 * screen, and nothing else in the suite fetches that URL without a live photo.
 */
final class AdminPhotoScreenTest extends TestCase
{
    /** A rendered page shorter than this is an error page or a redirect, not an admin screen. */
    private const MIN_PAGE_BYTES = 2000;

    /**
     * Values that must never reach a query.
     *
     * The dispatcher casts image_id to int and refuses anything at or below
     * zero, so these are the classes either side of that boundary plus the
     * non-numeric one a hand-edited URL produces.
     */
    private const REFUSED_IMAGE_IDS = array(
        '0',    // [BVA] the boundary itself
        '-1',   // [BVA] below it
        'abc',  // [ECP] not a number at all
        );

    private Db $db;
    private WsClient $ws;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->ws->logout();
    }

    /**
     * [NEG] A photo id that does not exist renders the screen's message.
     *
     * The row is read before anything is assigned, so a missing guard here is a
     * diagnostic out of render_element_name() rather than a message the
     * administrator can act on.
     */
    public function testAPhotoThatNoLongerExistsRendersAMessageRatherThanAnError(): void
    {
        $highest = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images');
        $this->assertGreaterThan(0, $highest, 'anti-vacuity: this install has no photo, so no id can be past the last one');

        $res = $this->ws->fetchPage('/admin.php?page=plugin-persons&image_id=' . ($highest + 1));

        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($res['body']),
            'anti-vacuity: too little was returned to have rendered an admin screen'
        );

        $this->assertSame(array(), $this->diagnosticsIn($res['body']));
        $this->assertStringContainsString('class="errors"', $res['body']);
        $this->assertStringNotContainsString('persons-stage', $res['body'],
            'the screen rendered a stage for a photo that does not exist');
    }

    /**
     * [NEG] [BVA] An id that is not a usable photo id never reaches the screen.
     *
     * Each is refused by the dispatcher before admin/photo.php is included, so
     * none of them queries anything.
     */
    public function testAnUnusableImageIdIsRefusedBeforeTheScreenIsReached(): void
    {
        $this->assertGreaterThan(0, count(self::REFUSED_IMAGE_IDS), 'anti-vacuity: nothing to check');

        foreach (self::REFUSED_IMAGE_IDS as $imageId)
        {
            $res = $this->ws->fetchPage('/admin.php?page=plugin-persons&image_id=' . urlencode($imageId));

            $this->assertSame(200, $res['http_code'], "image_id=$imageId did not render");
            $this->assertGreaterThan(
                self::MIN_PAGE_BYTES,
                strlen($res['body']),
                "anti-vacuity: image_id=$imageId returned too little to have rendered"
            );

            $this->assertSame(array(), $this->diagnosticsIn($res['body']), "image_id=$imageId produced a PHP diagnostic");
            $this->assertStringNotContainsString('persons-stage', $res['body'],
                "image_id=$imageId reached the tagging screen");
        }
    }

    /**
     * PHP renders diagnostics inline as `<b>Warning</b>:  text in <b>file</b>...`
     * on this install, which makes the body a direct oracle for "no notice".
     */
    private function diagnosticsIn(string $body): array
    {
        $pattern = '#<b>(Warning|Notice|Deprecated|Fatal error|Parse error|Strict Standards)</b>:\s*(.*?)\s+in <b>#s';
        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

        $out = array();
        foreach ($matches as $match)
        {
            $out[] = $match[1] . ': ' . $match[2];
        }
        return $out;
    }
}
