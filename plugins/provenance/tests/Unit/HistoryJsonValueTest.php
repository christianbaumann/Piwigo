<?php
use PHPUnit\Framework\TestCase;

/**
 * The JSON values of tag and region history entries. Pure encode/decode; the
 * decoders return null for anything that is not exactly the documented shape,
 * because an undo must never act on a half-understood value.
 */
final class HistoryJsonValueTest extends TestCase
{
    /** [HAPPY] A tag survives a round trip, umlauts included and unescaped. */
    public function testTagRoundTrip(): void
    {
        $json = provenance_history_encode_tag(5, 'Kirche Müller');

        $this->assertSame('{"id":5,"name":"Kirche Müller"}', $json);
        $this->assertSame(array('id' => 5, 'name' => 'Kirche Müller'), provenance_history_decode_tag($json));
    }

    /** [HAPPY] A region survives a round trip with its box and drawer. */
    public function testRegionRoundTrip(): void
    {
        $json = provenance_history_encode_region('Anna Schmitz', array(0.25, 0.5, 0.1, 0.2), 12);

        $this->assertSame(
            array('name' => 'Anna Schmitz', 'box' => array(0.25, 0.5, 0.1, 0.2), 'drawn_by' => 12),
            provenance_history_decode_region($json)
        );
    }

    /** [ECP] A region nobody drew keeps drawn_by null, and a missing key reads as null. */
    public function testRegionWithoutDrawer(): void
    {
        $json = provenance_history_encode_region('Anna', array(0, 0, 1, 1), null);

        $this->assertNull(provenance_history_decode_region($json)['drawn_by']);
        $this->assertNull(provenance_history_decode_region('{"name":"A","box":[0,0,1,1]}')['drawn_by']);
    }

    /** [NEG] [ECP] NULL (an add's old value), the empty string and non-JSON decode to null. */
    public function testAbsentAndMalformedValuesDecodeToNull(): void
    {
        foreach (array(null, '', 'plain text', '[1,2]', '"x"', '{') as $value)
        {
            $this->assertNull(provenance_history_decode_tag($value), var_export($value, true));
            $this->assertNull(provenance_history_decode_region($value), var_export($value, true));
        }
    }

    /** [NEG] [BVA] A tag needs a positive integer id and a string name. */
    public function testTagShapeIsStrict(): void
    {
        foreach (array('{"id":0,"name":"a"}', '{"id":"5","name":"a"}', '{"id":5}', '{"id":5,"name":7}', '{"name":"a"}') as $value)
        {
            $this->assertNull(provenance_history_decode_tag($value), $value);
        }
    }

    /** [NEG] [BVA] A region needs a string name and exactly four numbers; drawn_by is an int or null. */
    public function testRegionShapeIsStrict(): void
    {
        $bad = array(
            '{"name":"a","box":[0,0,1]}',
            '{"name":"a","box":[0,0,1,1,1]}',
            '{"name":"a","box":[0,0,1,"1"]}',
            '{"name":"a","box":"0,0,1,1"}',
            '{"box":[0,0,1,1]}',
            '{"name":5,"box":[0,0,1,1]}',
            '{"name":"a","box":[0,0,1,1],"drawn_by":"12"}',
        );
        $this->assertGreaterThan(0, count($bad));
        foreach ($bad as $value)
        {
            $this->assertNull(provenance_history_decode_region($value), $value);
        }
    }
}
