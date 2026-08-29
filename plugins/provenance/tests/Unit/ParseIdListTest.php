<?php
use PHPUnit\Framework\TestCase;

/**
 * The apply operation is chunked by the client, so the id list arrives as one
 * comma-joined request parameter. Turning that string into ids is the only part
 * of the operation that is pure, and it is the part a malformed request reaches
 * first, so it is tested here rather than through the web service.
 */
final class ParseIdListTest extends TestCase
{
    /** [HAPPY] A plain list becomes ints, in the order given. */
    public function testPlainListBecomesInts(): void
    {
        $this->assertSame(array(3, 1, 2), provenance_parse_id_list('3,1,2'));
    }

    /** [ECP] Surrounding whitespace on a member is ignored. */
    public function testWhitespaceAroundMembersIsIgnored(): void
    {
        $this->assertSame(array(3, 1), provenance_parse_id_list(" 3 ,\n1 "));
    }

    /** [ECP] A repeated id is carried once: the update and the history row must not double. */
    public function testDuplicatesAreCollapsed(): void
    {
        $this->assertSame(array(7, 9), provenance_parse_id_list('7,9,7,9,7'));
    }

    /** [BVA] The empty string is an empty list, not a list holding one bad member. */
    public function testEmptyStringIsAnEmptyList(): void
    {
        $this->assertSame(array(), provenance_parse_id_list(''));
    }

    /** [BVA] A list of only separators is empty too. */
    public function testSeparatorsOnlyIsAnEmptyList(): void
    {
        $this->assertSame(array(), provenance_parse_id_list(',,  ,'));
    }

    /** [NEG] A non-numeric member makes the whole list unusable; a partial apply is worse than none. */
    public function testANonNumericMemberRejectsTheWholeList(): void
    {
        $this->assertNull(provenance_parse_id_list('3,x,5'));
    }

    /** [NEG] Zero is not an id. */
    public function testZeroIsRejected(): void
    {
        $this->assertNull(provenance_parse_id_list('3,0'));
    }

    /** [NEG] A negative number is not an id. */
    public function testNegativeIsRejected(): void
    {
        $this->assertNull(provenance_parse_id_list('-3'));
    }

    /** [NEG] A decimal is not an id - (int) casting one would silently apply to the wrong photo. */
    public function testDecimalIsRejected(): void
    {
        $this->assertNull(provenance_parse_id_list('3.5'));
    }

    /** [BVA] A list at the chunk ceiling is accepted. */
    public function testListAtTheChunkCeilingIsAccepted(): void
    {
        $ids = range(1, PROVENANCE_APPLY_MAX_CHUNK);

        $this->assertSame($ids, provenance_parse_id_list(implode(',', $ids)));
    }

    /** [BVA] One past the ceiling is refused: the chunk size is a contract, not a suggestion. */
    public function testListPastTheChunkCeilingIsRejected(): void
    {
        $ids = range(1, PROVENANCE_APPLY_MAX_CHUNK + 1);

        $this->assertNull(provenance_parse_id_list(implode(',', $ids)));
    }
}
