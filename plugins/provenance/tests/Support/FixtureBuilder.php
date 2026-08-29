<?php
/**
 * Forces a known database state and asserts it took effect, so a test never
 * runs over a state it merely hoped for.
 *
 * Cleanup restores what was recorded, but no assertion depends on cleanup
 * having run: a suite that needed it to pass would destroy its own failure
 * evidence.
 */
class FixtureBuilder
{
    /**
     * The schema, read from the production definition rather than typed again:
     * a second copy here would rot the day a column is added on one side only.
     */
    public static function albumColumns(): array
    {
        return array_keys(provenance_album_columns());
    }

    public static function imageColumns(): array
    {
        return array_keys(provenance_image_columns());
    }

    private Db $db;

    /** table => (row id => (column => value)) recorded before this fixture wrote */
    private array $original = array();

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function columnExists(string $table, string $column): bool
    {
        $result = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '" . $this->db->escape($column) . "'");
        return $result->num_rows > 0;
    }

    public function tableExists(string $table): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");
        return $result->num_rows > 0;
    }

    /**
     * Snapshots every provenance value in the install.
     *
     * Uninstalling the plugin drops the columns, taking the data with them, so a
     * test that exercises the lifecycle has to be able to put the values back.
     */
    public function recordAllProvenance(): void
    {
        $this->original = array(
            'piwigo_categories' => $this->readAll('piwigo_categories', self::albumColumns()),
            'piwigo_images'     => $this->readAll('piwigo_images', self::imageColumns()),
            );
    }

    public function restore(): void
    {
        foreach ($this->original as $table => $rows)
        {
            foreach ($rows as $id => $values)
            {
                $assignments = array();
                foreach ($values as $column => $value)
                {
                    if (!$this->columnExists($table, $column))
                    {
                        continue;
                    }
                    $assignments[] = "`$column` = " .
                        ($value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
                }
                if (count($assignments) > 0)
                {
                    $this->db->query("UPDATE `$table` SET " . implode(', ', $assignments) . ' WHERE id = ' . (int)$id);
                }
            }
        }
        $this->original = array();
    }

    /** Only rows that carry at least one non-NULL provenance value are worth restoring. */
    private function readAll(string $table, array $columns): array
    {
        $present = array();
        foreach ($columns as $column)
        {
            if ($this->columnExists($table, $column))
            {
                $present[] = $column;
            }
        }
        if (count($present) === 0)
        {
            return array();
        }

        $notNull = implode(' OR ', array_map(fn($c) => "`$c` IS NOT NULL", $present));
        $result = $this->db->query(
            'SELECT id, `' . implode('`, `', $present) . "` FROM `$table` WHERE $notNull"
        );

        $rows = array();
        while ($row = $result->fetch_assoc())
        {
            $id = (int)$row['id'];
            unset($row['id']);
            $rows[$id] = $row;
        }
        return $rows;
    }
}
