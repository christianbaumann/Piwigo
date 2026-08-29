<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the public picture page, the third of its kind alongside
 * AlbumTemplateAnchorTest and PhotoTemplateAnchorTest.
 *
 * The prefilter injects the provenance row by string match against picture.tpl.
 * A moved anchor is invisible at runtime: Smarty compiles the untouched
 * template, the photo page renders perfectly, and the row is simply absent.
 *
 * The anchor deliberately sits at the close of <dl id="standard"> rather than at
 * the {if isset($metadata)} point one line below it. Colored Tags injects at
 * that lower point, so an anchor spanning it would stop matching the moment
 * that plugin's prefilter happened to run first - the same silent no-op, but
 * dependent on plugin load order and therefore invisible in this suite.
 */
final class PicturePageAnchorTest extends TestCase
{
    private const TPL = 'themes/default/template/picture.tpl';

    /** A template shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_TPL_BYTES = 8000;

    private function template(): string
    {
        $path = PIWIGO_ROOT . self::TPL;
        $this->assertFileExists($path, 'the picture template the public prefilter targets is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read for the occurrence count below to mean anything'
        );

        return $content;
    }

    /** [HAPPY] The anchor is present exactly once, so the row is injected once. */
    public function testAnchorOccursExactlyOnceInThePictureTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PROVENANCE_TPL_INJECT_POINT),
            'the picture page anchor no longer matches picture.tpl - the prefilter injects nothing'
        );
    }

    /**
     * [BVA] The anchor closes <dl id="standard">, so a row injected before it
     * lands inside that list rather than loose between two definition lists.
     */
    public function testAnchorClosesTheStandardInfoList(): void
    {
        $content = $this->template();

        $listAt = strpos($content, '<dl id="standard"');
        $this->assertNotFalse($listAt, 'the standard info list is gone; the injection has nowhere to land');

        $anchorAt = strpos($content, PROVENANCE_TPL_INJECT_POINT);
        $this->assertGreaterThan($listAt, $anchorAt);

        $this->assertStringNotContainsString(
            '</dl>',
            substr($content, $listAt, $anchorAt - $listAt),
            'another list closes before the anchor - the row would not land inside #standard'
        );
    }

    /**
     * [NEG] The anchor stays clear of the {if isset($metadata)} point that
     * Colored Tags injects at, so the two prefilters cannot disable each other.
     */
    public function testAnchorDoesNotSpanTheColoredTagsInjectionPoint(): void
    {
        $this->assertStringNotContainsString('{if isset($metadata)}', PROVENANCE_TPL_INJECT_POINT);
    }
}
