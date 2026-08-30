<?php
use PHPUnit\Framework\TestCase;

/**
 * Every file the plugin includes at runtime has to survive a fresh clone.
 *
 * The root .gitignore excludes /plugins/* and re-includes the tracked ones with
 * a `!` entry. A new plugin without that entry is invisible to git: it works
 * perfectly on the machine it was written on and does not exist anywhere else.
 * Nothing else in the suite would notice.
 */
final class CleanCheckoutTest extends TestCase
{
    /** The files the plugin's own code includes or reads at runtime. */
    private const RUNTIME_FILES = array(
        'main.inc.php',
        'maintain.class.php',
        'include/functions.inc.php',
        'include/events_public.inc.php',
        'include/exiftool.inc.php',
        'include/index.inc.php',
        'include/rescan.inc.php',
        'include/ws_functions.inc.php',
        'template/public_overlay.tpl',
        'template/public_persons.tpl',
        'template/overlay.css',
        'template/overlay.js',
        'language/en_UK/plugin.lang.php',
    );

    /** [HAPPY] Each runtime file exists where the includes point. */
    public function testEveryRuntimeFileExists(): void
    {
        $this->assertGreaterThan(0, count(self::RUNTIME_FILES), 'anti-vacuity: nothing to check');

        foreach (self::RUNTIME_FILES as $file)
        {
            $this->assertFileExists(PERSONS_PATH . $file);
        }
    }

    /** [NEG] And none of them is ignored by git, so a clone gets all of them. */
    public function testNoRuntimeFileIsGitIgnored(): void
    {
        foreach (self::RUNTIME_FILES as $file)
        {
            $path = 'plugins/persons/' . $file;
            $status = 0;
            $output = array();
            exec(
                'cd ' . escapeshellarg(PIWIGO_ROOT) . ' && git check-ignore -q ' . escapeshellarg($path),
                $output,
                $status
            );

            // git check-ignore exits 0 when the path IS ignored, 1 when it is not.
            $this->assertSame(1, $status, "$path is git-ignored and would be missing from a fresh clone");
        }
    }
}
