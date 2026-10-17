<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a metadata sync may take from a file as the photo's date, and which
 * photo row a file read during the sync belongs to.
 *
 * Requirements: design "Never write DateTimeOriginal; protect the date on
 * re-sync", "A file's own date counts only with camera metadata".
 */
final class SyncExifTest extends TestCase
{
    private const FIELD = 'DateTimeOriginal';
    private const DATE = '2019:05:04 13:14:15';
    private const STORED = '1965-03-01 00:00:00';

    /**
     * [DT] photoinfo date (yes/no) x camera metadata (Make, Model, both,
     * neither): with a photoinfo date the field carries the stored date; without
     * one, the file's date survives only with camera metadata.
     */
    public static function decisions(): array
    {
        $make = array('Make' => 'Canon');
        $model = array('Model' => 'EOS 5D');

        return array(
            'no photoinfo date, Make and Model' => array(null, $make + $model, self::DATE),
            'no photoinfo date, Make only' => array(null, $make, self::DATE),
            'no photoinfo date, Model only' => array(null, $model, self::DATE),
            'no photoinfo date, neither' => array(null, array(), null),
            'photoinfo date, Make and Model' => array(self::STORED, $make + $model, self::STORED),
            'photoinfo date, Make only' => array(self::STORED, $make, self::STORED),
            'photoinfo date, Model only' => array(self::STORED, $model, self::STORED),
            'photoinfo date, neither' => array(self::STORED, array(), self::STORED),
            );
    }

    #[DataProvider('decisions')]
    public function testTheFieldCarriesTheDateTheSyncMayWrite(?string $stored, array $camera, ?string $expected): void
    {
        $exif = $camera + array(self::FIELD => self::DATE, 'FileSize' => 1234);

        $result = photoinfo_sync_exif($exif, self::FIELD, $stored);

        $this->assertSame($expected, $result[self::FIELD] ?? null);
        $this->assertSame(1234, $result['FileSize'], 'other EXIF fields are passed through');
    }

    /** [BVA] An empty Make or Model is no camera metadata. */
    public function testAnEmptyMakeAndModelCountAsNone(): void
    {
        $exif = array('Make' => '', 'Model' => "  ", self::FIELD => self::DATE);

        $this->assertArrayNotHasKey(self::FIELD, photoinfo_sync_exif($exif, self::FIELD, null));
    }

    /** [NEG] Nothing read from the file (a PNG, a HEIC) and no photoinfo date stays nothing. */
    public function testNoExifAndNoPhotoinfoDateIsPassedThrough(): void
    {
        $this->assertNull(photoinfo_sync_exif(null, self::FIELD, null));
    }

    /**
     * [HAPPY] A photoinfo date is put into the field even when the file had none
     * or PHP read nothing: a sync that writes missing values as NULL
     * (site_update's "meta_empty_overrides") would otherwise clear it.
     */
    public function testAPhotoinfoDateIsSuppliedWhereTheFileHasNone(): void
    {
        $this->assertSame(array(self::FIELD => self::STORED), photoinfo_sync_exif(null, self::FIELD, self::STORED));
        $this->assertSame(array('Make' => 'Canon', self::FIELD => self::STORED),
            photoinfo_sync_exif(array('Make' => 'Canon'), self::FIELD, self::STORED));
    }

    /** [NEG] Without a date mapping there is nothing to protect. */
    public function testWithoutAMappedFieldNothingChanges(): void
    {
        $exif = array(self::FIELD => self::DATE);

        $this->assertSame($exif, photoinfo_sync_exif($exif, null, self::STORED));
        $this->assertNull(photoinfo_sync_exif(null, null, self::STORED));
    }

    /** [ECP] The field mapped to date_creation is the one set, whatever it is named. */
    public function testTheMappedFieldIsTheOneSet(): void
    {
        $exif = array('DateTimeDigitized' => self::DATE, self::FIELD => self::DATE);

        $result = photoinfo_sync_exif($exif, 'DateTimeDigitized', self::STORED);

        $this->assertSame(self::STORED, $result['DateTimeDigitized']);
        $this->assertSame(self::DATE, $result[self::FIELD]);
    }

