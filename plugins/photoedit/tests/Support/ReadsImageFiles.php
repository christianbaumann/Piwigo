<?php
/**
 * Reads the test photo's file with ImageMagick - a reader that is neither this
 * plugin nor exiftool, which the pipeline itself uses to copy the metadata back.
 *
 * Expects the using test to hold the photo FixtureBuilder made in $this->image.
 */
trait ReadsImageFiles
{
    /** Pixels in from each corner where the marker colour is sampled. */
    private const SAMPLE_INSET = 5;

    /** The file's size, as ImageMagick reads it. */
    private function identify(): array
    {
        $out = FixtureBuilder::run('identify -format "%w %h" ' . escapeshellarg($this->image['file']));
        return array_map('intval', explode(' ', trim($out)));
    }

    /** The file's page geometry (canvas size and offset), e.g. "150x200+0+0". */
    private function pageGeometry(): string
    {
        return trim(FixtureBuilder::run('identify -format "%g" ' . escapeshellarg($this->image['file'])));
    }

    /** The colour of one pixel, as ImageMagick reads it, normalised to srgb(r,g,b). */
    private function pixel(int $x, int $y): string
    {
        $out = FixtureBuilder::run(sprintf(
            'convert %s -format "%%[pixel:p{%d,%d}]" info:',
            escapeshellarg($this->image['file']), $x, $y
        ));
        return trim($out);
    }

    /** EXIF Orientation as ImageMagick names it: TopLeft is upright, RightTop is Orientation 6. */
    private function orientation(): string
    {
        return trim(FixtureBuilder::run('identify -format "%[orientation]" ' . escapeshellarg($this->image['file'])));
    }

    /** The JPEG quality ImageMagick estimates from the file's quantization tables. */
    private function jpegQuality(): int
    {
        return (int)trim(FixtureBuilder::run('identify -format "%Q" ' . escapeshellarg($this->image['file'])));
    }

    /** Whether a sampled colour is the marker's red, allowing for JPEG's drift. */
    private static function isRed(string $pixel): bool
    {
        if (!preg_match('/^s?rgba?\((\d+),(\d+),(\d+)/', str_replace(' ', '', $pixel), $m))
        {
            return false;
        }
        return (int)$m[1] > 200 && (int)$m[2] < 60 && (int)$m[3] < 60;
    }

    /** Which corners are red, sampled a few pixels in from each. */
    private function redCorners(): array
    {
        list($w, $h) = $this->identify();
        $inset = self::SAMPLE_INSET;
        $corners = array(
            'top-left' => array($inset, $inset),
            'top-right' => array($w - 1 - $inset, $inset),
            'bottom-right' => array($w - 1 - $inset, $h - 1 - $inset),
            'bottom-left' => array($inset, $h - 1 - $inset),
            );

        $found = array();
        foreach ($corners as $name => list($x, $y))
        {
            if (self::isRed($this->pixel($x, $y)))
            {
                $found[] = $name;
            }
        }
        return $found;
    }

    /**
     * The regions in the file, from ImageMagick's raw XMP packet.
     *
     * @return array name => array(x, y, w, h), plus '' => applied (w, h)
     */
    private function regionsInFile(): array
    {
        $packet = FixtureBuilder::run('convert ' . escapeshellarg($this->image['file']) . ' xmp:-');
        $dom = new DOMDocument();
        $this->assertTrue(@$dom->loadXML($packet), 'not an XMP packet: ' . $packet);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mwg-rs', 'http://www.metadataworkinggroup.com/schemas/regions/');
        $xpath->registerNamespace('stArea', 'http://ns.adobe.com/xmp/sType/Area#');
        $xpath->registerNamespace('stDim', 'http://ns.adobe.com/xap/1.0/sType/Dimensions#');

        $regions = array();
        foreach ($xpath->query('//mwg-rs:RegionList//mwg-rs:Area/..') as $li)
        {
            $value = fn (string $tag) => (float)$xpath->evaluate("string(mwg-rs:Area/stArea:$tag)", $li);
            $regions[$xpath->evaluate('string(mwg-rs:Name)', $li)] = array($value('x'), $value('y'), $value('w'), $value('h'));
        }
        $regions[''] = array(
            (int)$xpath->evaluate('string(//mwg-rs:AppliedToDimensions/stDim:w)'),
            (int)$xpath->evaluate('string(//mwg-rs:AppliedToDimensions/stDim:h)'),
            );
        return $regions;
    }

}
