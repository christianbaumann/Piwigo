<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard: tags.js tells core's duplicate-name refusal from any other
 * refusal of pwg.tags.rename by comparing the server's message with a copy of
 * core's literal. If core rewords it, a real duplicate silently shows core's
 * English text instead of the screen's "existiert bereits".
 */
final class TagRenameDuplicateMessageTest extends TestCase
{
    private const JS = 'admin/themes/default/js/tags.js';
    private const PHP = 'include/ws_functions/pwg.tags.php';

    /** A file shorter than this is a stub or a failed read. */
    private const MIN_FILE_BYTES = 2000;

    private function read(string $relative): string
    {
        $content = (string)file_get_contents(PIWIGO_ROOT . $relative);
        $this->assertGreaterThan(self::MIN_FILE_BYTES, strlen($content), 'anti-vacuity: ' . $relative . ' read too little');

        return $content;
    }

    /** [HAPPY] The literal in tags.js is the one core's rename emits, exactly once each. */
    public function testTheScriptsCopyIsCoresDuplicateMessage(): void
    {
        $this->assertSame(
            1,
            preg_match("/const RENAME_DUPLICATE_MESSAGE = '([^']+)';/", $this->read(self::JS), $m),
            'tags.js no longer declares RENAME_DUPLICATE_MESSAGE'
        );
        $this->assertSame(
            1,
            substr_count($this->read(self::PHP), "WS_ERR_INVALID_PARAM, '" . $m[1] . "'"),
            'core no longer answers a duplicate rename with the text tags.js compares against'
        );
    }
}
