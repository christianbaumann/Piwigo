<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the photo screen, the twin of AlbumTemplateAnchorTest.
 *
 * The prefilter injects by string match against picture_modify.tpl, and an
 * anchor that stops matching is invisible at runtime: Smarty compiles the
 * untouched template and the photo screen renders perfectly, without the
 * provenance block.
 */
final class PhotoTemplateAnchorTest extends TestCase
{
    private const TPL = 'admin/themes/default/template/picture_modify.tpl';

    /** A template shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_TPL_BYTES = 4000;

    private function template(): string
    {
        $path = PIWIGO_ROOT . self::TPL;
        $this->assertFileExists($path, 'the photo properties template the prefilter targets is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read for the occurrence count below to mean anything'
        );

        return $content;
    }

    /** [HAPPY] The anchor is present exactly once, so the block is injected once. */
    public function testAnchorOccursExactlyOnceInThePhotoTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PROVENANCE_TPL_PHOTO_ANCHOR),
            'the photo anchor no longer matches picture_modify.tpl - the prefilter injects nothing'
        );
    }

    /**
     * [BVA] The anchor sits after the close of the photo form, so the injected
     * block is outside it and core's unsaved-changes tracking never sees the
     * Notiz field.
     */
    public function testAnchorFollowsTheClosingOfThePhotoForm(): void
    {
        $content = $this->template();

        $anchorAt = strpos($content, PROVENANCE_TPL_PHOTO_ANCHOR);
        $formCloseAt = strrpos($content, '</form>');

        $this->assertNotFalse($formCloseAt, 'the photo form is gone; the layout assumption no longer holds');
        $this->assertSame(1, substr_count($content, '</form>'), 'a second form makes "after the last </form>" ambiguous');
        $this->assertGreaterThan($formCloseAt, $anchorAt);
    }
}
