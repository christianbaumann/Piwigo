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
        'admin.php',
        'admin/photo.php',
        'admin/persons.php',
        'include/events_admin.inc.php',
        'include/events_public.inc.php',
        'include/render.inc.php',
        'include/exiftool.inc.php',
        'include/index.inc.php',
        'include/rescan.inc.php',
        'include/ws_functions.inc.php',
        'template/admin_persons.tpl',
        'template/admin_persons.css',
        'template/admin_persons.js',
        'template/admin_photo.tpl',
        'template/admin_photo.css',
        'template/admin_photo_link.tpl',
        'template/public_overlay.tpl',
        'template/public_persons.tpl',
        'template/overlay.css',
        'template/overlay.js',
        'template/editor.css',
        'template/editor.js',
        'language/en_UK/plugin.lang.php',
    );

    /**
     * The files the documented commands invoke, rather than the ones the plugin
     * includes at runtime.
     *
     * Separate list, because they fail differently: a missing runtime file
     * breaks the gallery, a missing one of these breaks only a fresh clone's
     * ability to run the suite at all - and the suite that would report it is
     * the one that cannot start.
     */
    private const SUITE_FILES = array(
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'phpunit.xml',
        'playwright.config.js',
        'tests/Support/TestUsers.php',
        'tests/Support/create-test-users.php',
        'tests/Support/FixtureBuilder.php',
        'tests/e2e/auth.setup.js',
        'tests/e2e/support/seed.php',
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

    /**
     * [NEG] Every file a documented command names is tracked by git.
     *
     * check-ignore is not enough on its own: a file can be unignored and still
     * never added, which looks identical here and is missing from every clone.
     * `.claude/rules/plugin-test-suites.md` is where those commands live.
     */
    public function testEverySuiteEntryPointIsCommitted(): void
    {
        $this->assertGreaterThan(0, count(self::SUITE_FILES), 'anti-vacuity: nothing to check');

        foreach (array_merge(self::RUNTIME_FILES, self::SUITE_FILES) as $file)
        {
            $path = 'plugins/persons/' . $file;
            $status = 0;
            $output = array();
            exec(
                'cd ' . escapeshellarg(PIWIGO_ROOT) . ' && git ls-files --error-unmatch '
                . escapeshellarg($path) . ' 2>/dev/null',
                $output,
                $status
            );

            $this->assertSame(0, $status, "$path is not committed and would be missing from a fresh clone");
        }
    }
}
