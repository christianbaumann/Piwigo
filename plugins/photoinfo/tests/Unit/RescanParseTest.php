<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * What a rescan reads back from a file: the EDTF date, exiftool's -X output and
 * the columns both become.
 *
 * Requirements: design "The file carries photoinfo's values separately, and a
 * rescan rebuilds them", "Date model" (the combination table), "Year bounds
 * 1800 to the current year".
 */
final class RescanParseTest extends TestCase
{
    private const NOW = 2026;

    /** What the parse gives for a file photoinfo never wrote tags into. */
    private const NO_TAGS = array('subject' => array(), 'hierarchy' => array(), 'tags_marker' => false);

    /**
     * [DT] Every combination the form can save comes back from its EDTF string
     * as the dating that wrote it.
     */
    #[DataProviderExternal(DatingTest::class, 'combinations')]
    public function testEveryEdtfTheFormWritesParsesBack(string $qualifier, array $start, array $end, array $dating, string $shown, string $edtf): void
    {
        $this->assertSame($edtf, photoinfo_dating_edtf($dating), 'anti-vacuity: the formatter wrote this string');
        $this->assertSame(array('dating' => $dating, 'error' => null), photoinfo_dating_from_edtf($edtf, self::NOW));
    }

    public static function refusedEdtf(): array
    {
        return array(
            // EDTF allows these, but they name nothing the editor can hold.
            'unknown end [NEG]' => array('1965/', PHOTOINFO_DATE_ERROR_EDTF),
            'unknown start [NEG]' => array('/1965', PHOTOINFO_DATE_ERROR_EDTF),
            'uncertain [NEG]' => array('1965?', PHOTOINFO_DATE_ERROR_EDTF),
            'uncertain and approximate [NEG]' => array('1965%', PHOTOINFO_DATE_ERROR_EDTF),
            'open at both ends [NEG]' => array('../..', PHOTOINFO_DATE_ERROR_EDTF),
            'approximate range: ca. never takes one [NEG]' => array('1965~/1970', PHOTOINFO_DATE_ERROR_EDTF),
            'unspecified digits [NEG]' => array('196X', PHOTOINFO_DATE_ERROR_EDTF),
            'one-digit month [NEG]' => array('1965-3', PHOTOINFO_DATE_ERROR_EDTF),
            'two-digit year [NEG]' => array('65', PHOTOINFO_DATE_ERROR_EDTF),
            'empty [NEG]' => array('', PHOTOINFO_DATE_ERROR_EDTF),
            'text [NEG]' => array('ca. 1965', PHOTOINFO_DATE_ERROR_EDTF),
            // The form's own checks, applied to what the file says.
            'year before 1800 [BVA]' => array('1799', PHOTOINFO_DATE_ERROR_YEAR),
            'year after the current one [BVA]' => array((string)(self::NOW + 1), PHOTOINFO_DATE_ERROR_YEAR),
            'month 13 [BVA]' => array('1965-13', PHOTOINFO_DATE_ERROR_MONTH),
            'month 00 [BVA]' => array('1965-00', PHOTOINFO_DATE_ERROR_MONTH),
            '29 February outside a leap year [BVA]' => array('1965-02-29', PHOTOINFO_DATE_ERROR_DAY),
            'end before start [BVA]' => array('1970/1969', PHOTOINFO_DATE_ERROR_END_BEFORE_START),
            'bad end inside a range [NEG]' => array('1965/1970-04-31', PHOTOINFO_DATE_ERROR_DAY),
            );
    }

    /** A string the plugin did not write, or one naming an impossible date, is refused. */
    #[DataProvider('refusedEdtf')]
    public function testRefusedEdtf(string $edtf, string $error): void
    {
        $this->assertSame(array('dating' => null, 'error' => $error), photoinfo_dating_from_edtf($edtf, self::NOW));
    }

    /** [BVA] The edges the refusals sit next to are accepted. */
    public function testTheBoundariesThemselvesParse(): void
    {
        foreach (array('1800', (string)self::NOW, '1964-02-29', '1965-12', '1965-01-31', '1965/1965') as $edtf)
        {
            $parsed = photoinfo_dating_from_edtf($edtf, self::NOW);
            $this->assertNull($parsed['error'], $edtf);
            $this->assertSame($edtf, photoinfo_dating_edtf($parsed['dating']), $edtf);
        }
    }

    /**
     * What `exiftool -X` prints for a file with both tags, as measured with
     * exiftool 13.25 on 2026-10-08.
     */
    private static function exiftoolXml(string $body): string
    {
        return "<?xml version='1.0' encoding='UTF-8'?>\n"
            . "<rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n\n"
            . "<rdf:Description rdf:about='/tmp/t.png'\n"
            . "  xmlns:et='http://ns.exiftool.org/1.0/' et:toolkit='Image::ExifTool 13.25'\n"
            . "  xmlns:XMP-pwginfo='" . PHOTOINFO_RDF_GROUP_URI . "'\n"
            . "  xmlns:XMP-dc='http://ns.exiftool.org/XMP/XMP-dc/1.0/'\n"
            . "  xmlns:XMP-lr='http://ns.exiftool.org/XMP/XMP-lr/1.0/'>\n"
            . $body
            . "</rdf:Description>\n</rdf:RDF>\n";
    }

