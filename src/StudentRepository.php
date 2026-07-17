<?php
declare(strict_types=1);

/**
 * All database queries related to students.
 *
 * Each method returns plain arrays suitable for json_encode().
 */
class StudentRepository
{
    private Database $db;
    private string $viewYear;

    public function __construct(Database $db, string $viewYear = '')
    {
        $this->db       = $db;
        $this->viewYear = $viewYear;
    }

    // -----------------------------------------------------------------------
    // Student lookup
    // -----------------------------------------------------------------------

    public function getById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT s.id, s.first_name, s.last_name, s.email, s.benutzername, s.graduation_level, s.current_room,
                    c.id AS class_id, c.label AS class_label, c.grade AS class_grade
             FROM students s
             JOIN classes c ON s.class = c.id
             WHERE s.id = ?',
            [$id]
        );
    }

    /**
     * Build the full student data object expected by the frontend JS.
     */
    public function getFullData(int $studentId): ?array
    {
        $row = $this->getById($studentId);
        if (!$row) {
            return null;
        }

        return [
            'id'              => $row['id'],
            'firstName'       => $row['first_name'],
            'lastName'        => $row['last_name'],
            'email'           => $row['email'],
            'graduationLevel' => (int)$row['graduation_level'],
            'schoolClass'     => [
                'id'    => (int)$row['class_id'],
                'label' => $row['class_label'],
                'grade' => (int)$row['class_grade'],
            ],
            'currentRoom'     => $row['current_room'] ? ['label' => $row['current_room']] : null,
        ];
    }

    // -----------------------------------------------------------------------
    // Profile
    // -----------------------------------------------------------------------

    /**
     * Returns all data needed to render the Schülerprofil page.
     */
    public function getProfileData(int $studentId): ?array
    {
        $row = $this->getById($studentId);
        if (!$row) {
            return null;
        }
        $grade = (int)$row['class_grade'];

        // Subjects the student is enrolled in -- gates task-set/grade visibility
        // on their dashboard and this profile view (see LerngruppeRepository).
        $subjects = $this->db->fetchAll(
            'SELECT sub.id, sub.name
             FROM student_topics st
             JOIN subjects sub ON st.subject_id = sub.id
             WHERE st.student_id = ?
             ORDER BY sub.name',
            [$studentId]
        );

        // Subjects available to enroll in (all subjects not yet enrolled)
        $available = $this->db->fetchAll(
            'SELECT s.id, s.name
             FROM subjects s
             WHERE s.id NOT IN (SELECT subject_id FROM student_topics WHERE student_id = ?)
             ORDER BY s.name',
            [$studentId]
        );

        return [
            'id'               => (int)$row['id'],
            'firstName'        => $row['first_name'],
            'lastName'         => $row['last_name'],
            'email'            => $row['email'],
            'benutzername'     => $row['benutzername'],
            'graduationLevel'  => (int)$row['graduation_level'],
            'class'            => [
                'id'    => (int)$row['class_id'],
                'label' => $row['class_label'],
                'grade' => $grade,
            ],
            'currentRoom'       => $row['current_room'],
            'subjects'          => $subjects,
            'availableSubjects' => $available,
        ];
    }

    /** Enroll a student in a subject (gates task-set/grade visibility for that subject). */
    public function enrollStudentInSubject(int $studentId, int $subjectId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO student_topics (student_id, subject_id) VALUES (?, ?)',
            [$studentId, $subjectId]
        );
    }

    /** Unenroll a student from a subject, hiding its task sets/grades from their dashboard and profile view. */
    public function unenrollStudentFromSubject(int $studentId, int $subjectId): void
    {
        $this->db->execute(
            'DELETE FROM student_topics WHERE student_id = ? AND subject_id = ?',
            [$studentId, $subjectId]
        );
    }

    // -----------------------------------------------------------------------
    // Room
    // -----------------------------------------------------------------------

    public function updateRoom(int $studentId, string $room): void
    {
        if ($room !== '') {
            $row = $this->db->fetch(
                'SELECT s.graduation_level AS level, r.minimum_level AS minLevel
                 FROM students s, rooms r
                 WHERE s.id = ? AND r.label = ?',
                [$studentId, $room]
            );
            if ($row && (int)$row['minLevel'] > (int)$row['level']) {
                throw new \RuntimeException('Dieser Raum erfordert ein höheres Lernlevel.');
            }
        }
        $this->db->execute(
            'UPDATE students SET current_room = ? WHERE id = ?',
            [$room !== '' ? $room : null, $studentId]
        );
    }

}
