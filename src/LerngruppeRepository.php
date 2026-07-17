<?php
declare(strict_types=1);

/**
 * Database queries for teacher-created Aufgabensets tied to a Lerngruppe
 * (teacher + class + subject), and student progress on those sets.
 *
 * Each Aufgabenset has a name and a max_points value.
 * Student status per set: 1 = in progress, 2 = submitted.
 */
class LerngruppeRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS lg_grading_scales (
                class_id   INT NOT NULL,
                subject_id INT NOT NULL,
                thresholds JSON NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (class_id, subject_id),
                FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS year_archive (
                school_year     VARCHAR(9)   NOT NULL,
                student_id      INT          NOT NULL,
                taskset_id      INT          NOT NULL,
                status          INT          NOT NULL DEFAULT 0,
                achieved_points INT          NULL,
                taskset_name    VARCHAR(255) NOT NULL,
                max_points      INT          NOT NULL,
                is_pass_fail    TINYINT(1)   NOT NULL DEFAULT 0,
                subject_id      INT          NOT NULL,
                subject_name    VARCHAR(255) NOT NULL,
                student_grade   INT          NOT NULL,
                PRIMARY KEY (school_year, student_id, taskset_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS year_archive_scale (
                school_year  VARCHAR(9)   NOT NULL,
                student_id   INT          NOT NULL,
                subject_id   INT          NOT NULL,
                scale_json   TEXT         NOT NULL,
                PRIMARY KEY (school_year, student_id, subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // Idempotent schema migration: add is_pass_fail to year_archive if missing
        try {
            $cols = $this->db->fetchAll("SHOW COLUMNS FROM year_archive LIKE 'is_pass_fail'");
            if (empty($cols)) {
                $this->db->execute(
                    "ALTER TABLE year_archive ADD COLUMN is_pass_fail TINYINT(1) NOT NULL DEFAULT 0"
                );
            }
        } catch (\Throwable) {}
        // Idempotent schema migration: drop teacher_id from lg_grading_scales if still present
        try {
            $cols = $this->db->fetchAll("SHOW COLUMNS FROM lg_grading_scales LIKE 'teacher_id'");
            if (!empty($cols)) {
                $fks = $this->db->fetchAll(
                    "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lg_grading_scales'
                     AND COLUMN_NAME = 'teacher_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
                );
                foreach ($fks as $f) {
                    $this->db->execute("ALTER TABLE lg_grading_scales DROP FOREIGN KEY `{$f['CONSTRAINT_NAME']}`");
                }
                $this->db->execute('ALTER TABLE lg_grading_scales DROP PRIMARY KEY');
                $this->db->execute('ALTER TABLE lg_grading_scales DROP COLUMN teacher_id');
                $this->db->execute('ALTER TABLE lg_grading_scales ADD PRIMARY KEY (class_id, subject_id)');
            }
        } catch (\Throwable) {}
    }

    // -----------------------------------------------------------------------
    // Teacher — reading
    // -----------------------------------------------------------------------

    /**
     * Returns the teacher's Lerngruppen with their Aufgabensets.
     */
    public function getLerngruppenWithTaskSets(int $teacherId): array
    {
        $lerngruppen = $this->db->fetchAll(
            'SELECT c.id AS classId, c.label AS classLabel, c.grade AS classGrade,
                    s.id AS subjectId, s.name AS subjectName
             FROM teacher_lerngruppen tl
             JOIN classes c  ON tl.class_id  = c.id
             JOIN subjects s ON tl.subject_id = s.id
             WHERE tl.teacher_id = ?
             ORDER BY c.grade, c.label, s.sort_order, s.name',
            [$teacherId]
        );

        foreach ($lerngruppen as &$lg) {
            $lg['classId']    = (int)$lg['classId'];
            $lg['subjectId']  = (int)$lg['subjectId'];
            $lg['classGrade'] = (int)$lg['classGrade'];
            try {
                $lg['taskSets'] = $this->db->fetchAll(
                    'SELECT id, name, max_points AS maxPoints, active, is_pass_fail AS isPassFail
                     FROM lg_tasksets
                     WHERE subject_id = ?
                       AND (class_id = ? OR (class_id IS NULL AND grade = ?))
                     ORDER BY id',
                    [$lg['subjectId'], $lg['classId'], $lg['classGrade']]
                );
                foreach ($lg['taskSets'] as &$ts) {
                    $ts['id']        = (int)$ts['id'];
                    $ts['maxPoints'] = (int)$ts['maxPoints'];
                    $ts['active']    = (bool)$ts['active'];
                    $ts['isPassFail'] = (bool)($ts['isPassFail'] ?? false);
                }
                unset($ts);
            } catch (\Throwable) {
                $lg['taskSets'] = [];   // table not yet created
            }
            $lg['gradingScale'] = $this->getGradingScaleForClass($lg['classId'], $lg['subjectId']);
        }
        unset($lg);

        return $lerngruppen;
    }

    // -----------------------------------------------------------------------
    // Teacher — writing
    // -----------------------------------------------------------------------

    /**
     * Creates an Aufgabenset for a class+subject.
     * For teachers: verifies they are assigned to this class+subject.
     * For admins: pass $teacherId = 0 to skip the access check.
     * Returns null if the access check fails.
     */
    public function createTaskSet(
        int    $teacherId,
        int    $classId,
        int    $subjectId,
        string $name,
        int    $maxPoints,
        string $schoolYear = '',
        bool   $isPassFail = false
    ): ?array {
        if ($teacherId > 0) {
            $access = $this->db->fetch(
                'SELECT 1 FROM teacher_lerngruppen WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
                [$teacherId, $classId, $subjectId]
            );
            if (!$access) return null;
        }

        $grade = (int)($this->db->fetch('SELECT grade FROM classes WHERE id = ?', [$classId])['grade'] ?? 0);

        $this->db->execute(
            'INSERT INTO lg_tasksets (class_id, subject_id, grade, name, max_points, school_year, is_pass_fail)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$classId, $subjectId, $grade, $name, $maxPoints, $schoolYear, $isPassFail ? 1 : 0]
        );

        return [
            'id'         => $this->db->lastInsertId(),
            'name'       => $name,
            'maxPoints'  => $maxPoints,
            'active'     => false,
            'isPassFail' => $isPassFail,
        ];
    }

    /**
     * Updates name and max_points for a task set.
     * Access check via teacher_lerngruppen.
     * Returns false if ownership check fails.
     */
    public function updateTaskSet(int $taskSetId, int $teacherId, string $name, int $maxPoints, bool $isPassFail = false): bool
    {
        $row = $this->db->fetch(
            'SELECT class_id, subject_id, grade FROM lg_tasksets WHERE id = ?',
            [$taskSetId]
        );
        if (!$row) return false;

        if ($teacherId > 0) {
            if ($row['class_id'] === null) {
                $access = $this->db->fetch(
                    'SELECT 1 FROM teacher_lerngruppen tl JOIN classes c ON c.id = tl.class_id
                     WHERE tl.teacher_id = ? AND tl.subject_id = ? AND c.grade = ?',
                    [$teacherId, $row['subject_id'], $row['grade']]
                );
            } else {
                $access = $this->db->fetch(
                    'SELECT 1 FROM teacher_lerngruppen
                     WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
                    [$teacherId, $row['class_id'], $row['subject_id']]
                );
            }
            if (!$access) return false;
        }

        $this->db->execute(
            'UPDATE lg_tasksets SET name = ?, max_points = ?, is_pass_fail = ? WHERE id = ?',
            [$name, $maxPoints, $isPassFail ? 1 : 0, $taskSetId]
        );
        return true;
    }

    /**
     * Toggles the active flag for a task set.
     * Returns the new state, or null if ownership check fails.
     */
    public function toggleTaskSet(int $taskSetId, int $teacherId): ?bool
    {
        $row = $this->db->fetch(
            'SELECT class_id, subject_id, grade, active FROM lg_tasksets WHERE id = ?',
            [$taskSetId]
        );
        if (!$row) return null;

        $newActive = $row['active'] ? 0 : 1;

        if ($teacherId > 0) {
            if ($row['class_id'] === null) {
                $access = $this->db->fetch(
                    'SELECT 1 FROM teacher_lerngruppen tl JOIN classes c ON c.id = tl.class_id
                     WHERE tl.teacher_id = ? AND tl.subject_id = ? AND c.grade = ?',
                    [$teacherId, $row['subject_id'], $row['grade']]
                );
            } else {
                $access = $this->db->fetch(
                    'SELECT 1 FROM teacher_lerngruppen
                     WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
                    [$teacherId, $row['class_id'], $row['subject_id']]
                );
            }
            if (!$access) return null;
        }

        $this->db->execute('UPDATE lg_tasksets SET active = ? WHERE id = ?', [$newActive, $taskSetId]);
        return (bool)$newActive;
    }

    /**
     * Deletes a task set. No-ops if ownership check fails.
     */
    public function deleteTaskSet(int $taskSetId, int $teacherId): void
    {
        $row = $this->db->fetch(
            'SELECT class_id, subject_id, grade FROM lg_tasksets WHERE id = ?',
            [$taskSetId]
        );
        if (!$row) return;

        if ($teacherId > 0) {
            if ($row['class_id'] === null) {
                $access = $this->db->fetch(
                    'SELECT 1 FROM teacher_lerngruppen tl JOIN classes c ON c.id = tl.class_id
                     WHERE tl.teacher_id = ? AND tl.subject_id = ? AND c.grade = ?',
                    [$teacherId, $row['subject_id'], $row['grade']]
                );
            } else {
                $access = $this->db->fetch(
                    'SELECT 1 FROM teacher_lerngruppen
                     WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
                    [$teacherId, $row['class_id'], $row['subject_id']]
                );
            }
            if (!$access) return;
        }

        $this->db->execute('DELETE FROM lg_tasksets WHERE id = ?', [$taskSetId]);
    }

    private function currentSchoolYear(): string
    {
        $row = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'current_school_year'");
        return $row ? (json_decode($row['value'], true) ?? $row['value']) : '';
    }

    /**
     * Returns saved grading scale thresholds [t1..t5], or null if not yet set.
     */
    public function getGradingScaleForClass(int $classId, int $subjectId): ?array
    {
        try {
            $row = $this->db->fetch(
                'SELECT thresholds FROM lg_grading_scales
                 WHERE class_id = ? AND subject_id = ? LIMIT 1',
                [$classId, $subjectId]
            );
            return $row ? json_decode($row['thresholds'], true) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Upserts the grading scale for a class+subject.
     */
    public function saveGradingScale(int $classId, int $subjectId, array $thresholds): void
    {
        $this->db->execute(
            'INSERT INTO lg_grading_scales (class_id, subject_id, thresholds)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE thresholds = VALUES(thresholds)',
            [$classId, $subjectId, json_encode(array_values($thresholds))]
        );
    }

    public function saveGradingScaleForClass(int $classId, int $subjectId, array $thresholds): void
    {
        $this->saveGradingScale($classId, $subjectId, $thresholds);
    }

    /**
     * Saves a grading scale for every class at the given grade + subject.
     * Applies to every class at the grade, not just teacher-assigned ones --
     * same policy as migrateTaskSetsToClass() -- so a class without a teacher
     * assigned yet still gets the correct scale once one is added, instead of
     * silently falling back to the default on a later class-scope switch.
     */
    public function saveGradingScaleForGrade(int $grade, int $subjectId, array $thresholds): void
    {
        $classes = $this->db->fetchAll(
            'SELECT id FROM classes WHERE grade = ?',
            [$grade]
        );
        foreach ($classes as $c) {
            $this->saveGradingScale((int)$c['id'], $subjectId, $thresholds);
        }
    }

    // -----------------------------------------------------------------------
    // Teacher — notifications
    // -----------------------------------------------------------------------

    /**
     * Returns all Lerngruppen for the teacher with pending notifications:
     * submitted and "Brauche Hilfe" Aufgabensets per Lerngruppe.
     */
    public function getNotificationsForTeacher(int $teacherId): array
    {
        $lerngruppen = $this->db->fetchAll(
            'SELECT c.id AS classId, c.label AS classLabel, c.grade AS classGrade,
                    s.id AS subjectId, s.name AS subjectName
             FROM teacher_lerngruppen tl
             JOIN classes c  ON tl.class_id  = c.id
             JOIN subjects s ON tl.subject_id = s.id
             WHERE tl.teacher_id = ?
             ORDER BY c.grade, c.label, s.sort_order, s.name',
            [$teacherId]
        );

        foreach ($lerngruppen as &$lg) {
            $lg['classId']    = (int)$lg['classId'];
            $lg['subjectId']  = (int)$lg['subjectId'];
            $lg['classGrade'] = (int)$lg['classGrade'];

            // Students who submitted an Aufgabenset in this Lerngruppe (status 2)
            try {
                $submissionRows = $this->db->fetchAll(
                    'SELECT s.id AS studentId,
                            CONCAT(s.first_name, \' \', s.last_name) AS name,
                            s.current_room AS currentRoom,
                            ls.id AS taskSetId,
                            ls.name AS taskSetName,
                            ls.max_points AS maxPoints,
                            ls.is_pass_fail AS isPassFail
                     FROM lg_taskset_status lss
                     JOIN students s    ON lss.student_id = s.id
                     JOIN lg_tasksets ls ON lss.taskset_id = ls.id
                     WHERE s.class = ?
                       AND ls.subject_id = ?
                       AND (ls.class_id = ? OR (ls.class_id IS NULL AND ls.grade = ?))
                       AND lss.status = 2 AND lss.achieved_points IS NULL
                     ORDER BY s.last_name, s.first_name, ls.id',
                    [$lg['classId'], $lg['subjectId'], $lg['classId'], $lg['classGrade']]
                );
                $lg['submissions'] = array_map(fn(array $r): array => [
                    'studentId'   => (int)$r['studentId'],
                    'name'        => $r['name'],
                    'currentRoom' => $r['currentRoom'],
                    'taskSetId'   => (int)$r['taskSetId'],
                    'taskSetName' => $r['taskSetName'],
                    'maxPoints'   => (int)$r['maxPoints'],
                    'isPassFail'  => (bool)($r['isPassFail'] ?? false),
                ], $submissionRows);
            } catch (\Throwable) {
                $lg['submissions'] = [];
            }

            // Students who marked an Aufgabenset as "Brauche Hilfe" (status 3)
            try {
                $helpRows = $this->db->fetchAll(
                    'SELECT s.id AS studentId,
                            CONCAT(s.first_name, \' \', s.last_name) AS name,
                            s.current_room AS currentRoom,
                            ls.id AS taskSetId,
                            ls.name AS taskSetName
                     FROM lg_taskset_status lss
                     JOIN students s    ON lss.student_id = s.id
                     JOIN lg_tasksets ls ON lss.taskset_id = ls.id
                     WHERE s.class = ?
                       AND ls.subject_id = ?
                       AND (ls.class_id = ? OR (ls.class_id IS NULL AND ls.grade = ?))
                       AND lss.status = 3
                     ORDER BY s.last_name, s.first_name, ls.id',
                    [$lg['classId'], $lg['subjectId'], $lg['classId'], $lg['classGrade']]
                );
                $lg['helpRequests'] = array_map(fn(array $r): array => [
                    'studentId'   => (int)$r['studentId'],
                    'name'        => $r['name'],
                    'currentRoom' => $r['currentRoom'],
                    'taskSetId'   => (int)$r['taskSetId'],
                    'taskSetName' => $r['taskSetName'],
                ], $helpRows);
            } catch (\Throwable) {
                $lg['helpRequests'] = [];
            }
        }
        unset($lg);

        return $lerngruppen;
    }

    /**
     * Returns all unique students across the teacher's Lerngruppen classes.
     */
    public function getStudentsForTeacher(int $teacherId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT s.id, s.first_name AS firstName, s.last_name AS lastName,
                    s.email, c.label AS classLabel, s.graduation_level AS graduationLevel
             FROM teacher_lerngruppen tl
             JOIN classes c  ON tl.class_id = c.id
             JOIN students s ON s.class      = c.id
             WHERE tl.teacher_id = ?
             ORDER BY s.last_name, s.first_name',
            [$teacherId]
        );

        return array_map(function (array $r): array {
            $r['id']              = (int)$r['id'];
            $r['graduationLevel'] = (int)$r['graduationLevel'];
            return $r;
        }, $rows);
    }

    // -----------------------------------------------------------------------
    // Admin-only mode helpers
    // -----------------------------------------------------------------------

    /**
     * Checks whether all classes sharing the same Stufe + Fach have identical
     * task set names and max_points (hard) and grading scale thresholds (soft).
     * Returns ['hardConflicts' => [...], 'softWarnings' => [...]].
     * Hard conflicts block the merge; soft warnings can be overridden.
     */
    public function checkTaskSetConsistency(): array
    {
        $tsRows = $this->db->fetchAll(
            'SELECT c.grade, c.label AS classLabel,
                    s.id AS subjectId, s.name AS subjectName,
                    GROUP_CONCAT(DISTINCT
                        CONCAT(ts.name, \':\', ts.max_points, \':\', ts.is_pass_fail)
                        ORDER BY ts.name SEPARATOR \'|\'
                    ) AS fingerprint
             FROM lg_tasksets ts
             JOIN classes c  ON ts.class_id   = c.id
             JOIN subjects s ON ts.subject_id = s.id
             JOIN class_subjects cs ON cs.class_id = ts.class_id AND cs.subject_id = ts.subject_id
             WHERE ts.class_id IS NOT NULL
             GROUP BY c.grade, ts.subject_id, ts.class_id, c.label, s.name
             ORDER BY c.grade, ts.subject_id'
        );

        $scaleRows = $this->db->fetchAll(
            'SELECT c.grade, lgs.subject_id AS subjectId, c.label AS classLabel,
                    lgs.thresholds
             FROM lg_grading_scales lgs
             JOIN classes c ON lgs.class_id = c.id
             ORDER BY c.grade, lgs.subject_id'
        );

        $groups = [];

        foreach ($tsRows as $r) {
            $key = $r['grade'] . '_' . $r['subjectId'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'grade'       => (int)$r['grade'],
                    'subjectName' => $r['subjectName'],
                    'classes'     => [],
                ];
            }
            $groups[$key]['classes'][$r['classLabel']]['taskSets'] = $r['fingerprint'] ?? '';
        }

        foreach ($scaleRows as $r) {
            $key = $r['grade'] . '_' . $r['subjectId'];
            if (!isset($groups[$key])) continue;
            $groups[$key]['classes'][$r['classLabel']]['scale'] = $r['thresholds'] ?? '';
        }

        $hardConflicts = [];
        $softWarnings  = [];

        foreach ($groups as $group) {
            if (count($group['classes']) <= 1) continue;

            $classValues = array_values($group['classes']);
            $taskFingerprints = array_unique(array_column($classValues, 'taskSets'));
            $scaleFingerprints = array_unique(array_filter(array_column($classValues, 'scale')));

            $entry = [
                'grade'       => $group['grade'],
                'subjectName' => $group['subjectName'],
                'classes'     => array_keys($group['classes']),
            ];

            if (count($taskFingerprints) > 1) {
                $hardConflicts[] = $entry;
            } elseif (count($scaleFingerprints) > 1) {
                $softWarnings[] = $entry;
            }
        }

        return ['hardConflicts' => $hardConflicts, 'softWarnings' => $softWarnings];
    }

    /**
     * Copies all grade-level tasks (class_id IS NULL) to class-specific rows
     * for every class in the matching Stufe + Fach, then deletes the grade-level rows.
     * Called when switching task_scope from 'grade' to 'class'.
     */
    public function migrateTaskSetsToClass(): void
    {
        $gradeTasks = $this->db->fetchAll(
            'SELECT id, grade, subject_id, name, max_points, active, school_year, is_pass_fail
             FROM lg_tasksets WHERE class_id IS NULL'
        );

        if (empty($gradeTasks)) return;

        $groups = [];
        foreach ($gradeTasks as $task) {
            $groups[$task['grade'] . '_' . $task['subject_id']][] = $task;
        }

        foreach ($groups as $tasks) {
            $grade     = (int)$tasks[0]['grade'];
            $subjectId = (int)$tasks[0]['subject_id'];

            // Copy to every class at this grade, not just teacher-assigned ones.
            // A class without a teacher assigned yet still gets the task rows so
            // they appear as soon as a teacher is added.
            $classes = $this->db->fetchAll(
                'SELECT id AS classId FROM classes WHERE grade = ?',
                [$grade]
            );

            foreach ($classes as $cls) {
                foreach ($tasks as $task) {
                    $this->db->execute(
                        'INSERT IGNORE INTO lg_tasksets
                         (class_id, subject_id, grade, name, max_points, active, school_year, is_pass_fail)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$cls['classId'], $subjectId, $grade, $task['name'], $task['max_points'], $task['active'], $task['school_year'] ?? '', (int)($task['is_pass_fail'] ?? 0)]
                    );
                    $newId = $this->db->lastInsertId();
                    if (!$newId) continue;

                    $this->db->execute(
                        'INSERT IGNORE INTO lg_taskset_status (student_id, taskset_id, status, achieved_points)
                         SELECT lss.student_id, ?, lss.status, lss.achieved_points
                         FROM lg_taskset_status lss
                         JOIN students s ON s.id = lss.student_id
                         WHERE lss.taskset_id = ? AND s.class = ?',
                        [$newId, $task['id'], $cls['classId']]
                    );
                }
            }
        }

        $this->db->execute('DELETE FROM lg_tasksets WHERE class_id IS NULL');
    }

    /**
     * Collapses class-specific tasks back to grade-level rows. Called when
     * switching task_scope from 'class' to 'grade' after a clean consistency
     * check (see checkTaskSetConsistency()).
     *
     * Grading scales are per (class_id, subject_id) regardless of scope --
     * grade-scope mode just fans the same thresholds out to every class at
     * that grade (see saveGradingScaleForGrade()). So a grade+subject group
     * whose classes already agree on thresholds needs no changes at all; only
     * groups where classes genuinely disagreed (soft-warning, force-merged)
     * get reset to the default scale.
     */
    public function mergeTaskSetsToGrade(): void
    {
        $tasks = $this->db->fetchAll(
            'SELECT MIN(ts.id) AS id, MIN(ts.name) AS name, ts.max_points,
                    MIN(ts.active) AS active, ts.subject_id, c.grade,
                    MIN(ts.school_year) AS school_year, ts.is_pass_fail
             FROM lg_tasksets ts
             JOIN classes c ON c.id = ts.class_id
             JOIN class_subjects cs ON cs.class_id = ts.class_id AND cs.subject_id = ts.subject_id
             WHERE ts.class_id IS NOT NULL
             GROUP BY c.grade, ts.subject_id, ts.name, ts.max_points, ts.is_pass_fail'
        );

        if (empty($tasks)) {
            $this->db->execute('DELETE FROM lg_grading_scales');
            return;
        }

        // Snapshot existing scales per grade+subject before the merge, so we
        // can tell afterwards which groups actually disagreed.
        $scaleGroups = [];
        foreach ($this->db->fetchAll(
            'SELECT c.grade, lgs.subject_id AS subjectId, lgs.class_id AS classId, lgs.thresholds
             FROM lg_grading_scales lgs
             JOIN classes c ON c.id = lgs.class_id'
        ) as $r) {
            $key = $r['grade'] . '_' . $r['subjectId'];
            $scaleGroups[$key]['classIds'][]   = (int)$r['classId'];
            $scaleGroups[$key]['thresholds'][] = $r['thresholds'];
        }

        foreach ($tasks as $task) {
            $this->db->execute(
                'INSERT INTO lg_tasksets (class_id, subject_id, grade, name, max_points, active, school_year, is_pass_fail)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)',
                [(int)$task['subject_id'], (int)$task['grade'], $task['name'], (int)$task['max_points'], (int)$task['active'], $task['school_year'] ?? '', (int)($task['is_pass_fail'] ?? 0)]
            );
            $newId = $this->db->lastInsertId();

            $this->db->execute(
                'INSERT IGNORE INTO lg_taskset_status (student_id, taskset_id, status, achieved_points)
                 SELECT lss.student_id, ?, lss.status, lss.achieved_points
                 FROM lg_taskset_status lss
                 JOIN lg_tasksets ts ON ts.id = lss.taskset_id
                 JOIN classes c ON c.id = ts.class_id
                 WHERE ts.subject_id = ? AND ts.name = ? AND c.grade = ? AND ts.class_id IS NOT NULL',
                [$newId, (int)$task['subject_id'], $task['name'], (int)$task['grade']]
            );
        }

        $this->db->execute('DELETE FROM lg_tasksets WHERE class_id IS NOT NULL');

        foreach ($scaleGroups as $group) {
            if (count(array_unique($group['thresholds'])) <= 1) {
                continue; // already identical across every class -- keep as-is
            }
            $placeholders = implode(',', array_fill(0, count($group['classIds']), '?'));
            $this->db->execute(
                "DELETE FROM lg_grading_scales WHERE class_id IN ($placeholders)",
                $group['classIds']
            );
        }
    }

    /**
     * When a new Lerngruppe is added in admin-only mode, copies all existing
     * task sets from another class at the same Stufe + Fach.
     */
    public function copyTaskSetsToNewLerngruppe(int $classId, int $subjectId): void
    {
        // Only copy when the class has no tasksets for this subject yet.
        // Without this guard, re-adding a teacher to an existing lerngruppe
        // would duplicate every taskset (there is no unique constraint on the table).
        $existing = $this->db->fetch(
            'SELECT 1 FROM lg_tasksets WHERE class_id = ? AND subject_id = ? LIMIT 1',
            [$classId, $subjectId]
        );
        if ($existing) return;

        $classRow = $this->db->fetch('SELECT grade FROM classes WHERE id = ?', [$classId]);
        if (!$classRow) return;

        $sourceRow = $this->db->fetch(
            'SELECT ts.class_id AS sourceClassId
             FROM lg_tasksets ts
             JOIN classes c ON ts.class_id = c.id
             WHERE c.grade = ? AND ts.subject_id = ? AND ts.class_id != ?
             LIMIT 1',
            [(int)$classRow['grade'], $subjectId, $classId]
        );
        if (!$sourceRow) return;

        $grade = (int)$classRow['grade'];
        $sourceSets = $this->db->fetchAll(
            'SELECT name, max_points, active, school_year, is_pass_fail FROM lg_tasksets
             WHERE class_id = ? AND subject_id = ?',
            [$sourceRow['sourceClassId'], $subjectId]
        );

        foreach ($sourceSets as $ts) {
            $this->db->execute(
                'INSERT IGNORE INTO lg_tasksets (class_id, subject_id, grade, name, max_points, active, school_year, is_pass_fail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$classId, $subjectId, $grade, $ts['name'], $ts['max_points'], $ts['active'], $ts['school_year'] ?? '', (int)($ts['is_pass_fail'] ?? 0)]
            );
        }
    }

    // -----------------------------------------------------------------------
    // Class overview matrix
    // -----------------------------------------------------------------------

    /**
     * Returns all active task-set statuses for every student in a class,
     * structured as a matrix: list of subjects (with their task sets) and
     * list of students (each with a cells map: subjectId → task-set statuses).
     *
     * Used by the class dashboard overview panel.
     */
    public function getClassTaskMatrix(int $classId): array
    {
        try {
            $cy = $this->currentSchoolYear();
            $rows = $cy !== ''
                ? $this->db->fetchAll(
                    'SELECT st.id AS studentId, st.first_name AS firstName, st.last_name AS lastName,
                            st.current_room AS currentRoom, st.graduation_level AS graduationLevel,
                            ls.id AS taskSetId, ls.name AS taskSetName, ls.max_points AS maxPoints,
                            ls.is_pass_fail AS isPassFail,
                            s.id AS subjectId, s.name AS subjectName, s.color AS subjectColor,
                            COALESCE(lss.status, 0) AS status, lss.achieved_points AS achievedPoints
                     FROM students st
                     JOIN classes c ON c.id = st.class
                     JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
                     JOIN subjects s ON ls.subject_id = s.id
                     LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
                     WHERE st.class = ? AND ls.active = 1 AND ls.school_year = ?
                     ORDER BY lastName, firstName, s.sort_order, subjectName, taskSetId',
                    [$classId, $cy]
                  )
                : $this->db->fetchAll(
                    'SELECT st.id AS studentId, st.first_name AS firstName, st.last_name AS lastName,
                            st.current_room AS currentRoom, st.graduation_level AS graduationLevel,
                            ls.id AS taskSetId, ls.name AS taskSetName, ls.max_points AS maxPoints,
                            ls.is_pass_fail AS isPassFail,
                            s.id AS subjectId, s.name AS subjectName, s.color AS subjectColor,
                            COALESCE(lss.status, 0) AS status, lss.achieved_points AS achievedPoints
                     FROM students st
                     JOIN classes c ON c.id = st.class
                     JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
                     JOIN subjects s ON ls.subject_id = s.id
                     LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
                     WHERE st.class = ? AND ls.active = 1
                     ORDER BY lastName, firstName, s.sort_order, subjectName, taskSetId',
                    [$classId]
                  );
        } catch (\Throwable) {
            return ['subjects' => [], 'students' => []];
        }

        $subjects        = [];   // subjectId => {id, name, taskSets:[]}
        $subjectTaskSeen = [];   // subjectId => [taskSetId => true]
        $students        = [];   // studentId => {studentId, firstName, lastName, cells:{}}

        foreach ($rows as $r) {
            $studentId = (int)$r['studentId'];
            $subjectId = (int)$r['subjectId'];
            $taskSetId = (int)$r['taskSetId'];

            if (!isset($subjects[$subjectId])) {
                $subjects[$subjectId]        = ['id' => $subjectId, 'name' => $r['subjectName'], 'color' => $r['subjectColor'], 'taskSets' => []];
                $subjectTaskSeen[$subjectId] = [];
            }
            if (!isset($subjectTaskSeen[$subjectId][$taskSetId])) {
                $subjectTaskSeen[$subjectId][$taskSetId] = true;
                $subjects[$subjectId]['taskSets'][] = [
                    'id'         => $taskSetId,
                    'name'       => $r['taskSetName'],
                    'maxPoints'  => (int)$r['maxPoints'],
                    'isPassFail' => (bool)($r['isPassFail'] ?? false),
                ];
            }

            if (!isset($students[$studentId])) {
                $students[$studentId] = [
                    'studentId'   => $studentId,
                    'firstName'   => $r['firstName'],
                    'lastName'    => $r['lastName'],
                    'currentRoom' => $r['currentRoom'] ?? null,
                    'graduationLevel' => (int)$r['graduationLevel'],
                    'cells'       => [],
                ];
            }
            $students[$studentId]['cells'][$subjectId][] = [
                'taskSetId'      => $taskSetId,
                'maxPoints'      => (int)$r['maxPoints'],
                'isPassFail'     => (bool)($r['isPassFail'] ?? false),
                'status'         => (int)$r['status'],
                'achievedPoints' => $r['achievedPoints'] !== null ? (int)$r['achievedPoints'] : null,
                'name'           => $r['taskSetName'],
            ];
        }

        return [
            'subjects' => array_values($subjects),
            'students' => array_values($students),
        ];
    }

    // -----------------------------------------------------------------------
    // Student — reading
    // -----------------------------------------------------------------------

    /**
     * Returns all Aufgabensets visible to a student (based on their class),
     * with the student's status for each set.
     */
    public function getTaskSetsForStudent(int $studentId, string $schoolYear = ''): array
    {
        try {
            // Treat current year same as no-year: use live query, not archive
            if ($schoolYear !== '' && $schoolYear === $this->currentSchoolYear()) {
                $schoolYear = '';
            }
            if ($schoolYear !== '') {
                $rows = $this->db->fetchAll(
                    "SELECT ya.taskset_id AS id, ya.taskset_name AS name, ya.max_points AS maxPoints,
                            ya.is_pass_fail AS isPassFail,
                            ya.subject_id AS subjectId, ya.subject_name AS subjectName,
                            s.color AS subjectColor,
                            ya.status, ya.achieved_points AS achievedPoints
                     FROM year_archive ya
                     LEFT JOIN subjects s ON s.id = ya.subject_id
                     WHERE ya.student_id = ? AND ya.school_year = ?
                     ORDER BY ya.subject_name, ya.taskset_id",
                    [$studentId, $schoolYear]
                );
            } else {
                $cy = $this->currentSchoolYear();
                if ($cy !== '') {
                    $rows = $this->db->fetchAll(
                        'SELECT ls.id, ls.name, ls.max_points AS maxPoints, ls.is_pass_fail AS isPassFail,
                                s.id AS subjectId, s.name AS subjectName, s.color AS subjectColor,
                                COALESCE(lss.status, 0) AS status, lss.achieved_points AS achievedPoints
                         FROM students st
                         JOIN classes c ON c.id = st.class
                         JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
                         JOIN subjects s ON ls.subject_id = s.id
                         JOIN student_topics stt ON stt.student_id = st.id AND stt.subject_id = s.id
                         LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
                         WHERE st.id = ? AND ls.active = 1 AND ls.school_year = ?
                         ORDER BY s.sort_order, subjectName, ls.id',
                        [$studentId, $cy]
                    );
                } else {
                    $rows = $this->db->fetchAll(
                        'SELECT ls.id, ls.name, ls.max_points AS maxPoints, ls.is_pass_fail AS isPassFail,
                                s.id AS subjectId, s.name AS subjectName, s.color AS subjectColor,
                                COALESCE(lss.status, 0) AS status, lss.achieved_points AS achievedPoints
                         FROM students st
                         JOIN classes c ON c.id = st.class
                         JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
                         JOIN subjects s ON ls.subject_id = s.id
                         JOIN student_topics stt ON stt.student_id = st.id AND stt.subject_id = s.id
                         LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
                         WHERE st.id = ? AND ls.active = 1
                         ORDER BY s.sort_order, subjectName, ls.id',
                        [$studentId]
                    );
                }
            }

            return array_map(function (array $r): array {
                return [
                    'id'             => (int)$r['id'],
                    'name'           => $r['name'],
                    'maxPoints'      => (int)$r['maxPoints'],
                    'isPassFail'     => (bool)($r['isPassFail'] ?? false),
                    'subjectId'      => (int)$r['subjectId'],
                    'subjectName'    => $r['subjectName'],
                    'subjectColor'   => $r['subjectColor'] ?? null,
                    'status'         => (int)$r['status'],
                    'achievedPoints' => $r['achievedPoints'] !== null ? (int)$r['achievedPoints'] : null,
                ];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Returns available school years for the performance panel:
     * archived years (from year_archive) plus the current live year.
     */
    public function getAvailableYearsForStudent(int $studentId): array
    {
        try {
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT school_year FROM year_archive
                 WHERE student_id = ? ORDER BY school_year DESC",
                [$studentId]
            );
            $years = array_column($rows, 'school_year');

            $current = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'current_school_year'");
            if ($current) {
                $cy = json_decode($current['value'], true) ?? $current['value'];
                if ($cy !== '' && !in_array($cy, $years, true)) {
                    array_unshift($years, $cy);
                }
            }

            return $years;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Returns all task sets per subject for a student with achieved points and
     * grading scale. Pass a school year string to view a historical year
     * (reads from year_archive); omit to show only currently active task sets.
     */
    public function getPerformanceForStudent(int $studentId, string $schoolYear = ''): array
    {
        try {
            // Treat current year same as no-year: use live query, not archive
            if ($schoolYear !== '' && $schoolYear === $this->currentSchoolYear()) {
                $schoolYear = '';
            }
            if ($schoolYear !== '') {
                $rows = $this->db->fetchAll(
                    "SELECT ya.taskset_id AS id, ya.taskset_name AS name, ya.max_points AS maxPoints,
                            ya.is_pass_fail AS isPassFail,
                            ya.subject_id AS subjectId, ya.subject_name AS subjectName,
                            ya.status, ya.achieved_points AS achievedPoints,
                            yas.scale_json
                     FROM year_archive ya
                     LEFT JOIN year_archive_scale yas
                         ON yas.school_year = ya.school_year
                         AND yas.student_id = ya.student_id
                         AND yas.subject_id = ya.subject_id
                     WHERE ya.student_id = ? AND ya.school_year = ?
                     ORDER BY ya.subject_name, ya.taskset_id",
                    [$studentId, $schoolYear]
                );

                $subjects = [];
                foreach ($rows as $r) {
                    $sid = (int)$r['subjectId'];
                    if (!isset($subjects[$sid])) {
                        $subjects[$sid] = [
                            'subjectId'    => $sid,
                            'subjectName'  => $r['subjectName'],
                            'taskSets'     => [],
                            'gradingScale' => $r['scale_json'] ? json_decode($r['scale_json'], true) : null,
                        ];
                    }
                    $subjects[$sid]['taskSets'][] = [
                        'id'             => (int)$r['id'],
                        'name'           => $r['name'],
                        'maxPoints'      => (int)$r['maxPoints'],
                        'status'         => (int)$r['status'],
                        'achievedPoints' => $r['achievedPoints'] !== null ? (int)$r['achievedPoints'] : null,
                    ];
                }
                return array_values($subjects);
            } else {
                $cy = $this->currentSchoolYear();
                $rows = $cy !== ''
                    ? $this->db->fetchAll(
                        "SELECT ls.id, ls.name, ls.max_points AS maxPoints, ls.is_pass_fail AS isPassFail,
                                s.id AS subjectId, s.name AS subjectName,
                                COALESCE(lss.status, 0) AS status,
                                lss.achieved_points AS achievedPoints,
                                st.class AS classId
                         FROM students st
                         JOIN classes c ON c.id = st.class
                         JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
                         JOIN subjects s ON ls.subject_id = s.id
                         JOIN student_topics stt ON stt.student_id = st.id AND stt.subject_id = s.id
                         LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
                         WHERE st.id = ? AND ls.active = 1 AND ls.school_year = ?
                         ORDER BY s.sort_order, subjectName, ls.id",
                        [$studentId, $cy]
                      )
                    : $this->db->fetchAll(
                        "SELECT ls.id, ls.name, ls.max_points AS maxPoints, ls.is_pass_fail AS isPassFail,
                                s.id AS subjectId, s.name AS subjectName,
                                COALESCE(lss.status, 0) AS status,
                                lss.achieved_points AS achievedPoints,
                                st.class AS classId
                         FROM students st
                         JOIN classes c ON c.id = st.class
                         JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
                         JOIN subjects s ON ls.subject_id = s.id
                         JOIN student_topics stt ON stt.student_id = st.id AND stt.subject_id = s.id
                         LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
                         WHERE st.id = ? AND ls.active = 1
                         ORDER BY s.sort_order, subjectName, ls.id",
                        [$studentId]
                      );

                $subjects = [];
                foreach ($rows as $r) {
                    $sid = (int)$r['subjectId'];
                    if (!isset($subjects[$sid])) {
                        $subjects[$sid] = [
                            'subjectId'    => $sid,
                            'subjectName'  => $r['subjectName'],
                            'taskSets'     => [],
                            'gradingScale' => isset($r['classId'])
                                ? $this->getGradingScaleForClass((int)$r['classId'], $sid)
                                : [],
                        ];
                    }
                    $subjects[$sid]['taskSets'][] = [
                        'id'             => (int)$r['id'],
                        'name'           => $r['name'],
                        'maxPoints'      => (int)$r['maxPoints'],
                        'status'         => (int)$r['status'],
                        'achievedPoints' => $r['achievedPoints'] !== null ? (int)$r['achievedPoints'] : null,
                    ];
                }
                return array_values($subjects);
            }
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Snapshots all student×taskset data for the given school year into year_archive,
     * and all per-student grading scales into year_archive_scale.
     * Must be called BEFORE promoting students so class membership is still accurate.
     */
    public function snapshotCurrentYear(string $schoolYear): void
    {
        $this->db->execute(
            "INSERT IGNORE INTO year_archive
                 (school_year, student_id, taskset_id, status, achieved_points,
                  taskset_name, max_points, is_pass_fail, subject_id, subject_name, student_grade)
             SELECT ?, st.id, ls.id,
                    COALESCE(lss.status, 0),
                    lss.achieved_points,
                    ls.name, ls.max_points, ls.is_pass_fail,
                    s.id, s.name,
                    c.grade
             FROM students st
             JOIN classes c ON c.id = st.class
             JOIN lg_tasksets ls ON (ls.class_id = st.class OR (ls.class_id IS NULL AND ls.grade = c.grade))
             JOIN subjects s ON ls.subject_id = s.id
             LEFT JOIN lg_taskset_status lss ON lss.taskset_id = ls.id AND lss.student_id = st.id
             WHERE ls.school_year = ?",
            [$schoolYear, $schoolYear]
        );

        $this->db->execute(
            "INSERT IGNORE INTO year_archive_scale (school_year, student_id, subject_id, scale_json)
             SELECT ?, st.id, gs.subject_id, gs.thresholds
             FROM students st
             JOIN lg_grading_scales gs ON gs.class_id = st.class",
            [$schoolYear]
        );
    }

    /**
     * Returns a list of archived years with student counts, newest first.
     */
    public function getYearArchives(): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT school_year AS schoolYear,
                        COUNT(DISTINCT student_id) AS studentCount
                 FROM year_archive
                 GROUP BY school_year
                 ORDER BY school_year DESC"
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Deletes all archive rows for a given school year.
     */
    public function deleteYearArchive(string $schoolYear): void
    {
        $this->db->execute("DELETE FROM year_archive WHERE school_year = ?", [$schoolYear]);
        $this->db->execute("DELETE FROM year_archive_scale WHERE school_year = ?", [$schoolYear]);
    }

    /**
     * Records the achieved points for a submitted task set.
     * Only the owning teacher may set scores.
     */
    public function setAchievedPoints(int $teacherId, int $studentId, int $taskSetId, int $points): bool
    {
        $taskSet = $this->db->fetch(
            'SELECT ts.max_points FROM lg_tasksets ts
             WHERE ts.id = ? AND (
                 (ts.class_id IS NOT NULL AND EXISTS (
                     SELECT 1 FROM teacher_lerngruppen
                     WHERE teacher_id = ? AND class_id = ts.class_id AND subject_id = ts.subject_id
                 ))
                 OR (ts.class_id IS NULL AND EXISTS (
                     SELECT 1 FROM teacher_lerngruppen tl JOIN classes c ON tl.class_id = c.id
                     WHERE tl.teacher_id = ? AND tl.subject_id = ts.subject_id AND c.grade = ts.grade
                 ))
             )',
            [$taskSetId, $teacherId, $teacherId]
        );
        if (!$taskSet) return false;
        if ($points > (int)$taskSet['max_points']) return false;

        $this->db->execute(
            'UPDATE lg_taskset_status
             SET achieved_points = ?, scored_at = NOW()
             WHERE student_id = ? AND taskset_id = ?',
            [$points, $studentId, $taskSetId]
        );
        return true;
    }

    public function adminSetAchievedPoints(int $studentId, int $taskSetId, ?int $points): bool
    {
        $taskSet = $this->db->fetch(
            'SELECT max_points, is_pass_fail FROM lg_tasksets WHERE id = ?',
            [$taskSetId]
        );
        if (!$taskSet) return false;
        $maxPoints = (int)$taskSet['max_points'];
        if ($points !== null && ($points < 0 || $points > $maxPoints)) return false;
        if ($points !== null && $taskSet['is_pass_fail'] && $points !== 0 && $points !== $maxPoints) return false;

        if ($points === null) {
            $this->db->execute(
                'UPDATE lg_taskset_status SET achieved_points = NULL, scored_at = NULL
                 WHERE student_id = ? AND taskset_id = ?',
                [$studentId, $taskSetId]
            );
        } else {
            $this->db->execute(
                'UPDATE lg_taskset_status SET achieved_points = ?, scored_at = NOW()
                 WHERE student_id = ? AND taskset_id = ?',
                [$points, $studentId, $taskSetId]
            );
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Student — writing
    // -----------------------------------------------------------------------

    /**
     * Returns a submitted task to the student (resets their status to 0).
     * Only the owning teacher may return a task.
     */
    public function returnTaskSet(int $teacherId, int $studentId, int $taskSetId): bool
    {
        $owns = $this->db->fetch(
            'SELECT 1 FROM lg_tasksets ts
             WHERE ts.id = ? AND (
                 (ts.class_id IS NOT NULL AND EXISTS (
                     SELECT 1 FROM teacher_lerngruppen
                     WHERE teacher_id = ? AND class_id = ts.class_id AND subject_id = ts.subject_id
                 ))
                 OR (ts.class_id IS NULL AND EXISTS (
                     SELECT 1 FROM teacher_lerngruppen tl JOIN classes c ON tl.class_id = c.id
                     WHERE tl.teacher_id = ? AND tl.subject_id = ts.subject_id AND c.grade = ts.grade
                 ))
             )',
            [$taskSetId, $teacherId, $teacherId]
        );
        if (!$owns) return false;

        $this->db->execute(
            'DELETE FROM lg_taskset_status WHERE student_id = ? AND taskset_id = ?',
            [$studentId, $taskSetId]
        );
        return true;
    }

    /**
     * Sets a student's status for an Aufgabenset.
     * status 0 clears the row; 1 = in progress, 2 = submitted.
     */
    public function setTaskSetStatusAsTeacher(int $teacherId, int $studentId, int $taskSetId, int $status): bool
    {
        $ok = $this->db->fetch(
            'SELECT 1 FROM lg_tasksets ts
             WHERE ts.id = ? AND (
                 (ts.class_id IS NOT NULL AND EXISTS (
                     SELECT 1 FROM teacher_lerngruppen
                     WHERE teacher_id = ? AND class_id = ts.class_id AND subject_id = ts.subject_id
                 ))
                 OR (ts.class_id IS NULL AND EXISTS (
                     SELECT 1 FROM teacher_lerngruppen tl JOIN classes c ON tl.class_id = c.id
                     WHERE tl.teacher_id = ? AND tl.subject_id = ts.subject_id AND c.grade = ts.grade
                 ))
             )',
            [$taskSetId, $teacherId, $teacherId]
        );
        if (!$ok) return false;
        $this->setTaskSetStatus($studentId, $taskSetId, $status);
        return true;
    }

    public function setTaskSetStatus(int $studentId, int $taskSetId, int $status): void
    {
        if ($status === 0) {
            $this->db->execute(
                'DELETE FROM lg_taskset_status WHERE student_id = ? AND taskset_id = ?',
                [$studentId, $taskSetId]
            );
        } else {
            $this->db->execute(
                'INSERT INTO lg_taskset_status (student_id, taskset_id, status) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)',
                [$studentId, $taskSetId, $status]
            );
        }
    }
}
