<?php
use PHPUnit\Framework\TestCase;

require_once GALLERYRULES_PATH . 'include/plain_text.inc.php';

/**
 * Titel, Autor, Info, an album's name and its description are plain text
 * (decision 0073): what was typed is what the page shows, markup included.
 *
 * Technique notes: decision table not applicable, one input and one outcome per
 * class; state transition not applicable, the functions keep no state.
 */
final class PlainTextTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    /** [HAPPY] Markup comes back as text. */
    public function testMarkupBecomesText(): void
    {
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', galleryrules_plain_text(self::PAYLOAD));
    }

    /** [ECP] Both quote characters are escaped, so a value cannot close the attribute it sits in. */
    public function testQuotesCannotCloseAnAttribute(): void
    {
        $this->assertSame('Oma&#039;s &quot;Album&quot;', galleryrules_plain_text('Oma\'s "Album"'));
    }

    /** [ECP] An entity typed in is shown as typed, not as the character it names. */
    public function testATypedEntityIsShownAsTyped(): void
    {
        $this->assertSame('Tom &amp;amp; Jerry', galleryrules_plain_text('Tom &amp; Jerry'));
    }

    /** [ECP] Text without special characters, umlauts included, is unchanged. */
    public function testPlainTextIsUnchanged(): void
    {
        $this->assertSame('Kirmes in Sefferweich, Bäumer 1954', galleryrules_plain_text('Kirmes in Sefferweich, Bäumer 1954'));
    }

    /** [BVA] The empty string stays empty and null stays null, so core's emptiness checks still hold. */
    public function testEmptyAndNullPassThrough(): void
    {
        $this->assertSame('', galleryrules_plain_text(''));
        $this->assertNull(galleryrules_plain_text(null));
    }

    /** [NEG] A broken UTF-8 sequence is replaced, not allowed to blank the whole value. */
    public function testBrokenUtf8DoesNotBlankTheValue(): void
    {
        $this->assertSame("Kirmes \u{FFFD} 1954", galleryrules_plain_text("Kirmes \xC3 1954"));
    }

    /** [HAPPY] A three-line description keeps its three lines. */
    public function testLinesBecomeLineBreaks(): void
    {
        $this->assertSame("eins<br />\nzwei<br />\ndrei", galleryrules_plain_text_lines("eins\nzwei\ndrei"));
    }

    /** [ECP] The text is escaped before the line breaks go in, so only the breaks are markup. */
    public function testMarkupOnALineStaysText(): void
    {
        $this->assertSame(
            "&lt;b&gt;eins&lt;/b&gt;<br />\nzwei",
            galleryrules_plain_text_lines("<b>eins</b>\nzwei")
            );
    }

    /** [ECP] Windows line ends count once each. */
    public function testCrLfIsOneLineBreak(): void
    {
        $this->assertSame("eins<br />\r\nzwei", galleryrules_plain_text_lines("eins\r\nzwei"));
    }

    /** [BVA] One line gets no break; empty and null pass through. */
    public function testOneLineGetsNoBreak(): void
    {
        $this->assertSame('eins', galleryrules_plain_text_lines('eins'));
        $this->assertSame('', galleryrules_plain_text_lines(''));
        $this->assertNull(galleryrules_plain_text_lines(null));
    }
}
