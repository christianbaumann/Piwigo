<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the two anchors the public picture page is injected at.
 *
 * Both injections are string matches against picture.tpl. A moved anchor is
 * invisible at runtime: Smarty compiles the untouched template, the photo page
 * renders perfectly, and the overlay - or the person row - is simply absent.
 *
 * The stage anchor is {$ELEMENT_CONTENT}, which the prefilter wraps rather than
 * prepends to: the wrapper is what supplies the positioning context the boxes
 * are placed in, so it has to enclose the image element, not sit beside it.
 *
 * The row anchor is the close of <dl id="standard">, the same point
 * plugins/provenance injects at. Both prepend and keep the anchor, so neither
 * prefilter can disable the other whatever order they run in.
 */
final class PicturePageAnchorTest extends TestCase
{
    private const TPL = 'themes/default/template/picture.tpl';

    /** The theme the install runs. It has no picture.tpl of its own, so it inherits the one above. */
    private const OVERRIDING_TPL = 'themes/modus/template/picture.tpl';

    /** A template shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_TPL_BYTES = 8000;

    private function template(): string
    {
        $path = PIWIGO_ROOT . self::TPL;
        $this->assertFileExists($path, 'the picture template both prefilters target is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read for the occurrence counts below to mean anything'
        );

        return $content;
    }

    /** [HAPPY] The stage anchor is present exactly once, so the overlay is injected once. */
    public function testStageAnchorOccursExactlyOnceInThePictureTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PERSONS_TPL_INJECT_POINT),
            'the stage anchor no longer matches picture.tpl exactly once - the overlay is injected zero or twice'
        );
    }

    /** [HAPPY] The row anchor is present exactly once, so the person row is injected once. */
    public function testRowAnchorOccursExactlyOnceInThePictureTemplate(): void
    {
        $this->assertSame(
            1,
            substr_count($this->template(), PERSONS_TPL_ROW_INJECT_POINT),
            'the row anchor no longer matches picture.tpl exactly once'
        );
    }

    /**
     * [BVA] The stage anchor sits inside <div id="theImage">, so the wrapper the
     * prefilter puts around it encloses the image and nothing else.
     */
    public function testStageAnchorSitsInsideTheImageContainer(): void
    {
        $content = $this->template();

        $containerAt = strpos($content, '<div id="theImage">');
        $this->assertNotFalse($containerAt, 'the image container is gone; the stage has nothing to wrap');

        $anchorAt = strpos($content, PERSONS_TPL_INJECT_POINT);
        $this->assertGreaterThan($containerAt, $anchorAt);
    }

    /** [BVA] The row anchor closes <dl id="standard">, so the row lands inside that list. */
    public function testRowAnchorClosesTheStandardInfoList(): void
    {
        $content = $this->template();

        $listAt = strpos($content, '<dl id="standard"');
        $this->assertNotFalse($listAt, 'the standard info list is gone; the row has nowhere to land');

        $anchorAt = strpos($content, PERSONS_TPL_ROW_INJECT_POINT);
        $this->assertGreaterThan($listAt, $anchorAt);

        $this->assertStringNotContainsString(
            '</dl>',
            substr($content, $listAt, $anchorAt - $listAt),
            'another list closes before the anchor - the row would not land inside #standard'
        );
    }

    /**
     * [NEG] The two anchors are distinct strings that do not overlap, so the two
     * injections cannot consume each other's anchor.
     */
    public function testTheTwoAnchorsAreDistinct(): void
    {
        $this->assertNotSame(PERSONS_TPL_INJECT_POINT, PERSONS_TPL_ROW_INJECT_POINT);
        $this->assertStringNotContainsString(PERSONS_TPL_INJECT_POINT, PERSONS_TPL_ROW_INJECT_POINT);
        $this->assertStringNotContainsString(PERSONS_TPL_ROW_INJECT_POINT, PERSONS_TPL_INJECT_POINT);
    }

    /**
     * [NEG] The active theme still has no picture.tpl of its own.
     *
     * modus declares 'parent' => 'default' and inherits the template above. The
     * day it ships its own copy, both prefilters would run against a template
     * the page no longer uses and inject nothing, with no other symptom.
     */
    public function testTheActiveThemeDoesNotOverrideThePictureTemplate(): void
    {
        $this->assertFileDoesNotExist(
            PIWIGO_ROOT . self::OVERRIDING_TPL,
            'modus now overrides picture.tpl; both public injections target the wrong file'
        );
    }
}
