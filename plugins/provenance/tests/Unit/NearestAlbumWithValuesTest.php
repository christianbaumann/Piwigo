<?php
use PHPUnit\Framework\TestCase;

/**
 * Which album's Herkunft a photo joining an album inherits: the album's own,
 * else the nearest ancestor's that has any (design: "Herkunft: first album
 * wins, sub-albums inherit").
 *
 * The chain is core's uppercats: ids from the top album down to the album
 * itself, comma-separated.
 *
 * Decision table not applicable beyond the cases below: one condition per
 * album in the chain (has values or not), resolved nearest first.
 */
final class NearestAlbumWithValuesTest extends TestCase
{
    private const EMPTY_ALBUM = array(
        'provenance_physical_album' => null,
        'provenance_owner' => null,
        'provenance_scanned_on' => null,
        'provenance_note' => null,
        );

    private static function album(string $owner): array
    {
        return array_merge(self::EMPTY_ALBUM, array('provenance_owner' => $owner));
    }

    /** [HAPPY] An album with values of its own is its own source. */
    public function testAnAlbumWithValuesIsItsOwnSource(): void
    {
        $albums = array(1 => self::album('top'), 2 => self::album('self'));

        $this->assertSame(self::album('self'), provenance_nearest_album_with_values('1,2', $albums));
    }

    /** [HAPPY] An empty album takes its parent's values. */
    public function testAnEmptyAlbumTakesItsParentsValues(): void
    {
        $albums = array(1 => self::album('parent'), 2 => self::EMPTY_ALBUM);

        $this->assertSame(self::album('parent'), provenance_nearest_album_with_values('1,2', $albums));
    }

    /** [ECP] The nearest ancestor with values wins over one further up. */
    public function testTheNearestAncestorWins(): void
    {
        $albums = array(1 => self::album('top'), 2 => self::album('middle'), 3 => self::EMPTY_ALBUM);

        $this->assertSame(self::album('middle'), provenance_nearest_album_with_values('1,2,3', $albums));
    }

    /** [ECP] An empty album in between is skipped. */
    public function testAnEmptyAncestorInBetweenIsSkipped(): void
    {
        $albums = array(1 => self::album('top'), 2 => self::EMPTY_ALBUM, 3 => self::EMPTY_ALBUM);

        $this->assertSame(self::album('top'), provenance_nearest_album_with_values('1,2,3', $albums));
    }

    /** [NEG] No album in the chain has values: nothing to inherit. */
    public function testAChainWithoutValuesGivesNull(): void
    {
        $albums = array(1 => self::EMPTY_ALBUM, 2 => self::EMPTY_ALBUM);

        $this->assertNull(provenance_nearest_album_with_values('1,2', $albums));
    }

    /** [BVA] Whitespace alone is no value; a top-level album is a chain of one. */
    public function testWhitespaceIsNoValue(): void
    {
        $albums = array(7 => array_merge(self::EMPTY_ALBUM, array('provenance_note' => "  \n")));

        $this->assertNull(provenance_nearest_album_with_values('7', $albums));
    }

    /** [NEG] An id of the chain with no row is skipped, not an error. */
    public function testAnIdWithoutARowIsSkipped(): void
    {
        $albums = array(1 => self::album('top'));

        $this->assertSame(self::album('top'), provenance_nearest_album_with_values('1,2', $albums));
    }
}
