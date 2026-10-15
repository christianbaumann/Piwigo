<?php
/** Thin mysqli wrapper for fixture setup/teardown. */
class Db
{
    private mysqli $conn;

    public function __construct()
    {
        $this->conn = new mysqli(Config::dbHost(), Config::dbUser(), Config::dbPassword(), Config::dbName());
        if ($this->conn->connect_error)
        {
            throw new RuntimeException('DB connection failed: ' . $this->conn->connect_error);
        }
    }

    public function query(string $sql): mysqli_result|bool
    {
        $result = $this->conn->query($sql);
        if ($result === false)
        {
            throw new RuntimeException('Query failed: ' . $this->conn->error . ' — ' . $sql);
        }
        return $result;
    }

    public function scalar(string $sql): mixed
    {
        $result = $this->query($sql);
        $row = $result->fetch_row();
        return $row === null ? null : $row[0];
    }

    public function insertId(): int
    {
        return (int)$this->conn->insert_id;
    }

    public function escape(string $value): string
    {
        return $this->conn->real_escape_string($value);
    }
}
