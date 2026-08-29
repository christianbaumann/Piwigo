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
            'the photo savebar anchor no longer matches picture_modify.tpl - the prefilter injects nothing'
        );
    }

    /**
     * [BVA] The anchor sits after the form's last field, so the injected block
     * lands among the photo's own properties rather than ahead of them.
     */
    public function testAnchorFollowsThePhotoFormFields(): void
    {
        $content = $this->template();

        $anchorAt = strpos($content, PROVENANCE_TPL_PHOTO_ANCHOR);
        $lastFieldAt = strrpos($content, 'id="description"');

        $this->assertNotFalse($lastFieldAt, 'the photo description field is gone; the layout assumption no longer holds');
        $this->assertGreaterThan($lastFieldAt, $anchorAt);
    }
}
