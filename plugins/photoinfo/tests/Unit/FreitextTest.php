<?php
use PHPUnit\Framework\TestCase;

/**
 * Which tags a save put into Freitext: created by the save (an id above the
 * highest one before it, and linked to the photos it saved), in no group, and
 * not the tag of a person.
 */
final class FreitextTest extends TestCase
{
    private const MAX_BEFORE = 40;

    private static function row(int $id, ?int $group = null): array
    {
        return array('id' => $id, 'group' => $group);
    }

    /** @return int[] the ids of all rows: every tag linked to the saved photos */
    private static function linked(array $rows): array
    {
        return array_column($rows, 'id');
    }

    /** [BVA] The highest id before the save is old; the next one is new. */
    public function testOnlyIdsAboveTheMaximumAreNew(): void
    {
        $rows = array(self::row(self::MAX_BEFORE), self::row(self::MAX_BEFORE + 1));

        $this->assertSame(array(self::MAX_BEFORE + 1), photoinfo_new_freitext_tags($rows, self::MAX_BEFORE, array(), self::linked($rows)));
    }

    /** [ECP] A new tag that is in a group already keeps it. */
    public function testANewGroupedTagIsLeftAlone(): void
    {
        $rows = array(self::row(41, 7), self::row(42));

        $this->assertSame(array(42), photoinfo_new_freitext_tags($rows, self::MAX_BEFORE, array(), self::linked($rows)));
    }

    /** [NEG] A person's tag is never put into Freitext. */
    public function testAPersonTagIsLeftAlone(): void
    {
        $rows = array(self::row(41), self::row(42));

        $this->assertSame(array(42), photoinfo_new_freitext_tags($rows, self::MAX_BEFORE, array(41), self::linked($rows)));
    }

    /**
     * [NEG] A tag created meanwhile by another request, linked to none of the
     * photos this save changed, is not this save's to group.
     */
    public function testANewTagNotLinkedToTheSavedPhotosIsLeftAlone(): void
    {
        $rows = array(self::row(41), self::row(42));

        $this->assertSame(array(42), photoinfo_new_freitext_tags($rows, self::MAX_BEFORE, array(), array(42)));
    }

    /** [BVA] No new tag, nothing to assign. */
    public function testNoNewTagGivesNothing(): void
    {
        $this->assertSame(array(), photoinfo_new_freitext_tags(array(), self::MAX_BEFORE, array(), array()));
        $this->assertSame(array(), photoinfo_new_freitext_tags(array(self::row(3)), self::MAX_BEFORE, array(), array(3)));
    }

    /** [ECP] Ids as the database hands them back, as strings, compare as numbers. */
    public function testIdsReadAsStringsAreComparedAsNumbers(): void
    {
        $rows = array(array('id' => '100', 'group' => null), array('id' => '41', 'group' => '3'));

        $this->assertSame(array(100), photoinfo_new_freitext_tags($rows, '99', array('7'), array('100', '41')));
    }

    /**
     * [ECP] A person's tag id read as a string, as query2array() delivers it,
     * still keeps that tag out of Freitext.
     */
    public function testAPersonTagIdReadAsAStringIsLeftAlone(): void
    {
        $rows = array(self::row(41), self::row(42));

        $this->assertSame(array(42), photoinfo_new_freitext_tags($rows, self::MAX_BEFORE, array('41'), self::linked($rows)));
    }
}