    /** [HAPPY] Both values come back as the text the file holds, escapes undone, lines and spaces kept. */
    public function testTheXmlGivesBothValuesAsText(): void
    {
        $xml = self::exiftoolXml(
            " <XMP-pwginfo:Info>Zeile &lt;b&gt;eins&lt;/b&gt; &amp; &quot;zwei&quot;  \nÄpfel</XMP-pwginfo:Info>\n"
            . " <XMP-pwginfo:DateEDTF>1965/1970</XMP-pwginfo:DateEDTF>\n");

        $this->assertSame(
            array('info' => "Zeile <b>eins</b> & \"zwei\"  \nÄpfel", 'edtf' => '1965/1970') + self::NO_TAGS,
            photoinfo_parse_rescan_xml($xml));
    }

    /** [ERR] A number-like info text stays the text it was: the reason for -X over -j. */
    public function testANumberLikeInfoStaysText(): void
    {
        $xml = self::exiftoolXml(" <XMP-pwginfo:Info>1.50</XMP-pwginfo:Info>\n");

        $this->assertSame(array('info' => '1.50', 'edtf' => null) + self::NO_TAGS, photoinfo_parse_rescan_xml($xml));
    }

    /** [ECP] A file with neither tag gives two nulls, which is not a failure. */
    public function testAFileWithoutTheTagsGivesNulls(): void
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n"
            . "<rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n\n"
            . "<rdf:Description rdf:about='/tmp/u.png'\n"
            . "  xmlns:et='http://ns.exiftool.org/1.0/' et:toolkit='Image::ExifTool 13.25'>\n"
            . "</rdf:Description>\n</rdf:RDF>\n";

