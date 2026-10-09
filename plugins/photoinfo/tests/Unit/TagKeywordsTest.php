<?php
use PHPUnit\Framework\TestCase;

/**
 * What a photo's tags become in its file: a flat keyword list for
 * XMP-dc:Subject and IPTC:Keywords, and "Group|Tag" for
 * XMP-lr:HierarchicalSubject.
 */
final class TagKeywordsTest extends TestCase
{
    /** [HAPPY] A tag in a group is a keyword and a hierarchy entry. */
    public function testGroupedTagsGetAHierarchyEntry(): void
    {
        $this->assertSame(
            array('subject' => array('Kirmes'), 'hierarchy' => array('Feste, Bräuche, Jahreskreis|Kirmes')),
            photoinfo_file_keywords(array(
                array('name' => 'Kirmes', 'group' => 'Feste, Bräuche, Jahreskreis'),
            ))
        );
    }

    /** [ECP] A tag without a group - a person's name - is a keyword only. */
    public function testAnUngroupedTagIsFlatOnly(): void
    {
        $this->assertSame(
            array('subject' => array('Anna Muster'), 'hierarchy' => array()),
            photoinfo_file_keywords(array(array('name' => 'Anna Muster', 'group' => null)))
        );
    }

    /** [NEG] Ausstellung stays in the database. */
    public function testAusstellungIsNeverWritten(): void
    {
        $this->assertSame(
            array('subject' => array('Kirmes'), 'hierarchy' => array()),
            photoinfo_file_keywords(array(
                array('name' => 'Ausstellung', 'group' => 'Ausstellung'),
                array('name' => 'Kirmes', 'group' => null),
            ))
        );
    }

    /** [ECP] A question mark anywhere in the name keeps the tag local. */
    public function testANameWithAQuestionMarkIsNeverWritten(): void
    {
        $this->assertSame(
            array('subject' => array('Kirmes'), 'hierarchy' => array()),
            photoinfo_file_keywords(array(
                array('name' => 'Name ?', 'group' => 'Name ?'),
                array('name' => 'Kategorie ?', 'group' => 'Kategorie ?'),
                array('name' => 'Wer?', 'group' => null),
                array('name' => 'Kirmes', 'group' => null),
            ))
        );
    }

    /**
     * [ERR] A group name holding the separator would split in the wrong place
     * when read back, so its tags are written flat. No requirement beyond the
     * plan's; records the chosen behaviour.
     */
    public function testAGroupNameWithThePipeGivesNoHierarchyEntry(): void
    {
        $this->assertSame(
            array('subject' => array('Kirmes'), 'hierarchy' => array()),
            photoinfo_file_keywords(array(array('name' => 'Kirmes', 'group' => 'A|B')))
        );
    }

    /** [ECP] Order of the rows does not matter, and a name appears once. */
    public function testOutputIsSortedAndDeduplicated(): void
    {
        $this->assertSame(
            array('subject' => array('Anna', 'Kirmes', 'Zug'), 'hierarchy' => array('Feste|Kirmes', 'Feste|Zug')),
            photoinfo_file_keywords(array(
                array('name' => 'Zug', 'group' => 'Feste'),
                array('name' => 'Kirmes', 'group' => 'Feste'),
                array('name' => 'Anna', 'group' => null),
                array('name' => 'Kirmes', 'group' => 'Feste'),
            ))
        );
    }

    /** [BVA] No tags gives two empty lists, which delete the fields. */
    public function testNoTagsGivesEmptyLists(): void
    {
        $this->assertSame(array('subject' => array(), 'hierarchy' => array()), photoinfo_file_keywords(array()));
    }

    /**
     * [NEG] A line break in a name would start a new exiftool option in the
     * argfile, so control characters become spaces; a name of nothing else is
     * dropped.
     */
    public function testControlCharactersInANameBecomeSpaces(): void
    {
        $this->assertSame(
            array('subject' => array('Kirmes -o x'), 'hierarchy' => array('Feste|Kirmes -o x')),
            photoinfo_file_keywords(array(
                array('name' => "Kirmes\n-o\tx", 'group' => 'Feste'),
                array('name' => "\r\n", 'group' => 'Feste'),
            ))
        );
    }

    /** [NEG] The same holds for a group's name, which typetags stores as typed. */
    public function testControlCharactersInAGroupNameBecomeSpaces(): void
    {
        $this->assertSame(
            array('subject' => array('Kirmes'), 'hierarchy' => array('Feste -o x|Kirmes')),
            photoinfo_file_keywords(array(array('name' => 'Kirmes', 'group' => "Feste\n-o\tx")))
        );
    }
}
