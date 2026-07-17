<?php
declare(strict_types=1);

/**
 * Database operations for parent accounts.
 *
 * Parents are standalone accounts linked to one or more students via the
 * parent_student junction table.  They can view (but not write) student data.
 */
class ParentRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // -----------------------------------------------------------------------
    // Lookup / auth
    // -----------------------------------------------------------------------

    /** Fetch a parent row by email (for login). */
    public function getByEmail(string $email): ?array
    {
        return $this->db->fetch(
            'SELECT id, email, password FROM parents WHERE email = ?',
            [$email]
        );
    }

    /** Return all student IDs linked to this parent. */
    public function getLinkedStudentIds(int $parentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT student_id FROM parent_student WHERE parent_id = ?',
            [$parentId]
        );
        return array_column($rows, 'student_id');
    }

    /**
     * Return basic info for all students linked to this parent,
     * ordered by last name then first name.
     */
    public function getLinkedStudents(int $parentId): array
    {
        return $this->db->fetchAll(
            'SELECT s.id, s.first_name AS firstName, s.last_name AS lastName
             FROM students s
             JOIN parent_student ps ON ps.student_id = s.id
             WHERE ps.parent_id = ?
             ORDER BY s.last_name, s.first_name',
            [$parentId]
        );
    }

    // -----------------------------------------------------------------------
    // Admin: create / list / password reset / delete
    // -----------------------------------------------------------------------

    /**
     * Create a new parent account with no password set.
     * The parent will be prompted to choose a password on first login.
     * Throws if the email is already taken.
     */
    public function create(string $email, ?string $benutzername = null): int
    {
        $existing = $this->db->fetch('SELECT id FROM parents WHERE email = ?', [$email]);
        if ($existing) {
            throw new InvalidArgumentException("Ein Elternkonto mit der E-Mail \"{$email}\" existiert bereits.");
        }
        if ($benutzername !== null) {
            foreach (['students', 'teachers', 'parents'] as $table) {
                if ($this->db->fetch("SELECT id FROM {$table} WHERE benutzername = ?", [$benutzername])) {
                    throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
                }
            }
        }
        $this->db->execute(
            'INSERT INTO parents (email, password, benutzername) VALUES (?, NULL, ?)',
            [$email, $benutzername]
        );
        return (int)$this->db->lastInsertId();
    }

    /**
     * Link a parent to a student.
     * Silently ignores duplicate links (INSERT IGNORE).
     */
    public function linkStudent(int $parentId, int $studentId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO parent_student (parent_id, student_id) VALUES (?, ?)',
            [$parentId, $studentId]
        );
    }

    /** Remove the link between a parent and a student. */
    public function unlinkStudent(int $parentId, int $studentId): void
    {
        $this->db->execute(
            'DELETE FROM parent_student WHERE parent_id = ? AND student_id = ?',
            [$parentId, $studentId]
        );
    }

    /** Clear a parent's password so they are prompted to set a new one on next login. */
    public function resetPassword(int $parentId): void
    {
        $this->db->execute('UPDATE parents SET password = NULL WHERE id = ?', [$parentId]);
    }

    /** Delete a parent account (cascade removes parent_student links). */
    public function delete(int $parentId): void
    {
        $this->db->execute('DELETE FROM parents WHERE id = ?', [$parentId]);
    }

    /**
     * Return all parent accounts with their linked student names,
     * for the admin management view.
     */
    public function getAll(): array
    {
        $parents = $this->db->fetchAll(
            'SELECT id, email, benutzername FROM parents ORDER BY email'
        );
        foreach ($parents as &$parent) {
            $parent['students'] = $this->getLinkedStudents((int)$parent['id']);
        }
        return $parents;
    }

    public function update(int $id, string $email, ?string $benutzername): void
    {
        if ($this->db->fetch('SELECT id FROM parents WHERE email = ? AND id != ?', [$email, $id])) {
            throw new InvalidArgumentException("Diese E-Mail-Adresse ist bereits vergeben.");
        }
        if ($benutzername !== null) {
            foreach (['students', 'teachers'] as $table) {
                if ($this->db->fetch("SELECT id FROM {$table} WHERE benutzername = ?", [$benutzername])) {
                    throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
                }
            }
            if ($this->db->fetch('SELECT id FROM parents WHERE benutzername = ? AND id != ?', [$benutzername, $id])) {
                throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
            }
            if ($this->db->fetch('SELECT username FROM admins WHERE benutzername = ?', [$benutzername])) {
                throw new InvalidArgumentException("Der Benutzername \"{$benutzername}\" ist bereits vergeben.");
            }
        }
        $this->db->execute('UPDATE parents SET email=?, benutzername=? WHERE id=?', [$email, $benutzername, $id]);
    }
}
