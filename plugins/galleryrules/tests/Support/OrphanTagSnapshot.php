<?php
/**
 * The install's unused tags, kept on disk while a suite whose cleanup sweeps
 * every unused tag of the install runs. A run killed between take() and
 * restore() leaves the file; the next take() puts those tags back first.
 * Shared path on purpose: either suite restores the other's leftover.
 *
 * Tags the two suites create themselves (a killed run leaves them unused) are
 * never kept: restoring one would re-create test debris as if it were the install's.
 */
class OrphanTagSnapshot
{
    private const FILE = '/_data/orphan-tags-snapshot.json';
    private const FIXTURE_NAME = '/^(plain|coloured|person|leftover|used|young|old|listed)-[0-9a-f]{6}$/';

    private array $rows = array();

    public function __construct(private Db $db)
    {
    }

    public static function path(): string
    {
        return dirname(__DIR__, 4) . self::FILE;
    }

    public static function isFixtureName(string $name): bool
    {
        return preg_match(self::FIXTURE_NAME, $name) === 1;
    }

    public function take(): void
    {
        $this->restoreLeftover();
        $result = $this->db->query(
            'SELECT t.* FROM `piwigo_tags` t LEFT JOIN `piwigo_image_tag` i ON t.id = i.tag_id WHERE i.tag_id IS NULL'
        );
        $this->rows = array_values(array_filter(
            $result->fetch_all(MYSQLI_ASSOC),
            fn(array $row) => !self::isFixtureName((string)$row['name'])
        ));
        $this->write($this->rows);
    }

    public function restoreLeftover(): void
    {
        if (!is_file(self::path()))
        {
            return;
        }
        $rows = json_decode((string)file_get_contents(self::path()), true);
        if (!is_array($rows))
        {
            throw new RuntimeException('Unreadable tag snapshot ' . self::path() . ': delete it only after checking it by hand');
        }
        $this->insert($rows);
        unlink(self::path());
    }

    public function restore(): void
    {
        $this->insert($this->rows);
        $this->rows = array();
        if (is_file(self::path()))
        {
            unlink(self::path());
        }
    }

    private function write(array $rows): void
    {
        $written = file_put_contents(self::path(), json_encode($rows, JSON_THROW_ON_ERROR));
        if ($written === false)
        {
            throw new RuntimeException('Cannot write the tag snapshot ' . self::path());
        }
    }

    private function insert(array $rows): void
    {
        foreach ($rows as $row)
        {
            $columns = array();
            $values = array();
            foreach ($row as $column => $value)
            {
                $columns[] = '`' . $column . '`';
                $values[] = $value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'";
            }
            $this->db->query(
                'INSERT IGNORE INTO `piwigo_tags` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')'
            );
        }
    }
}
