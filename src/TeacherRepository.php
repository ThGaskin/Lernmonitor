<?php
declare(strict_types=1);

/**
 * All database queries related to teacher-facing views and actions.
 */
class TeacherRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
        try {
            $this->db->execute('ALTER TABLE subjects ADD COLUMN color VARCHAR(7) NULL DEFAULT NULL');
        } catch (\Throwable) { /* column already exists */ }
    }

    // -----------------------------------------------------------------------
    // Teacher's own classes and subjects
    // -----------------------------------------------------------------------

    public function getClassesForTeacher(int $teacherId): array
    {
        return $this->db->fetchAll(
            'SELECT c.id, c.label, c.grade
             FROM classes c
             JOIN teacher_classes tc ON tc.class_id = c.id
             WHERE tc.teacher_id = ?
             ORDER BY c.grade, c.label',
            [$teacherId]
        );
    }

    public function getSubjectsForTeacher(int $teacherId): array
    {
        return $this->db->fetchAll(
            'SELECT s.id, s.name
             FROM subjects s
             JOIN teacher_subjects ts ON ts.subject_id = s.id
             WHERE ts.teacher_id = ?
             ORDER BY s.sort_order, s.name',
            [$teacherId]
        );
    }

    // -----------------------------------------------------------------------
    // Student lists
    // -----------------------------------------------------------------------

    /** All students in a class: {id, name, room, graduationLevel} */
    public function getStudentsInClass(int $classId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.id,
                    CONCAT(s.first_name, \' \', s.last_name) AS name,
                    COALESCE(s.current_room, \'-\') AS room,
                    s.graduation_level AS graduationLevel
             FROM students s
             WHERE s.class = ?
             ORDER BY s.last_name, s.first_name',
            [$classId]
        );

        return array_map(function (array $r): array {
            $r['id']              = (int)$r['id'];
            $r['graduationLevel'] = (int)$r['graduationLevel'];
            return $r;
        }, $rows);
    }

    // -----------------------------------------------------------------------
    // Curriculum lookups
    // -----------------------------------------------------------------------

    /** All subjects (for admin/teacher dropdowns). */
    public function getAllSubjects(): array
    {
        return $this->db->fetchAll(
            'SELECT s.id, s.name, s.color, s.sort_order AS sortOrder, COUNT(DISTINCT ts.id) AS taskSetCount
             FROM subjects s
             LEFT JOIN lg_tasksets ts ON ts.subject_id = s.id
             GROUP BY s.id, s.name, s.color, s.sort_order
             ORDER BY s.sort_order, s.name'
        );
    }

    /** All classes. */
    public function getAllClasses(): array
    {
        return $this->db->fetchAll(
            'SELECT c.id, c.label, c.grade, COUNT(s.id) AS studentCount
             FROM classes c
             LEFT JOIN students s ON s.class = c.id
             GROUP BY c.id, c.label, c.grade
             ORDER BY c.grade, c.label'
        );
    }

    // -----------------------------------------------------------------------
    // Student mutations (teacher permissions required)
    // -----------------------------------------------------------------------

    public function changeGraduationLevel(int $studentId, int $level): void
    {
        $this->db->execute(
            'UPDATE students SET graduation_level = ? WHERE id = ?',
            [$level, $studentId]
        );
    }
}
