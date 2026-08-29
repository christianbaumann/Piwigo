<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard. The XMP namespace URI is stated twice by necessity: once in
 * PHP, where the write-back builds its tag names, and once in the ExifTool
 * config file, where Perl declares the namespace. Nothing but this test notices
 * when the two drift - a mismatch produces no error, just tags written into a
 * namespace nothing reads back.
 */
final class ExiftoolConfigContractTest extends TestCase
{
    /** A config file shorter than this is truncated or empty, not a real declaration. */
    private const MIN_CONFIG_BYTES = 200;

    private function config(): string
    {
        $path = PROVENANCE_PATH . 'exiftool/pwgprov.config';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_CONFIG_BYTES,
            strlen($content),
            'anti-vacuity: a near-empty config would make every containment assertion below pass'
        );

        return $content;
    }

    /** [HAPPY] The config declares exactly the URI the PHP side writes into. */
    public function testConfigDeclaresTheNamespaceUriThePluginUses(): void
    {
        $this->assertStringContainsString(
            "'" . PROVENANCE_XMP_PREFIX . "' => '" . PROVENANCE_XMP_NAMESPACE_URI . "'",
            $this->config()
        );
    }

    /** [HAPPY] All five provenance tags are declared, or a write silently drops one. */
    public function testConfigDeclaresEveryProvenanceTag(): void
    {
        $tags = array('PhysicalAlbum', 'Owner', 'ScannedOn', 'AlbumNote', 'PhotoNote');
        $config = $this->config();

        foreach ($tags as $tag)
        {
            $this->assertStringContainsString($tag, $config, "tag $tag is not declared in pwgprov.config");
        }
    }

    /** [HAPPY] The group name the read side asks for is XMP-<prefix>. */
    public function testConfigGroupMatchesThePrefix(): void
    {
        $this->assertStringContainsString(
            "1 => 'XMP-" . PROVENANCE_XMP_PREFIX . "'",
            $this->config()
        );
    }
}
