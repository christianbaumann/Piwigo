<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once PROVENANCE_PATH . 'include/events_public.inc.php';
require_once PROVENANCE_PATH . 'include/events_admin.inc.php';

/**
 * The prefilters reference the plugin's template files, they do not copy them.
 *
 * Smarty decides whether a compiled template is current from the mtime of the
 * template it compiled and never from a file a prefilter read while compiling
 * it, so a file's content pasted in is frozen into the compiled page until
 * _data/templates_c/ is emptied by hand. An {include} is compiled as a template
 * of its own and checked on its own mtime. The twin of persons'
 * InjectedTemplateIncludeTest, where the frozen copy was found.
 */
final class InjectedTemplateIncludeTest extends TestCase
{
    /** Shorter than this, the file is a stub or a failed read and "not pasted in" says nothing. */
    private const MIN_TPL_BYTES = 100;

    public static function injections(): array
    {
        return array(
            'picture row' => array('provenance_picture_prefilter', PROVENANCE_TPL_INJECT_POINT, 'public_provenance.tpl'),
            'album screen' => array('provenance_album_prefilter', PROVENANCE_TPL_ALBUM_ANCHOR, 'album_provenance.tpl'),
            'photo screen' => array('provenance_photo_prefilter', PROVENANCE_TPL_PHOTO_ANCHOR, 'photo_provenance.tpl'),
            'batch move' => array('provenance_batch_prefilter', PROVENANCE_TPL_BATCH_MOVE_ANCHOR, 'batch_move_provenance.tpl'),
        );
    }

    private function templateFile(string $name): string
    {
        $path = realpath(PROVENANCE_PATH . 'template/' . $name);
        $this->assertNotFalse($path, $name . ' is gone');
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen((string)file_get_contents($path)),
            'anti-vacuity: too little was read for "not pasted in" to mean anything'
        );

        return $path;
    }

    /** [HAPPY] The anchor gets exactly one include of the file, by absolute path. */
    #[DataProvider('injections')]
    public function testThePrefilterIncludesTheTemplateFileOnce(string $prefilter, string $content, string $name): void
    {
        $path = $this->templateFile($name);

        $this->assertSame(
            1,
            preg_match_all('/\{include file=([\'"])' . preg_quote($path, '/') . '\1\}/', $prefilter($content)),
            $prefilter . ' does not include ' . $name . ' exactly once'
        );
    }

    /** [NEG] The file's content is not in the output, which is what froze it into the compiled page. */
    #[DataProvider('injections')]
    public function testThePrefilterDoesNotPasteTheTemplateFileIn(string $prefilter, string $content, string $name): void
    {
        $fileContent = (string)file_get_contents($this->templateFile($name));

        $this->assertStringNotContainsString($fileContent, $prefilter($content));
    }
}
