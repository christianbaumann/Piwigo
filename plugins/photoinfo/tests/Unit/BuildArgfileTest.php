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
}
