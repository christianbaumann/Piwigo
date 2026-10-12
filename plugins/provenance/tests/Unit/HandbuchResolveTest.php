<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once PIWIGO_ROOT . 'include/functions_handbuch.inc.php';

/**
 * handbuch_resolve() / handbuch_root_path(): which file handbuch.php serves, and where the
 * gallery root is from the URL the browser asked for (decision 0063).
 *
 * Requirement: only html, css and png files inside handbuch/, never its tools/, never
 * anything outside it. Runs against the real handbuch/ directory, so a page the tests name
 * must exist (anti-vacuity: a missing file would make every [HAPPY] case fail, not pass).
 * Decision table not applicable: each refusal reason is independent, covered as one
 * equivalence class each.
 */
final class HandbuchResolveTest extends TestCase
{
    private const DIR = PIWIGO_ROOT . 'handbuch';

    /** [HAPPY][ECP] A handbook file resolves to itself; an empty path to the index. */
    public function testAPageResolvesToItsFile(): void
    {
        $this->assertSame(realpath(self::DIR . '/index.html'), handbuch_resolve(self::DIR, ''));
        $this->assertSame(realpath(self::DIR . '/01-alben.html'), handbuch_resolve(self::DIR, '01-alben.html'));
        $this->assertSame(realpath(self::DIR . '/assets/handbuch.css'), handbuch_resolve(self::DIR, 'assets/handbuch.css'));
    }

    /** [HAPPY][ECP] A screenshot resolves, and is sent as png. */
    public function testAScreenshotResolvesAsPng(): void
    {
        $shots = glob(self::DIR . '/assets/screenshots/*.png');
        $this->assertNotEmpty($shots, 'no screenshot in handbuch/assets/screenshots/');
        $file = handbuch_resolve(self::DIR, 'assets/screenshots/' . basename($shots[0]));

        $this->assertSame(realpath($shots[0]), $file);
        $this->assertSame('image/png', handbuch_content_type($file));
    }

    /** [ECP] Each served type gets its own content type. */
    public function testContentTypes(): void
    {
        $this->assertSame('text/html; charset=utf-8', handbuch_content_type(handbuch_resolve(self::DIR, 'index.html')));
        $this->assertSame('text/css; charset=utf-8', handbuch_content_type(handbuch_resolve(self::DIR, 'assets/handbuch.css')));
    }

    /** @return array<string, array{0: string}> */
    public static function refusedPaths(): array
    {
        return array(
            'traversal to the DB credentials' => array('../local/config/database.inc.php'),
            'traversal inside a path' => array('assets/../../index.php'),
            'tools, checker' => array('tools/check.php'),
            'tools, any file' => array('tools/seed.php'),
            'dot segment into tools' => array('./tools/check.php'),
            'hidden file' => array('.htaccess'),
            'absolute path' => array('/etc/passwd'),
            'backslash' => array('assets\\handbuch.css'),
            'NUL byte' => array("index.html\0.png"),
            'traversal back through tools' => array('tools/../index.php'),
            'missing file of a served type' => array('99-gibt-es-nicht.html'),
            'directory without index' => array('assets/'),
            'empty segment' => array('assets//handbuch.css'),
        );
    }

    /** [NEG] Anything outside the whitelist, outside handbuch/ or in tools/ is refused. */
    #[DataProvider('refusedPaths')]
    public function testARefusedPathResolvesToNothing(string $requested): void
    {
        $this->assertNull(handbuch_resolve(self::DIR, $requested));
    }

    /** [NEG][ECP] A file of a served type under tools/ is refused for being there. */
    public function testAServedTypeUnderToolsIsRefused(): void
    {
        $dir = sys_get_temp_dir() . '/handbuch-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tools', 0777, true);
        file_put_contents($dir . '/tools/x.html', 'x');
        file_put_contents($dir . '/x.html', 'x');
        try
        {
            $this->assertNotNull(handbuch_resolve($dir, 'x.html'));
            $this->assertNull(handbuch_resolve($dir, 'tools/x.html'));
        }
        finally
        {
            unlink($dir . '/tools/x.html');
            unlink($dir . '/x.html');
            rmdir($dir . '/tools');
            rmdir($dir);
        }
    }

    /**
     * [NEG][ECP] A file outside the whitelist is refused for its type alone: same directory,
     * same name, only the extension differs from one that resolves.
     */
    public function testATypeOutsideTheWhitelistIsRefused(): void
    {
        $dir = sys_get_temp_dir() . '/handbuch-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (array('x.html', 'x.php', 'x.js', 'x.HTML') as $name)
        {
            file_put_contents($dir . '/' . $name, 'x');
        }
        try
        {
            $this->assertNotNull(handbuch_resolve($dir, 'x.html'));
            $this->assertNull(handbuch_resolve($dir, 'x.php'));
            $this->assertNull(handbuch_resolve($dir, 'x.js'));
            $this->assertNotNull(handbuch_resolve($dir, 'x.HTML'), 'the extension is compared case-insensitively');
        }
        finally
        {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    /**
     * [NEG][ECP] tools/ is refused in any case: a case-insensitive file system (the macOS
     * bind mount of the local Apache container) would otherwise serve tools/ as Tools/.
     */
    public function testToolsIsRefusedInAnyCase(): void
    {
        $dir = sys_get_temp_dir() . '/handbuch-' . bin2hex(random_bytes(4));
        mkdir($dir . '/TOOLS', 0777, true);
        file_put_contents($dir . '/TOOLS/x.html', 'x');
        try
        {
            $this->assertNull(handbuch_resolve($dir, 'TOOLS/x.html'));
        }
        finally
        {
            unlink($dir . '/TOOLS/x.html');
            rmdir($dir . '/TOOLS');
            rmdir($dir);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function rootPaths(): array
    {
        return array(
            'index through the rewrite' => array('/handbuch/', '', '../'),
            'page through the rewrite' => array('/handbuch/01-alben.html', '01-alben.html', '../'),
            'nested file through the rewrite' => array('/handbuch/assets/handbuch.css', 'assets/handbuch.css', '../../'),
            'subdirectory install' => array('/gallery/handbuch/01-alben.html', '01-alben.html', '../'),
            'entry asked directly' => array('/handbuch.php', 'assets/handbuch.css', './'),
            'entry asked directly, subdirectory' => array('/gallery/handbuch.php', '', './'),
        );
    }

    /** [ECP][BVA] The root climbs one level per directory of the rewritten URL, none when asked directly. */
    #[DataProvider('rootPaths')]
    public function testTheRootPath(string $requestPath, string $requested, string $expected): void
    {
        $this->assertSame($expected, handbuch_root_path($requestPath, $requested));
    }
}
