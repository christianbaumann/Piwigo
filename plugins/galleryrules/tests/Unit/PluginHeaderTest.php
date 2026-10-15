<?php
use PHPUnit\Framework\TestCase;

/**
 * Core lists a plugin by the header comment it reads out of the first
 * HEADER_BYTES of main.inc.php (admin/include/plugins.class.php,
 * get_fs_plugins()). A header pushed past that window, or a version core's
 * pattern does not match, leaves the plugin nameless or at version 0, with no
 * error anywhere. The two patterns below are core's, as written there.
 */
final class PluginHeaderTest extends TestCase
{
    private const HEADER_BYTES = 2048;
    private const MIN_HEADER_BYTES = 100;

    private string $header;

    protected function setUp(): void
    {
        $this->header = (string)file_get_contents(GALLERYRULES_PATH . 'main.inc.php', false, null, 0, self::HEADER_BYTES);
        $this->assertGreaterThan(self::MIN_HEADER_BYTES, strlen($this->header));
    }

    /** [HAPPY] Core finds the plugin's name inside the window it reads. */
    public function testCoreReadsThePluginName(): void
    {
        $this->assertSame(1, preg_match('|Plugin Name:\\s*(.+)|', $this->header, $match));
        $this->assertSame('Gallery Rules', trim($match[1]));
    }

    /** [HAPPY] Core finds a version, so activation does not record version 0. */
    public function testCoreReadsAVersion(): void
    {
        $this->assertSame(1, preg_match('|Version:\\s*([\\w.-]+)|', $this->header, $match));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $match[1]);
    }

    /** [NEG] main.inc.php refuses to run under another folder name, and core keys the maintain class on it. */
    public function testThePluginLivesInTheFolderItsMaintainClassNames(): void
    {
        $this->assertSame('galleryrules', basename(GALLERYRULES_PATH));
        $this->assertStringContainsString(
            'class galleryrules_maintain extends PluginMaintain',
            (string)file_get_contents(GALLERYRULES_PATH . 'maintain.class.php')
        );
    }
}
