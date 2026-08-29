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

    /**
     * The recorded original state, for a process that will not live long enough
     * to restore it itself.
     *
     * The E2E suite seeds from a short-lived CLI process and restores from
     * another one, so what recordAllProvenance() remembered has to survive as
     * data on disk between the two.
     */
    public function exportState(): array
    {
        return $this->original;
    }

    public function importState(array $state): void
    {
        $this->original = $state;
    }

    /** An album that exists, so a fixture refers to something real. */
    public function anyAlbumId(): int
    {
        $id = $this->db->scalar('SELECT MIN(id) FROM piwigo_categories');
        if ($id === null)
        {
            throw new RuntimeException('this install has no album to attach provenance to');
        }
        return (int)$id;
    }

    /**
     * Forces one album's four provenance columns and asserts the write took
     * effect, so a spec never runs over a state it merely hoped for.
     *
     * @param array $values album column => value, '' or null to clear
     * @return array the values now in the database
     */
    public function albumProvenance(int $catId, array $values): array
    {
        $assignments = array();
        foreach (self::albumColumns() as $column)
        {
            $value = $values[$column] ?? null;
            $assignments[] = "`$column` = " .
                ($value === null || $value === '' ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
        }

        $this->db->query(
            'UPDATE `piwigo_categories` SET ' . implode(', ', $assignments) . ' WHERE id = ' . $catId
        );

        $actual = $this->readAlbumProvenance($catId);
        foreach (self::albumColumns() as $column)
        {
            $wanted = ($values[$column] ?? null) === '' ? null : ($values[$column] ?? null);
            if ($actual[$column] !== $wanted)
            {
                throw new RuntimeException(
                    "fixture did not take effect: album $catId has $column = " .
                    var_export($actual[$column], true) . ', wanted ' . var_export($wanted, true)
                );
            }
        }

        return $actual;
    }

    public function readAlbumProvenance(int $catId): array
    {
        $result = $this->db->query(
            'SELECT `' . implode('`, `', self::albumColumns()) . '` FROM `piwigo_categories` WHERE id = ' . $catId
        );
        $row = $result->fetch_assoc();
        if ($row === null)
        {
            throw new RuntimeException("no album with id $catId");
        }
        return $row;
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
