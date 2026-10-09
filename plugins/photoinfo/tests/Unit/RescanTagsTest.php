<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a rescan does with the tags a file names: link the ones the photo
 * lacks, with the group the hierarchy gives, and report the ones the photo has
 * that the file does not name. It never removes; pruning is separate.
 *
 * Tags are compared by id: a name in the file stands for the tag core's
 * tag_id_from_tag_name() would find for it (MariaDB's collation, then the URL
 * name), which the caller resolves and passes in.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", Phase 6 and Desired
 * End State 3 (Q2a: no marker, nothing at all; Q5/Q12: local-only names).
 */
final class RescanTagsTest extends TestCase
{
    private static function file(array $subject, array $hierarchy = array(), bool $marker = true): array
    {
        return array('subject' => $subject, 'hierarchy' => $hierarchy, 'tags_marker' => $marker);
    }

    private static function db(int $id, string $name, ?string $group = null): array
    {
        return array('id' => $id, 'name' => $name, 'group' => $group);
    }

    public static function decisions(): array
    {
        // file tags, database tags, existing tag ids by file name => expected link, expected not_in_file
        return array(
            'in the file only, no group [DT]' => array(
                self::file(array('Anna')), array(), array(),
                array('Anna' => null), array()),
            'in the file only, with a group [DT]' => array(
                self::file(array('Kirmes'), array('Feste|Kirmes')), array(), array(),
                array('Kirmes' => 'Feste'), array()),
            'in the file, an existing tag the photo lacks [DT]' => array(
                self::file(array('Kirmes')), array(), array('Kirmes' => 9),
                array('Kirmes' => null), array()),
            'in both [DT]' => array(
                self::file(array('Kirmes'), array('Feste|Kirmes')), array(self::db(9, 'Kirmes', 'Feste')), array('Kirmes' => 9),
                array(), array()),
            'in both, the names differ in case only [DT]' => array(
                self::file(array('kirche')), array(self::db(9, 'Kirche')), array('kirche' => 9),
                array(), array()),
            'in the database only [DT]' => array(
                self::file(array()), array(self::db(9, 'Kirmes', 'Feste')), array(),
                array(), array(9 => 'Kirmes')),
            'a number as name, in the database only [DT]' => array(
                self::file(array()), array(self::db(9, '1965')), array(),
                array(), array(9 => '1965')),
            'local-only in the database only [DT]' => array(
                self::file(array()), array(self::db(9, 'Ausstellung', 'Ausstellung'), self::db(10, 'Name ?')), array(),
                array(), array()),
            'local-only in the file [DT]' => array(
                self::file(array('Ausstellung', 'Wer?')), array(), array(),
                array(), array()),
            'in a local-only group, in the database only [DT]' => array(
                self::file(array()), array(self::db(9, 'Vernissage 1987', 'Ausstellung'), self::db(10, 'Hans', 'Kategorie ?')), array(),
                array(), array()),
            'in a local-only group stored with a trailing space, in the database only [BVA]' => array(
                self::file(array()), array(self::db(9, 'Vernissage 1987', 'Ausstellung '), self::db(10, 'Ausstellung ')), array(),
                array(), array()),
            'in a local-only group, in the file [DT]' => array(
                self::file(array('Vernissage 1987'), array('Ausstellung|Vernissage 1987')), array(), array(),
                array(), array()),
            'in a group named like a local-only one, in the file [DT]' => array(
                self::file(array('Vernissage 1987'), array('Ausstellungen|Vernissage 1987')), array(), array(),
                array('Vernissage 1987' => 'Ausstellungen'), array()),
            );
    }

    #[DataProvider('decisions')]
    public function testTheDecisionTable(array $file, array $db, array $existing, array $link, array $notInFile): void
    {
        $this->assertSame(array('link' => $link, 'not_in_file' => $notInFile), photoinfo_rescan_tags($file, $db, $existing));
    }

    /** [NEG] Without the marker the file says nothing about tags: nothing linked, nothing reported. */
    public function testWithoutTheMarkerNothingHappens(): void
    {
        $this->assertSame(array('link' => array(), 'not_in_file' => array()),
            photoinfo_rescan_tags(self::file(array('Anna'), array(), false), array(self::db(9, 'Kirmes')), array()));
    }

    /** [ERR] A hierarchy entry whose tag is missing from Subject still links the tag with its group. */
    public function testAHierarchyEntryWithoutItsSubjectStillLinks(): void
    {
        $this->assertSame(array('link' => array('Kirmes' => 'Feste'), 'not_in_file' => array()),
            photoinfo_rescan_tags(self::file(array(), array('Feste|Kirmes')), array(), array()));
    }

    /** [BVA] An entry with two separators splits at the first: the group, then the rest as the tag. */
    public function testAnEntrySplitsAtTheFirstSeparator(): void
    {
        $this->assertSame(array('Zug|Mehr' => 'Häuser'),
            photoinfo_file_tag_names(self::file(array('Zug|Mehr'), array('Häuser|Zug|Mehr'))));
    }

    /** [NEG] An entry without a separator, or with an empty side, names nothing. */
    public function testAMalformedEntryNamesNothing(): void
    {
        foreach (array('Kirmes', '|Kirmes', 'Feste|') as $entry)
        {
            $this->assertSame(array(), photoinfo_file_tag_names(self::file(array(), array($entry))), $entry);
        }
    }

    /** [ECP] Tags the file and the database both hold are neither linked nor reported, whatever the order. */
    public function testOrderDoesNotMatter(): void
    {
        $this->assertSame(array('link' => array(), 'not_in_file' => array()),
            photoinfo_rescan_tags(self::file(array('Zug', 'Anna')), array(self::db(1, 'Anna'), self::db(2, 'Zug')),
                array('Anna' => 1, 'Zug' => 2)));
    }
}
