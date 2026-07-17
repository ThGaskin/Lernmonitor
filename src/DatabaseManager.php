<?php
declare(strict_types=1);

class DatabaseManager
{
    // Tables ordered so that FK dependencies come before the tables that reference them.
    private const TABLE_ORDER = [
        'settings', 'classes', 'rooms', 'subjects',
        'students', 'teachers', 'admins', 'parents',
        'class_subjects', 'parent_student',
        'teacher_lerngruppen', 'lg_tasksets', 'lg_grading_scales',
        'student_topics',
        'lg_taskset_status',
        'year_archive', 'year_archive_scale',
    ];

    public function __construct(private Database $db) {}

    public function backup(): string
    {
        $tables = [];
        foreach (self::TABLE_ORDER as $table) {
            $rows = $this->db->fetchAll("SELECT * FROM `{$table}`");
            if (empty($rows)) {
                $tables[$table] = ['columns' => [], 'rows' => []];
            } else {
                $tables[$table] = [
                    'columns' => array_keys($rows[0]),
                    'rows'    => array_map(fn($r) => array_values($r), $rows),
                ];
            }
        }
        return json_encode([
            'version'    => 1,
            'created_at' => date('c'),
            'tables'     => $tables,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function backupYearArchive(string $schoolYear): string
    {
        $tables = [];
        foreach (self::TABLE_ORDER as $table) {
            if ($table === 'year_archive') {
                $rows = $this->db->fetchAll(
                    "SELECT * FROM year_archive WHERE school_year = ?", [$schoolYear]
                );
            } elseif ($table === 'year_archive_scale') {
                $rows = $this->db->fetchAll(
                    "SELECT * FROM year_archive_scale WHERE school_year = ?", [$schoolYear]
                );
            } else {
                $rows = $this->db->fetchAll("SELECT * FROM `{$table}`");
            }
            if (empty($rows)) {
                $tables[$table] = ['columns' => [], 'rows' => []];
            } else {
                $tables[$table] = [
                    'columns' => array_keys($rows[0]),
                    'rows'    => array_map(fn($r) => array_values($r), $rows),
                ];
            }
        }
        return json_encode([
            'version'    => 1,
            'created_at' => date('c'),
            'tables'     => $tables,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function restore(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['version'] ?? 0) !== 1) {
            throw new \RuntimeException('Ungültige Backup-Datei.');
        }
        $tables = $data['tables'] ?? [];

        $this->db->execute('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_reverse(self::TABLE_ORDER) as $table) {
                $this->db->execute("DELETE FROM `{$table}`");
            }
            foreach (self::TABLE_ORDER as $table) {
                $td = $tables[$table] ?? null;
                if (!$td || empty($td['rows'])) continue;
                $cols    = $td['columns'];
                $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
                $ph      = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                foreach ($td['rows'] as $row) {
                    $this->db->execute("INSERT INTO `{$table}` ({$colList}) VALUES {$ph}", $row);
                }
            }
        } finally {
            $this->db->execute('SET FOREIGN_KEY_CHECKS=1');
        }

        self::destroyAllSessions();
    }

    public function reset(string $newEmail, string $newPassword, string $firstName = '', string $lastName = ''): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::TABLE_ORDER) as $table) {
            $this->db->execute("TRUNCATE TABLE `{$table}`");
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS=1');

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->execute(
            'INSERT INTO admins (username, password_hash, email, first_name, last_name) VALUES (?, ?, ?, ?, ?)',
            [$newEmail, $hash, $newEmail, $firstName, $lastName]
        );

        self::destroyAllSessions();
    }

    private static function destroyAllSessions(): void
    {
        $path = rtrim(session_save_path() ?: sys_get_temp_dir(), '/');
        foreach (glob($path . '/sess_*') ?: [] as $f) {
            @unlink($f);
        }
        session_unset();
        session_destroy();
    }
}
