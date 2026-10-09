<?php
use PHPUnit\Framework\TestCase;

/**
 * The argfile one tag write hands exiftool: each keyword field replaced as a
 * whole, and the marker that tells a rescan the file speaks for the tags.
 */
final class BuildTagsArgfileTest extends TestCase
{
    /** [HAPPY] One line per keyword and field; the first replaces the list, the rest add. */
    public function testEveryKeywordIsOneLinePerField(): void
    {
        $this->assertSame(
            array(
                '-charset',
                'iptc=UTF8',
                '-XMP-dc:Subject=Anna',
                '-XMP-dc:Subject=Kirmes',
                '-IPTC:Keywords=Anna',
                '-IPTC:Keywords=Kirmes',
                '-XMP-lr:HierarchicalSubject=Feste|Kirmes',
                '-XMP-pwginfo:TagsWritten=1',
            ),
            photoinfo_build_tags_argfile(array(
                'subject' => array('Anna', 'Kirmes'),
                'hierarchy' => array('Feste|Kirmes'),
            ))
        );
    }

    /** [BVA] An empty list deletes its field: exiftool reads "-TAG=" as delete. */
    public function testAnEmptyListDeletesEachField(): void
    {
        $lines = photoinfo_build_tags_argfile(array('subject' => array(), 'hierarchy' => array()));

        $this->assertContains('-XMP-dc:Subject=', $lines);
        $this->assertContains('-IPTC:Keywords=', $lines);
        $this->assertContains('-XMP-lr:HierarchicalSubject=', $lines);
    }

    /**
     * [DT] The marker is set whatever the lists hold: a file whose last tag
     * was removed must still tell a rescan it carries no tags, rather than
     * look like one never written.
     */
    public function testTheMarkerIsAlwaysSet(): void
    {
        $cases = array(
            'both empty' => array('subject' => array(), 'hierarchy' => array()),
            'flat only' => array('subject' => array('Anna'), 'hierarchy' => array()),
            'both' => array('subject' => array('Kirmes'), 'hierarchy' => array('Feste|Kirmes')),
        );

        foreach ($cases as $label => $keywords)
        {
            $lines = photoinfo_build_tags_argfile($keywords);
            $this->assertSame('-' . PHOTOINFO_TAGS_MARKER_TAG . '=1', end($lines), $label);
        }
    }

    /**
     * [ERR] exiftool splits a value at commas only when told to (-sep), so a
     * group name with commas stays one keyword. Records exiftool's default.
     */
    public function testACommaInANameStaysOneKeyword(): void
    {
        $lines = photoinfo_build_tags_argfile(array(
            'subject' => array('Feste, Bräuche, Jahreskreis'),
            'hierarchy' => array(),
        ));

        $this->assertContains('-XMP-dc:Subject=Feste, Bräuche, Jahreskreis', $lines);
        $this->assertNotContains('-sep', $lines);
    }

    /** [HAPPY] The config declares the marker the writer sets. */
    public function testTheConfigDeclaresTheMarker(): void
    {
        $config = file_get_contents(PHOTOINFO_PATH . 'exiftool/pwginfo.config');

        $this->assertGreaterThan(100, strlen($config), 'anti-vacuity: the config was not read');
        $this->assertSame('XMP-' . PHOTOINFO_XMP_PREFIX . ':TagsWritten', PHOTOINFO_TAGS_MARKER_TAG);
        $this->assertMatchesRegularExpression('/^\s*TagsWritten\s*=>/m', $config);
    }
}
