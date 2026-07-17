<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper. Singleton so the connection is reused within a request.
 *
 * Usage:
 *   $db = Database::getInstance();
 *   $row  = $db->fetch('SELECT * FROM students WHERE id = ?', [$id]);
 *   $rows = $db->fetchAll('SELECT * FROM students WHERE class = ?', [$classId]);
 *   $db->execute('UPDATE students SET graduation_level = ? WHERE id = ?', [$level, $id]);
 */
class Database
{
    private PDO $pdo;
    private static ?Database $instance = null;

    private function __construct()
    {
        $this->pdo = new PDO(
            Config::dbDsn(),
            Config::dbUser(),
            Config::dbPass(),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Run a query and return the raw PDOStatement (for cases needing rowCount, etc.). */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch a single row, or null if no match. */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    /** Fetch all matching rows. */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Run INSERT / UPDATE / DELETE; returns number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /** Last auto-increment ID inserted in this connection. */
    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }
}
