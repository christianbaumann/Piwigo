<?php
use PHPUnit\Framework\TestCase;

require_once GALLERYRULES_PATH . 'include/events_album_name.inc.php';

/**
 * The create-album dialog of Alben > Verwaltung says "Name fehlt" next to the
 * name field (design: "A blank album name shows a hint"). The prefilter puts the
 * hint's template right after that field in core's albums.tpl.
 *
 * Structural guard: a core edit that moved or renamed the field would leave the
 * dialog rendering without the hint and with no error anywhere.
 *
 * Technique notes: equivalence classes and boundaries not applicable, the input
 * is one template; decision table and state transition not applicable, one
 * outcome per template.
 */
final class AlbumNameHintPrefilterTest extends TestCase
{
    private const MIN_TEMPLATE_BYTES = 2000;

    private string $template;

    protected function setUp(): void
    {
        $this->template = (string)file_get_contents(PIWIGO_ROOT . 'admin/themes/default/template/albums.tpl');
        $this->assertGreaterThan(self::MIN_TEMPLATE_BYTES, strlen($this->template));
    }

    /** [HAPPY] Core's albums.tpl holds the name field of the create dialog exactly once. */
    public function testCoreTemplateHoldsTheNameFieldOnce(): void
    {
        $this->assertSame(1, preg_match_all(GALLERYRULES_ALBUM_NAME_FIELD, $this->template));
    }

    /** [HAPPY] The hint's include follows the name field, inside the create dialog, not the rename dialog. */
    public function testTheHintIsIncludedRightAfterTheNameField(): void
    {
        $filtered = galleryrules_album_name_hint_prefilter($this->template);

        $include = galleryrules_album_name_hint_include();
        $this->assertSame(1, substr_count($filtered, $include));
        $this->assertSame(1, preg_match(GALLERYRULES_ALBUM_NAME_FIELD, $filtered, $match, PREG_OFFSET_CAPTURE));
        $this->assertSame($match[0][1] + strlen($match[0][0]), strpos($filtered, $include));
        $this->assertLessThan(strpos($filtered, 'id="RenameAlbum"'), strpos($filtered, $include));
    }

    /** [HAPPY] The include names the plugin's template by an absolute path that exists (decision 0036). */
    public function testTheIncludeNamesAnExistingTemplate(): void
    {
        $this->assertSame(1, preg_match("/^\\{include file='(\\/[^']+)'\\}$/", galleryrules_album_name_hint_include(), $match));
        $this->assertFileExists($match[1]);
    }

    /** [NEG] A template without the field is returned unchanged. */
    public function testATemplateWithoutTheFieldIsUnchanged(): void
    {
        $other = '<div>{\'Rename album\'|@translate}<input class="user-property-input" /></div>';
        $this->assertSame($other, galleryrules_album_name_hint_prefilter($other));
    }
}
