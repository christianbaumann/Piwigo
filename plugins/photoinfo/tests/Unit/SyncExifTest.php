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

    /**
     * [DT] photoinfo date (yes/no) x camera metadata (Make, Model, both,
     * neither): the file's date survives only without a photoinfo date and with
     * camera metadata.
     */
    public static function decisions(): array
    {
        $make = array('Make' => 'Canon');
        $model = array('Model' => 'EOS 5D');

        return array(
            'no photoinfo date, Make and Model' => array(false, $make + $model, true),
            'no photoinfo date, Make only' => array(false, $make, true),
            'no photoinfo date, Model only' => array(false, $model, true),
            'no photoinfo date, neither' => array(false, array(), false),
            'photoinfo date, Make and Model' => array(true, $make + $model, false),
            'photoinfo date, neither' => array(true, array(), false),
            );
    }

    #[DataProvider('decisions')]
    public function testTheFileDateIsKeptOnlyForACameraFileWithoutAPhotoinfoDate(bool $hasDate, array $camera, bool $kept): void
    {
        $exif = $camera + array(self::FIELD => self::DATE, 'FileSize' => 1234);

        $result = photoinfo_sync_exif($exif, self::FIELD, $hasDate);

        $this->assertSame($kept, array_key_exists(self::FIELD, $result));
        $this->assertSame(1234, $result['FileSize'], 'other EXIF fields are passed through');
    }

    /** [BVA] An empty Make or Model is no camera metadata. */
    public function testAnEmptyMakeAndModelCountAsNone(): void
    {
        $exif = array('Make' => '', 'Model' => "  ", self::FIELD => self::DATE);

        $this->assertArrayNotHasKey(self::FIELD, photoinfo_sync_exif($exif, self::FIELD, false));
    }

    /** [NEG] Nothing read from the file (a PNG, a HEIC) stays nothing. */
    public function testNoExifIsPassedThrough(): void
    {
        $this->assertNull(photoinfo_sync_exif(null, self::FIELD, false));
        $this->assertNull(photoinfo_sync_exif(null, self::FIELD, true));
    }

    /** [NEG] Without a date mapping there is nothing to protect. */
    public function testWithoutAMappedFieldNothingChanges(): void
    {
        $exif = array(self::FIELD => self::DATE);

        $this->assertSame($exif, photoinfo_sync_exif($exif, null, true));
    }

    /** [ECP] The field mapped to date_creation is the one dropped, whatever it is named. */
    public function testTheMappedFieldIsTheOneDropped(): void
    {
        $exif = array('DateTimeDigitized' => self::DATE, self::FIELD => self::DATE);

        $result = photoinfo_sync_exif($exif, 'DateTimeDigitized', true);

        $this->assertArrayNotHasKey('DateTimeDigitized', $result);
        $this->assertSame(self::DATE, $result[self::FIELD]);
    }

    public static function paths(): array
    {
        // file (as the sync builds it), root => images.path
        return array(
            'root ./' => array('/var/www/html/upload/a/b.jpg', '/var/www/html', './upload/a/b.jpg'),
            'root with slash' => array('/var/www/html/galleries/x.png', '/var/www/html/', './galleries/x.png'),
            'outside the root' => array('/tmp/x.png', '/var/www/html', null),
            'a sibling sharing the prefix' => array('/var/www/html2/x.png', '/var/www/html', null),
            'no file' => array(false, '/var/www/html', null),
            );
    }

    /** [ECP] A file's resolved path maps to images.path relative to the gallery root. */
    #[DataProvider('paths')]
    public function testAFileMapsToItsImagePath(string|false $file, string $root, ?string $expected): void
    {
        $this->assertSame($expected, photoinfo_image_path($file, $root));
    }
}
