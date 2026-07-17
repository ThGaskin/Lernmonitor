<?php
declare(strict_types=1);

/**
 * Authentication and session management.
 *
 * Passwords are hashed with PHP's built-in password_hash() (bcrypt by default),
 * which handles salting automatically. Use Auth::hashPassword() when storing
 * new passwords and Auth::attempt() to verify them.
 *
 * Session data stored in $_SESSION['user']:
 *   id                — int (student/teacher/parent) or string (admin username)
 *   type              — 'student' | 'teacher' | 'admin' | 'parent'
 *   username          — email (student/teacher/parent) or username (admin)
 *   user_agent        — captured at login for hijack detection
 *   ip                — captured at login for hijack detection
 *
 * Additional fields for parents:
 *   linked_student_ids — int[] all student IDs linked to this parent
 *   active_student_id  — int   the student whose data is currently shown
 */
class Auth
{
    private static bool $started = false;

    // -----------------------------------------------------------------------
    // Session lifecycle
    // -----------------------------------------------------------------------

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        // Harden session cookie before starting
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        // Secure flag only when actually on HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
        self::$started = true;
    }

    // -----------------------------------------------------------------------
    // Password hashing — bcrypt via PHP's built-in password_hash()
    // -----------------------------------------------------------------------

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    // -----------------------------------------------------------------------
    // Login attempt
    // -----------------------------------------------------------------------

    /**
     * Check whether an identifier (email, benutzername, or admin username) is registered and has a password.
     * Returns: 'has_password' | 'needs_password' | 'not_found'
     */
    public static function lookupEmail(string $identifier): string
    {
        $db = Database::getInstance();

        // Admin: benutzername → email
        $row = $db->fetch('SELECT password_hash FROM admins WHERE benutzername = ?', [$identifier]);
        if ($row) return $row['password_hash'] === null ? 'needs_password' : 'has_password';
        $row = $db->fetch('SELECT password_hash FROM admins WHERE email = ? AND benutzername IS NULL', [$identifier]);
        if ($row) return $row['password_hash'] === null ? 'needs_password' : 'has_password';

        // Benutzername check across teachers/students/parents (case-sensitive)
        foreach (['teachers', 'students', 'parents'] as $table) {
            $row = $db->fetch("SELECT password FROM {$table} WHERE benutzername = ?", [$identifier]);
            if ($row) return $row['password'] === null ? 'needs_password' : 'has_password';
        }

        // Email fallback — only for accounts that have no benutzername set
        foreach (['teachers', 'students', 'parents'] as $table) {
            $row = $db->fetch("SELECT password FROM {$table} WHERE email = ? AND benutzername IS NULL", [$identifier]);
            if ($row) return $row['password'] === null ? 'needs_password' : 'has_password';
        }

        return 'not_found';
    }

    /**
     * Validate credentials and create a session on success.
     * Tries: admin (username) → teacher (email) → student (email) → parent (email).
     *
     * Returns:
     *   'ok'             — credentials valid, session created
     *   'needs_password' — account exists but has no password set (first login)
     *   'invalid'        — no matching account or wrong password
     */
    public static function attempt(string $username, string $password): string
    {
        $db = Database::getInstance();

        // 1. Benutzername check across all account types (case-sensitive)
        //    If an account has a benutzername set, it MUST be used — email no longer works.
        $row = $db->fetch('SELECT username, password_hash FROM admins WHERE benutzername = ?', [$username]);
        if ($row) {
            if ($row['password_hash'] === null) return 'needs_password';
            return password_verify($password, $row['password_hash'])
                ? (self::createSession('admin', $row['username'], $row['username']) ?: 'ok')
                : 'invalid';
        }
        $row = $db->fetch('SELECT id, email, password FROM teachers WHERE benutzername = ?', [$username]);
        if ($row) {
            if ($row['password'] === null) return 'needs_password';
            return password_verify($password, $row['password'])
                ? (self::createSession('teacher', $row['id'], $row['email']) ?: 'ok')
                : 'invalid';
        }
        $row = $db->fetch('SELECT id, email, password FROM students WHERE benutzername = ?', [$username]);
        if ($row) {
            if ($row['password'] === null) return 'needs_password';
            return password_verify($password, $row['password'])
                ? (self::createSession('student', $row['id'], $row['email']) ?: 'ok')
                : 'invalid';
        }
        $row = $db->fetch('SELECT id, email, password FROM parents WHERE benutzername = ?', [$username]);
        if ($row) {
            if ($row['password'] === null) return 'needs_password';
            if (password_verify($password, $row['password'])) {
                $linkedIds = array_column(
                    $db->fetchAll('SELECT student_id FROM parent_student WHERE parent_id = ?', [$row['id']]),
                    'student_id'
                );
                self::createSession('parent', $row['id'], $row['email'], $linkedIds);
                return 'ok';
            }
            return 'invalid';
        }

        // 2. Admin by email (benutzername not set)
        $row = $db->fetch('SELECT username, password_hash FROM admins WHERE email = ? AND benutzername IS NULL', [$username]);
        if ($row) {
            if ($row['password_hash'] === null) return 'needs_password';
            if (password_verify($password, $row['password_hash'])) {
                self::createSession('admin', $row['username'], $row['username']);
                return 'ok';
            }
        }

        // 3. Teacher by email — only if no benutzername is set on that account
        $row = $db->fetch('SELECT id, email, password FROM teachers WHERE email = ? AND benutzername IS NULL', [$username]);
        if ($row) {
            if ($row['password'] === null) return 'needs_password';
            if (password_verify($password, $row['password'])) {
                self::createSession('teacher', $row['id'], $row['email']);
                return 'ok';
            }
        }

        // 4. Student by email — only if no benutzername is set on that account
        $row = $db->fetch('SELECT id, email, password FROM students WHERE email = ? AND benutzername IS NULL', [$username]);
        if ($row) {
            if ($row['password'] === null) return 'needs_password';
            if (password_verify($password, $row['password'])) {
                self::createSession('student', $row['id'], $row['email']);
                return 'ok';
            }
        }

        // 5. Parent by email — only if no benutzername is set on that account
        $row = $db->fetch('SELECT id, email, password FROM parents WHERE email = ? AND benutzername IS NULL', [$username]);
        if ($row) {
            if ($row['password'] === null) return 'needs_password';
            if (password_verify($password, $row['password'])) {
                $linkedIds = array_column(
                    $db->fetchAll('SELECT student_id FROM parent_student WHERE parent_id = ?', [$row['id']]),
                    'student_id'
                );
                self::createSession('parent', $row['id'], $row['email'], $linkedIds);
                return 'ok';
            }
        }

        return 'invalid';
    }

    /**
     * Set the initial password for an account that currently has none, and log them in.
     * Searches teachers → students → parents by email.
     * Returns false if no passwordless account with that email exists.
     */
    public static function setInitialPassword(string $identifier, string $newPassword): bool
    {
        $db   = Database::getInstance();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Try benutzername first (case-sensitive), then email (only if no benutzername set)
        foreach (['benutzername', 'email'] as $field) {
            $emailCond = $field === 'email' ? ' AND benutzername IS NULL' : '';

            $row = $db->fetch("SELECT username, password_hash FROM admins WHERE {$field} = ?{$emailCond}", [$identifier]);
            if ($row && $row['password_hash'] === null) {
                $db->execute('UPDATE admins SET password_hash = ? WHERE username = ?', [$hash, $row['username']]);
                self::createSession('admin', $row['username'], $row['username']);
                return true;
            }

            $row = $db->fetch("SELECT id, email, password FROM teachers WHERE {$field} = ?{$emailCond}", [$identifier]);
            if ($row && $row['password'] === null) {
                $db->execute('UPDATE teachers SET password = ? WHERE id = ?', [$hash, $row['id']]);
                self::createSession('teacher', $row['id'], $row['email']);
                return true;
            }

            $row = $db->fetch("SELECT id, email, password FROM students WHERE {$field} = ?{$emailCond}", [$identifier]);
            if ($row && $row['password'] === null) {
                $db->execute('UPDATE students SET password = ? WHERE id = ?', [$hash, $row['id']]);
                self::createSession('student', $row['id'], $row['email']);
                return true;
            }

            $row = $db->fetch("SELECT id, email, password FROM parents WHERE {$field} = ?{$emailCond}", [$identifier]);
            if ($row && $row['password'] === null) {
                $db->execute('UPDATE parents SET password = ? WHERE id = ?', [$hash, $row['id']]);
                $linkedIds = array_column(
                    $db->fetchAll('SELECT student_id FROM parent_student WHERE parent_id = ?', [$row['id']]),
                    'student_id'
                );
                self::createSession('parent', $row['id'], $row['email'], $linkedIds);
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Password reset ("Passwort vergessen")
    // -----------------------------------------------------------------------

    /**
     * Look up an account by email across admins/teachers/students/parents,
     * independent of benutzername. Returns ['type' => ..., 'id' => ...] or null.
     */
    private static function findAccountByEmail(string $email): ?array
    {
        $db = Database::getInstance();

        $row = $db->fetch('SELECT username FROM admins WHERE email = ?', [$email]);
        if ($row) return ['type' => 'admin', 'id' => $row['username']];

        foreach (['teacher' => 'teachers', 'student' => 'students', 'parent' => 'parents'] as $type => $table) {
            $row = $db->fetch("SELECT id FROM {$table} WHERE email = ?", [$email]);
            if ($row) return ['type' => $type, 'id' => (int)$row['id']];
        }

        return null;
    }

    /**
     * Generate a single-use, 10-minute reset token and email it to the account's
     * address, if one exists. Always safe to call with an unknown email — does
     * nothing silently, so the caller can return the same generic response either
     * way (no account enumeration via this endpoint).
     */
    public static function requestPasswordReset(string $email): void
    {
        $db = Database::getInstance();

        if (self::findAccountByEmail($email) === null) {
            return;
        }

        // Throttle: skip if a token was already issued for this email very recently,
        // so repeatedly hitting the form can't spam the inbox.
        $recent = $db->fetch(
            "SELECT 1 FROM password_resets WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND)",
            [$email]
        );
        if ($recent) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);

        $db->execute('DELETE FROM password_resets WHERE email = ?', [$email]);
        $db->execute(
            'INSERT INTO password_resets (token_hash, email, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
            [$hash, $email]
        );

        $resetUrl = Config::appUrl() . '/reset-password?token=' . $token;
        Mailer::sendPasswordReset($email, $resetUrl);
    }

    /**
     * Validate a reset token and set a new password on the matching account.
     * The token is single-use: consumed (deleted) whether or not it succeeds
     * past this point, so a link can never be replayed.
     */
    public static function confirmPasswordReset(string $token, string $newPassword): bool
    {
        $db   = Database::getInstance();
        $hash = hash('sha256', $token);

        $row = $db->fetch(
            'SELECT email FROM password_resets WHERE token_hash = ? AND expires_at > NOW()',
            [$hash]
        );
        if (!$row) {
            return false;
        }

        $db->execute('DELETE FROM password_resets WHERE email = ?', [$row['email']]);

        $account = self::findAccountByEmail($row['email']);
        if ($account === null) {
            return false;
        }

        $pwHash = password_hash($newPassword, PASSWORD_DEFAULT);
        match ($account['type']) {
            'admin'   => $db->execute('UPDATE admins SET password_hash = ? WHERE username = ?', [$pwHash, $account['id']]),
            'teacher' => $db->execute('UPDATE teachers SET password = ? WHERE id = ?', [$pwHash, $account['id']]),
            'student' => $db->execute('UPDATE students SET password = ? WHERE id = ?', [$pwHash, $account['id']]),
            'parent'  => $db->execute('UPDATE parents SET password = ? WHERE id = ?', [$pwHash, $account['id']]),
        };

        return true;
    }

    private static function createSession(string $type, int|string $id, string $username, array $linkedStudentIds = []): void
    {
        // Rotate session ID on login to prevent session fixation attacks
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'         => $id,
            'type'       => $type,
            'username'   => $username,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        if ($type === 'parent') {
            $intIds = array_map('intval', $linkedStudentIds);
            $_SESSION['user']['linked_student_ids'] = $intIds;
            $_SESSION['user']['active_student_id']  = $intIds[0] ?? null;
        }
        if ($type === 'admin') {
            $db = Database::getInstance();
            $linked = $db->fetch('SELECT id FROM teachers WHERE linked_admin_username = ?', [$username]);
            if ($linked) {
                $_SESSION['user']['linked_teacher_id'] = (int)$linked['id'];
                $_SESSION['user']['active_role']       = 'admin';
            }
        }
    }

    public static function switchRole(string $role): void
    {
        $user = self::user();
        if (!$user || $user['type'] !== 'admin' || empty($user['linked_teacher_id'])) return;
        $_SESSION['user']['active_role'] = $role;
    }

    /** Returns the effective teacher ID for the current session.
     *  For regular teachers: their own id.
     *  For admins with a linked teacher (in teacher mode): the linked teacher's id.
     *  Otherwise: 0.
     */
    public static function effectiveTeacherId(): int
    {
        $user = self::user();
        if (!$user) return 0;
        if ($user['type'] === 'teacher') return (int)$user['id'];
        if ($user['type'] === 'admin' && !empty($user['linked_teacher_id'])) {
            return (int)$user['linked_teacher_id'];
        }
        return 0;
    }

    // -----------------------------------------------------------------------
    // Session validation
    // -----------------------------------------------------------------------

    /** True if a user is logged in AND the session fingerprint is still valid. */
    public static function validate(): bool
    {
        if (!isset($_SESSION['user'])) {
            return false;
        }
        $user = $_SESSION['user'];
        // Reject if User-Agent or IP changed since login (session hijack protection)
        if (($user['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return false;
        }
        if (($user['ip'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            return false;
        }
        return true;
    }

    /** True if any user is logged in (does not validate fingerprint). */
    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    // -----------------------------------------------------------------------
    // Current user accessors
    // -----------------------------------------------------------------------

    /** Current session data array, or null if not logged in / invalid session. */
    public static function user(): ?array
    {
        return self::validate() ? $_SESSION['user'] : null;
    }

    public static function userType(): string
    {
        $user = self::user();
        return $user ? $user['type'] : 'anonymous';
    }

    public static function isAdmin(): bool
    {
        return self::userType() === 'admin';
    }

    /** Admins can act in the teacher role too (matches Java behaviour). */
    public static function isTeacher(): bool
    {
        $type = self::userType();
        return $type === 'teacher' || $type === 'admin';
    }

    public static function isStudent(): bool
    {
        return self::userType() === 'student';
    }

    public static function isParent(): bool
    {
        return self::userType() === 'parent';
    }

    /**
     * Switch which child a parent is currently viewing.
     * Returns false if $studentId is not linked to the current parent.
     */
    public static function setActiveStudent(int $studentId): bool
    {
        $user = self::user();
        if (!$user || $user['type'] !== 'parent') {
            return false;
        }
        if (!in_array($studentId, $user['linked_student_ids'] ?? [], true)) {
            return false;
        }
        $_SESSION['user']['active_student_id'] = $studentId;
        return true;
    }

    // -----------------------------------------------------------------------
    // Logout
    // -----------------------------------------------------------------------

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
    }

    // -----------------------------------------------------------------------
    // Access guards — call at the top of protected route handlers
    // -----------------------------------------------------------------------

    /**
     * Redirect to /login if the required access level is not met.
     *
     * Levels: 'public' | 'user' | 'student' | 'teacher' | 'admin' | 'parent'
     */
    public static function require(string $level): void
    {
        $granted = match ($level) {
            'public'  => true,
            'user'    => self::validate(),
            'student' => self::isStudent(),
            'teacher' => self::isTeacher(),
            'admin'   => self::isAdmin(),
            'parent'  => self::isParent(),
            default   => false,
        };

        if (!$granted) {
            http_response_code(302);
            header('Location: /login');
            exit;
        }
    }
}
