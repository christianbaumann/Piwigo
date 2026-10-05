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
            if (preg_match('/^s?rgba?\(255,0,0/', str_replace(' ', '', $this->pixel($x, $y))))
            {
                $found[] = $name;
            }
        }
        return $found;
    }
}
