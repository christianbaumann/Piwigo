<?php
use PHPUnit\Framework\TestCase;

/**
 * History entries as text, against provenance's own German strings. The
 * oracle for the wording is the task's examples; the value shapes are the ones
 * tasks 01 to 05 store.
 */
final class HistoryTextTest extends TestCase
{
    private const NOW = '2026-10-21 15:00:00';
    private const MIN_LANG_KEYS = 40;

    private static $t;

    public static function setUpBeforeClass(): void
    {
        $lang = array();
        include PROVENANCE_PATH . 'language/de_DE/plugin.lang.php';
        if (count($lang) < self::MIN_LANG_KEYS)
        {
            throw new RuntimeException('anti-vacuity: the German language file was not read');
        }
        self::$t = function ($key) use ($lang) { return isset($lang[$key]) ? $lang[$key] : $key; };
    }

    private static function text(string $field, ?string $old, ?string $new): string
    {
        return provenance_history_entry_text(array('field' => $field, 'old_value' => $old, 'new_value' => $new), self::$t);
    }

    private static function tag(string $name): string
    {
        return provenance_history_encode_tag(5, $name);
    }

    private static function region(string $name): string
    {
        return provenance_history_encode_region($name, array(0.1, 0.1, 0.2, 0.2), null);
    }

    /** [HAPPY] [ECP] A tag added, a tag removed. */
    public function testTagEntries(): void
    {
        $this->assertSame('„Kirche“ hinzugefügt', self::text('tag', null, self::tag('Kirche')));
        $this->assertSame('„Kirche“ entfernt', self::text('tag', self::tag('Kirche'), null));
    }

    /** [HAPPY] [ECP] A region added, deleted, renamed. */
    public function testRegionEntries(): void
    {
        $this->assertSame('„Anna Schmitz“ markiert', self::text('region', null, self::region('Anna Schmitz')));
        $this->assertSame('Markierung „Anna“ entfernt', self::text('region', self::region('Anna'), null));
        $this->assertSame('Markierung geändert: „Anna“ → „Anne“', self::text('region', self::region('Anna'), self::region('Anne')));
    }

    /** [HAPPY] [ECP] A date set, changed, removed; the EDTF is not shown. */
    public function testDateEntries(): void
    {
        $this->assertSame('Datum geändert: ca. 1955 → vor 1960', self::text('date', 'ca. 1955 (1955~)', 'vor 1960 (../1960)'));
        $this->assertSame('Datum gesetzt: März 1965', self::text('date', null, 'März 1965 (1965-03)'));
        $this->assertSame('Datum entfernt: 1965', self::text('date', '1965 (1965)', null));
    }

    /** [ECP] The display is the text before the last " (": a display holding one stays whole. */
    public function testDateDisplayCutsAtTheLastParenthesis(): void
    {
        $this->assertSame('Taufe (ca.) 1950', provenance_history_date_display('Taufe (ca.) 1950 (1950~)'));
        $this->assertSame('1950', provenance_history_date_display('1950'));
        $this->assertNull(provenance_history_date_display(null));
    }

    /** [ECP] Info: the text itself is not repeated. */
    public function testInfoEntries(): void
    {
        $this->assertSame('Info hinzugefügt', self::text('info', null, 'Taufe'));
        $this->assertSame('Info geändert', self::text('info', 'Taufe', 'Taufe im Garten'));
        $this->assertSame('Info entfernt', self::text('info', 'Taufe', null));
    }

    /** [DT] turns x crop: only turns, only crop, both. */
    public function testPhotoEntries(): void
    {
        $this->assertSame('Foto gedreht', self::text('photo', null, '{"turns":1,"crop":null}'));
        $this->assertSame('Foto zugeschnitten', self::text('photo', null, '{"turns":0,"crop":{"w":300,"h":200}}'));
        $this->assertSame('Foto gedreht und zugeschnitten', self::text('photo', null, '{"turns":3,"crop":{"w":300,"h":200}}'));
    }

    /** [NEG] A value of the wrong shape falls back to the field's name, never to JSON. */
    public function testMalformedValuesFallBack(): void
    {
        $this->assertSame('Schlagwort geändert', self::text('tag', null, '{'));
        $this->assertSame('Person geändert', self::text('region', null, '[]'));
        $this->assertSame('Bildbearbeitung geändert', self::text('photo', null, '{"turns":0,"crop":null}'));
    }

    /** [ECP] The read-only page's cells: names and words, other fields as stored. */
    public function testValueTextForTheTable(): void
    {
        $this->assertSame('Kirche', provenance_history_value_text('tag', self::tag('Kirche'), self::$t));
        $this->assertSame('Anna', provenance_history_value_text('region', self::region('Anna'), self::$t));
        $this->assertSame('Foto gedreht', provenance_history_value_text('photo', '{"turns":2,"crop":null}', self::$t));
        $this->assertSame('', provenance_history_value_text('tag', null, self::$t));
        $this->assertSame('ca. 1955 (1955~)', provenance_history_value_text('date', 'ca. 1955 (1955~)', self::$t));
    }

    public static function whenCases(): array
    {
        return array(
            '[BVA] today, last second' => array('2026-10-21 23:59:59', '2026-10-21 15:00:00', 'heute, 23:59'),
            '[BVA] today, first second' => array('2026-10-21 00:00:00', '2026-10-21 15:00:00', 'heute, 00:00'),
            '[BVA] yesterday, last second' => array('2026-10-20 23:59:59', '2026-10-21 15:00:00', 'gestern, 23:59'),
            '[BVA] two days ago is a date' => array('2026-10-19 23:59:59', '2026-10-21 15:00:00', 'DATE 2026-10-19 23:59:59'),
            '[BVA] yesterday across a month end' => array('2026-09-30 18:42:00', '2026-10-01 08:00:00', 'gestern, 18:42'),
            '[ECP] a year ago' => array('2025-10-21 14:05:00', '2026-10-21 15:00:00', 'DATE 2025-10-21 14:05:00'),
            );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('whenCases')]
    public function testRelativeTime(string $occured, string $now, string $expected): void
    {
        $older = function ($occured_on) { return 'DATE '.$occured_on; };

        $this->assertSame($expected, provenance_history_when($occured, $now, self::$t, $older));
    }

    /** [DT] viewer's own, someone else's, an account that is gone, nobody. */
    public function testUserText(): void
    {
        $names = array(7 => 'maria.t', 9 => 'hans');

        $this->assertSame('maria.t (Sie)', provenance_history_user_text(7, $names, 7, self::$t));
        $this->assertSame('hans', provenance_history_user_text(9, $names, 7, self::$t));
        $this->assertSame('gelöschter Benutzer', provenance_history_user_text(12, $names, 7, self::$t));
        $this->assertSame('', provenance_history_user_text(null, $names, 7, self::$t));
        $this->assertSame('hans', provenance_history_user_text(9, $names, null, self::$t));
    }

    /** [ERR] Photo edits with nothing in them decode to null (a no-op entry is never stored). */
    public function testPhotoDecodeBounds(): void
    {
        $this->assertNull(provenance_history_decode_photo('{"turns":4,"crop":null}'));
        $this->assertNull(provenance_history_decode_photo('{"turns":-1,"crop":null}'));
        $this->assertNull(provenance_history_decode_photo(null));
        $this->assertSame(array('turned' => true, 'cropped' => false), provenance_history_decode_photo('{"turns":3,"crop":null}'));
    }
}
