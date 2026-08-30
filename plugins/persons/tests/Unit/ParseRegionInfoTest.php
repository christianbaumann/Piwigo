<?php
use PHPUnit\Framework\TestCase;

/**
 * The walk from exiftool's decoded JSON into the plugin's region shape.
 *
 * Pure, so every case here is a fixture rather than a file: what exiftool
 * actually emits was captured from a real round-trip through
 * `exiftool -json -struct -XMP-mwg-rs:RegionInfo` on 2026-08-30, and the
 * defensive cases come from the shapes other writers are documented to produce.
 *
 * The parser reports what the file says. It does not decide what is worth
 * keeping: a region it cannot index (no name, coordinates in a unit it does not
 * understand) is handed back verbatim under 'unusable' so a later merge writes
 * it out again untouched. Dropping it here would delete somebody else's data on
 * the first write this plugin makes to the file.
 */
final class ParseRegionInfoTest extends TestCase
{
    private const EPSILON = 1e-9;

    /** The shape exiftool emits, as one decoded top-level array. */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'anti-vacuity: the fixture JSON itself does not decode');
        return $decoded;
    }

    /** [HAPPY] One named face, with its area and its applied dimensions. */
    public function testASingleRegionIsParsed(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "SourceFile": "/tmp/a.png",
  "RegionInfo": {
    "AppliedToDimensions": {"H": 3000, "Unit": "pixel", "W": 4000},
    "RegionList": [{
      "Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4},
      "Name": "Jane Doe",
      "Type": "Face"
    }]
  },
  "PersonInImage": ["Jane Doe"]
}]'));

        $this->assertCount(1, $parsed['regions']);
        $region = $parsed['regions'][0];
        $this->assertSame('Jane Doe', $region['name']);
        $this->assertSame('Face', $region['type']);
        $this->assertSame('piwigo', $region['source']);
        $this->assertEqualsWithDelta(0.5, $region['x'], self::EPSILON);
        $this->assertEqualsWithDelta(0.4, $region['y'], self::EPSILON);
        $this->assertEqualsWithDelta(0.1, $region['w'], self::EPSILON);
        $this->assertEqualsWithDelta(0.2, $region['h'], self::EPSILON);
        $this->assertSame(4000, $parsed['applied']['w']);
        $this->assertSame(3000, $parsed['applied']['h']);
        $this->assertSame(array('Jane Doe'), $parsed['names']);
    }

    /** [HAPPY] Two regions keep the order the file lists them in. */
    public function testTwoRegionsAreParsedInOrder(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "AppliedToDimensions": {"H": 3000, "Unit": "pixel", "W": 4000},
    "RegionList": [
      {"Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4}, "Name": "Jane Doe", "Type": "Face"},
      {"Area": {"H": 0.1, "Unit": "normalized", "W": 0.1, "X": 0.2, "Y": 0.3}, "Name": "John Smith", "Type": "Face"}
    ]
  },
  "PersonInImage": ["Jane Doe", "John Smith"]
}]'));

        $this->assertCount(2, $parsed['regions']);
        $this->assertSame('Jane Doe', $parsed['regions'][0]['name']);
        $this->assertSame('John Smith', $parsed['regions'][1]['name']);
    }

    /**
     * [ERR] A writer that emits one region as a bare object rather than a
     * one-element list. Recorded behaviour, not a requirement: no spec permits
     * it, and exiftool 13.25 does not do it - other readers handle it, so this
     * one does too.
     */
    public function testARegionListArrivingAsAnObjectIsTreatedAsOneRegion(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "AppliedToDimensions": {"H": 3000, "W": 4000},
    "RegionList": {
      "Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4},
      "Name": "Jane Doe",
      "Type": "Face"
    }
  }
}]'));

        $this->assertCount(1, $parsed['regions']);
        $this->assertSame('Jane Doe', $parsed['regions'][0]['name']);
    }

    /**
     * [ERR] Coordinates arriving as JSON strings. This is the bug Immich fixed
     * in PR #29333; a parser that compares them as strings puts the box at 0.
     */
    public function testNumericFieldsArrivingAsStringsAreParsed(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "AppliedToDimensions": {"H": "3000", "W": "4000"},
    "RegionList": [{
      "Area": {"H": "0.2", "Unit": "normalized", "W": "0.1", "X": "0.5", "Y": "0.4"},
      "Name": "Jane Doe",
      "Type": "Face"
    }]
  }
}]'));

        $this->assertCount(1, $parsed['regions']);
        $this->assertEqualsWithDelta(0.5, $parsed['regions'][0]['x'], self::EPSILON);
        $this->assertSame(4000, $parsed['applied']['w']);
        $this->assertSame(3000, $parsed['applied']['h']);
    }

    /** [BVA] Full double precision survives; a float cast to a string would not. */
    public function testHighPrecisionCoordinatesDoNotLosePosition(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [{
      "Area": {"H": 0.123456789012, "Unit": "normalized", "W": 0.987654321098,
               "X": 0.333333333333, "Y": 0.666666666667},
      "Name": "Jane Doe", "Type": "Face"
    }]
  }
}]'));

        $this->assertCount(1, $parsed['regions']);
        $this->assertEqualsWithDelta(0.333333333333, $parsed['regions'][0]['x'], 1e-12);
        $this->assertEqualsWithDelta(0.666666666667, $parsed['regions'][0]['y'], 1e-12);
    }

    /**
     * [NEG] digiKam omits AppliedToDimensions entirely (KDE bug 429219).
     * Unknown must stay unknown: a zero would make every such region look
     * infinitely stale, and a guess would make a crop invisible.
     */
    public function testAMissingAppliedToDimensionsYieldsUnknownNotZero(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [{
      "Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4},
      "Name": "Jane Doe", "Type": "Face"
    }]
  }
}]'));

        $this->assertNull($parsed['applied']['w']);
        $this->assertNull($parsed['applied']['h']);
        $this->assertCount(1, $parsed['regions'], 'the region itself is still usable');
    }

    /**
     * [NEG] An unnamed region is a detected-but-unconfirmed face. This plugin
     * has no unconfirmed state, so it cannot index one - but it is somebody
     * else's data and comes back under 'unusable' to be written out again.
     */
    public function testARegionWithNoNameIsSkipped(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [
      {"Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4}, "Type": "Face"},
      {"Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.1, "Y": 0.1}, "Name": "Jane Doe", "Type": "Face"}
    ]
  }
}]'));

        $this->assertCount(1, $parsed['regions']);
        $this->assertSame('Jane Doe', $parsed['regions'][0]['name']);
        $this->assertCount(1, $parsed['unusable'], 'the unnamed region must survive for the merge');
    }

    /** [NEG] Same for coordinates in a unit this plugin cannot interpret. */
    public function testARegionWithANonNormalizedUnitIsSkipped(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [{
      "Area": {"H": 400, "Unit": "pixel", "W": 300, "X": 1200, "Y": 900},
      "Name": "Jane Doe", "Type": "Face"
    }]
  }
}]'));

        $this->assertCount(0, $parsed['regions']);
        $this->assertCount(1, $parsed['unusable']);
    }

    /** [BVA] A RegionInfo whose list is empty yields nothing, not a warning. */
    public function testAnEmptyRegionListYieldsNoRegions(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{"RegionInfo": {"AppliedToDimensions": {"H": 3000, "W": 4000}, "RegionList": []}}]'));

        $this->assertSame(array(), $parsed['regions']);
        $this->assertSame(array(), $parsed['unusable']);
        $this->assertSame(4000, $parsed['applied']['w']);
    }

    /** [NEG] json_decode() failed: null in, empty result out, no notice raised. */
    public function testMalformedJsonYieldsNoRegionsAndNoWarning(): void
    {
        $parsed = persons_parse_regioninfo(json_decode('{not json', true));

        $this->assertSame(array(), $parsed['regions']);
        $this->assertSame(array(), $parsed['names']);
        $this->assertNull($parsed['applied']['w']);
    }

    /**
     * [ECP] A Pet, Focus or BarCode region is parsed and marked foreign. It is
     * not this plugin's to rewrite, and a merge that dropped it would delete
     * data the file's other reader put there.
     */
    public function testANonFaceRegionTypeIsKeptAsForeign(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [
      {"Area": {"H": 0.1, "Unit": "normalized", "W": 0.1, "X": 0.2, "Y": 0.3}, "Name": "Rex", "Type": "Pet"},
      {"Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4}, "Name": "Jane Doe", "Type": "Face"}
    ]
  }
}]'));

        $this->assertCount(2, $parsed['regions']);
        $this->assertSame('Pet', $parsed['regions'][0]['type']);
        $this->assertSame('foreign', $parsed['regions'][0]['source']);
        $this->assertSame('piwigo', $parsed['regions'][1]['source']);
    }

    /** [ERR] A name outside ASCII arrives intact - exiftool emits UTF-8 JSON. */
    public function testAUnicodeNameSurvivesParsing(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [{
      "Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4},
      "Name": "Sölvi Ærø", "Type": "Face"
    }]
  },
  "PersonInImage": "Sölvi Ærø"
}]'));

        $this->assertSame('Sölvi Ærø', $parsed['regions'][0]['name']);
        $this->assertSame(array('Sölvi Ærø'), $parsed['names'],
            'a single PersonInImage value arrives as a bare string, not a list');
    }

    /**
     * [ECP] A Type the MWG schema does not define is still somebody's data.
     * It cannot be indexed - the region_type column is an ENUM of the four MWG
     * types - so it comes back unusable rather than being coerced into 'Face'.
     */
    public function testARegionWithATypeOutsideTheSchemaIsUnusable(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [{
      "Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4},
      "Name": "Jane Doe", "Type": "Hologram"
    }]
  }
}]'));

        $this->assertCount(0, $parsed['regions']);
        $this->assertCount(1, $parsed['unusable']);
    }

    /** [ECP] MWG makes Type optional and Face the default. */
    public function testARegionWithNoTypeIsTreatedAsAFace(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('
[{
  "RegionInfo": {
    "RegionList": [{
      "Area": {"H": 0.2, "Unit": "normalized", "W": 0.1, "X": 0.5, "Y": 0.4},
      "Name": "Jane Doe"
    }]
  }
}]'));

        $this->assertCount(1, $parsed['regions']);
        $this->assertSame('Face', $parsed['regions'][0]['type']);
    }

    /**
     * [BVA] AppliedToDimensions present but carrying a zero, and [NEG] present
     * but carrying something that is not a number.
     *
     * Both must read as unknown rather than as 0. A 0 is not merely wrong, it
     * is worse than absent: persons_region_is_stale() divides by it, and a
     * region stored with applied_w = 0 would render dashed and dimmed forever.
     *
     * Written after a mutant that turned the unknown case into 0 survived the
     * integration suite, 2026-08-30 - nothing reached this branch.
     */
    public function testAnAppliedDimensionThatIsZeroOrNotANumberIsUnknown(): void
    {
        $cases = array(
            '{"H": 0, "W": 4000}',
            '{"H": 3000, "W": 0}',
            '{"H": "", "W": ""}',
            '{"H": "tall", "W": "wide"}',
        );
        $this->assertGreaterThan(0, count($cases), 'anti-vacuity: an empty table would pass trivially');

        foreach ($cases as $applied)
        {
            $parsed = persons_parse_regioninfo($this->decoded('
[{"RegionInfo": {"AppliedToDimensions": ' . $applied . ', "RegionList": []}}]'));

            $this->assertNull($parsed['applied']['w'], "W stayed set for $applied");
            $this->assertNull($parsed['applied']['h'], "H stayed set for $applied");
        }
    }

    /** [NEG] A file with no RegionInfo at all: the common case, and not an error. */
    public function testAFileWithNoRegionInfoYieldsNothing(): void
    {
        $parsed = persons_parse_regioninfo($this->decoded('[{"SourceFile": "/tmp/c.png"}]'));

        $this->assertSame(array(), $parsed['regions']);
        $this->assertSame(array(), $parsed['names']);
        $this->assertNull($parsed['applied']['h']);
    }
}
