<?php
use PHPUnit\Framework\TestCase;

/**
 * The picture prefilter and the core template it rewrites.
 *
 * Both anchors are structural guards: a moved anchor makes Smarty compile the
 * untouched template and the page silently shows the description above the
 * photo again, with no Info row.
 */
final class PicturePrefilterTest extends TestCase
{
    private const MIN_TEMPLATE_BYTES = 5000;

    private static function pictureTemplate(): string
    {
        return file_get_contents(PIWIGO_ROOT . 'themes/default/template/picture.tpl');
    }

    public static function setUpBeforeClass(): void
    {
        require_once PHOTOINFO_PATH . 'include/events_public.inc.php';
    }

    /** [HAPPY] Each anchor occurs exactly once in core's picture template. */
    public function testCoreTemplateCarriesBothAnchorsOnce(): void
    {
        $template = self::pictureTemplate();

        $this->assertGreaterThan(self::MIN_TEMPLATE_BYTES, strlen($template), 'anti-vacuity: picture.tpl was not read');
        $this->assertSame(1, substr_count($template, PHOTOINFO_TPL_ROW_ANCHOR));
        $this->assertSame(1, substr_count($template, PHOTOINFO_TPL_COMMENT_BLOCK));
    }

    /** [HAPPY] The prefilter removes the description block and adds the row once. */
    public function testThePrefilterMovesTheDescriptionIntoTheRow(): void
    {
        $out = photoinfo_picture_prefilter(self::pictureTemplate());

        $this->assertStringNotContainsString('class="imageComment"', $out);
        $this->assertSame(1, substr_count($out, photoinfo_template_include('public_info.tpl') . PHOTOINFO_TPL_ROW_ANCHOR));
    }

    /** [NEG] A sub-template without the row anchor is left alone. */
    public function testATemplateWithoutTheAnchorIsUntouched(): void
    {
        $content = "{if isset(\$COMMENT_IMG)}\n<p class=\"imageComment\">{\$COMMENT_IMG}</p>\n{/if}\n<dl></dl>";

        $this->assertSame($content, photoinfo_picture_prefilter($content));
    }

    /** [HAPPY] The include names a template that exists. */
    public function testTheIncludedTemplateExists(): void
    {
        $this->assertMatchesRegularExpression("/^\\{include file='\\/.+public_info\\.tpl'\\}$/", photoinfo_template_include('public_info.tpl'));
    }

    /** [HAPPY] Core's "Created on" row occurs once, so the pattern removes exactly it. */
    public function testCoreTemplateCarriesOneCreatedOnRow(): void
    {
        $this->assertSame(1, preg_match_all(PHOTOINFO_TPL_DATE_ROW_PATTERN, self::pictureTemplate()));
    }

    /** [HAPPY] The prefilter removes "Created on" and puts the Datum row ahead of the Info row. */
    public function testThePrefilterReplacesTheCreatedOnRow(): void
    {
        $template = self::pictureTemplate();
        $this->assertStringContainsString('id="datecreate"', $template, 'anti-vacuity: the core row is not there to remove');

        $out = photoinfo_picture_prefilter($template);

        $this->assertStringNotContainsString('id="datecreate"', $out);
        $this->assertSame(1, substr_count($out,
            photoinfo_template_include('public_date.tpl') . photoinfo_template_include('public_info.tpl') . PHOTOINFO_TPL_ROW_ANCHOR));
    }
}
