<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard: the admin prefilter injects the provenance block by string
 * match against cat_modify.tpl. Nothing else in the stack notices when that
 * string moves - Smarty compiles the untouched template, the page renders, and
 * the provenance fields are simply absent.
 *
 * The anchor is read from the production constant, never typed again here
 * (.claude/rules/test-design.md, *do not transcribe production data into a test*).
 */
final class AlbumTemplateAnchorTest extends TestCase
{
    private const TPL = 'admin/themes/default/template/cat_modify.tpl';

    /** A template shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_TPL_BYTES = 4000;

    private function template(): string
    {
        $path = PIWIGO_ROOT . self::TPL;
        $this->assertFileExists($path, 'the album properties template the prefilter targets is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read for the occurrence count below to mean anything'
        );

        return $content;
    }

    /**
     * [HAPPY] The anchor is present exactly once.
     *
     * Zero occurrences means the injection silently does nothing; more than one
     * means it would be injected several times, giving the page duplicate input
     * ids.
     */
    public function testAnchorOccursExactlyOnceInTheAlbumTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PROVENANCE_TPL_ALBUM_ANCHOR),
            'the album save button anchor no longer matches cat_modify.tpl - the prefilter injects nothing'
        );
    }

    /**
     * [BVA] The anchor sits inside the album form's footer, after the last input
     * the form carries, so the injected block lands among the fields rather than
     * ahead of the page's own content.
     */
    public function testAnchorFollowsTheAlbumFormFields(): void
    {
        $content = $this->template();

        $anchorAt = strpos($content, PROVENANCE_TPL_ALBUM_ANCHOR);
        $lastFieldAt = strrpos($content, 'id="cat-comment"');

        $this->assertNotFalse($lastFieldAt, 'the album description field is gone; the layout assumption no longer holds');
        $this->assertGreaterThan($lastFieldAt, $anchorAt);
    }
}
