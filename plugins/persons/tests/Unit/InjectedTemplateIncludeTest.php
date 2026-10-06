<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once PERSONS_PATH . 'include/events_public.inc.php';
require_once PERSONS_PATH . 'include/events_admin.inc.php';

/**
 * The prefilters reference the plugin's template files, they do not copy them.
 *
 * Smarty decides whether a compiled template is current from the mtime of the
 * template it compiled - picture.tpl, picture_modify.tpl - and never from a
 * file a prefilter read while compiling it. A file's content pasted in by the
 * prefilter is therefore frozen into the compiled page until someone empties
 * _data/templates_c/ by hand: the 2026-10-04 reload-on-exit attribute never
 * reached an install that had compiled the picture page before it. An
 * {include} is compiled as a template of its own and checked on its own mtime.
 */
final class InjectedTemplateIncludeTest extends TestCase
{
    /** Shorter than this, the file is a stub or a failed read and "not pasted in" says nothing. */
    private const MIN_TPL_BYTES = 100;

    public static function injections(): array
    {
        return array(
            'picture stage' => array('persons_picture_prefilter', PERSONS_TPL_INJECT_POINT . PERSONS_TPL_ROW_INJECT_POINT, 'public_overlay.tpl'),
            'picture row' => array('persons_picture_prefilter', PERSONS_TPL_INJECT_POINT . PERSONS_TPL_ROW_INJECT_POINT, 'public_persons.tpl'),
            'picture editor' => array('persons_picture_prefilter', PERSONS_TPL_INJECT_POINT . PERSONS_TPL_ROW_INJECT_POINT, 'public_editor.tpl'),
            'photo screen link' => array('persons_photo_prefilter', PERSONS_TPL_PHOTO_ANCHOR, 'admin_photo_link.tpl'),
        );
    }

    private function templateFile(string $name): string
    {
        $path = realpath(PERSONS_PATH . 'template/' . $name);
        $this->assertNotFalse($path, $name . ' is gone');
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen((string)file_get_contents($path)),
            'anti-vacuity: too little was read for "not pasted in" to mean anything'
        );

        return $path;
    }

    /**
     * [HAPPY] The anchor gets exactly one include of the file, by absolute path,
     * so it resolves whatever directory Smarty compiles from.
     */
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

    /**
     * [NEG] The file's content is not in the output, which is what froze it
     * into the compiled page.
     */
    #[DataProvider('injections')]
    public function testThePrefilterDoesNotPasteTheTemplateFileIn(string $prefilter, string $content, string $name): void
    {
        $fileContent = (string)file_get_contents($this->templateFile($name));

        $this->assertStringNotContainsString($fileContent, $prefilter($content));
    }
}
