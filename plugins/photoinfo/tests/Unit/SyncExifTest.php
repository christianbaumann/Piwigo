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
