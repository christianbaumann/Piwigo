<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the theme markup editor.js and editor.css depend on.
 *
 * None of it is checked at runtime: a renamed element leaves the picture page
 * rendering perfectly, with the edit button missing or the edit mode unable to
 * hold back the theme - silently.
 *
 * It watches the template side only. The ids below are also written in
 * editor.js, so renaming one there is caught by the E2E suite, not here.
 */
final class PicturePageAnchorTest extends TestCase
{
    private const PICTURE_TPL = 'themes/default/template/picture.tpl';

    /** modus' own photo element template; it overrides picture_content.tpl. */
    private const CONTENT_TPL = 'themes/modus/template/picture_content_asize.tpl';

    /** Where core prints the plugin buttons. */
    private const BUTTONS_ANCHOR = '$PLUGIN_PICTURE_BUTTONS';

    /** The photo element and its area, as editor.js looks them up. */
    private const IMAGE_ID = 'theMainImage';
    private const AREA_ID = 'theImage';

    /** A template shorter than this is a stub or a failed read. */
    private const MIN_PICTURE_TPL_BYTES = 8000;
    private const MIN_CONTENT_TPL_BYTES = 200;

    private function read(string $relative, int $minBytes): string
    {
        $path = PIWIGO_ROOT . $relative;
        $this->assertFileExists($path);

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan($minBytes, strlen($content), "anti-vacuity: too little was read from $relative");

        return $content;
    }

    /** [HAPPY] Core prints the plugin buttons exactly once, so the button appears once. */
    public function testThePluginButtonsArePrintedOnce(): void
    {
        $tpl = $this->read(self::PICTURE_TPL, self::MIN_PICTURE_TPL_BYTES);

        $this->assertSame(1, substr_count($tpl, self::BUTTONS_ANCHOR . ' item=button'));
    }

    /**
     * [HAPPY] The buttons are direct children of .actionButtons: editor.css
     * hides that container's children in edit mode and shows the controls.
     */
    public function testThePluginButtonsSitDirectlyInTheActionButtons(): void
    {
        $tpl = $this->read(self::PICTURE_TPL, self::MIN_PICTURE_TPL_BYTES);

        $open = strpos($tpl, '<div class="actionButtons">');
        $anchor = strpos($tpl, self::BUTTONS_ANCHOR);
        $this->assertNotFalse($open, '.actionButtons is gone from picture.tpl');
        $this->assertNotFalse($anchor);

        // Every <div> opened between the two is closed again before the anchor.
        $between = substr($tpl, $open + 1, $anchor - $open - 1);
        $this->assertSame(substr_count($between, '<div'), substr_count($between, '</div>'));
    }

    /** [HAPPY] The photo's area and the photo itself carry the ids editor.js looks up. */
    public function testThePhotoAndItsAreaKeepTheirIds(): void
    {
        $picture = $this->read(self::PICTURE_TPL, self::MIN_PICTURE_TPL_BYTES);
        $content = $this->read(self::CONTENT_TPL, self::MIN_CONTENT_TPL_BYTES);

        $this->assertSame(1, substr_count($picture, 'id="' . self::AREA_ID . '"'));
        // Twice: the <noscript> fallback carries the same id.
        $this->assertSame(2, substr_count($content, 'id="' . self::IMAGE_ID . '"'));
    }
}