    /**
     * [HAPPY] The date columns handed in go into the EXIF fields of the same
     * name, which main.inc.php maps onto them, next to the date field; a NULL
     * column stays NULL.
     */
    public function testTheDateColumnsTravelNextToTheDateField(): void
    {
        $columns = array('photoinfo_date_precision' => 'year', 'photoinfo_date_qualifier' => null);

        $result = photoinfo_sync_exif(null, self::FIELD, self::STORED, $columns);

        $this->assertSame(array(self::FIELD => self::STORED) + $columns, $result);
    }

    /** [NEG] Without a date to write, columns handed in are not written either. */
    public function testWithoutADateNoColumnIsWritten(): void
    {
        $exif = array('Make' => 'Canon', self::FIELD => self::DATE);

        $this->assertSame($exif, photoinfo_sync_exif($exif, self::FIELD, null, array('photoinfo_date_precision' => 'year')));
    }

    /** [ECP] The mapping main.inc.php adds: each of photoinfo's date columns, read from the field of its own name. */
    public function testTheMappingNamesEachDateColumn(): void
    {
        $columns = array_keys(photoinfo_image_columns());
        $this->assertGreaterThan(0, count($columns), 'anti-vacuity: photoinfo has date columns');

        $this->assertSame(array_combine($columns, $columns), photoinfo_sync_mapping());
        $this->assertArrayNotHasKey('date_creation', photoinfo_sync_mapping(), 'core already maps date_creation');
    }

    public static function fileDates(): array
    {
        // EDTF, DateCreated as exiftool -X prints it => date_creation, precision, qualifier, end, end precision
        return array(
            'EDTF range [HAPPY]' => array('1965/1970', '1965', array('1965-01-01 00:00:00', 'year', 'between', '1970-01-01', 'year')),
            'EDTF circa month [HAPPY]' => array('1965-03~', '1965:03', array('1965-03-01 00:00:00', 'month', 'circa', null, null)),
            'EDTF wins over DateCreated [ECP]' => array('../1950', '1965:03:12', array('1950-01-01 00:00:00', 'year', 'before', null, null)),
            'DateCreated alone, a year [ECP]' => array(null, '1965', array('1965-01-01 00:00:00', 'year', null, null, null)),
            'DateCreated alone, a month [ECP]' => array(null, '1965:03', array('1965-03-01 00:00:00', 'month', null, null, null)),
            'DateCreated alone, a day [ECP]' => array(null, '1965:03:12', array('1965-03-12 00:00:00', 'day', null, null, null)),
            'DateCreated with a time: the day [ECP]' => array(null, '2019:05:04 13:14:15+02:00', array('2019-05-04 00:00:00', 'day', null, null, null)),
            'unreadable EDTF falls back to DateCreated [NEG]' => array('1965?', '1966', array('1966-01-01 00:00:00', 'year', null, null, null)),
            'unreadable EDTF, no DateCreated [NEG]' => array('1965?', null, null),
            'impossible DateCreated [BVA]' => array(null, '1965:02:29', null),
            'DateCreated before 1800 [BVA]' => array(null, '1799', null),
            'DateCreated as text [NEG]' => array(null, 'März 1965', null),
            'neither tag [NEG]' => array(null, null, null),
            );
    }

    /** What a sync takes from a file without a photoinfo date in its row: EDTF first, then DateCreated. */
    #[DataProvider('fileDates')]
    public function testTheFileGivesTheDateColumns(?string $edtf, ?string $dateCreated, ?array $expected): void
    {
        $columns = photoinfo_sync_file_columns(array('edtf' => $edtf, 'date_created' => $dateCreated), 2026, null);

        $this->assertSame($expected === null ? null : array_combine(array_keys(photoinfo_dating_columns(null)), $expected), $columns);
    }

