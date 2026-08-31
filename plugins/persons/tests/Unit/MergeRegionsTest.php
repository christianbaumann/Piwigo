<?php
use PHPUnit\Framework\TestCase;

/**
 * The merge, which is the one place a bug loses somebody else's data.
 *
 * Regions live in the image file and nowhere else, so a write that rebuilds the
 * region list from this plugin's own idea of it deletes every region this plugin
 * does not understand. That is the review point that stalled Immich's write-back
 * PR and the reason the Piwigo Face Tag Editor rewrote its writer.
 *
 * The function is pure, so every case below is a value comparison rather than a
 * file on disk.
 */
final class MergeRegionsTest extends TestCase
{
    private const APPLIED_W = 4000;
    private const APPLIED_H = 3000;

    /** [HAPPY] The first region of an empty file becomes the whole list. */
    public function testAddingToAFileWithNoRegionsYieldsThatOneRegion(): void
    {
        $merged = persons_merge_regions(
            persons_parse_regioninfo(null),
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertCount(1, $this->regionListOf($merged));
        $entry = $this->regionListOf($merged)[0];
        $this->assertSame('Jane', $entry['Name']);
        $this->assertSame('Face', $entry['Type']);
        $this->assertSame('normalized', $entry['Area']['Unit']);
        $this->assertEqualsWithDelta(0.5, $entry['Area']['X'], 1e-9);
        $this->assertEqualsWithDelta(0.4, $entry['Area']['Y'], 1e-9);
        $this->assertEqualsWithDelta(0.1, $entry['Area']['W'], 1e-9);
        $this->assertEqualsWithDelta(0.2, $entry['Area']['H'], 1e-9);
        $this->assertSame(array('Jane'), $merged['names']);
    }

    /** [HAPPY] AppliedToDimensions is recorded against the caller's dimensions. */
    public function testTheWrittenAppliedDimensionsAreTheOnesPassedIn(): void
    {
        $merged = persons_merge_regions(
            persons_parse_regioninfo(null),
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(self::APPLIED_W, $merged['regioninfo']['AppliedToDimensions']['W']);
        $this->assertSame(self::APPLIED_H, $merged['regioninfo']['AppliedToDimensions']['H']);
        $this->assertSame('pixel', $merged['regioninfo']['AppliedToDimensions']['Unit']);
    }

    /**
     * [ERR] Unknown dimensions are omitted rather than written as zero.
     *
     * digiKam omits AppliedToDimensions (KDE bug 429219). Writing 0x0 back would
     * make every reader treat the regions as infinitely stale.
     */
    public function testUnknownAppliedDimensionsAreOmittedEntirely(): void
    {
        $merged = persons_merge_regions(
            persons_parse_regioninfo(null),
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array(),
            null,
            null
        );

        $this->assertArrayNotHasKey('AppliedToDimensions', $merged['regioninfo']);
    }

    /** [HAPPY] Adding B to a file that already holds A leaves both. */
    public function testAddingASecondPersonKeepsTheFirst(): void
    {
        $existing = $this->parsedFile(array(
            $this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2),
        ));

        $merged = persons_merge_regions(
            $existing,
            array($this->region('John', 0.2, 0.3, 0.1, 0.1)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('Jane', 'John'), $this->namesOf($merged));
        $this->assertSame(array('Jane', 'John'), $merged['names']);
    }

    /**
     * [ECP] A region this plugin never wrote survives a write.
     *
     * A Pet region is the cheapest example of the class: something another tool
     * put in the file, which this plugin indexes as foreign and must hand back
     * unchanged.
     */
    public function testAForeignRegionSurvivesAnAdd(): void
    {
        $existing = $this->parsedFile(array(
            $this->fileEntry('Rex', 0.7, 0.7, 0.2, 0.2, 'Pet'),
        ));

        $merged = persons_merge_regions(
            $existing,
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('Rex', 'Jane'), $this->namesOf($merged));
        $this->assertSame('Pet', $this->regionListOf($merged)[0]['Type']);
        $this->assertSame(array('Jane'), $merged['names'], 'a pet is not a person and never reaches PersonInImage');
    }

    /**
     * [ECP] A RegionList entry the parser could not index is written back
     * verbatim.
     *
     * This is the data-loss case with no other guard: a region in a unit this
     * plugin cannot convert, or of a type outside the MWG schema, is invisible
     * to every other function here. If the merge rebuilt the list from indexed
     * regions alone, the first tag anybody added would delete it.
     */
    public function testAnUnindexableEntryIsWrittenBackUnchanged(): void
    {
        $foreign = array(
            'Area' => array('X' => 120, 'Y' => 80, 'W' => 40, 'H' => 40, 'Unit' => 'pixel'),
            'Name' => 'Written by something else',
            'Type' => 'Face',
        );
        $existing = $this->parsedFile(array($foreign));

        $this->assertCount(1, $existing['unusable'], 'anti-vacuity: the fixture must be unindexable');
        $this->assertSame(array(), $existing['regions']);

        $merged = persons_merge_regions(
            $existing,
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertContains($foreign, $this->regionListOf($merged));
        $this->assertCount(2, $this->regionListOf($merged));
    }

    /** [HAPPY] A removal takes out the named region and leaves the others. */
    public function testRemovingOneRegionLeavesTheRest(): void
    {
        $existing = $this->parsedFile(array(
            $this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2),
            $this->fileEntry('John', 0.2, 0.3, 0.1, 0.1),
        ));

        $merged = persons_merge_regions(
            $existing,
            array(),
            array($this->region('John', 0.2, 0.3, 0.1, 0.1)),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('Jane'), $this->namesOf($merged));
        $this->assertSame(array('Jane'), $merged['names']);
    }

    /**
     * [ECP] Two regions of one person on one photo are told apart by their box,
     * not by the name.
     */
    public function testRemovingOneOfTwoBoxesForTheSamePersonKeepsTheOther(): void
    {
        $existing = $this->parsedFile(array(
            $this->fileEntry('Jane', 0.2, 0.4, 0.1, 0.2),
            $this->fileEntry('Jane', 0.8, 0.4, 0.1, 0.2),
        ));

        $merged = persons_merge_regions(
            $existing,
            array(),
            array($this->region('Jane', 0.2, 0.4, 0.1, 0.2)),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertCount(1, $this->regionListOf($merged));
        $this->assertEqualsWithDelta(0.8, $this->regionListOf($merged)[0]['Area']['X'], 1e-9);
        $this->assertSame(array('Jane'), $merged['names'], 'the person is still in the photo');
    }

    /**
     * [DT] The last uncovered cell of the merge decision table: a region that is
     * in the file, in $add and in $remove at once (E=1 A=1 R=1). The add wins.
     *
     * The plan predicted the opposite ("resolves to remove"). It is wrong, and
     * the rename path is why: persons_rename_person() removes every box of the
     * old name and re-adds the same boxes under the new one, in one call
     * (index.inc.php:712). A rename whose matcher also matches what is being
     * added - renaming to a name that differs only where the matcher does not
     * look - would delete the person's regions outright if remove won. Removals
     * are therefore applied to what the FILE held, and adds are applied after.
     */
    public function testARegionInBothAddAndRemoveIsKept(): void
    {
        $existing = $this->parsedFile(array(
            $this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2),
            $this->fileEntry('John', 0.2, 0.3, 0.1, 0.1),
        ));

        $merged = persons_merge_regions(
            $existing,
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('John', 'Jane'), $this->namesOf($merged),
            'the add is applied after the removal, so the region survives');
        $this->assertCount(2, $this->regionListOf($merged));
        $this->assertSame(array('Jane', 'John'), $merged['names']);
    }

    /** [ECP] A matcher with a name and no box removes every box of that person. */
    public function testAMatcherWithoutABoxRemovesEveryRegionOfThatPerson(): void
    {
        $existing = $this->parsedFile(array(
            $this->fileEntry('Jane', 0.2, 0.4, 0.1, 0.2),
            $this->fileEntry('Jane', 0.8, 0.4, 0.1, 0.2),
            $this->fileEntry('John', 0.5, 0.5, 0.1, 0.1),
        ));

        $merged = persons_merge_regions($existing, array(), array(array('name' => 'Jane')),
            self::APPLIED_W, self::APPLIED_H);

        $this->assertSame(array('John'), $this->namesOf($merged));
        $this->assertSame(array('John'), $merged['names']);
    }

    /** [NEG] Removing a region the file does not hold changes nothing. */
    public function testRemovingSomethingThatIsNotThereIsANoOp(): void
    {
        $existing = $this->parsedFile(array($this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2)));

        $merged = persons_merge_regions(
            $existing,
            array(),
            array($this->region('Nobody', 0.1, 0.1, 0.1, 0.1)),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('Jane'), $this->namesOf($merged));
    }

    /** [ECP] Adding the same box for the same person twice yields one region. */
    public function testAddingARegionThatIsAlreadyThereDoesNotDuplicateIt(): void
    {
        $existing = $this->parsedFile(array($this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2)));

        $merged = persons_merge_regions(
            $existing,
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertCount(1, $this->regionListOf($merged));
        $this->assertSame(array('Jane'), $merged['names']);
    }

    /**
     * [BVA] An add whose box overruns an edge is clipped to the frame rather
     * than written past it, per MWG.
     */
    public function testAnAddThatOverrunsAnEdgeIsClipped(): void
    {
        $merged = persons_merge_regions(
            persons_parse_regioninfo(null),
            array($this->region('Jane', 0.05, 0.5, 0.2, 0.2)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $area = $this->regionListOf($merged)[0]['Area'];
        $this->assertEqualsWithDelta(0.075, $area['X'], 1e-9);
        $this->assertEqualsWithDelta(0.15, $area['W'], 1e-9);
    }

    /** [NEG] An add whose centre is outside the frame is refused, not written. */
    public function testAnAddWhoseCentreIsOutsideTheFrameIsDropped(): void
    {
        $existing = $this->parsedFile(array($this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2)));

        $merged = persons_merge_regions(
            $existing,
            array($this->region('Nowhere', 1.5, 0.5, 0.1, 0.1)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('Jane'), $this->namesOf($merged));
    }

    /** [NEG] An add smaller than the minimum box is refused. */
    public function testAnAddBelowTheMinimumBoxIsDropped(): void
    {
        $tiny = PERSONS_MIN_BOX_FRACTION / 2;

        $merged = persons_merge_regions(
            persons_parse_regioninfo(null),
            array($this->region('Speck', 0.5, 0.5, $tiny, $tiny)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array(), $merged['regioninfo'], 'nothing was written, so RegionInfo is deleted');
    }

    /**
     * [ST] Removing the last region asks exiftool to delete the tags rather
     * than to write an empty structure, which would leave the file claiming it
     * has a region list of nothing.
     *
     * The empty array is the ask: measured 2026-08-30 against exiftool 13.25,
     * a JSON [] deletes the tag, "" writes an empty structure and null writes a
     * literal null into the name list.
     */
    public function testRemovingTheLastRegionDeletesBothTags(): void
    {
        $existing = $this->parsedFile(array($this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2)));

        $merged = persons_merge_regions(
            $existing,
            array(),
            array($this->region('Jane', 0.5, 0.4, 0.1, 0.2)),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array(), $merged['regioninfo']);
        $this->assertSame(array(), $merged['names']);
    }

    /**
     * [ECP] A PersonInImage name that no region backs is left alone.
     *
     * Some tools write the name list without regions at all. It is not this
     * plugin's to delete, and a merge that rebuilt the list from regions alone
     * would silently drop it.
     */
    public function testANameWithNoRegionBehindItSurvives(): void
    {
        $existing = persons_parse_regioninfo(array(array(
            'RegionInfo' => array('RegionList' => array($this->fileEntry('Jane', 0.5, 0.4, 0.1, 0.2))),
            'PersonInImage' => array('Jane', 'Somebody Off Camera'),
        )));

        $merged = persons_merge_regions(
            $existing,
            array($this->region('John', 0.2, 0.3, 0.1, 0.1)),
            array(),
            self::APPLIED_W,
            self::APPLIED_H
        );

        $this->assertSame(array('Jane', 'Somebody Off Camera', 'John'), $merged['names']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** A region in this plugin's own shape, as a caller passes it in. */
    private function region(string $name, float $x, float $y, float $w, float $h, string $type = 'Face'): array
    {
        return array('name' => $name, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'type' => $type);
    }

    /** A RegionList entry in exiftool's shape, as it comes out of a file. */
    private function fileEntry(string $name, float $x, float $y, float $w, float $h, string $type = 'Face'): array
    {
        return array(
            'Area' => array('X' => $x, 'Y' => $y, 'W' => $w, 'H' => $h, 'Unit' => 'normalized'),
            'Name' => $name,
            'Type' => $type,
        );
    }

    /** The parse result for a file holding these RegionList entries. */
    private function parsedFile(array $entries): array
    {
        $names = array();
        foreach ($entries as $entry)
        {
            if (($entry['Type'] ?? 'Face') === 'Face' && ($entry['Area']['Unit'] ?? 'normalized') === 'normalized')
            {
                $names[$entry['Name']] = true;
            }
        }

        return persons_parse_regioninfo(array(array(
            'RegionInfo' => array(
                'AppliedToDimensions' => array('W' => self::APPLIED_W, 'H' => self::APPLIED_H, 'Unit' => 'pixel'),
                'RegionList' => $entries,
            ),
            'PersonInImage' => array_keys($names),
        )));
    }

    /**
     * The merged RegionList, guarded.
     *
     * Anti-vacuity (`.claude/rules/test-design.md`): a merge that lost every
     * region returns the "delete the tag" shape, where RegionList is absent.
     * Counting that directly makes the test die of a TypeError instead of
     * reporting the behaviour it names - which is what the 2026-08-31 merge
     * mutant exposed.
     */
    private function regionListOf(array $merged): array
    {
        $this->assertIsArray($merged['regioninfo'], 'the merge returned no RegionInfo at all');
        $this->assertArrayHasKey('RegionList', $merged['regioninfo'],
            'the merge returned the "delete the tag" shape, not a region list');
        $this->assertIsArray($merged['regioninfo']['RegionList']);

        return $merged['regioninfo']['RegionList'];
    }

    /** The names in the merged RegionList, in list order. */
    private function namesOf(array $merged): array
    {
        $names = array();
        foreach ($this->regionListOf($merged) as $entry)
        {
            $names[] = $entry['Name'];
        }

        return $names;
    }
}
