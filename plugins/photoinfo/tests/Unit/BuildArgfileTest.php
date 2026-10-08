<?php
use PHPUnit\Framework\TestCase;

/**
 * The argfile one save hands exiftool: the composed caption in provenance's
 * five slots and the info text alone in XMP-pwginfo:Info.
 */
final class BuildArgfileTest extends TestCase
{
    private const PREFIX = '/ops/42-';

    /** [HAPPY] Single-line values: charset first, every slot, then the info tag. */
    public function testSingleLineValuesStayOnTheirLines(): void
    {
        $this->assertSame(
            array(
                '-charset',
                'iptc=UTF8',
                '-EXIF:ImageDescription=Info | P',
                '-IPTC:Caption-Abstract=Info | P',
                '-XMP-dc:Description=Info | P',
                '-XMP-photoshop:Headline=Info | P',
                '-XMP-tiff:ImageDescription=Info | P',
                '-XMP-pwginfo:Info=Info',
            ),
            photoinfo_build_argfile('Info | P', 'Info', self::PREFIX)
        );
    }

    /**
     * [NEG] A multi-line caption and info text never put a line break on an
     * argfile line; each names its value file, which carries the text.
     */
    public function testMultiLineValuesTravelInValueFiles(): void
    {
        $caption = "Zeile 1\nZeile 2\n\nOwner: Anna";
        $info = "Zeile 1\nZeile 2";
        $lines = photoinfo_build_argfile($caption, $info, self::PREFIX);

        foreach ($lines as $line)
        {
            $this->assertStringNotContainsString("\n", $line);
        }
        $this->assertContains('-XMP-dc:Description<=' . self::PREFIX . 'caption.txt', $lines);
        $this->assertContains('-XMP-pwginfo:Info<=' . self::PREFIX . PHOTOINFO_INFO_VALUE_FILE, $lines);

        $this->assertSame(
            array(
                self::PREFIX . 'caption.txt' => $caption,
                self::PREFIX . 'caption-iptc.txt' => $caption,
                self::PREFIX . PHOTOINFO_INFO_VALUE_FILE => $info,
            ),
            provenance_argfile_value_files(photoinfo_argfile_values($caption, $info), self::PREFIX)
        );
    }

    /**
     * [BVA] Nothing to say still emits every tag with an empty value - exiftool's
     * "delete" - so clearing the text clears it from the file.
     */
    public function testEmptyValuesDeleteEveryTag(): void
    {
        $lines = photoinfo_build_argfile('', '  ', self::PREFIX);

        $this->assertCount(8, $lines);
        foreach (array_slice($lines, 2) as $line)
        {
            $this->assertMatchesRegularExpression('/^-[A-Za-z-]+:[A-Za-z-]+=$/', $line);
        }
        $this->assertSame('-XMP-pwginfo:Info=', end($lines));
    }

    /** [HAPPY] The info tag uses the prefix the exiftool config declares. */
    public function testTheConfigDeclaresTheNamespaceTheWriterUses(): void
    {
        $config = file_get_contents(PHOTOINFO_PATH . 'exiftool/pwginfo.config');

        $this->assertGreaterThan(100, strlen($config), 'anti-vacuity: the config was not read');
        $this->assertStringContainsString("'" . PHOTOINFO_XMP_PREFIX . "' => '" . PHOTOINFO_XMP_NAMESPACE_URI . "'", $config);
        $this->assertStringContainsString("1 => 'XMP-" . PHOTOINFO_XMP_PREFIX . "'", $config);
        $this->assertSame('XMP-' . PHOTOINFO_XMP_PREFIX . ':Info', PHOTOINFO_INFO_TAG);
        $this->assertMatchesRegularExpression('/^\s*Info\s*=>/m', $config);
    }

    /**
     * [HAPPY] A date save: charset, every caption slot, then the three date
     * tags - and never EXIF DateTimeOriginal.
     */
    public function testADateSaveWritesTheCaptionAndTheThreeDateTags(): void
    {
        $lines = photoinfo_build_date_argfile('März 1965 | P', self::dating(array('year' => 1965, 'month' => 3, 'day' => null)), self::PREFIX);

        $this->assertSame(
            array(
                '-charset',
                'iptc=UTF8',
                '-EXIF:ImageDescription=März 1965 | P',
                '-IPTC:Caption-Abstract=März 1965 | P',
                '-XMP-dc:Description=März 1965 | P',
                '-XMP-photoshop:Headline=März 1965 | P',
                '-XMP-tiff:ImageDescription=März 1965 | P',
                '-XMP-photoshop:DateCreated=1965-03',
                '-IPTC:DateCreated=1965:03:00',
                '-XMP-pwginfo:DateEDTF=1965-03',
            ),
            $lines
        );
        $this->assertSame(array(), preg_grep('/DateTimeOriginal/i', $lines));
    }

