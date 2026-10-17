<?php
use PHPUnit\Framework\TestCase;

/**
 * Which tag removals a tag tool must refuse: a person's tag stays on a photo
 * while the person is marked there (design "Tag tools refuse to remove or
 * delete a person's tag"), and how a tag field's raw values split into tag ids
 * and typed names.
 */
final class PersonTagGuardTest extends TestCase
{
    private const PHOTO = 11;
    private const OTHER_PHOTO = 12;
    private const JANE = 101;
    private const JOHN = 102;
    private const PLAIN = 201;

    /** [NEG] A marked person's tag left out of the new list is refused. */
    public function testAMarkedTagLeftOutIsRefused(): void
    {
        $refused = persons_marked_tags_removed(
            array(self::PHOTO => array(self::JANE)),
            array(self::PHOTO => array(self::JANE, self::PLAIN)),
            array(self::PHOTO => array(self::PLAIN))
        );

        $this->assertSame(array(self::PHOTO => array(self::JANE)), $refused);
    }

    /** [HAPPY] An ordinary tag left out is not refused. */
    public function testAnOrdinaryTagLeftOutIsNotRefused(): void
    {
        $refused = persons_marked_tags_removed(
            array(self::PHOTO => array(self::JANE)),
            array(self::PHOTO => array(self::JANE, self::PLAIN)),
            array(self::PHOTO => array(self::JANE))
        );

        $this->assertSame(array(), $refused);
    }

    /** [ECP] A person's tag on a photo where the person is not marked may go. */
    public function testAPersonsTagWithoutAMarkOnThePhotoIsNotRefused(): void
    {
        $refused = persons_marked_tags_removed(
            array(self::OTHER_PHOTO => array(self::JANE)),
            array(self::PHOTO => array(self::JANE), self::OTHER_PHOTO => array(self::JANE)),
            array(self::PHOTO => array(), self::OTHER_PHOTO => array(self::JANE))
        );

        $this->assertSame(array(), $refused);
    }

    /** [ECP] A marked person whose tag the photo does not carry loses nothing. */
    public function testAMarkedTagThePhotoDoesNotCarryIsNotRefused(): void
    {
        $refused = persons_marked_tags_removed(
            array(self::PHOTO => array(self::JANE)),
            array(self::PHOTO => array(self::PLAIN)),
            array(self::PHOTO => array())
        );

        $this->assertSame(array(), $refused);
    }

    /** [ECP] Ids compare as numbers: the request's strings match the index's ints. */
    public function testStringIdsMatchIntIds(): void
    {
        $refused = persons_marked_tags_removed(
            array(self::PHOTO => array(self::JANE, self::JOHN)),
            array(self::PHOTO => array((string)self::JANE, (string)self::JOHN)),
            array(self::PHOTO => array((string)self::JOHN))
        );

        $this->assertSame(array(self::PHOTO => array(self::JANE)), $refused);
    }

    /** [ECP] Only the photos losing a marked tag are named, each with its own tags. */
    public function testEachPhotoIsJudgedOnItsOwn(): void
    {
        $refused = persons_marked_tags_removed(
            array(self::PHOTO => array(self::JANE), self::OTHER_PHOTO => array(self::JOHN)),
            array(self::PHOTO => array(self::JANE), self::OTHER_PHOTO => array(self::JOHN)),
            array(self::PHOTO => array(self::JANE), self::OTHER_PHOTO => array())
        );

        $this->assertSame(array(self::OTHER_PHOTO => array(self::JOHN)), $refused);
    }

    /** [HAPPY] ~~id~~ values are tag ids, anything else a typed name. */
    public function testRawTagsSplitIntoIdsAndNames(): void
    {
        $split = persons_split_raw_tags(array('~~101~~', 'Jane Doe', '~~201~~'));

        $this->assertSame(array('ids' => array(101, 201), 'names' => array('Jane Doe')), $split);
    }

    /** [BVA] An empty field, absent or '', keeps no tag. */
    public function testAnEmptyFieldKeepsNothing(): void
    {
        $this->assertSame(array('ids' => array(), 'names' => array()), persons_split_raw_tags(null));
        $this->assertSame(array('ids' => array(), 'names' => array()), persons_split_raw_tags(''));
    }

    /** [ECP] A comma-separated string splits as core's get_tag_ids() splits it. */
    public function testACommaSeparatedStringSplits(): void
    {
        $this->assertSame(
            array('ids' => array(101), 'names' => array('Jane')),
            persons_split_raw_tags('~~101~~,Jane')
        );
    }

    /** [NEG] A value that only looks like an id is a name, as core reads it. */
    public function testAMalformedIdIsAName(): void
    {
        $this->assertSame(
            array('ids' => array(), 'names' => array('~~10a~~')),
            persons_split_raw_tags(array('~~10a~~'))
        );
    }
}
