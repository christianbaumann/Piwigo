<?php
use PHPUnit\Framework\TestCase;

/**
 * A person's name is user input that reaches three places with different rules:
 * a varchar column, an XMP string, and a JSON argfile handed to exiftool. This
 * is the one function that has to satisfy all three.
 */
final class PersonNameTest extends TestCase
{
    /** [HAPPY] An ordinary name is stored as typed. */
    public function testATypicalNameIsUnchanged(): void
    {
        $this->assertSame('Jane Doe', persons_clean_name('Jane Doe'));
    }

    /** [NEG] The name is rendered into a page; markup never survives. */
    public function testMarkupIsStripped(): void
    {
        $this->assertSame('Jane Doe', persons_clean_name('<b>Jane</b> Doe'));
    }

    /** [ECP] Surrounding whitespace is not part of the name. */
    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $this->assertSame('Jane Doe', persons_clean_name('   Jane Doe  '));
    }

    /** [ECP] Nor is a run of it in the middle. */
    public function testInternalWhitespaceIsCollapsed(): void
    {
        $this->assertSame('Jane Doe', persons_clean_name("Jane \t  Doe"));
    }

    /**
     * [NEG] A newline reaches exiftool inside a JSON argfile, where a raw one
     * is a different value than the user typed. It is flattened to a space.
     */
    public function testANameContainingANewlineIsFlattened(): void
    {
        $this->assertSame('Jane Doe', persons_clean_name("Jane\nDoe"));
    }

    /** [BVA] [NEG] Nothing typed is not a name. */
    public function testAnEmptyNameIsRejected(): void
    {
        $this->assertSame('', persons_clean_name(''));
    }

    /** [BVA] [NEG] Nor is whitespace alone. */
    public function testANameOfOnlyWhitespaceIsRejected(): void
    {
        $this->assertSame('', persons_clean_name("  \t\n "));
    }

    /** [BVA] A name that exactly fills the column is kept whole. */
    public function testANameAtExactlyTheByteCapIsAccepted(): void
    {
        $name = str_repeat('a', PERSONS_NAME_MAX_BYTES);

        $this->assertSame($name, persons_clean_name($name));
    }

    /** [BVA] One byte over is cut, not refused. */
    public function testANameOneByteOverTheCapIsTruncated(): void
    {
        $cleaned = persons_clean_name(str_repeat('a', PERSONS_NAME_MAX_BYTES + 1));

        $this->assertSame(PERSONS_NAME_MAX_BYTES, strlen($cleaned));
    }

    /**
     * [BVA] The cut lands on a character boundary. A truncated UTF-8 sequence
     * is not a shorter name, it is an invalid string, and MariaDB would reject
     * or mangle it.
     */
    public function testANameOverTheCapIsTruncatedOnAUtf8Boundary(): void
    {
        // 'e' + 127 two-byte characters is 255 bytes; one more overruns by two.
        $cleaned = persons_clean_name('e' . str_repeat('é', 128));

        $this->assertLessThanOrEqual(PERSONS_NAME_MAX_BYTES, strlen($cleaned));
        $this->assertGreaterThan(0, strlen($cleaned), 'anti-vacuity: an empty result would pass the check below trivially');
        $this->assertSame($cleaned, mb_convert_encoding($cleaned, 'UTF-8', 'UTF-8'), 'the cut split a character');
    }

    /**
     * [ERR] The cap is a byte count because the column is. A multibyte name is
     * therefore shorter in characters than an ASCII one. No requirement states
     * this; it records what the column forces.
     */
    public function testAMultibyteNameCountsBytesNotCharacters(): void
    {
        $cleaned = persons_clean_name(str_repeat('é', PERSONS_NAME_MAX_BYTES));

        $this->assertLessThanOrEqual(PERSONS_NAME_MAX_BYTES, strlen($cleaned));
        $this->assertLessThan(PERSONS_NAME_MAX_BYTES, mb_strlen($cleaned, 'UTF-8'));
    }

    /** [HAPPY] A name outside ASCII survives untouched when it fits. */
    public function testAUnicodeNameSurvivesCleaning(): void
    {
        $this->assertSame('Zoë Müller', persons_clean_name('Zoë Müller'));
    }
}
