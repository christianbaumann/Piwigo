<?php
use PHPUnit\Framework\TestCase;

/**
 * provenance_truncate_for_iptc() enforces the IPTC-IIM 2:120 byte cap. The cap
 * is a *byte* limit, not a character limit, so the boundary cases below are
 * expressed in bytes throughout and multi-byte input is the interesting case.
 */
final class TruncateForIptcTest extends TestCase
{
    /** [BVA] One byte under the cap is left alone. */
    public function testJustUnderTheCapIsUnchanged(): void
    {
        $text = str_repeat('a', PROVENANCE_IPTC_MAX_BYTES - 1);
        $result = provenance_truncate_for_iptc($text);

        $this->assertSame($text, $result['text']);
        $this->assertFalse($result['truncated']);
    }

    /** [BVA] Exactly at the cap is left alone - the cap is inclusive. */
    public function testExactlyAtTheCapIsUnchanged(): void
    {
        $text = str_repeat('a', PROVENANCE_IPTC_MAX_BYTES);
        $result = provenance_truncate_for_iptc($text);

        $this->assertSame($text, $result['text']);
        $this->assertFalse($result['truncated']);
    }

    /** [BVA] One byte over the cap is truncated to within the budget and flagged. */
    public function testJustOverTheCapIsTruncated(): void
    {
        $text = str_repeat('a', PROVENANCE_IPTC_MAX_BYTES + 1);
        $result = provenance_truncate_for_iptc($text);

        $this->assertTrue($result['truncated']);
        $this->assertNotSame($text, $result['text']);
        $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($result['text']));
    }

    /** [BVA] The empty string is unchanged and not flagged. */
    public function testEmptyStringIsUnchanged(): void
    {
        $result = provenance_truncate_for_iptc('');

        $this->assertSame('', $result['text']);
        $this->assertFalse($result['truncated']);
    }

    /** [BVA] The truncation mark is appended, and fits inside the byte budget. */
    public function testTruncationMarkIsAppendedWithinTheBudget(): void
    {
        $result = provenance_truncate_for_iptc(str_repeat('a', PROVENANCE_IPTC_MAX_BYTES * 2));

        $this->assertStringEndsWith(PROVENANCE_TRUNCATION_MARK, $result['text']);
        $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($result['text']));
        $this->assertGreaterThan(strlen(PROVENANCE_TRUNCATION_MARK), strlen($result['text']));
    }

    /**
     * [ERR] A multi-byte character straddling the byte boundary is dropped whole.
     *
     * Characterization of the cut rule: no requirement names the exact cut
     * point, but the result must always be valid UTF-8 - a half character in an
     * IPTC packet is a corrupt packet.
     */
    public function testCharacterStraddlingTheBoundaryIsNotSplit(): void
    {
        // 'ä' is two bytes. Padding by an odd number of ASCII bytes puts a
        // character boundary in the middle of the budget rather than on it.
        for ($pad = 0; $pad < 4; $pad++)
        {
            $text = str_repeat('a', $pad) . str_repeat("\xc3\xa4", PROVENANCE_IPTC_MAX_BYTES);
            $result = provenance_truncate_for_iptc($text);

            $this->assertTrue($result['truncated'], "pad=$pad should have been truncated");
            $this->assertTrue(
                mb_check_encoding($result['text'], 'UTF-8'),
                "pad=$pad produced invalid UTF-8"
            );
            $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($result['text']));
        }
    }

    /** [BVA] Text of only multi-byte characters stays valid UTF-8 within the cap. */
    public function testAllMultiByteTextStaysValidUtf8(): void
    {
        // Three-byte characters, so no multiple of the character width divides
        // the budget evenly.
        $text = str_repeat("\xe6\x97\xa5", PROVENANCE_IPTC_MAX_BYTES);
        $result = provenance_truncate_for_iptc($text);

        $this->assertTrue($result['truncated']);
        $this->assertTrue(mb_check_encoding($result['text'], 'UTF-8'));
        $this->assertLessThanOrEqual(PROVENANCE_IPTC_MAX_BYTES, strlen($result['text']));
        $this->assertStringEndsWith(PROVENANCE_TRUNCATION_MARK, $result['text']);
    }

    /**
     * [ECP] Multi-byte text under the cap by bytes is untouched, even though it
     * has fewer characters than bytes. This is the case a character-counting
     * implementation would get wrong in the opposite direction.
     */
    public function testMultiByteTextUnderTheByteCapIsUnchanged(): void
    {
        $text = str_repeat("\xc3\xa4", (int) (PROVENANCE_IPTC_MAX_BYTES / 2));
        $result = provenance_truncate_for_iptc($text);

        $this->assertSame(PROVENANCE_IPTC_MAX_BYTES, strlen($text), 'fixture must sit exactly on the cap');
        $this->assertSame($text, $result['text']);
        $this->assertFalse($result['truncated']);
    }

    /** [HAPPY] The truncated text is a prefix of the original plus the mark. */
    public function testTruncatedTextIsAPrefixOfTheOriginal(): void
    {
        $text = str_repeat('abcde', PROVENANCE_IPTC_MAX_BYTES);
        $result = provenance_truncate_for_iptc($text);

        $body = substr($result['text'], 0, -strlen(PROVENANCE_TRUNCATION_MARK));

        $this->assertGreaterThan(0, strlen($body), 'anti-vacuity: an empty body would make the prefix check pass trivially');
        $this->assertSame($body, substr($text, 0, strlen($body)));
    }
}
