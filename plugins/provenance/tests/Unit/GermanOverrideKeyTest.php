<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the fork's German string overrides.
 *
 * local/language/de_DE.lang.php translates keys by their exact English (or
 * French) literal. An upstream merge that renames or drops one of those
 * literals is invisible at runtime: l10n() falls back to returning the key,
 * the screen silently reverts to English, and nothing errors. Every screenshot
 * in docs/handbuch/ then shows a language the handbook does not.
 *
 * [ERR] The oracle is the current template source, not a requirement. These
 * cases report a change; they do not prove any wording correct.
 */
final class GermanOverrideKeyTest extends TestCase
{
    private const OVERRIDE = 'local/language/de_DE.lang.php';
    private const CORE_ADMIN_LANG = 'language/de_DE/admin.lang.php';

    /** A file shorter than this is a stub or a failed read, on which a count assertion says nothing. */
    private const MIN_BYTES = 500;

    /**
     * Every literal, the file that emits it, and how often it occurs there.
     *
     * The two typetags entries in events_public.inc.php sit inside PHP string
     * literals, so their quotes are backslash-escaped and each occurs twice -
     * asserting the plain form, or a count of one, would be a guard that
     * cannot fail.
     *
     * Forms verified 2026-08-31.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function emitters(): array
    {
        return array(
            'Album updated' => array(
                'admin/themes/default/template/cat_modify.tpl',
                "{'Album updated'|@translate}",
                1,
            ),
            'An error has occured while saving album settings' => array(
                'admin/themes/default/template/cat_modify.tpl',
                "{'An error has occured while saving album settings'|@translate}",
                1,
            ),
            'No photos in the current album, no thumbnail available' => array(
                'admin/themes/default/template/cat_modify.tpl',
                "{'No photos in the current album, no thumbnail available'|@translate}",
                1,
            ),
            'Rename album' => array(
                'admin/themes/default/template/albums.tpl',
                "{'Rename album'|@translate}",
                1,
            ),
            'Album %s now contains %d photos' => array(
                'admin/themes/default/template/photos_add_direct.tpl',
                "{'Album %s now contains %d photos'|translate|escape:javascript}",
                1,
            ),
            '%d photos updated' => array(
                'admin/themes/default/template/photos_add_direct.tpl',
                "{'%d photos updated'|translate|escape:javascript}",
                1,
            ),
            'Posted the %s' => array(
                'admin/picture_modify.php',
                "l10n('Posted the %s'",
                1,
            ),
            'Batch Manager Filter' => array(
                'admin/themes/default/template/batch_manager_global.tpl',
                "{'Batch Manager Filter'|@translate}",
                1,
            ),
            'No filter, add one' => array(
                'admin/themes/default/template/include/batch_manager_filter.inc.tpl',
                "{'No filter, add one'|@translate}",
                1,
            ),
            'Rename Tag' => array(
                'admin/themes/default/template/tags.tpl',
                "{'Rename Tag'|@translate}",
                1,
            ),
            'Remove color' => array(
                'plugins/typetags/template/tags.tpl',
                "{'Remove color'|translate}",
                1,
            ),
            'Couleur' => array(
                'plugins/typetags/include/events_admin.inc.php',
                '{"Couleur"|translate}',
                1,
            ),
            'Add tag' => array(
                'plugins/typetags/include/events_public.inc.php',
                "{\'Add tag\'|@translate}",
                2,
            ),
            'Remove tag' => array(
                'plugins/typetags/include/events_public.inc.php',
                "{\'Remove tag\'|@translate}",
                2,
            ),
            'Create' => array(
                'plugins/typetags/template/tags.tpl',
                "{'Create'|translate}",
                1,
            ),
        );
    }

    /**
     * Which language file carries each key's German translation.
     *
     * Fourteen live in the fork's local override. 'Create' is the exception:
     * core already translates it, so wrapping the raw French literal in a
     * |translate filter was the whole fix and no fork-local entry exists or
     * should - one would shadow core's wording everywhere the plugin loads.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function translations(): array
    {
        $rows = array();
        foreach (array_keys(self::emitters()) as $key)
        {
            $rows[$key] = array($key, 'Create' === $key ? self::CORE_ADMIN_LANG : self::OVERRIDE);
        }

        return $rows;
    }

    /** The subset the fork translates itself, and so the subset that can shadow core. */
    public static function overriddenKeys(): array
    {
        return array_filter(self::translations(), static fn (array $row): bool => self::OVERRIDE === $row[1]);
    }

    private function read(string $file): string
    {
        $path = PIWIGO_ROOT . $file;
        $this->assertFileExists($path, $file . ' is gone');

        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_BYTES,
            strlen($content),
            'anti-vacuity: too little was read from ' . $file . ' for the count below to mean anything'
        );

        return $content;
    }

    /**
     * [ERR] The literal the override translates still occurs in the file that
     * emits it, the expected number of times.
     *
     */
    #[PHPUnit\Framework\Attributes\DataProvider('emitters')]
    public function testTheEmittingFileStillCarriesTheLiteral(string $file, string $literal, int $times): void
    {
        $this->assertSame(
            $times,
            substr_count($this->read($file), $literal),
            $literal . ' moved in ' . $file . ' - its German translation no longer reaches the screen'
        );
    }

    /**
     * [ERR] The other half: a key dropped from the language file reverts the
     * screen just as silently as a renamed literal.
     *
     */
    #[PHPUnit\Framework\Attributes\DataProvider('translations')]
    public function testTheLanguageFileStillTranslatesTheKey(string $key, string $langFile): void
    {
        $this->assertSame(
            1,
            substr_count($this->read($langFile), "\$lang['" . $key . "']"),
            $key . ' has no German translation in ' . $langFile
        );
    }

    /**
     * [BVA] The two format strings are fed through sprintf. A translation that
     * adds, drops or reorders a placeholder is a runtime error, not a wording
     * problem, so the placeholder sequence is asserted rather than the text.
     */
    public function testTheFormatStringsKeepTheirPlaceholderSequence(): void
    {
        $lang = array();
        require PIWIGO_ROOT . self::OVERRIDE;

        $this->assertSame(array('%s', '%d'), $this->placeholders($lang['Album %s now contains %d photos']));
        $this->assertSame(array('%d'), $this->placeholders($lang['%d photos updated']));
    }

    /** @return string[] */
    private function placeholders(string $format): array
    {
        preg_match_all('/%[sd]/', $format, $matches);

        return $matches[0];
    }

    /**
     * [NEG] The override shadows nothing core already translates.
     *
     * load_language() merges the local file over core, so a key core's de_DE
     * files also define would be silently replaced by the wording here - a
     * screen that was already German would change without anything reporting
     * it. Every key in the override exists precisely because core has none.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('overriddenKeys')]
    public function testTheOverrideDoesNotShadowACoreGermanString(string $key, string $langFile): void
    {
        $files = glob(PIWIGO_ROOT . 'language/de_DE/*.lang.php');
        $this->assertNotEmpty($files, 'anti-vacuity: the core German locale was not found, so nothing was checked');

        foreach ($files as $file)
        {
            $this->assertSame(
                0,
                substr_count((string)file_get_contents($file), "\$lang['" . $key . "']"),
                'core already translates ' . $key . ' in ' . basename($file) . '; the override would shadow it'
            );
        }
    }
}