    /**
     * [HAPPY] A range puts its start into both DateCreated slots and the
     * whole range into the EDTF tag.
     */
    public function testARangeWritesItsStartAsDateCreated(): void
    {
        $dating = array(
            'qualifier' => 'between',
            'start' => array('year' => 1965, 'month' => 3, 'day' => null),
            'end' => array('year' => 1970, 'month' => null, 'day' => null),
        );

        $this->assertSame(
            array('-XMP-photoshop:DateCreated=1965-03', '-IPTC:DateCreated=1965:03:00', '-XMP-pwginfo:DateEDTF=1965-03/1970'),
            array_slice(photoinfo_build_date_argfile('1965–1970', $dating, self::PREFIX), -3)
        );
    }

    private static function dating(array $start): array
    {
        return array('qualifier' => null, 'start' => $start, 'end' => null);
    }

    /** [BVA] No date deletes all three date tags. */
    public function testNoDateDeletesTheDateTags(): void
    {
        $lines = photoinfo_build_date_argfile('', null, self::PREFIX);

        $this->assertSame(
            array('-XMP-photoshop:DateCreated=', '-IPTC:DateCreated=', '-XMP-pwginfo:DateEDTF='),
            array_slice($lines, -3)
        );
    }

    /** [NEG] A multi-line caption names its value file, never a line break on a line. */
    public function testAMultiLineCaptionInADateSaveTravelsInAValueFile(): void
    {
        $lines = photoinfo_build_date_argfile("1965\nInfo\n\nP", self::dating(array('year' => 1965, 'month' => null, 'day' => null)), self::PREFIX);

        foreach ($lines as $line)
        {
            $this->assertStringNotContainsString("\n", $line);
        }
        $this->assertContains('-XMP-dc:Description<=' . self::PREFIX . 'caption.txt', $lines);
    }

    /**
     * [HAPPY] A save from one of core's screens writes everything: caption,
     * the info text alone, then the three date tags.
     */
    public function testAFullSaveWritesCaptionInfoAndDate(): void
    {
        $this->assertSame(
            array(
                '-charset',
                'iptc=UTF8',
                '-EXIF:ImageDescription=14. März 1965 | Am See',
                '-IPTC:Caption-Abstract=14. März 1965 | Am See',
                '-XMP-dc:Description=14. März 1965 | Am See',
                '-XMP-photoshop:Headline=14. März 1965 | Am See',
                '-XMP-tiff:ImageDescription=14. März 1965 | Am See',
                '-XMP-pwginfo:Info=Am See',
                '-XMP-photoshop:DateCreated=1965-03-14',
                '-IPTC:DateCreated=1965:03:14',
                '-XMP-pwginfo:DateEDTF=1965-03-14',
            ),
            photoinfo_build_full_argfile('14. März 1965 | Am See', 'Am See',
                self::dating(array('year' => 1965, 'month' => 3, 'day' => 14)), self::PREFIX)
        );
    }

    /** [BVA] A full save with no date and no text deletes every tag it names. */
    public function testAnEmptyFullSaveDeletesEveryTag(): void
    {
        $lines = photoinfo_build_full_argfile('', '', null, self::PREFIX);

        $this->assertCount(11, $lines);
        foreach (array_slice($lines, 2) as $line)
        {
            $this->assertMatchesRegularExpression('/^-[\w:-]+=$/', $line);
        }
    }

    /** [HAPPY] The config declares the EDTF tag the writer names. */
    public function testTheConfigDeclaresTheDateTag(): void
    {
        $config = file_get_contents(PHOTOINFO_PATH . 'exiftool/pwginfo.config');

        $this->assertSame('XMP-' . PHOTOINFO_XMP_PREFIX . ':DateEDTF', PHOTOINFO_DATE_EDTF_TAG);
        $this->assertMatchesRegularExpression('/^\s*DateEDTF\s*=>/m', $config);
    }
}
