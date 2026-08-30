<?php
use PHPUnit\Framework\TestCase;

/**
 * The schema definition every other layer reads: maintain.class.php builds its
 * CREATE TABLE from it, the indexer writes against it, and the tests read it
 * rather than carrying a second copy that rots.
 *
 * The CREATE TABLE statements are checked here as text rather than against a
 * live database on purpose - this is the unit layer, and what would break is a
 * column declared in one place and not the other, which is a text fact.
 */
final class SchemaDefinitionTest extends TestCase
{
    /** Shorter than this and maintain.class.php was not really read. */
    private const MIN_MAINTAIN_BYTES = 2000;

    private function maintainSource(): string
    {
        $path = PERSONS_PATH . 'maintain.class.php';
        $this->assertFileExists($path);

        $source = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_MAINTAIN_BYTES,
            strlen($source),
            'anti-vacuity: too little was read for the assertions below to mean anything'
        );

        return $source;
    }

    /** [HAPPY] The person index carries exactly the columns the plugin reads. */
    public function testPersonColumns(): void
    {
        $this->assertSame(
            array('id', 'name', 'url_name', 'tag_id', 'lastmodified'),
            array_keys(persons_person_columns())
        );
    }

    /** [HAPPY] And the region index carries the coordinate contract in full. */
    public function testRegionColumns(): void
    {
        $this->assertSame(
            array(
                'id', 'image_id', 'person_id',
                'area_x', 'area_y', 'area_w', 'area_h',
                'applied_w', 'applied_h', 'rotation_at_write',
                'region_type', 'source',
            ),
            array_keys(persons_region_columns())
        );
    }

    /**
     * [HAPPY] persons.id is wider than a smallint.
     *
     * This is the whole reason persons are not simply piwigo_tags rows: that
     * table's id is a smallint, and its 65,535 ceiling is the constraint the
     * separate table exists to escape. A narrowing edit here would silently put
     * the ceiling back.
     */
    public function testPersonIdIsWiderThanTheTagsTableCeiling(): void
    {
        $this->assertStringContainsString('mediumint', persons_person_columns()['id']);
    }

    /**
     * [HAPPY] The region type column accepts every MWG type the parser keeps.
     *
     * A file may already hold a Pet or BarCode region. MySQL stores a value
     * outside an ENUM as '', so a column narrower than the parser does not fail
     * loudly - it writes rows that claim nothing.
     */
    public function testTheRegionTypeColumnAcceptsEveryTypeTheParserKeeps(): void
    {
        $definition = persons_region_columns()['region_type'];

        $this->assertGreaterThan(0, count(persons_region_types()), 'anti-vacuity: an empty type list');
        foreach (persons_region_types() as $type)
        {
            $this->assertStringContainsString("'" . $type . "'", $definition);
        }
    }

    /** [HAPPY] The same, for who wrote a region. */
    public function testTheSourceColumnAcceptsEveryDeclaredSource(): void
    {
        $definition = persons_region_columns()['source'];

        $this->assertGreaterThan(0, count(persons_region_sources()), 'anti-vacuity: an empty source list');
        foreach (persons_region_sources() as $source)
        {
            $this->assertStringContainsString("'" . $source . "'", $definition);
        }
    }

    /**
     * [HAPPY] The lifecycle builds its tables from the shared definition rather
     * than from a transcribed copy - the single-source rule, asserted.
     */
    public function testMaintainBuildsItsTablesFromTheSharedDefinition(): void
    {
        $source = $this->maintainSource();

        $this->assertStringContainsString('persons_person_columns()', $source);
        $this->assertStringContainsString('persons_region_columns()', $source);
    }

    /**
     * [NEG] No column name is typed into the CREATE TABLE by hand. One that is
     * would be created but never read, or read but never created.
     */
    public function testNoColumnIsTranscribedIntoTheLifecycle(): void
    {
        $source = $this->maintainSource();

        $columns = array_merge(
            array_keys(persons_person_columns()),
            array_keys(persons_region_columns())
        );
        $this->assertGreaterThan(0, count($columns), 'anti-vacuity: no columns to check');

        // The primary and secondary keys name three of them; nothing else may.
        $declaredInKeys = array('id', 'name', 'image_id', 'person_id');

        foreach ($columns as $column)
        {
            if (in_array($column, $declaredInKeys, true))
            {
                continue;
            }
            $this->assertStringNotContainsString(
                '`' . $column . '`',
                $source,
                "column $column is typed into maintain.class.php as well as declared in functions.inc.php"
            );
        }
    }

    /** [HAPPY] Both tables are created only if absent, so install() re-enters. */
    public function testBothTablesAreCreatedIdempotently(): void
    {
        $this->assertSame(2, substr_count($this->maintainSource(), 'CREATE TABLE IF NOT EXISTS `'));
    }

    /** [ST] And both are dropped again, so an uninstall leaves nothing behind. */
    public function testBothTablesAreDroppedOnUninstall(): void
    {
        $this->assertSame(2, substr_count($this->maintainSource(), 'DROP TABLE IF EXISTS'));
    }
}