        $this->assertSame(array('info' => null, 'edtf' => null) + self::NO_TAGS, photoinfo_parse_rescan_xml($xml));
    }

    /** [NEG] A tag of the same name in another namespace is not photoinfo's. */
    public function testATagOfAnotherNamespaceIsIgnored(): void
    {
        $xml = self::exiftoolXml(" <XMP-other:Info xmlns:XMP-other='http://example.test/'>fremd</XMP-other:Info>\n");

        $this->assertSame(array('info' => null, 'edtf' => null) + self::NO_TAGS, photoinfo_parse_rescan_xml($xml));
    }

    /**
     * [BVA] A list field as exiftool -X prints it (13.25, measured 2026-10-09):
     * absent when empty, plain text for one entry, an rdf:Bag for more.
     */
    public function testListFieldsOfNoneOneAndManyEntries(): void
    {
        $this->assertSame(array(), photoinfo_parse_rescan_xml(self::exiftoolXml(''))['subject']);

        $one = photoinfo_parse_rescan_xml(self::exiftoolXml(
            " <XMP-dc:Subject>Anna</XMP-dc:Subject>\n"
            . " <XMP-lr:HierarchicalSubject>Feste|Kirmes</XMP-lr:HierarchicalSubject>\n"));
        $this->assertSame(array('Anna'), $one['subject']);
        $this->assertSame(array('Feste|Kirmes'), $one['hierarchy']);

        $many = photoinfo_parse_rescan_xml(self::exiftoolXml(
            " <XMP-dc:Subject>\n  <rdf:Bag>\n   <rdf:li>Anna</rdf:li>\n   <rdf:li>Bräuche, Feste</rdf:li>\n  </rdf:Bag>\n </XMP-dc:Subject>\n"
            . " <XMP-lr:HierarchicalSubject>\n  <rdf:Bag>\n   <rdf:li>Feste|Kirmes</rdf:li>\n   <rdf:li>Häuser|Zug|Mehr</rdf:li>\n  </rdf:Bag>\n </XMP-lr:HierarchicalSubject>\n"));
        $this->assertSame(array('Anna', 'Bräuche, Feste'), $many['subject']);
        $this->assertSame(array('Feste|Kirmes', 'Häuser|Zug|Mehr'), $many['hierarchy']);
    }

    /** [ECP] The marker says the tags were written, even when there are none. */
    public function testTheMarkerIsReadPresentOrAbsent(): void
    {
        $this->assertTrue(photoinfo_parse_rescan_xml(self::exiftoolXml(
            " <XMP-pwginfo:TagsWritten>1</XMP-pwginfo:TagsWritten>\n"))['tags_marker']);
        $this->assertFalse(photoinfo_parse_rescan_xml(self::exiftoolXml(
            " <XMP-dc:Subject>Anna</XMP-dc:Subject>\n"))['tags_marker']);
    }

    /** [NEG] A Subject of another namespace is not the keywords. */
    public function testASubjectOfAnotherNamespaceIsIgnored(): void
    {
        $xml = self::exiftoolXml(" <XMP-other:Subject xmlns:XMP-other='http://example.test/'>fremd</XMP-other:Subject>\n");

        $this->assertSame(array(), photoinfo_parse_rescan_xml($xml)['subject']);
    }

    /** [NEG] Output that is not XML is unreadable, not "no tags". */
    #[DataProvider('notXml')]
    public function testOutputThatIsNotXmlIsUnreadable(string $output): void
    {
        $this->assertNull(photoinfo_parse_rescan_xml($output));
    }

    public static function notXml(): array
    {
        return array(
            'empty' => array(''),
            'an error message' => array('Error: File not found - x.png'),
            'cut off' => array("<?xml version='1.0'?>\n<rdf:RDF>"),
            );
    }

    /** [HAPPY] Both tags give the comment and the five date columns. */
    public function testBothTagsGiveTheCommentAndTheDate(): void
    {
        $this->assertSame(
            array(
                'columns' => array(
                    'comment' => 'Hochzeit',
                    'date_creation' => '1965-03-01 00:00:00',
                    'photoinfo_date_precision' => 'month',
                    'photoinfo_date_qualifier' => 'after',
                    'photoinfo_date_end' => null,
                    'photoinfo_date_end_precision' => null,
                    ),
                'error' => null,
                ),
            photoinfo_rescan_columns(array('info' => 'Hochzeit', 'edtf' => '1965-03/..'), true, self::NOW));
    }

    /** [ECP] A missing tag leaves its columns out, so the row keeps what it has. */
    public function testAMissingTagLeavesItsColumnsAlone(): void
    {
        $this->assertSame(array('columns' => array(), 'error' => null),
            photoinfo_rescan_columns(array('info' => null, 'edtf' => null), true, self::NOW));
        $this->assertSame(array('comment'),
            array_keys(photoinfo_rescan_columns(array('info' => 'Taufe', 'edtf' => null), true, self::NOW)['columns']));
        $this->assertSame(array_keys(photoinfo_dating_columns(null)),
            array_keys(photoinfo_rescan_columns(array('info' => null, 'edtf' => '1965'), true, self::NOW)['columns']));
    }

    /** [NEG] An unreadable date is reported and leaves the date alone, while the info text is still restored. */
    public function testAnUnreadableDateIsReportedAndTheInfoStillRestored(): void
    {
        $this->assertSame(
            array('columns' => array('comment' => 'Taufe'), 'error' => PHOTOINFO_DATE_ERROR_EDTF . ': 1965/'),
            photoinfo_rescan_columns(array('info' => 'Taufe', 'edtf' => '1965/'), true, self::NOW));
    }

    /** [ECP] The info text is cleaned like a save: markup only where the install allows it. */
    public function testTheInfoTextIsCleanedLikeASave(): void
    {
        $tags = array('info' => ' <b>Taufe</b> ', 'edtf' => null);

        $this->assertSame(array('comment' => '<b>Taufe</b>'), photoinfo_rescan_columns($tags, true, self::NOW)['columns']);
        $this->assertSame(array('comment' => 'Taufe'), photoinfo_rescan_columns($tags, false, self::NOW)['columns']);
        $this->assertSame(array(), photoinfo_rescan_columns(array('info' => '<br>', 'edtf' => null), false, self::NOW)['columns'],
            'markup alone, stripped, is no text');
    }

    public static function storedDates(): array
    {
        // stored date_creation, EDTF in the file => whether date_creation is written
        return array(
            'same day, a time of day [ECP]' => array('1987-06-21 14:30:00', '1987-06-21', false),
            'same start of a year [ECP]' => array('1965-01-01 09:00:00', '1965', false),
            'the day after [BVA]' => array('1987-06-22 14:30:00', '1987-06-21', true),
            'no date [ECP]' => array(null, '1987-06-21', true),
            );
    }

    /**
     * [ECP] A stored date on the file's day keeps its time of day: core's
     * picker and a camera's EXIF store one, and photoinfo holds a day at most.
     * The plugin's own columns are written either way.
     */
    #[DataProvider('storedDates')]
    public function testAStoredDateOnTheSameDayKeepsItsTime(?string $stored, string $edtf, bool $written): void
    {
        $columns = photoinfo_rescan_columns(array('info' => null, 'edtf' => $edtf), true, self::NOW, $stored)['columns'];

        $this->assertSame($written, array_key_exists('date_creation', $columns));
        $this->assertArrayHasKey('photoinfo_date_precision', $columns);
    }
}
