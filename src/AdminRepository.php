<?php
declare(strict_types=1);

/**
 * Admin-only database operations: CRUD for all entities, CSV imports, exports.
 */
class AdminRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
        try {
            $this->db->execute('ALTER TABLE subjects ADD COLUMN color VARCHAR(7) NULL DEFAULT NULL');
        } catch (\Throwable) { /* column already exists */ }
        try {
            $this->db->execute('ALTER TABLE subjects ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
        } catch (\Throwable) {}
        // Self-heal: if all sort_order values are 0 (column just added or subjects were imported
        // without sort_order), assign initial order alphabetically.
        try {
            $unset = $this->db->fetch('SELECT COUNT(*) AS n FROM subjects WHERE sort_order = 0')['n'] ?? 0;
            $total = $this->db->fetch('SELECT COUNT(*) AS n FROM subjects')['n'] ?? 0;
            if ($total > 0 && (int)$unset === (int)$total) {
                $rows = $this->db->fetchAll('SELECT id FROM subjects ORDER BY name');
                foreach ($rows as $i => $r) {
                    $this->db->execute('UPDATE subjects SET sort_order = ? WHERE id = ?', [($i + 1) * 10, $r['id']]);
                }
            }
        } catch (\Throwable) {}
        try {
            $this->db->execute('ALTER TABLE lg_tasksets ADD COLUMN grade INT NOT NULL DEFAULT 0');
            // Backfill grade from the class for existing rows
            $this->db->execute(
                'UPDATE lg_tasksets ts JOIN classes c ON ts.class_id = c.id SET ts.grade = c.grade WHERE ts.grade = 0'
            );
        } catch (\Throwable) {}
        try {
            $this->db->execute("ALTER TABLE lg_tasksets ADD COLUMN school_year VARCHAR(9) NOT NULL DEFAULT ''");
        } catch (\Throwable) {}
        try {
            $this->db->execute('ALTER TABLE lg_tasksets ADD COLUMN is_pass_fail TINYINT(1) NOT NULL DEFAULT 0');
        } catch (\Throwable) {}
        try {
            $this->db->execute('ALTER TABLE teachers ADD COLUMN linked_admin_username VARCHAR(100) NULL DEFAULT NULL');
            $this->db->execute('ALTER TABLE teachers ADD CONSTRAINT fk_teacher_admin FOREIGN KEY (linked_admin_username) REFERENCES admins(username) ON DELETE SET NULL');
        } catch (\Throwable) {}
        try {
            $this->db->execute('ALTER TABLE admins ADD COLUMN first_name VARCHAR(100) NULL DEFAULT NULL');
            $this->db->execute('ALTER TABLE admins ADD COLUMN last_name  VARCHAR(100) NULL DEFAULT NULL');
        } catch (\Throwable) {}
        try {
            $this->db->execute('ALTER TABLE admins MODIFY COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL');
        } catch (\Throwable) {}
        try {
            $this->ensureRoomFkCascade('students');
            $this->ensureRoomFkCascade('teachers');
        } catch (\Throwable) {}
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
            'CREATE TABLE IF NOT EXISTS class_subjects (
                class_id   INT NOT NULL,
                subject_id INT NOT NULL,
                PRIMARY KEY (class_id, subject_id),
                FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    // -----------------------------------------------------------------------
    // Admin accounts
    // -----------------------------------------------------------------------

    public function addAdmin(string $email, ?string $benutzername = null, string $firstName = '', string $lastName = ''): void
    {
        if ($this->db->fetch('SELECT username FROM admins WHERE username = ?', [$email])) {
            throw new InvalidArgumentException("Ein Administratorkonto mit der E-Mail \"{$email}\" existiert bereits.");
        }
        if ($this->db->fetch('SELECT username FROM admins WHERE email = ?', [$email])) {
            throw new InvalidArgumentException("Ein Administratorkonto mit der E-Mail \"{$email}\" existiert bereits.");
        }
        if ($benutzername !== null && $this->db->fetch('SELECT username FROM admins WHERE benutzername = ?', [$benutzername])) {
            throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
        }
        $this->db->execute(
            'INSERT INTO admins (username, password_hash, email, benutzername, first_name, last_name) VALUES (?, NULL, ?, ?, ?, ?)',
            [$email, $email, $benutzername, $firstName ?: null, $lastName ?: null]
        );
    }

    public function getAllAdmins(): array
    {
        $admins = $this->db->fetchAll(
            'SELECT a.username, a.email, a.benutzername, a.first_name AS firstName, a.last_name AS lastName,
                    (t.id IS NOT NULL) AS hasLinkedTeacher
             FROM admins a
             LEFT JOIN teachers t ON t.linked_admin_username = a.username
             ORDER BY a.last_name, a.first_name, a.username'
        );
        foreach ($admins as &$a) {
            $a['hasLinkedTeacher'] = (bool)$a['hasLinkedTeacher'];
        }
        return $admins;
    }

    public function getAllUsers(): array
    {
        $adminUsernames = array_column(
            $this->db->fetchAll('SELECT username FROM admins'),
            'username'
        );
        $students = $this->db->fetchAll(
            "SELECT id, first_name AS firstName, last_name AS lastName, email, 'student' AS type, 0 AS isLinked
             FROM students ORDER BY last_name, first_name"
        );
        $teachers = $this->db->fetchAll(
            "SELECT id, first_name AS firstName, last_name AS lastName, email, 'teacher' AS type,
                    (linked_admin_username IS NOT NULL) AS isLinked
             FROM teachers ORDER BY last_name, first_name"
        );
        $parents = $this->db->fetchAll(
            "SELECT id, '' AS firstName, '' AS lastName, email, 'parent' AS type, 0 AS isLinked
             FROM parents ORDER BY email"
        );
        $users = array_merge($students, $teachers, $parents);
        foreach ($users as &$u) {
            $u['isAdmin']  = in_array($u['email'], $adminUsernames, true);
            $u['isLinked'] = (bool)$u['isLinked'];
        }
        usort($users, fn($a, $b) => strcmp($a['lastName'] . $a['firstName'], $b['lastName'] . $b['firstName']));
        return $users;
    }

    public function promoteUserToAdmin(int $id, string $type): void
    {
        if ($type !== 'teacher') {
            throw new InvalidArgumentException("Nur Lehrkräfte können als Administratoren hinzugefügt werden.");
        }
        $row = $this->db->fetch(
            'SELECT id, email, benutzername, password AS hash, linked_admin_username, first_name, last_name FROM teachers WHERE id = ?', [$id]
        );
        if (!$row) {
            throw new InvalidArgumentException("Lehrkraft nicht gefunden.");
        }
        if ($row['linked_admin_username'] !== null) {
            throw new InvalidArgumentException("Diese Lehrkraft hat bereits ein verknüpftes Admin-Konto.");
        }
        $existing = $this->db->fetch('SELECT username FROM admins WHERE username = ?', [$row['email']]);
        if ($existing) {
            throw new InvalidArgumentException("Ein Admin-Konto mit der E-Mail \"{$row['email']}\" existiert bereits.");
        }
        $this->db->execute(
            'INSERT INTO admins (username, password_hash, email, benutzername, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)',
            [$row['email'], $row['hash'], $row['email'], $row['benutzername'], $row['first_name'], $row['last_name']]
        );
        $this->db->execute(
            'UPDATE teachers SET linked_admin_username = ? WHERE id = ?',
            [$row['email'], $id]
        );
    }

    public function resetUserPassword(int $id, string $type, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        match ($type) {
            'student' => $this->db->execute('UPDATE students SET password = ? WHERE id = ?', [$hash, $id]),
            'teacher' => $this->db->execute('UPDATE teachers SET password = ? WHERE id = ?', [$hash, $id]),
            'parent'  => $this->db->execute('UPDATE parents SET password = ? WHERE id = ?', [$hash, $id]),
            default   => throw new InvalidArgumentException("Unbekannter Benutzertyp."),
        };
    }

    /**
     * Delete an admin. Returns false if the target is $currentUser or if
     * deleting would leave zero admins; returns true on success.
     */
    public function deleteAdmin(string $username, string $currentUser): bool
    {
        if ($username === $currentUser) {
            return false;
        }
        $count = (int)($this->db->fetch('SELECT COUNT(*) AS n FROM admins')['n'] ?? 0);
        if ($count <= 1) {
            return false;
        }
        $this->db->execute('DELETE FROM admins WHERE username = ?', [$username]);
        return true;
    }

    public function updateAdmin(string $username, string $email, ?string $benutzername, string $firstName = '', string $lastName = ''): void
    {
        $emailConflict = $this->db->fetch(
            'SELECT username FROM admins WHERE email = ? AND username != ?', [$email, $username]
        );
        if ($emailConflict) throw new InvalidArgumentException("Diese E-Mail-Adresse ist bereits vergeben.");
        if ($benutzername !== null && $this->benutzernameTakenExcluding($benutzername, '', 0, $username)) {
            throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
        }
        $this->db->execute(
            'UPDATE admins SET email=?, benutzername=?, first_name=?, last_name=? WHERE username=?',
            [$email, $benutzername, $firstName ?: null, $lastName ?: null, $username]
        );
    }

    /**
     * Reset an admin's password to a new random value.
     * Returns the plaintext password.
     */
    public function resetAdminPassword(string $username, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->execute(
            'UPDATE admins SET password_hash = ? WHERE username = ?',
            [$hash, $username]
        );
    }

    // -----------------------------------------------------------------------
    // Students
    // -----------------------------------------------------------------------

    /**
     * Add a single student. Returns ['password' => plaintext].
     * Throws InvalidArgumentException if the class or graduation level is invalid.
     */
    public function addStudent(string $firstName, string $lastName, string $email, int $classId, int $graduationLevel, ?string $benutzername = null): array
    {
        $class = $this->db->fetch('SELECT id FROM classes WHERE id = ?', [$classId]);
        if (!$class) {
            throw new InvalidArgumentException("Klasse mit ID {$classId} existiert nicht.");
        }
        if ($graduationLevel < 0) {
            throw new InvalidArgumentException("Abschlussstufe muss eine positive Zahl sein.");
        }

        if ($this->emailExists($email)) {
            throw new InvalidArgumentException("Ein Konto mit dieser E-Mail-Adresse existiert bereits.");
        }

        if ($benutzername !== null && $this->benutzernameTaken($benutzername)) {
            throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
        }

        $next = $this->db->fetch('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM students');
        $id   = (int)$next['next_id'];

        $this->db->execute(
            'INSERT INTO students (id, first_name, last_name, email, password, benutzername, class, graduation_level)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?)',
            [$id, $firstName, $lastName, $email, $benutzername, $classId, $graduationLevel]
        );

        $this->autoEnrolStudentInClassSubjects($id, $classId);

        return ['id' => $id];
    }

    public function updateStudent(int $id, string $firstName, string $lastName, string $email, int $classId, int $graduationLevel, ?string $benutzername = null): void
    {
        $class = $this->db->fetch('SELECT id FROM classes WHERE id = ?', [$classId]);
        if (!$class) {
            throw new InvalidArgumentException("Klasse mit ID {$classId} existiert nicht.");
        }
        if ($this->emailExistsExcluding($email, $id, 'students')) {
            throw new InvalidArgumentException("Ein Konto mit dieser E-Mail-Adresse existiert bereits.");
        }
        if ($benutzername !== null && $this->benutzernameTakenExcluding($benutzername, 'students', $id)) {
            throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
        }
        $this->db->execute(
            'UPDATE students SET first_name=?, last_name=?, email=?, class=?, graduation_level=?, benutzername=? WHERE id=?',
            [$firstName, $lastName, $email, $classId, $graduationLevel, $benutzername, $id]
        );
    }

    public function deleteStudent(int $id): void
    {
        $this->db->execute('DELETE FROM students WHERE id = ?', [$id]);
    }

    // -----------------------------------------------------------------------
    // Class-subject assignments
    // -----------------------------------------------------------------------

    /** Returns all subject IDs assigned to a class. */
    public function getClassSubjects(int $classId): array
    {
        return array_column(
            $this->db->fetchAll(
                'SELECT subject_id FROM class_subjects WHERE class_id = ?',
                [$classId]
            ),
            'subject_id'
        );
    }

    /** Returns all class-subject assignments as [{classId, subjectId}]. */
    public function getAllClassSubjects(): array
    {
        return $this->db->fetchAll('SELECT class_id AS classId, subject_id AS subjectId FROM class_subjects');
    }

    /**
     * Assign a subject to a class and bulk-enrol every current student in it.
     * Uses INSERT IGNORE on student_topics so existing enrolments are not overwritten.
     */
    public function assignSubjectToClass(int $classId, int $subjectId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (?, ?)',
            [$classId, $subjectId]
        );
        $this->enrollClassInSubject($classId, $subjectId);
    }

    /**
     * Remove a subject assignment from a class.
     * Does NOT unenrol existing students — that is done per-student on the profile page.
     */
    public function removeSubjectFromClass(int $classId, int $subjectId): void
    {
        $this->db->execute(
            'DELETE FROM class_subjects WHERE class_id = ? AND subject_id = ?',
            [$classId, $subjectId]
        );
    }

    /**
     * Enrol a newly-added student in all subjects currently assigned to their class.
     */
    public function autoEnrolStudentInClassSubjects(int $studentId, int $classId): void
    {
        $subjectIds = $this->getClassSubjects($classId);
        foreach ($subjectIds as $subjectId) {
            $this->db->execute(
                'INSERT IGNORE INTO student_topics (student_id, subject_id) VALUES (?, ?)',
                [$studentId, (int)$subjectId]
            );
        }
    }

    /** Bulk-enrol all students in a class in a single subject. */
    private function enrollClassInSubject(int $classId, int $subjectId): void
    {
        $students = $this->db->fetchAll('SELECT id FROM students WHERE class = ?', [$classId]);
        foreach ($students as $s) {
            $this->db->execute(
                'INSERT IGNORE INTO student_topics (student_id, subject_id) VALUES (?, ?)',
                [(int)$s['id'], $subjectId]
            );
        }
    }

    /**
     * Returns all Lerngruppen with their task sets, structured as:
     * [ { grade, subjects: [ { subjectId, subjectName, classes: [ { classId, classLabel,
     *   teacherNames, taskSets: [ { id, name, maxPoints, active } ], gradingScale } ] } ] } ]
     */
    public function getAllTaskSets(): array
    {
        // Fetch subject sort orders so we can sort subjects correctly at the end
        $subjectSortOrders = [];
        foreach ($this->db->fetchAll('SELECT id, sort_order FROM subjects') as $r) {
            $subjectSortOrders[(int)$r['id']] = (int)$r['sort_order'];
        }

        $cyRow = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'current_school_year'");
        $currentYear = $cyRow ? (json_decode($cyRow['value'], true) ?? $cyRow['value']) : '';

        // Build lookup of which subjects are actually assigned to each class
        $assignedSubjects = [];
        foreach ($this->db->fetchAll('SELECT class_id, subject_id FROM class_subjects') as $r) {
            $assignedSubjects[$r['class_id'] . '_' . $r['subject_id']] = true;
        }

        // Build class+subject structure from teacher assignments
        $lgRows = $this->db->fetchAll(
            'SELECT c.grade, c.id AS classId, c.label AS classLabel,
                    s.id AS subjectId, s.name AS subjectName,
                    CONCAT(t.first_name, \' \', t.last_name) AS teacherName
             FROM teacher_lerngruppen tl
             JOIN classes c  ON tl.class_id   = c.id
             JOIN subjects s ON tl.subject_id = s.id
             JOIN teachers t ON tl.teacher_id = t.id
             ORDER BY c.grade, s.sort_order, s.name, c.label'
        );

        // Class-scope task sets indexed by "classId_subjectId"
        $classTasks     = [];
        $classTasksMeta = []; // grade/label/subject info for classes that may have no teacher
        try {
            $taskRows = $currentYear !== ''
                ? $this->db->fetchAll(
                    'SELECT ts.id AS taskSetId, ts.name AS taskSetName,
                            ts.max_points AS maxPoints, ts.active, ts.is_pass_fail AS isPassFail,
                            ts.class_id AS classId, ts.subject_id AS subjectId,
                            c.grade, c.label AS classLabel, s.name AS subjectName
                     FROM lg_tasksets ts
                     JOIN classes  c ON c.id = ts.class_id
                     JOIN subjects s ON s.id = ts.subject_id
                     WHERE ts.class_id IS NOT NULL AND ts.school_year = ?
                     ORDER BY ts.class_id, ts.subject_id, ts.id',
                    [$currentYear]
                  )
                : $this->db->fetchAll(
                    'SELECT ts.id AS taskSetId, ts.name AS taskSetName,
                            ts.max_points AS maxPoints, ts.active, ts.is_pass_fail AS isPassFail,
                            ts.class_id AS classId, ts.subject_id AS subjectId,
                            c.grade, c.label AS classLabel, s.name AS subjectName
                     FROM lg_tasksets ts
                     JOIN classes  c ON c.id = ts.class_id
                     JOIN subjects s ON s.id = ts.subject_id
                     WHERE ts.class_id IS NOT NULL
                     ORDER BY ts.class_id, ts.subject_id, ts.id'
                  );
            foreach ($taskRows as $r) {
                $key = $r['classId'] . '_' . $r['subjectId'];
                $classTasks[$key][] = [
                    'id'         => (int)$r['taskSetId'],
                    'name'       => $r['taskSetName'],
                    'maxPoints'  => (int)$r['maxPoints'],
                    'active'     => (bool)$r['active'],
                    'isPassFail' => (bool)($r['isPassFail'] ?? false),
                ];
                if (!isset($classTasksMeta[$key])) {
                    $classTasksMeta[$key] = [
                        'classId'     => (int)$r['classId'],
                        'classLabel'  => $r['classLabel'],
                        'grade'       => (int)$r['grade'],
                        'subjectId'   => (int)$r['subjectId'],
                        'subjectName' => $r['subjectName'],
                    ];
                }
            }
        } catch (\Throwable) {}

        // Grading scales indexed by "classId_subjectId"
        $scales = [];
        try {
            foreach ($this->db->fetchAll('SELECT class_id, subject_id, thresholds FROM lg_grading_scales') as $r) {
                $scales[$r['class_id'] . '_' . $r['subject_id']] = json_decode($r['thresholds'], true);
            }
        } catch (\Throwable) {}

        // Build grade-level scale lookup ("grade_subjectId") for virtual class 0
        $classGradeMap = [];
        foreach ($lgRows as $r) {
            $classGradeMap[(int)$r['classId']] = (int)$r['grade'];
        }
        $gradeScales = [];
        foreach ($scales as $key => $thresholds) {
            [$cid, $sid] = explode('_', $key, 2);
            if (isset($classGradeMap[(int)$cid])) {
                $gradeScales[$classGradeMap[(int)$cid] . '_' . $sid] = $thresholds;
            }
        }

        $grades = [];
        foreach ($lgRows as $r) {
            $grade     = (int)$r['grade'];
            $subjectId = (int)$r['subjectId'];
            $classId   = (int)$r['classId'];
            $key       = $classId . '_' . $subjectId;

            // Skip combos where the class hasn't had this subject formally assigned
            if (!isset($assignedSubjects[$key])) continue;

            if (!isset($grades[$grade])) {
                $grades[$grade] = ['grade' => $grade, 'subjects' => []];
            }
            if (!isset($grades[$grade]['subjects'][$subjectId])) {
                $grades[$grade]['subjects'][$subjectId] = [
                    'subjectId'   => $subjectId,
                    'subjectName' => $r['subjectName'],
                    'classes'     => [],
                ];
            }
            if (!isset($grades[$grade]['subjects'][$subjectId]['classes'][$classId])) {
                $grades[$grade]['subjects'][$subjectId]['classes'][$classId] = [
                    'classId'      => $classId,
                    'classLabel'   => $r['classLabel'],
                    'teacherNames' => [$r['teacherName']],
                    'taskSets'     => $classTasks[$key] ?? [],
                    'gradingScale' => $scales[$key] ?? null,
                ];
            } else {
                $existing = &$grades[$grade]['subjects'][$subjectId]['classes'][$classId]['teacherNames'];
                if (!in_array($r['teacherName'], $existing, true)) {
                    $existing[] = $r['teacherName'];
                }
                unset($existing);
            }
        }

        // Include class-scope task sets for classes that have no teacher assigned yet
        foreach ($classTasksMeta as $key => $meta) {
            $grade     = $meta['grade'];
            $subjectId = $meta['subjectId'];
            $classId   = $meta['classId'];

            // Skip combos where the class hasn't had this subject formally assigned
            if (!isset($assignedSubjects[$key])) continue;

            if (!isset($grades[$grade])) {
                $grades[$grade] = ['grade' => $grade, 'subjects' => []];
            }
            if (!isset($grades[$grade]['subjects'][$subjectId])) {
                $grades[$grade]['subjects'][$subjectId] = [
                    'subjectId'   => $subjectId,
                    'subjectName' => $meta['subjectName'],
                    'classes'     => [],
                ];
            }
            if (!isset($grades[$grade]['subjects'][$subjectId]['classes'][$classId])) {
                $grades[$grade]['subjects'][$subjectId]['classes'][$classId] = [
                    'classId'      => $classId,
                    'classLabel'   => $meta['classLabel'],
                    'teacherNames' => [],
                    'taskSets'     => $classTasks[$key] ?? [],
                    'gradingScale' => $scales[$key] ?? null,
                ];
            }
        }

        // Include grade-level task sets (class_id IS NULL) — fold into a virtual class[0]
        try {
            $gradeTs = $currentYear !== ''
                ? $this->db->fetchAll(
                    'SELECT ts.id AS taskSetId, ts.name AS taskSetName, ts.max_points AS maxPoints,
                            ts.active, ts.is_pass_fail AS isPassFail, ts.grade,
                            ts.subject_id AS subjectId, s.name AS subjectName
                     FROM lg_tasksets ts
                     JOIN subjects s ON s.id = ts.subject_id
                     WHERE ts.class_id IS NULL AND ts.school_year = ?
                     ORDER BY ts.grade, s.sort_order, s.name, ts.id',
                    [$currentYear]
                  )
                : $this->db->fetchAll(
                    'SELECT ts.id AS taskSetId, ts.name AS taskSetName, ts.max_points AS maxPoints,
                            ts.active, ts.is_pass_fail AS isPassFail, ts.grade,
                            ts.subject_id AS subjectId, s.name AS subjectName
                     FROM lg_tasksets ts
                     JOIN subjects s ON s.id = ts.subject_id
                     WHERE ts.class_id IS NULL
                     ORDER BY ts.grade, s.sort_order, s.name, ts.id'
                  );
            foreach ($gradeTs as $r) {
                $grade     = (int)$r['grade'];
                $subjectId = (int)$r['subjectId'];
                if (!isset($grades[$grade])) {
                    $grades[$grade] = ['grade' => $grade, 'subjects' => []];
                }
                if (!isset($grades[$grade]['subjects'][$subjectId])) {
                    $grades[$grade]['subjects'][$subjectId] = [
                        'subjectId'   => $subjectId,
                        'subjectName' => $r['subjectName'],
                        'classes'     => [],
                    ];
                }
                if (!isset($grades[$grade]['subjects'][$subjectId]['classes'][0])) {
                    $grades[$grade]['subjects'][$subjectId]['classes'][0] = [
                        'classId'      => 0,
                        'classLabel'   => null,
                        'teacherNames' => [],
                        'taskSets'     => [],
                        'gradingScale' => $gradeScales[$grade . '_' . $subjectId] ?? null,
                    ];
                }
                $grades[$grade]['subjects'][$subjectId]['classes'][0]['taskSets'][] = [
                    'id'         => (int)$r['taskSetId'],
                    'name'       => $r['taskSetName'],
                    'maxPoints'  => (int)$r['maxPoints'],
                    'active'     => (bool)$r['active'],
                    'isPassFail' => (bool)($r['isPassFail'] ?? false),
                ];
            }
        } catch (\Throwable) {}

        // Subjects per grade: use class_subjects (the canonical class-level enrollment table).
        // This is grade-specific and directly mirrors what manage_classes shows.
        // Removing a subject from all classes in a grade removes it here too.
        $noLg = [];
        try {
            $noLg = $this->db->fetchAll(
                'SELECT DISTINCT c.grade, s.id AS subjectId, s.name AS subjectName
                 FROM class_subjects cs
                 JOIN classes c  ON c.id = cs.class_id
                 JOIN subjects s ON s.id = cs.subject_id
                 ORDER BY c.grade, s.name'
            );
        } catch (\Throwable) {}

        // Ensure every grade that has a class appears, even if it has no subjects yet.
        $allGrades = $this->db->fetchAll('SELECT DISTINCT grade FROM classes ORDER BY grade');
        foreach ($allGrades as $r) {
            $grade = (int)$r['grade'];
            if (!isset($grades[$grade])) {
                $grades[$grade] = ['grade' => $grade, 'subjects' => []];
            }
        }
        foreach ($noLg as $r) {
            $grade     = (int)$r['grade'];
            $subjectId = (int)$r['subjectId'];
            if (!isset($grades[$grade])) {
                $grades[$grade] = ['grade' => $grade, 'subjects' => []];
            }
            if (!isset($grades[$grade]['subjects'][$subjectId])) {
                $grades[$grade]['subjects'][$subjectId] = [
                    'subjectId'   => $subjectId,
                    'subjectName' => $r['subjectName'],
                    'classes'     => [],
                ];
            }
        }
        ksort($grades);

        $result = [];
        foreach ($grades as $grade => $gData) {
            uasort($gData['subjects'], function ($a, $b) use ($subjectSortOrders) {
                $sa = $subjectSortOrders[$a['subjectId']] ?? PHP_INT_MAX;
                $sb = $subjectSortOrders[$b['subjectId']] ?? PHP_INT_MAX;
                return $sa !== $sb ? $sa - $sb : strcmp($a['subjectName'], $b['subjectName']);
            });
            $subjects = [];
            foreach ($gData['subjects'] as $sData) {
                uasort($sData['classes'], fn($a, $b) => strcmp($a['classLabel'] ?? '', $b['classLabel'] ?? ''));
                $subjects[] = [
                    'subjectId'   => $sData['subjectId'],
                    'subjectName' => $sData['subjectName'],
                    'classes'     => array_values($sData['classes']),
                ];
            }
            $result[] = ['grade' => $gData['grade'], 'subjects' => $subjects];
        }
        return $result;
    }

    public function getAllStudents(): array
    {
        return $this->db->fetchAll(
            'SELECT s.id, s.first_name AS firstName, s.last_name AS lastName,
                    s.email, s.graduation_level AS graduationLevel,
                    s.current_room AS room,
                    c.id AS classId, c.label AS classLabel, c.grade AS classGrade
             FROM students s JOIN classes c ON s.class = c.id
             ORDER BY c.grade, c.label, s.last_name, s.first_name'
        );
    }

    /**
     * Import students from CSV text.
     * Expected format (with or without header): ID,Vorname,Nachname,Klasse,Abschlussstufe,E-Mail
     * Returns a CSV string with generated passwords for distribution.
     */
    public function addStudentsFromCsv(string $csv): string
    {
        $lines    = $this->parseCsvLines($csv);
        $result   = "ID,Vorname,Nachname,E-Mail,Passwort\n";
        $classIds = [];

        foreach ($lines as $cols) {
            if (count($cols) < 6) {
                continue;
            }
            [$id, $firstName, $lastName, $classLabel, $graduationLevel, $email] = $cols;
            $id              = (int)$id;
            $graduationLevel = (int)$graduationLevel;

            // Resolve class label → ID
            $class = $this->db->fetch('SELECT id FROM classes WHERE label = ? LIMIT 1', [$classLabel]);
            if (!$class) {
                continue; // skip if class doesn't exist
            }

            $password     = $this->generatePassword();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $this->db->execute(
                'INSERT INTO students (id, first_name, last_name, email, password, class, graduation_level)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   first_name = VALUES(first_name), last_name = VALUES(last_name),
                   email = VALUES(email), password = VALUES(password),
                   class = VALUES(class), graduation_level = VALUES(graduation_level)',
                [$id, $firstName, $lastName, $email, $passwordHash, $class['id'], $graduationLevel]
            );

            $classIds[(int)$class['id']] = true;
            $result .= self::csvRow([$id, $firstName, $lastName, $email, $password]);
        }

        // Bulk-enrol all imported students in their class subjects
        foreach (array_keys($classIds) as $classId) {
            foreach ($this->getClassSubjects($classId) as $subjectId) {
                $this->enrollClassInSubject($classId, (int)$subjectId);
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Teachers
    // -----------------------------------------------------------------------

    public function updateTeacher(int $id, string $firstName, string $lastName, string $email, ?string $benutzername = null): void
    {
        if ($this->emailExistsExcluding($email, $id, 'teachers')) {
            throw new InvalidArgumentException("Ein Konto mit dieser E-Mail-Adresse existiert bereits.");
        }
        if ($benutzername !== null && $this->benutzernameTakenExcluding($benutzername, 'teachers', $id)) {
            throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
        }
        $this->db->execute(
            'UPDATE teachers SET first_name=?, last_name=?, email=?, benutzername=? WHERE id=?',
            [$firstName, $lastName, $email, $benutzername, $id]
        );
    }

    public function deleteTeacher(int $id): void
    {
        $this->db->execute('DELETE FROM teachers WHERE id=?', [$id]);
    }

    public function getAllTeachers(): array
    {
        return $this->db->fetchAll(
            'SELECT id, first_name AS firstName, last_name AS lastName, email, current_room AS room
             FROM teachers ORDER BY last_name, first_name'
        );
    }

    /**
     * Import teachers from CSV text.
     * Expected format: Vorname,Nachname,Email
     * Returns a CSV string with generated passwords for distribution.
     */
    public function addTeachersFromCsv(string $csv): string
    {
        $lines  = $this->parseCsvLines($csv);
        $result = "Vorname,Nachname,E-Mail,Passwort\n";

        foreach ($lines as $cols) {
            if (count($cols) < 3) {
                continue;
            }
            [$firstName, $lastName, $email] = $cols;

            $password     = $this->generatePassword();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $this->db->execute(
                'INSERT INTO teachers (first_name, last_name, email, password)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   first_name = VALUES(first_name), last_name = VALUES(last_name), password = VALUES(password)',
                [$firstName, $lastName, $email, $passwordHash]
            );

            $result .= self::csvRow([$firstName, $lastName, $email, $password]);
        }

        return $result;
    }

    /** Add a single teacher, returns their data. */
    public function addTeacher(string $firstName, string $lastName, string $email, ?string $benutzername = null): array
    {
        // Check for a full admin match → create linked dual-role teacher.
        $adminByEmail = $this->db->fetch('SELECT username, benutzername FROM admins WHERE email = ?', [$email]);
        if ($adminByEmail !== null) {
            $adminBnMatches = ($benutzername === null)
                ? ($adminByEmail['benutzername'] === null)
                : ($adminByEmail['benutzername'] === $benutzername);
            if ($adminBnMatches) {
                if ($this->emailExists($email)) {
                    throw new InvalidArgumentException("Ein Konto mit dieser E-Mail-Adresse existiert bereits.");
                }
                $this->db->execute(
                    'INSERT INTO teachers (first_name, last_name, email, password, benutzername, linked_admin_username)
                     VALUES (?, ?, ?, NULL, ?, ?)',
                    [$firstName, $lastName, $email, $benutzername, $adminByEmail['username']]
                );
                $row = $this->db->fetch('SELECT id, first_name AS firstName, last_name AS lastName, email FROM teachers WHERE email = ?', [$email]);
                return $row + ['linked' => true];
            }
        }

        // Normal teacher creation.
        if ($this->emailExists($email)) {
            throw new InvalidArgumentException("Ein Konto mit dieser E-Mail-Adresse existiert bereits.");
        }
        if ($benutzername !== null && $this->benutzernameTaken($benutzername)) {
            throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
        }

        $this->db->execute(
            'INSERT INTO teachers (first_name, last_name, email, password, benutzername) VALUES (?, ?, ?, NULL, ?)',
            [$firstName, $lastName, $email, $benutzername]
        );

        return $this->db->fetch('SELECT id, first_name AS firstName, last_name AS lastName, email FROM teachers WHERE email = ?', [$email]);
    }

    // -----------------------------------------------------------------------
    // Rooms
    // -----------------------------------------------------------------------

    /**
     * Import rooms from CSV text.
     * Expected format: Raumname,Mindestlevel
     */
    public function addRoom(string $label, int $minimumLevel): void
    {
        $this->db->execute(
            'INSERT INTO rooms (label, minimum_level) VALUES (?, ?) ON DUPLICATE KEY UPDATE minimum_level=VALUES(minimum_level)',
            [trim($label), $minimumLevel]
        );
    }

    public function updateRoom(string $oldLabel, string $newLabel, int $minimumLevel): void
    {
        $newLabel = trim($newLabel);
        if ($newLabel !== $oldLabel) {
            $exists = $this->db->fetch('SELECT 1 AS x FROM rooms WHERE label = ?', [$newLabel]);
            if ($exists) {
                throw new \RuntimeException('Ein Raum mit diesem Namen existiert bereits.');
            }
        }
        $this->db->execute('UPDATE rooms SET label=?, minimum_level=? WHERE label=?', [$newLabel, $minimumLevel, $oldLabel]);
    }

    /**
     * Idempotent migration: ensure the FK from $table.current_room to rooms.label
     * cascades on update, so renaming a room doesn't orphan assigned students/teachers.
     */
    private function ensureRoomFkCascade(string $table): void
    {
        $rules = $this->db->fetchAll(
            'SELECT rc.CONSTRAINT_NAME, rc.UPDATE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = ? AND rc.REFERENCED_TABLE_NAME = ?',
            [$table, 'rooms']
        );
        foreach ($rules as $r) {
            if ($r['UPDATE_RULE'] !== 'CASCADE') {
                $this->db->execute("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$r['CONSTRAINT_NAME']}`");
                $this->db->execute(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$r['CONSTRAINT_NAME']}` " .
                    'FOREIGN KEY (current_room) REFERENCES rooms(label) ON DELETE SET NULL ON UPDATE CASCADE'
                );
            }
        }
    }

    public function deleteRoom(string $label): void
    {
        $this->db->execute('DELETE FROM rooms WHERE label=?', [$label]);
    }

    public function addRoomsFromCsv(string $csv): void
    {
        $lines = $this->parseCsvLines($csv);
        foreach ($lines as $cols) {
            if (count($cols) < 2) {
                continue;
            }
            [$label, $minimumLevel] = $cols;
            $this->db->execute(
                'INSERT INTO rooms (label, minimum_level) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE minimum_level = VALUES(minimum_level)',
                [trim($label), (int)$minimumLevel]
            );
        }
    }

    // -----------------------------------------------------------------------
    // Classes
    // -----------------------------------------------------------------------

    public function addClass(string $label, int $grade): int
    {
        $this->db->execute(
            'INSERT IGNORE INTO classes (label, grade) VALUES (?, ?)',
            [$label, $grade]
        );
        return (int)$this->db->lastInsertId();
    }

    public function editClass(int $id, string $label, int $grade): void
    {
        $this->db->execute(
            'UPDATE classes SET label = ?, grade = ? WHERE id = ?',
            [$label, $grade, $id]
        );
    }

    public function deleteClass(int $id): void
    {
        $this->db->execute('DELETE FROM classes WHERE id = ?', [$id]);
    }

    // -----------------------------------------------------------------------
    // Subjects
    // -----------------------------------------------------------------------

    public function addSubject(string $name, ?string $color = null): void
    {
        $max = (int)($this->db->fetch('SELECT COALESCE(MAX(sort_order), 0) AS m FROM subjects')['m'] ?? 0);
        $this->db->execute(
            'INSERT IGNORE INTO subjects (name, color, sort_order) VALUES (?, ?, ?)',
            [$name, $color, $max + 10]
        );
    }

    public function editSubject(int $id, string $name, ?string $color = null): void
    {
        $this->db->execute('UPDATE subjects SET name = ?, color = ? WHERE id = ?', [$name, $color, $id]);
    }

    public function deleteSubject(int $id): void
    {
        $this->db->execute('DELETE FROM subjects WHERE id = ?', [$id]);
    }

    // -----------------------------------------------------------------------
    // Teacher room
    // -----------------------------------------------------------------------

    public function updateTeacherRoom(int $teacherId, ?string $room): void
    {
        $this->db->execute(
            'UPDATE teachers SET current_room = ? WHERE id = ?',
            [$room ?: null, $teacherId]
        );
    }

    // -----------------------------------------------------------------------
    // Teacher Lerngruppen
    // -----------------------------------------------------------------------

    public function addLerngruppe(int $teacherId, int $classId, int $subjectId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO teacher_lerngruppen (teacher_id, class_id, subject_id) VALUES (?, ?, ?)',
            [$teacherId, $classId, $subjectId]
        );
    }

    public function removeLerngruppe(int $teacherId, int $classId, int $subjectId): void
    {
        $this->db->execute(
            'DELETE FROM teacher_lerngruppen WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
            [$teacherId, $classId, $subjectId]
        );
    }

    public function getTeacherProfileData(int $teacherId): ?array
    {
        $row = $this->db->fetch(
            'SELECT id, first_name, last_name, email, benutzername, current_room AS room FROM teachers WHERE id = ?',
            [$teacherId]
        );
        if (!$row) return null;

        $lerngruppen = $this->db->fetchAll(
            'SELECT c.id AS classId, c.label AS classLabel, c.grade AS classGrade,
                    s.id AS subjectId, s.name AS subjectName
             FROM teacher_lerngruppen tl
             JOIN classes c ON tl.class_id = c.id
             JOIN subjects s ON tl.subject_id = s.id
             WHERE tl.teacher_id = ?
             ORDER BY c.grade, c.label, s.sort_order, s.name',
            [$teacherId]
        );

        $availableClasses  = $this->db->fetchAll('SELECT id, label, grade FROM classes ORDER BY grade, label');
        $availableSubjects = $this->db->fetchAll('SELECT id, name FROM subjects ORDER BY sort_order, name');
        $availableRooms    = array_map(fn($r) => [
            'label'        => $r['label'],
            'minimumLevel' => $r['minimumLevel'],
            'studentCount' => (int)$r['studentCount'],
        ], $this->db->fetchAll(
            'SELECT r.label, r.minimum_level AS minimumLevel, COUNT(s.id) AS studentCount
             FROM rooms r LEFT JOIN students s ON s.current_room = r.label
             GROUP BY r.label, r.minimum_level ORDER BY r.label'
        ));

        return [
            'id'               => (int)$row['id'],
            'firstName'        => $row['first_name'],
            'lastName'         => $row['last_name'],
            'email'            => $row['email'],
            'benutzername'     => $row['benutzername'],
            'room'             => $row['room'],
            'lerngruppen'      => $lerngruppen,
            'availableClasses' => $availableClasses,
            'availableSubjects'=> $availableSubjects,
            'availableRooms'   => $availableRooms,
        ];
    }

    // -----------------------------------------------------------------------
    // CSV Import: preview & execute
    // -----------------------------------------------------------------------

    /** Export all rows of the given type as a CSV string (with header row). */
    public function exportToCsv(string $type): string
    {
        [$headers, $rows] = match ($type) {
            'students' => [
                ['ID', 'Vorname', 'Nachname', 'E-Mail', 'Klasse', 'Abschlussstufe', 'Benutzername'],
                $this->db->fetchAll(
                    'SELECT s.id, s.first_name, s.last_name, s.email, c.label, s.graduation_level, s.benutzername
                     FROM students s JOIN classes c ON s.class = c.id ORDER BY s.id'
                ),
            ],
            'teachers' => [
                ['ID', 'Vorname', 'Nachname', 'E-Mail', 'Benutzername'],
                $this->db->fetchAll('SELECT id, first_name, last_name, email, benutzername FROM teachers ORDER BY id'),
            ],
            'parents' => [
                ['E-Mail', 'Benutzername', 'Schüler-IDs'],
                array_map(function (array $p) {
                    $ids = array_column(
                        $this->db->fetchAll('SELECT student_id FROM parent_student WHERE parent_id = ?', [$p['id']]),
                        'student_id'
                    );
                    return [$p['email'], $p['benutzername'] ?? '', implode(';', $ids)];
                }, $this->db->fetchAll('SELECT id, email, benutzername FROM parents ORDER BY email')),
            ],
            'classes'  => [
                ['Bezeichnung', 'Klassenstufe'],
                $this->db->fetchAll('SELECT label, grade FROM classes ORDER BY grade, label'),
            ],
            'subjects' => [
                ['Name'],
                $this->db->fetchAll('SELECT name FROM subjects ORDER BY name'),
            ],
            'rooms' => [
                ['Raumname', 'Mindestlevel'],
                $this->db->fetchAll('SELECT label, minimum_level FROM rooms ORDER BY label'),
            ],
            'aufgabensets' => [
                ['ID', 'Schuljahr', 'Fach', 'Klasse', 'Klassenstufe', 'Name', 'Max. Punkte', 'Aktiv', 'Bestehensmodus'],
                $this->db->fetchAll(
                    'SELECT ts.id, ts.school_year, s.name AS subject, COALESCE(c.label, "") AS class_label,
                            ts.grade, ts.name, ts.max_points, ts.active, ts.is_pass_fail
                     FROM lg_tasksets ts
                     JOIN subjects s ON ts.subject_id = s.id
                     LEFT JOIN classes c ON ts.class_id = c.id
                     ORDER BY ts.school_year DESC, s.name, c.label, ts.name'
                ),
            ],
            default => throw new InvalidArgumentException("Unbekannter Exporttyp: {$type}"),
        };

        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($v) {
                $v = (string)$v;
                return str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")
                    ? '"' . str_replace('"', '""', $v) . '"'
                    : $v;
            }, array_values($row)));
        }
        return implode("\n", $lines) . "\n";
    }

    /** Expected column headers for each import type. */
    public static function importColumns(string $type): array
    {
        return match ($type) {
            'students' => ['ID', 'Vorname', 'Nachname', 'E-Mail', 'Klasse', 'Abschlussstufe'],
            'teachers' => ['ID', 'Vorname', 'Nachname', 'E-Mail'],  // ID required (linked to O365)
            'classes'  => ['Bezeichnung', 'Klassenstufe'],
            'subjects' => ['Name'],
            'rooms'    => ['Raumname', 'Mindestlevel'],
            'parents'       => ['E-Mail'],  // Schüler-IDs is optional — not listed here (used for template)
            'aufgabensets'  => ['ID', 'Schuljahr', 'Fach', 'Klasse', 'Klassenstufe', 'Name', 'Max. Punkte', 'Aktiv', 'Bestehensmodus'],
            default    => throw new InvalidArgumentException("Unbekannter Importtyp: {$type}"),
        };
    }

    /**
     * Validate a CSV string and return a preview of what would be imported —
     * without writing to the database.
     * Returns: { columns, rows: [{status, data, changes, errors}], summary }
     */
    public function previewImport(string $type, string $csv): array
    {
        self::importColumns($type); // validate type early

        $lines = $this->parseRawCsv($csv);
        if (!empty($lines) && $this->isHeaderRow($lines[0])) {
            $lines = array_slice($lines, 1);
        }

        // Detect optional columns for parents: col 1 = Benutzername, col 2 = Schüler-IDs.
        // Presence is structural (column exists in the row), not value-based — a column that's
        // present but blank on every row must still show up so row/header widths stay aligned.
        $hasBenutzername = match ($type) {
            'students' => !empty(array_filter($lines, fn($l) => array_key_exists(6, $l))),
            'teachers' => !empty(array_filter($lines, fn($l) => array_key_exists(4, $l))),
            'parents'  => !empty(array_filter($lines, fn($l) => array_key_exists(1, $l))),
            default    => false,
        };
        $hasStudentIds = $type === 'parents' &&
            !empty(array_filter($lines, fn($l) => array_key_exists(2, $l)));

        // For aufgabensets: read current scope setting and pass to each row preview
        $taskScope = $type === 'aufgabensets'
            ? AppSettings::getInstance($this->db)->get('task_scope', 'grade')
            : 'grade';

        $rows = array_map(fn($cols) => match ($type) {
            'students'     => $this->previewStudentRow($cols),
            'teachers'     => $this->previewTeacherRow($cols),
            'classes'      => $this->previewClassRow($cols),
            'subjects'     => $this->previewSubjectRow($cols),
            'rooms'        => $this->previewRoomRow($cols),
            'parents'      => $this->previewParentRow($cols, $hasStudentIds),
            'aufgabensets' => $this->previewAufgabensetRow($cols, $taskScope),
        }, $lines);

        // Mark within-batch duplicates as invalid
        $dupError = match ($type) {
            'students'     => 'ID mehrfach in dieser Datei vorhanden',
            'teachers'     => 'ID mehrfach in dieser Datei vorhanden',
            'classes'      => 'Bezeichnung mehrfach in dieser Datei vorhanden',
            'subjects'     => 'Name mehrfach in dieser Datei vorhanden',
            'rooms'        => 'Raumname mehrfach in dieser Datei vorhanden',
            'parents'      => 'E-Mail mehrfach in dieser Datei vorhanden',
            'aufgabensets' => 'ID mehrfach in dieser Datei vorhanden',
        };
        $seenKeys = [];
        foreach ($rows as $i => &$row) {
            if ($row['status'] === 'invalid') continue;
            $c   = array_map('trim', $lines[$i]);
            $key = match ($type) {
                'students' => $c[0] ?? null,
                'teachers' => $c[0] ?? null,
                default    => $c[0] ?? null,
            };
            if ($key === null || $key === '') continue;
            if (isset($seenKeys[$key])) {
                $row = ['data' => $row['data'], 'changes' => [], 'status' => 'invalid',
                        'errors' => [$dupError]];
            } else {
                $seenKeys[$key] = true;
            }
        }
        unset($row);

        // Within-batch email uniqueness (catches two new rows with the same email before DB checks can)
        $emailCol = match ($type) {
            'students', 'teachers' => 3,
            'parents'              => 0,
            default                => null,
        };
        if ($emailCol !== null) {
            $seenEmails = [];
            foreach ($rows as $i => &$row) {
                if ($row['status'] === 'invalid') continue;
                $email = trim($lines[$i][$emailCol] ?? '');
                if ($email === '') continue;
                if (isset($seenEmails[$email])) {
                    $row = ['data' => $row['data'], 'changes' => [], 'status' => 'invalid',
                            'errors' => ['E-Mail mehrfach in dieser Datei vorhanden']];
                } else {
                    $seenEmails[$email] = true;
                }
            }
            unset($row);
        }

        // Within-batch Benutzername uniqueness
        $bnCol = match ($type) {
            'students' => 6,
            'teachers' => 4,
            'parents'  => 1,
            default    => null,
        };
        if ($bnCol !== null) {
            $seenBns = [];
            foreach ($rows as $i => &$row) {
                if ($row['status'] === 'invalid') continue;
                $bn = trim($lines[$i][$bnCol] ?? '');
                if ($bn === '') continue;
                if (isset($seenBns[$bn])) {
                    $row = ['data' => $row['data'], 'changes' => [], 'status' => 'invalid',
                            'errors' => ["Benutzername \"{$bn}\" mehrfach in dieser Datei vorhanden"]];
                } else {
                    $seenBns[$bn] = true;
                }
            }
            unset($row);
        }

        // Build column header list to return (base columns + optional ones present in data)
        $columns = self::importColumns($type);
        if ($hasBenutzername) $columns[] = 'Benutzername';
        if ($hasStudentIds)   $columns[] = 'Schüler-IDs';

        // Ragged CSVs (rows shorter than others) must not desync the preview table's
        // columns from its data — pad every row to the full column count.
        $colCount = count($columns);
        foreach ($rows as &$row) {
            if (count($row['data']) < $colCount) {
                $row['data'] = array_pad($row['data'], $colCount, '');
            }
        }
        unset($row);

        return [
            'columns' => $columns,
            'rows'    => $rows,
            'summary' => [
                'new'      => count(array_filter($rows, fn($r) => $r['status'] === 'new')),
                'update'   => count(array_filter($rows, fn($r) => $r['status'] === 'update')),
                'existing' => count(array_filter($rows, fn($r) => $r['status'] === 'existing')),
                'invalid'  => count(array_filter($rows, fn($r) => $r['status'] === 'invalid')),
            ],
        ];
    }

    /**
     * Execute an import for a set of pre-validated rows.
     * $rows: array of raw column arrays matching the type's expected format.
     * Returns: { imported, updated, passwords }
     */
    public function executeImport(string $type, array $rows): array
    {
        $imported = 0;
        $updated  = 0;

        foreach ($rows as $cols) {
            if (!is_array($cols)) continue;
            $result = match ($type) {
                'students' => $this->importStudentRow($cols),
                'teachers' => $this->importTeacherRow($cols),
                'classes'  => $this->importClassRow($cols),
                'subjects' => $this->importSubjectRow($cols),
                'rooms'        => $this->importRoomRow($cols),
                'parents'      => $this->importParentRow($cols),
                'aufgabensets' => $this->importAufgabensetRow($cols),
                default        => null,
            };
            if (!$result) continue;
            if ($result['action'] === 'new')    $imported++;
            elseif ($result['action'] === 'update') $updated++;
        }

        return ['imported' => $imported, 'updated' => $updated];
    }

    // ---- Preview helpers (read-only) ----

    private function previewStudentRow(array $cols): array
    {
        $cols = array_map('trim', $cols);
        $base = ['data' => $cols, 'changes' => []];

        if (count($cols) < 6) {
            return $base + ['status' => 'invalid', 'errors' => ['Zu wenige Spalten (erwartet: 6)']];
        }
        [$id, $firstName, $lastName, $email, $classLabel, $graduationLevel] = $cols;
        $bnProvided = array_key_exists(6, $cols);
        $bn = $cols[6] ?? '';
        $errors = [];

        if (!is_numeric($id) || (int)$id <= 0)         $errors[] = 'ID muss eine positive Zahl sein';
        if ($firstName === '')                           $errors[] = 'Vorname fehlt';
        if ($lastName === '')                            $errors[] = 'Nachname fehlt';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige E-Mail-Adresse';
        elseif ($this->db->fetch('SELECT id FROM students WHERE email = ? AND id != ?', [$email, (int)$id]))
                                                         $errors[] = 'E-Mail bereits einem anderen Schüler zugewiesen';
        elseif (($conflict = $this->emailUsedElsewhere($email, 'students')) !== null)
                                                         $errors[] = "E-Mail bereits verknüpft mit {$conflict}";
        if ($bn !== '' && $this->benutzernameTakenExcluding($bn, 'students', (int)$id))
                                                         $errors[] = "Benutzername \"{$bn}\" bereits vergeben";
        $class = $this->db->fetch('SELECT id, label FROM classes WHERE label = ? LIMIT 1', [$classLabel]);
        if (!$class)                                     $errors[] = "Klasse \"{$classLabel}\" nicht gefunden";
        $gl = (int)$graduationLevel;
        if ($gl < 0 || $gl > 3)                         $errors[] = 'Abschlussstufe muss 0–3 sein';

        if ($errors) return $base + ['status' => 'invalid', 'errors' => $errors];

        $existing = $this->db->fetch(
            'SELECT s.id, s.first_name, s.last_name, s.email, s.graduation_level, s.benutzername, c.label AS classLabel
             FROM students s JOIN classes c ON s.class = c.id WHERE s.id = ?',
            [(int)$id]
        );
        if (!$existing) return $base + ['status' => 'new'];

        $changes = [];
        if ($existing['first_name']            !== $firstName)  $changes['Vorname']        = $existing['first_name'];
        if ($existing['last_name']             !== $lastName)   $changes['Nachname']       = $existing['last_name'];
        if ($existing['email']                 !== $email)      $changes['E-Mail']         = $existing['email'];
        if ($existing['classLabel']            !== $classLabel) $changes['Klasse']         = $existing['classLabel'];
        if ((int)$existing['graduation_level'] !== $gl)        $changes['Abschlussstufe'] = $existing['graduation_level'];
        if ($bnProvided && ($existing['benutzername'] ?? '') !== $bn)
                                                                $changes['Benutzername']   = $existing['benutzername'] ?? '(keiner)';

        if (empty($changes)) return $base + ['status' => 'existing'];
        return ['status' => 'update', 'data' => $cols, 'changes' => $changes, 'errors' => []];
    }

    private function previewTeacherRow(array $cols): array
    {
        $cols = array_map('trim', $cols);
        $base = ['data' => $cols, 'changes' => []];

        if (count($cols) < 4) {
            return $base + ['status' => 'invalid', 'errors' => ['Zu wenige Spalten (erwartet: 4)']];
        }
        [$id, $firstName, $lastName, $email] = array_slice($cols, 0, 4);
        $bnProvided = array_key_exists(4, $cols);
        $bn = $cols[4] ?? '';
        $errors = [];
        if (!is_numeric($id) || (int)$id <= 0)          $errors[] = 'ID muss eine positive Zahl sein';
        if ($firstName === '')                            $errors[] = 'Vorname fehlt';
        if ($lastName === '')                             $errors[] = 'Nachname fehlt';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Ungültige E-Mail-Adresse';
        elseif ($this->db->fetch('SELECT id FROM teachers WHERE email = ? AND id != ?', [$email, (int)$id]))
                                                          $errors[] = 'E-Mail bereits einer anderen Lehrkraft zugewiesen';
        elseif (($conflict = $this->emailUsedElsewhere($email, 'teachers')) !== null)
                                                          $errors[] = "E-Mail bereits verknüpft mit {$conflict}";
        if ($bn !== '' && $this->benutzernameTakenExcluding($bn, 'teachers', (int)$id))
                                                          $errors[] = "Benutzername \"{$bn}\" bereits vergeben";
        if ($errors) return $base + ['status' => 'invalid', 'errors' => $errors];

        $existing = $this->db->fetch(
            'SELECT id, first_name, last_name, email, benutzername FROM teachers WHERE id = ?', [(int)$id]
        );
        if (!$existing) return $base + ['status' => 'new'];

        $changes = [];
        if ($existing['first_name']                   !== $firstName) $changes['Vorname']       = $existing['first_name'];
        if ($existing['last_name']                    !== $lastName)  $changes['Nachname']      = $existing['last_name'];
        if ($existing['email']                        !== $email)     $changes['E-Mail']        = $existing['email'];
        if ($bnProvided && ($existing['benutzername'] ?? '') !== $bn) $changes['Benutzername']  = $existing['benutzername'] ?? '(keiner)';

        if (empty($changes)) return $base + ['status' => 'existing'];
        return ['status' => 'update', 'data' => $cols, 'changes' => $changes, 'errors' => []];
    }

    private function previewClassRow(array $cols): array
    {
        $cols = array_map('trim', $cols);
        $base = ['data' => $cols, 'changes' => []];
        if (count($cols) < 2) {
            return $base + ['status' => 'invalid', 'errors' => ['Zu wenige Spalten (erwartet: 2)']];
        }
        [$label, $grade] = $cols;
        $errors = [];
        if ($label === '')                                  $errors[] = 'Bezeichnung fehlt';
        if (!is_numeric($grade) || (int)$grade < 1)        $errors[] = 'Klassenstufe muss eine positive Zahl sein';
        if ($errors) return $base + ['status' => 'invalid', 'errors' => $errors];

        $existing = $this->db->fetch(
            'SELECT id FROM classes WHERE label = ? AND grade = ?', [$label, (int)$grade]
        );
        return $base + ['status' => $existing ? 'existing' : 'new'];
    }

    private function previewSubjectRow(array $cols): array
    {
        $cols = array_map('trim', $cols);
        $base = ['data' => $cols, 'changes' => []];
        if (empty($cols) || $cols[0] === '') {
            return $base + ['status' => 'invalid', 'errors' => ['Name fehlt']];
        }
        $existing = $this->db->fetch('SELECT id FROM subjects WHERE name = ?', [$cols[0]]);
        return $base + ['status' => $existing ? 'existing' : 'new'];
    }

    private function previewRoomRow(array $cols): array
    {
        $cols = array_map('trim', $cols);
        $base = ['data' => $cols, 'changes' => []];
        if (count($cols) < 2) {
            return $base + ['status' => 'invalid', 'errors' => ['Zu wenige Spalten (erwartet: 2)']];
        }
        [$label, $minLevel] = $cols;
        $errors = [];
        if ($label === '')                                  $errors[] = 'Raumname fehlt';
        if (!is_numeric($minLevel) || (int)$minLevel < 0)  $errors[] = 'Mindestlevel muss eine nicht-negative Zahl sein';
        if ($errors) return $base + ['status' => 'invalid', 'errors' => $errors];

        $existing = $this->db->fetch(
            'SELECT label, minimum_level AS minLevel FROM rooms WHERE label = ?', [$label]
        );
        if (!$existing) return $base + ['status' => 'new'];
        if ((int)$existing['minLevel'] !== (int)$minLevel) {
            return ['status' => 'update', 'data' => $cols, 'changes' => ['Mindestlevel' => $existing['minLevel']], 'errors' => []];
        }
        return $base + ['status' => 'existing'];
    }

    // ---- Execute helpers (write to DB) ----

    private function importStudentRow(array $cols): ?array
    {
        $cols = array_map('trim', $cols);
        if (count($cols) < 6) return null;
        [$id, $firstName, $lastName, $email, $classLabel, $graduationLevel] = $cols;
        $bnProvided = array_key_exists(6, $cols);
        $bn = $cols[6] ?? '';
        if (!is_numeric($id) || (int)$id <= 0) return null;
        $class = $this->db->fetch('SELECT id FROM classes WHERE label = ? LIMIT 1', [$classLabel]);
        if (!$class) return null;

        $id = (int)$id;
        $gl = max(0, min(3, (int)$graduationLevel));

        if ($this->db->fetch('SELECT id FROM students WHERE id = ?', [$id])) {
            $sql    = 'UPDATE students SET first_name=?, last_name=?, email=?, class=?, graduation_level=?';
            $params = [$firstName, $lastName, $email, $class['id'], $gl];
            // A present-but-blank Benutzername column explicitly clears it; an absent column leaves it untouched.
            if ($bnProvided) { $sql .= ', benutzername=?'; $params[] = $bn !== '' ? $bn : null; }
            $this->db->execute($sql . ' WHERE id=?', [...$params, $id]);
            return ['action' => 'update'];
        }

        $this->db->execute(
            'INSERT INTO students (id, first_name, last_name, email, password, benutzername, class, graduation_level)
             VALUES (?,?,?,?,NULL,?,?,?)',
            [$id, $firstName, $lastName, $email, $bn !== '' ? $bn : null, $class['id'], $gl]
        );
        return ['action' => 'new'];
    }

    private function importTeacherRow(array $cols): ?array
    {
        $cols = array_map('trim', $cols);
        if (count($cols) < 4) return null;
        [$id, $firstName, $lastName, $email] = array_slice($cols, 0, 4);
        $bnProvided = array_key_exists(4, $cols);
        $bn = $cols[4] ?? '';
        if (!is_numeric($id) || (int)$id <= 0) return null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        $id = (int)$id;
        if ($this->db->fetch('SELECT id FROM teachers WHERE id = ?', [$id])) {
            $sql    = 'UPDATE teachers SET first_name=?, last_name=?, email=?';
            $params = [$firstName, $lastName, $email];
            // A present-but-blank Benutzername column explicitly clears it; an absent column leaves it untouched.
            if ($bnProvided) { $sql .= ', benutzername=?'; $params[] = $bn !== '' ? $bn : null; }
            $this->db->execute($sql . ' WHERE id=?', [...$params, $id]);
            return ['action' => 'update'];
        }

        $this->db->execute(
            'INSERT INTO teachers (id, first_name, last_name, email, password, benutzername) VALUES (?,?,?,?,NULL,?)',
            [$id, $firstName, $lastName, $email, $bn !== '' ? $bn : null]
        );
        return ['action' => 'new'];
    }

    private function importClassRow(array $cols): ?array
    {
        $cols = array_map('trim', $cols);
        if (count($cols) < 2 || $cols[0] === '' || !is_numeric($cols[1])) return null;
        if ($this->db->fetch('SELECT id FROM classes WHERE label = ? AND grade = ?', [$cols[0], (int)$cols[1]])) {
            return ['action' => 'existing'];
        }
        $this->db->execute('INSERT INTO classes (label, grade) VALUES (?,?)', [$cols[0], (int)$cols[1]]);
        return ['action' => 'new'];
    }

    private function importSubjectRow(array $cols): ?array
    {
        $cols = array_map('trim', $cols);
        if (empty($cols) || $cols[0] === '') return null;
        if ($this->db->fetch('SELECT id FROM subjects WHERE name = ?', [$cols[0]])) {
            return ['action' => 'existing'];
        }
        $max = (int)($this->db->fetch('SELECT COALESCE(MAX(sort_order), 0) AS m FROM subjects')['m'] ?? 0);
        $this->db->execute('INSERT INTO subjects (name, sort_order) VALUES (?, ?)', [$cols[0], $max + 10]);
        return ['action' => 'new'];
    }

    private function importRoomRow(array $cols): ?array
    {
        $cols = array_map('trim', $cols);
        if (count($cols) < 2 || $cols[0] === '' || !is_numeric($cols[1])) return null;
        if ($this->db->fetch('SELECT label FROM rooms WHERE label = ?', [$cols[0]])) {
            $this->db->execute('UPDATE rooms SET minimum_level=? WHERE label=?', [(int)$cols[1], $cols[0]]);
            return ['action' => 'update'];
        }
        $this->db->execute('INSERT INTO rooms (label, minimum_level) VALUES (?,?)', [$cols[0], (int)$cols[1]]);
        return ['action' => 'new'];
    }

    private function previewParentRow(array $cols, bool $hasStudentIds): array
    {
        $cols  = array_map('trim', $cols);
        $email = $cols[0] ?? '';
        $bnProvided = array_key_exists(1, $cols);
        $bn    = $cols[1] ?? '';   // col 1 = optional benutzername
        $base  = ['data' => $cols, 'changes' => []];

        $existing = $this->db->fetch('SELECT id, benutzername FROM parents WHERE email = ?', [$email]);
        $errors = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Ungültige E-Mail-Adresse';
        } elseif (($conflict = $this->emailUsedElsewhere($email, 'parents')) !== null) {
            $errors[] = "E-Mail bereits verknüpft mit {$conflict}";
        }
        if ($bn !== '' && $this->benutzernameTakenExcluding($bn, 'parents', (int)($existing['id'] ?? -1))) {
            $errors[] = "Benutzername \"{$bn}\" bereits vergeben";
        }

        // Validate optional student IDs (now col 2)
        $studentIds = [];
        if ($hasStudentIds) {
            foreach (preg_split('/[;\s]+/', $cols[2] ?? '') as $sid) {
                $sid = trim($sid);
                if ($sid === '') continue;
                if (!ctype_digit($sid)) { $errors[] = "Ungültige Schüler-ID: {$sid}"; continue; }
                if (!$this->db->fetch('SELECT id FROM students WHERE id = ?', [(int)$sid])) {
                    $errors[] = "Schüler-ID {$sid} nicht gefunden";
                    continue;
                }
                $studentIds[] = (int)$sid;
            }
        }

        if ($errors) return $base + ['status' => 'invalid', 'errors' => $errors];
        if (!$existing) return $base + ['status' => 'new'];

        $changes = [];
        if ($bnProvided && ($existing['benutzername'] ?? '') !== $bn) {
            $changes['Benutzername'] = $existing['benutzername'] ?? '(keiner)';
        }
        if ($hasStudentIds && !empty($studentIds)) {
            $currentIds = array_map('intval', array_column(
                $this->db->fetchAll('SELECT student_id FROM parent_student WHERE parent_id = ?', [$existing['id']]),
                'student_id'
            ));
            sort($currentIds); sort($studentIds);
            if ($currentIds !== $studentIds) {
                $changes['Schüler-IDs'] = implode(';', $currentIds);
            }
        }

        if (empty($changes)) return $base + ['status' => 'existing'];
        return ['status' => 'update', 'data' => $cols, 'changes' => $changes, 'errors' => []];
    }

    private function importParentRow(array $cols): ?array
    {
        $cols  = array_map('trim', $cols);
        $email = $cols[0] ?? '';
        $bnProvided = array_key_exists(1, $cols);
        $bn    = $cols[1] ?? '';   // col 1 = optional benutzername
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        $existing = $this->db->fetch('SELECT id FROM parents WHERE email = ?', [$email]);
        if ($existing) {
            $parentId = (int)$existing['id'];
            $sql    = 'UPDATE parents SET email=?';
            $params = [$email];
            // A present-but-blank Benutzername column explicitly clears it; an absent column leaves it untouched.
            if ($bnProvided) { $sql .= ', benutzername=?'; $params[] = $bn !== '' ? $bn : null; }
            $this->db->execute($sql . ' WHERE id=?', [...$params, $parentId]);
            $action = 'update';
        } else {
            $this->db->execute(
                'INSERT INTO parents (email, password, benutzername) VALUES (?, NULL, ?)',
                [$email, $bn !== '' ? $bn : null]
            );
            $parentId = (int)$this->db->lastInsertId();
            $action   = 'new';
        }

        // Link students from optional col 2 (semicolon- or space-separated IDs)
        if (isset($cols[2]) && $cols[2] !== '') {
            foreach (preg_split('/[;\s]+/', $cols[2]) as $sid) {
                $sid = trim($sid);
                if (!ctype_digit($sid)) continue;
                $this->db->execute(
                    'INSERT IGNORE INTO parent_student (parent_id, student_id) VALUES (?, ?)',
                    [$parentId, (int)$sid]
                );
            }
        }

        return ['action' => $action, 'email' => $email];
    }

    private function previewAufgabensetRow(array $cols, string $taskScope): array
    {
        $cols       = array_map('trim', $cols);
        $base       = ['data' => $cols, 'changes' => []];
        $id         = $cols[0] ?? '';
        $schoolYear = $cols[1] ?? '';
        $subjectName= $cols[2] ?? '';
        $classLabel = $cols[3] ?? '';
        $grade      = $cols[4] ?? '';
        $name       = $cols[5] ?? '';
        $maxPoints  = $cols[6] ?? '';
        $active     = $cols[7] ?? '';
        $isPassFail = $cols[8] ?? '';

        $errors = [];

        // Row scope must match current setting
        $rowIsClass = $classLabel !== '';
        if ($rowIsClass && $taskScope !== 'class') {
            $errors[] = 'Klassenspezifisch, aber aktuelle Einstellung ist „Stufenspezifisch"';
        } elseif (!$rowIsClass && $taskScope !== 'grade') {
            $errors[] = 'Stufenspezifisch, aber aktuelle Einstellung ist „Klassenspezifisch"';
        }

        $subject = $this->db->fetch('SELECT id FROM subjects WHERE name = ?', [$subjectName]);
        if (!$subject) $errors[] = "Fach \"{$subjectName}\" nicht gefunden";

        $classRow = null;
        if ($rowIsClass) {
            $classRow = $this->db->fetch('SELECT id, grade FROM classes WHERE label = ?', [$classLabel]);
            if (!$classRow) {
                $errors[] = "Klasse \"{$classLabel}\" nicht gefunden";
            } else {
                if (is_numeric($grade) && (int)$classRow['grade'] !== (int)$grade) {
                    $errors[] = "Klassenstufe {$grade} stimmt nicht mit Klasse \"{$classLabel}\" überein (Stufe {$classRow['grade']})";
                }
                if ($subject && !$this->db->fetch(
                    'SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?',
                    [(int)$classRow['id'], (int)$subject['id']]
                )) {
                    $errors[] = "Klasse \"{$classLabel}\" hat das Fach \"{$subjectName}\" nicht zugewiesen";
                }
            }
        }

        if (!is_numeric($grade) || (int)$grade < 0) $errors[] = "Ungültige Klassenstufe: {$grade}";
        if ($name === '')                             $errors[] = 'Name darf nicht leer sein';
        if (!is_numeric($maxPoints) || (int)$maxPoints < 1) $errors[] = "Ungültige Max. Punkte: {$maxPoints}";
        if (!in_array($active, ['0','1'], true))     $errors[] = "Aktiv muss 0 oder 1 sein";
        if (!in_array($isPassFail, ['0','1'], true)) $errors[] = "Bestehensmodus muss 0 oder 1 sein";

        if ($errors) return $base + ['status' => 'invalid', 'errors' => $errors];

        // Check if this ID already exists
        if ($id !== '' && is_numeric($id)) {
            $existing = $this->db->fetch(
                'SELECT ts.name, ts.max_points, ts.active, ts.is_pass_fail, ts.school_year
                 FROM lg_tasksets ts WHERE ts.id = ?',
                [(int)$id]
            );
            if ($existing) {
                $changes = [];
                if ($existing['name']        !== $name)          $changes['Name']          = $existing['name'];
                if ((int)$existing['max_points'] !== (int)$maxPoints) $changes['Max. Punkte']   = $existing['max_points'];
                if ((int)$existing['active']     !== (int)$active)    $changes['Aktiv']         = $existing['active'];
                if ((int)$existing['is_pass_fail'] !== (int)$isPassFail) $changes['Bestehensmodus'] = $existing['is_pass_fail'];
                if ($existing['school_year'] !== $schoolYear)   $changes['Schuljahr']     = $existing['school_year'];
                return empty($changes)
                    ? $base + ['status' => 'existing']
                    : ['status' => 'update', 'data' => $cols, 'changes' => $changes, 'errors' => []];
            }
        }

        return $base + ['status' => 'new'];
    }

    private function importAufgabensetRow(array $cols): ?array
    {
        $cols       = array_map('trim', $cols);
        if (count($cols) < 9) return null;
        $id         = $cols[0];
        $schoolYear = $cols[1];
        $subjectName= $cols[2];
        $classLabel = $cols[3];
        $grade      = (int)$cols[4];
        $name       = $cols[5];
        $maxPoints  = max(1, (int)$cols[6]);
        $active     = (int)$cols[7];
        $isPassFail = (int)$cols[8];

        $subject = $this->db->fetch('SELECT id FROM subjects WHERE name = ?', [$subjectName]);
        if (!$subject) return null;
        $subjectId = (int)$subject['id'];

        $classId = null;
        if ($classLabel !== '') {
            $classRow = $this->db->fetch('SELECT id FROM classes WHERE label = ?', [$classLabel]);
            if (!$classRow) return null;
            $classId = (int)$classRow['id'];
            if (!$this->db->fetch(
                'SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?',
                [$classId, $subjectId]
            )) return null;
        }

        // Update existing record if ID matches
        if ($id !== '' && is_numeric($id)) {
            $existing = $this->db->fetch('SELECT id FROM lg_tasksets WHERE id = ?', [(int)$id]);
            if ($existing) {
                $this->db->execute(
                    'UPDATE lg_tasksets SET name=?, max_points=?, active=?, is_pass_fail=?, school_year=? WHERE id=?',
                    [$name, $maxPoints, $active, $isPassFail, $schoolYear, (int)$id]
                );
                return ['action' => 'update'];
            }
        }

        // Insert new
        $this->db->execute(
            'INSERT INTO lg_tasksets (class_id, subject_id, grade, name, max_points, active, school_year, is_pass_fail)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$classId, $subjectId, $grade, $name, $maxPoints, $active, $schoolYear, $isPassFail]
        );
        return ['action' => 'new'];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(6)); // 12-char hex string
    }

    /**
     * Check whether an email is already registered in a different user table.
     * Returns a human-readable label of the conflicting account type, or null.
     */
    private function benutzernameTaken(string $bn): bool
    {
        foreach (['students', 'teachers', 'parents'] as $table) {
            if ($this->db->fetch("SELECT id FROM {$table} WHERE benutzername = ?", [$bn])) {
                return true;
            }
        }
        return false;
    }

    private function benutzernameTakenExcluding(string $bn, string $excludeTable, int $excludeId, ?string $excludeAdminUsername = null): bool
    {
        $adminRow = $this->db->fetch('SELECT username FROM admins WHERE benutzername = ?', [$bn]);
        if ($adminRow && $adminRow['username'] !== $excludeAdminUsername) return true;
        foreach (['students', 'teachers', 'parents'] as $table) {
            $row = $table === $excludeTable
                ? $this->db->fetch("SELECT id FROM {$table} WHERE benutzername = ? AND id != ?", [$bn, $excludeId])
                : $this->db->fetch("SELECT id FROM {$table} WHERE benutzername = ?", [$bn]);
            if ($row) return true;
        }
        return false;
    }

    private function emailExists(string $email): bool
    {
        foreach (['students', 'teachers', 'parents'] as $table) {
            if ($this->db->fetch("SELECT id FROM {$table} WHERE email = ?", [$email])) {
                return true;
            }
        }
        return false;
    }

    /** Like emailExists but ignores a specific record (used when updating). */
    private function emailExistsExcluding(string $email, int $excludeId, string $excludeTable): bool
    {
        foreach (['students', 'teachers', 'parents'] as $table) {
            $row = $table === $excludeTable
                ? $this->db->fetch("SELECT id FROM {$table} WHERE email = ? AND id != ?", [$email, $excludeId])
                : $this->db->fetch("SELECT id FROM {$table} WHERE email = ?", [$email]);
            if ($row) {
                return true;
            }
        }
        return false;
    }

    private function emailUsedElsewhere(string $email, string $ownTable): ?string
    {
        $others = [
            'students' => 'einem Schülerkonto',
            'teachers' => 'einem Lehrerkonto',
            'parents'  => 'einem Elternkonto',
        ];
        foreach ($others as $table => $label) {
            if ($table === $ownTable) continue;
            if ($this->db->fetch("SELECT id FROM {$table} WHERE email = ?", [$email])) {
                return $label;
            }
        }
        return null;
    }

    /** Parse all non-empty CSV lines without any header skipping. */
    private function parseRawCsv(string $csv): array
    {
        $result = [];
        foreach (preg_split('/\r?\n/', trim($csv)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $result[] = str_getcsv($line, ',', '"', '');
            }
        }
        return $result;
    }

    /** Return true if a CSV row looks like a header row (first cell matches a known column name). */
    private function isHeaderRow(array $cols): bool
    {
        static $known = ['id', 'vorname', 'nachname', 'e-mail', 'email', 'klasse', 'abschlussstufe',
                         'bezeichnung', 'klassenstufe', 'name', 'raumname', 'mindestlevel', 'schüler-ids'];
        return in_array(strtolower(trim($cols[0] ?? '')), $known, true);
    }

    /** Parse CSV text into rows, skipping blank lines and header if first col is non-numeric for students. */
    private function parseCsvLines(string $csv): array
    {
        $lines  = preg_split('/\r?\n/', trim($csv));
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, ',', '"', '');
            // Skip header rows (first column is a string label, not a number or an ID)
            if (!is_numeric($cols[0]) && preg_match('/^[A-Za-zÄÖÜäöüß]/u', $cols[0])) {
                // Could be a header — skip only if it looks like "ID", "Vorname", etc.
                if (in_array(strtolower($cols[0]), ['id', 'vorname', 'first', 'name', 'raumname'], true)) {
                    continue;
                }
            }
            $result[] = $cols;
        }

        return $result;
    }

    private static function csvRow(array $fields): string
    {
        $escaped = array_map(function ($v): string {
            $v = (string)$v;
            if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
                return '"' . str_replace('"', '""', $v) . '"';
            }
            return $v;
        }, $fields);
        return implode(',', $escaped) . "\n";
    }
}