    /** [BVA] A stored date on the file's day keeps its time of day, as the rescan does; a day later does not. */
    public function testAStoredDateOnTheSameDayKeepsItsTime(): void
    {
        $file = array('edtf' => '1965-03-12', 'date_created' => null);

        $this->assertSame('1965-03-12 14:30:00', photoinfo_sync_file_columns($file, 2026, '1965-03-12 14:30:00')['date_creation']);
        $this->assertSame('1965-03-12 00:00:00', photoinfo_sync_file_columns($file, 2026, '1965-03-13 14:30:00')['date_creation']);
    }

    /** [HAPPY] exiftool -X's two date tags, as exiftool 13.25 prints them (measured 2026-10-17). */
    public function testTheXmlGivesBothDates(): void
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n"
            . "<rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n\n"
            . "<rdf:Description rdf:about='/tmp/t.png'\n"
            . "  xmlns:et='http://ns.exiftool.org/1.0/' et:toolkit='Image::ExifTool 13.25'\n"
            . "  xmlns:XMP-pwginfo='" . PHOTOINFO_RDF_GROUP_URI . "'\n"
            . "  xmlns:XMP-photoshop='http://ns.exiftool.org/XMP/XMP-photoshop/1.0/'>\n"
            . " <XMP-pwginfo:DateEDTF>1965-03~</XMP-pwginfo:DateEDTF>\n"
            . " <XMP-photoshop:DateCreated>1965:03</XMP-photoshop:DateCreated>\n"
            . "</rdf:Description>\n</rdf:RDF>\n";

        $this->assertSame(array('edtf' => '1965-03~', 'date_created' => '1965:03'), photoinfo_parse_sync_date_xml($xml));
    }

    /** [NEG] A file with neither tag gives two nulls; output that is no XML gives null. */
    public function testTheXmlWithoutDatesOrWithoutXml(): void
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n"
            . "<rdf:RDF xmlns:rdf='http://www.w3.org/1999/02/22-rdf-syntax-ns#'>\n"
            . "<rdf:Description rdf:about='/tmp/u.png'/>\n</rdf:RDF>\n";

        $this->assertSame(array('edtf' => null, 'date_created' => null), photoinfo_parse_sync_date_xml($xml));
        $this->assertNull(photoinfo_parse_sync_date_xml(''));
        $this->assertNull(photoinfo_parse_sync_date_xml('Error: File not found'));
    }

    public static function paths(): array
    {
        // file (as the sync builds it: root prefix + images.path), root prefix => images.path
        return array(
            'admin and ws.php' => array('././upload/a/b.jpg', './', './upload/a/b.jpg'),
            'path stored without ./' => array('./galleries/x.png', './', './galleries/x.png'),
            'another root prefix' => array('../galleries/x.png', '../', './galleries/x.png'),
            'outside the root' => array('/tmp/x.png', './', null),
            'climbing out' => array('././upload/../../x.png', './', null),
            );
    }

    /** [ECP] A file name maps to its images.path without touching the filesystem, so a symlinked folder cannot defeat it. */
    #[DataProvider('paths')]
    public function testAFileMapsToItsImagePath(string $file, string $root, ?string $expected): void
    {
        $this->assertSame($expected, photoinfo_image_path($file, $root));
    }

    public static function representatives(): array
    {
        // images.path of the file core read => original's path prefix and representative_ext, or null
        return array(
            'HEIC upload' => array('./upload/2026/10/08/x.jpg', null),
            'its representative' => array('./upload/2026/10/08/pwg_representative/x.jpg',
                array('./upload/2026/10/08/x.', 'jpg')),
            'dots in the name' => array('./galleries/a/pwg_representative/v.1.png', array('./galleries/a/v.1.', 'png')),
            'no extension' => array('./galleries/a/pwg_representative/x', null),
            );
    }

    /** [ECP] A representative file (pwg_representative/, see original_to_representative()) points back at its original. */
    #[DataProvider('representatives')]
    public function testARepresentativeMapsToItsOriginal(string $path, ?array $expected): void
    {
        $this->assertSame($expected, photoinfo_representative_original($path));
    }
}
