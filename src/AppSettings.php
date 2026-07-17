<?php
declare(strict_types=1);

/**
 * Key-value settings store backed by the `settings` DB table.
 * Values are JSON-encoded so booleans, integers, and arrays round-trip correctly.
 */
class AppSettings
{
    private static ?AppSettings $instance = null;
    private array $cache = [];
    private bool $loaded = false;

    private function __construct(private Database $db)
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS settings (
                `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
                `value` TEXT        NOT NULL
            )'
        );
    }

    public static function getInstance(Database $db): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    private function loadAll(): void
    {
        if ($this->loaded) return;
        foreach ($this->db->fetchAll('SELECT `key`, `value` FROM settings') as $row) {
            $this->cache[$row['key']] = $row['value'];
        }
        $this->loaded = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->loadAll();
        if (!array_key_exists($key, $this->cache)) return $default;
        $decoded = json_decode($this->cache[$key], true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->cache[$key];
    }

    public function set(string $key, mixed $value): void
    {
        $encoded = json_encode($value);
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $encoded]
        );
        $this->cache[$key] = $encoded;
    }

    public function all(): array
    {
        $this->loadAll();
        $result = [];
        foreach ($this->cache as $key => $raw) {
            $decoded = json_decode($raw, true);
            $result[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        return $result;
    }

    /**
     * Returns the current school year label, e.g. "2024/25".
     * Empty string if not yet configured.
     */
    public function currentSchoolYear(): string
    {
        return (string)$this->get('current_school_year', '');
    }

    /**
     * Infers the next year label from a label like "2024/25" → "2025/26".
     * Falls back to suggesting the next calendar year if the format doesn't match.
     */
    public static function nextYearLabel(string $current): string
    {
        if (preg_match('/^(\d{4})\/(\d{2})$/', $current, $m)) {
            $endYear  = (int)$m[1] + 1;
            $nextEnd  = $endYear + 1;
            return $endYear . '/' . substr((string)$nextEnd, -2);
        }
        $year = (int)date('Y');
        return $year . '/' . substr((string)($year + 1), -2);
    }
}
