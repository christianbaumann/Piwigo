<?php
use PHPUnit\Framework\TestCase;

/**
 * Which URLs the read-only provenance page answers, out of the tokens core
 * split index.php's path into.
 *
 * Decision table not applicable beyond the cases below: the route is a
 * sequence of three independent checks, each covered by its own [NEG] case.
 */
final class PageRouteTest extends TestCase
{
    /** [HAPPY] An album and a photo URL resolve to their object and id. */
    public function testAlbumAndPhotoUrlsResolve(): void
    {
        $this->assertSame(array('object' => 'album', 'id' => 12), provenance_page_route(array('provenance', 'album', '12')));
        $this->assertSame(array('object' => 'photo', 'id' => 7), provenance_page_route(array('provenance', 'photo', '7')));
    }

    /** [ECP] Any other section is not this page's: core keeps the request. */
    public function testOtherSectionsAreLeftAlone(): void
    {
        $this->assertNull(provenance_page_route(array('category', '12')));
        $this->assertNull(provenance_page_route(array('')));
        $this->assertNull(provenance_page_route(array()));
    }

    /**
     * [NEG] This page's section with an unusable rest is claimed, but carries no
     * object, so the page answers not found rather than core's home page.
     */
    public function testMalformedRestIsClaimedWithoutAnObject(): void
    {
        $none = array('object' => null, 'id' => 0);
        $this->assertSame($none, provenance_page_route(array('provenance')));
        $this->assertSame($none, provenance_page_route(array('provenance', 'tag', '3')));
        $this->assertSame($none, provenance_page_route(array('provenance', 'album', 'abc')));
        $this->assertSame($none, provenance_page_route(array('provenance', 'album', '12', 'extra')));
    }

    /** [BVA] Ids start at 1; zero and a leading zero are refused. */
    public function testIdBoundaries(): void
    {
        $this->assertSame(1, provenance_page_route(array('provenance', 'album', '1'))['id']);
        $this->assertNull(provenance_page_route(array('provenance', 'album', '0'))['object']);
        $this->assertNull(provenance_page_route(array('provenance', 'album', '01'))['object']);
        $this->assertNull(provenance_page_route(array('provenance', 'album', '-1'))['object']);
    }

    /** [HAPPY] The link the plugin builds is a URL the route reads back. */
    public function testBuiltSectionRoundTrips(): void
    {
        $tokens = explode('/', provenance_page_section('photo', 42));
        $this->assertSame(array('object' => 'photo', 'id' => 42), provenance_page_route($tokens));
    }

    /** [HAPPY] Every history source has a label on the page, so none shows as a raw code. */
    public function testEverySourceHasALabel(): void
    {
        $sources = provenance_history_sources();
        $this->assertGreaterThan(0, count($sources));
        $this->assertSame(array(), array_diff($sources, array_keys(provenance_history_source_label_keys())));
    }
}
