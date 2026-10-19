<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * photoedit_localize_message() in-process, for the messages no HTTP request
 * can force (no image library, failed writes) and the ones carrying a value.
 */
final class GermanMessageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        PiwigoRuntime::loadPipeline();
        require_once PHOTOEDIT_PATH . 'include/ws_functions.inc.php';
    }

    protected function setUp(): void
    {
        global $lang;
        $lang = array();
        load_language('plugin.lang', PHOTOEDIT_PATH, array('language' => 'de_DE', 'no_fallback' => true));
    }

    public static function messages(): array
    {
        return array(
            '[ECP] no image library' => array('No image library is available on this server', 'Bildbibliothek'),
            '[ECP] backup failed' => array('Could not back up the original file', 'gesichert'),
            '[ECP] write failed' => array('The photo could not be written', 'geschrieben'),
            '[ECP] library produced nothing' => array('The image library did not produce the edited photo', 'Bildbibliothek'),
            '[ECP] metadata copy failed' => array('exiftool could not copy the metadata back', 'Metadaten'),
            '[ECP] replace failed' => array('Could not replace the image file', 'ersetzt'),
            '[ECP] edit running' => array('Another edit of this photo is still running', 'läuft noch'),
            '[ECP] security token' => array('Invalid security token', 'Sicherheits'),
            '[ECP] crop must leave N pixels' => array('crop must leave at least 16 pixels on each side', 'mindestens 16 Pixel'),
            '[ECP] turns range' => array('turns must be a whole number from 0 to 3', 'von 0 bis 3'),
            '[ECP] extensions list' => array('Only jpg, png files can be edited', 'jpg, png'),
            '[ECP] crop fractions' => array('crop must be four fractions from 0 to 1', 'von 0 bis 1'),
            '[ECP] crop order' => array('crop must have l < r and t < b', 'l < r'),
            );
    }

    #[DataProvider('messages')]
    public function testAnInternalMessageIsTranslated(string $english, string $germanFragment): void
    {
        $german = photoedit_localize_message($english);

        $this->assertNotSame($english, $german, 'not translated');
        $this->assertStringContainsString($germanFragment, $german);
    }

    /** [ECP] A text already in the user's language (the persons refusal) passes through. */
    public function testAGermanMessagePassesThrough(): void
    {
        $this->assertSame('Diese Person wurde nicht von Ihnen markiert.', photoedit_localize_message('Diese Person wurde nicht von Ihnen markiert.'));
    }

    private const MIN_SOURCE_MESSAGES = 13;

    /** [ECP] Every fixed message the pipeline or the validation can return has a German and an English entry. */
    public function testEveryFixedMessageInTheSourceHasATranslation(): void
    {
        $found = array();
        foreach (array('include/pipeline.inc.php', 'include/functions.inc.php', 'include/ws_functions.inc.php') as $file)
        {
            $source = file_get_contents(PHOTOEDIT_PATH . $file);
            $this->assertGreaterThan(1000, strlen($source), $file);
            preg_match_all("/(?:photoedit_failure\\('\\w+', |photoedit_write_failure\\(|\\\$refuse\\(|photoedit_localize_message\\()'([^']+)'\\s*[,)]|'error' => '([^']+)'\\s*[,)]/", $source, $m);
            $found = array_merge($found, array_filter($m[1]), array_filter($m[2]));
        }
        $found = array_unique($found);
        $this->assertGreaterThanOrEqual(self::MIN_SOURCE_MESSAGES, count($found), implode("\n", $found));

        foreach (array('de_DE', 'en_UK') as $locale)
        {
            $lang = array();
            include PHOTOEDIT_PATH . 'language/' . $locale . '/plugin.lang.php';
            foreach ($found as $message)
            {
                $this->assertArrayHasKey($message, $lang, $locale . ' lacks a translation of: ' . $message);
            }
        }
    }
}
