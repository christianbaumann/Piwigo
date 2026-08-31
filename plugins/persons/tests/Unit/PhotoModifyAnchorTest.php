<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the anchor the admin photo screen is injected at.
 *
 * The link to the tagging screen is a string match against picture_modify.tpl.
 * A moved anchor is invisible at runtime: Smarty compiles the untouched
 * template, the photo screen renders perfectly, and the only way to the
 * tagging screen is simply absent - with no error anywhere.
 *
 * The anchor is the photo screen's action bar, the row of icon links beside the
 * thumbnail. The injection keeps it, so a plugin that prepends at the same
 * point cannot disable this one whatever order the prefilters run in.
 */
final class PhotoModifyAnchorTest extends TestCase
{
    private const TPL = 'admin/themes/default/template/picture_modify.tpl';

    /** The two admin themes that ship no picture_modify.tpl and inherit the one above. */
    private const INHERITING_TPLS = array(
        'admin/themes/clear/template/picture_modify.tpl',
        'admin/themes/roma/template/picture_modify.tpl',
        );

    /** A template shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_TPL_BYTES = 8000;

    private function template(): string
    {
        $path = PIWIGO_ROOT . self::TPL;
        $this->assertFileExists($path, 'the admin photo template the prefilter targets is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read for the occurrence counts below to mean anything'
        );

        return $content;
    }

    /** [HAPPY] The anchor is present exactly once, so the link is injected once. */
    public function testPhotoAnchorOccursExactlyOnceInThePhotoModifyTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PERSONS_TPL_PHOTO_ANCHOR),
            'the action-bar anchor no longer matches picture_modify.tpl exactly once - the link is injected zero or twice'
        );
    }

    /**
     * [BVA] The anchor opens the action bar that sits beside the thumbnail, so
     * the injected link lands among the screen's other photo actions rather
     * than somewhere down the form.
     */
    public function testPhotoAnchorSitsAboveTheThumbnail(): void
    {
        $content = $this->template();

        $anchorAt = strpos($content, PERSONS_TPL_PHOTO_ANCHOR);
        $thumbnailAt = strpos($content, '<img src="{$TN_SRC}"');

        $this->assertNotFalse($thumbnailAt, 'the thumbnail is gone; the action bar is no longer what it was');
        $this->assertGreaterThan($anchorAt, $thumbnailAt);
    }

    /**
     * [NEG] The anchor is not one of the public page's, so the three injections
     * cannot consume each other.
     */
    public function testThePhotoAnchorIsDistinctFromThePublicOnes(): void
    {
        $this->assertNotSame(PERSONS_TPL_PHOTO_ANCHOR, PERSONS_TPL_INJECT_POINT);
        $this->assertNotSame(PERSONS_TPL_PHOTO_ANCHOR, PERSONS_TPL_ROW_INJECT_POINT);
    }

    /**
     * [NEG] The other admin themes still ship no picture_modify.tpl of their own.
     *
     * They inherit the template above. The day one of them ships a copy, an
     * administrator running that theme gets a photo screen with no link on it
     * and no other symptom.
     */
    public function testNoOtherAdminThemeOverridesThePhotoModifyTemplate(): void
    {
        $this->assertGreaterThan(0, count(self::INHERITING_TPLS), 'anti-vacuity: nothing to check');

        foreach (self::INHERITING_TPLS as $tpl)
        {
            $this->assertFileDoesNotExist(
                PIWIGO_ROOT . $tpl,
                "$tpl now overrides the admin photo screen; the link injection targets the wrong file"
            );
        }
    }
}
