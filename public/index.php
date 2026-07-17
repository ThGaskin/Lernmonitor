<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Load .env (works on any server — no Apache/LiteSpeed SetEnv needed)
// ---------------------------------------------------------------------------
$_envFile = __DIR__ . '/../.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || str_starts_with($_line, '#')) continue;
        if (str_contains($_line, '=')) {
            [$_key, $_val] = explode('=', $_line, 2);
            $_key = trim($_key); $_val = trim($_val);
            // Process/server env vars take precedence (e.g. test runner sets DB_NAME)
            if (getenv($_key) === false && !isset($_ENV[$_key])) {
                $_ENV[$_key] = $_val;
                putenv("$_key=$_val");
            }
        }
    }
}
unset($_envFile, $_line, $_key, $_val);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Request.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Mailer.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/StudentRepository.php';
require_once __DIR__ . '/../src/TeacherRepository.php';
require_once __DIR__ . '/../src/AdminRepository.php';
require_once __DIR__ . '/../src/LerngruppeRepository.php';
require_once __DIR__ . '/../src/ParentRepository.php';
require_once __DIR__ . '/../src/AppSettings.php';
require_once __DIR__ . '/../src/DatabaseManager.php';

Auth::start();

$request = new Request();
$router  = new Router();

// ---------------------------------------------------------------------------
// Error handler
// ---------------------------------------------------------------------------
set_exception_handler(function (Throwable $e) use ($request) {
    http_response_code(500);
    $msg = Config::isDebug() ? $e->getMessage() : 'Internal server error';
    if ($request->wantsJson()) {
        Response::json(['error' => $msg], 500);
    } else {
        echo '<h1>500 Internal Server Error</h1><p>' . htmlspecialchars($msg) . '</p>';
    }
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Ensure the session user may act on the given student.
 * Students can only touch their own data; teachers and admins can touch anyone's.
 */
function assertCanActOnStudent(int $studentId): void
{
    $user = Auth::user();
    if (!$user) {
        Response::unauthorized();
        exit;
    }
    if ($user['type'] === 'student' && (int)$user['id'] !== $studentId) {
        Response::forbidden();
        exit;
    }
}

function db(): Database
{
    return Database::getInstance();
}

function settings(): AppSettings
{
    return AppSettings::getInstance(db());
}

/** Year to use for reading task data — from ?year= param, falling back to current setting. */
function viewYear(): string
{
    $requested = $_GET['year'] ?? '';
    if ($requested !== '') return $requested;
    return settings()->currentSchoolYear();
}

// ---------------------------------------------------------------------------
// Health check
// ---------------------------------------------------------------------------

$router->get('/health', function (Request $req) {
    $dbOk = false;
    try { db()->fetch('SELECT 1'); $dbOk = true; } catch (Throwable) {}
    Response::json(['db' => $dbOk], $dbOk ? 200 : 503);
});

// ---------------------------------------------------------------------------
// Root
// ---------------------------------------------------------------------------

$router->get('/', function (Request $req) {
    Response::json(['status' => 'ok', 'app' => 'student-database-php', 'version' => '0.1.0']);
});

// ===========================================================================
// AUTH
// ===========================================================================

$router->get('/login', function (Request $req) {
    if (Auth::validate()) { header('Location: /dashboard'); exit; }
    Response::html(__DIR__ . '/../templates/login.html');
});

$router->post('/check-email', function (Request $req) {
    // Returns 'needs_password' only when the account exists with no password set.
    // Returns 'has_password' for everything else (real password, or email not found)
    // so that regular accounts cannot be enumerated.
    $body  = $req->json() ?? [];
    $email = trim((string)($body['email'] ?? ''));
    $status = ($email !== '' && Auth::lookupEmail($email) === 'needs_password')
        ? 'needs_password'
        : 'has_password';
    Response::json(['status' => $status]);
});

$router->post('/login', function (Request $req) {
    $body     = $req->json() ?? [];
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '') { Response::json(['status' => 'invalid'], 400); return; }
    $result = Auth::attempt($username, $password);
    match ($result) {
        'ok'             => Response::json(['status' => 'ok']),
        'needs_password' => Response::json(['status' => 'needs_password']),
        default          => Response::json(['status' => 'invalid'], 401),
    };
});

$router->post('/set-initial-password', function (Request $req) {
    $body     = $req->json() ?? [];
    $email    = trim((string)($body['email']    ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($email === '' || strlen($password) < 6) { Response::json(['status' => 'invalid'], 400); return; }
    Auth::setInitialPassword($email, $password)
        ? Response::json(['status' => 'ok'])
        : Response::json(['status' => 'invalid'], 400);
});

$router->post('/request-password-reset', function (Request $req) {
    $body  = $req->json() ?? [];
    $email = trim((string)($body['email'] ?? ''));
    if ($email !== '') {
        Auth::requestPasswordReset($email);
    }
    // Always the same response, whether or not the email matched an account.
    Response::json(['ok' => true]);
});

$router->get('/reset-password', function (Request $req) {
    Response::html(__DIR__ . '/../templates/reset-password.html');
});

$router->post('/reset-password', function (Request $req) {
    $body     = $req->json() ?? [];
    $token    = trim((string)($body['token']    ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($token === '' || strlen($password) < 6) { Response::json(['status' => 'invalid'], 400); return; }
    Auth::confirmPasswordReset($token, $password)
        ? Response::json(['status' => 'ok'])
        : Response::json(['status' => 'invalid'], 400);
});

$router->post('/logout', function (Request $req) {
    Auth::logout();
    Response::text('Logged out');
});

$router->post('/switch-role', function (Request $req) {
    $user = Auth::user();
    if (!$user || $user['type'] !== 'admin' || empty($user['linked_teacher_id'])) {
        http_response_code(302); header('Location: /dashboard'); exit;
    }
    Auth::switchRole(($user['active_role'] ?? 'admin') === 'admin' ? 'teacher' : 'admin');
    http_response_code(302); header('Location: /dashboard'); exit;
});

$router->post('/change-password', function (Request $req) {
    Auth::require('user');
    $body        = $req->json() ?? [];
    $currentPw   = (string)($body['currentPassword'] ?? '');
    $newPw       = (string)($body['newPassword']     ?? '');
    if ($currentPw === '' || $newPw === '') {
        Response::json(['error' => 'Bitte alle Felder ausfüllen.'], 400); return;
    }
    if (strlen($newPw) < 6) {
        Response::json(['error' => 'Das neue Passwort muss mindestens 6 Zeichen lang sein.'], 400); return;
    }

    $user = Auth::user();
    $db   = db();

    $row = match ($user['type']) {
        'student' => $db->fetch('SELECT password AS hash FROM students WHERE id = ?', [$user['id']]),
        'teacher' => $db->fetch('SELECT password AS hash FROM teachers WHERE id = ?', [$user['id']]),
        'admin'   => $db->fetch('SELECT password_hash AS hash FROM admins WHERE username = ?', [$user['id']]),
        'parent'  => $db->fetch('SELECT password AS hash FROM parents WHERE id = ?', [$user['id']]),
        default   => null,
    };

    if (!$row || !password_verify($currentPw, $row['hash'])) {
        Response::json(['error' => 'Das aktuelle Passwort ist falsch.'], 401); return;
    }

    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    match ($user['type']) {
        'student' => $db->execute('UPDATE students SET password = ? WHERE id = ?', [$hash, $user['id']]),
        'teacher' => $db->execute('UPDATE teachers SET password = ? WHERE id = ?', [$hash, $user['id']]),
        'admin'   => $db->execute('UPDATE admins SET password_hash = ? WHERE username = ?', [$hash, $user['id']]),
        'parent'  => $db->execute('UPDATE parents SET password = ? WHERE id = ?', [$hash, $user['id']]),
    };

    Response::json(['ok' => true]);
});

// ===========================================================================
// PAGES
// ===========================================================================

$router->get('/me', function (Request $req) {
    Auth::require('user');
    $user = Auth::user();
    $extra = [];
    if ($user['type'] === 'admin') {
        $row = db()->fetch('SELECT first_name, last_name FROM admins WHERE username = ?', [$user['id']]);
        $extra['firstName'] = $row['first_name'] ?? '';
        $extra['lastName']  = $row['last_name']  ?? '';
    }
    Response::json(array_merge(['role' => $user['type'], 'username' => $user['username']], $extra));
});

$router->get('/dashboard', function (Request $req) {
    Auth::require('user');
    $user = Auth::user();
    if ($user['type'] === 'parent') {
        $activeId = (int)($user['active_student_id'] ?? 0);
        if ($activeId <= 0) {
            Response::text('Kein Schüler verknüpft.', 403);
            return;
        }
        $linkedStudents = (new ParentRepository(db()))->getLinkedStudents((int)$user['id']);
        Response::html(
            __DIR__ . '/../templates/student/dashboard.php',
            200,
            ['readOnly' => true, 'activeStudentId' => $activeId, 'linkedStudents' => $linkedStudents, 'showGrades' => settings()->get('show_grades', true), 'showClassDashboard' => settings()->get('show_class_dashboard', true), 'projectionDefault' => (int)settings()->get('projection_default', 50)]
        );
        return;
    }
    if ($user['type'] === 'admin' && ($user['active_role'] ?? 'admin') === 'teacher') {
        // Verify the linked teacher still exists; if not, fall back to admin dashboard.
        $teacherId = Auth::effectiveTeacherId();
        if ($teacherId <= 0 || !db()->fetch('SELECT id FROM teachers WHERE id = ?', [$teacherId])) {
            $_SESSION['user']['active_role']       = 'admin';
            unset($_SESSION['user']['linked_teacher_id']);
            http_response_code(302); header('Location: /dashboard'); exit;
        }
    }
    if ($user['type'] === 'teacher' || ($user['type'] === 'admin' && ($user['active_role'] ?? 'admin') === 'teacher')) {
        Response::html(
            __DIR__ . '/../templates/teacher/dashboard.php',
            200,
            ['adminOnlyTasksets' => settings()->get('task_scope', 'grade') === 'grade' || settings()->get('admin_only_tasksets', true)]
        );
        return;
    }
    $template = match ($user['type']) {
        'student' => __DIR__ . '/../templates/student/dashboard.php',
        'admin'   => __DIR__ . '/../templates/admin/dashboard.php',
        default   => null,
    };
    $template ? Response::html($template, 200, ['schoolYearMissing' => settings()->currentSchoolYear() === '', 'showGrades' => settings()->get('show_grades', true), 'showClassDashboard' => settings()->get('show_class_dashboard', true), 'projectionDefault' => (int)settings()->get('projection_default', 50)]) : Response::text('Unknown user type', 500);
});

$router->get('/student-profile', function (Request $req) {
    Auth::require('teacher');
    Response::html(__DIR__ . '/../templates/student_profile.html');
});

$router->get('/api/student-profile', function (Request $req) {
    Auth::require('teacher');
    $id = (int)($req->get('id') ?? 0);
    if ($id <= 0) { Response::notFound(); return; }
    $data = (new StudentRepository(db(), viewYear()))->getProfileData($id);
    $data ? Response::json($data) : Response::notFound();
});

$router->get('/api/my-profile', function (Request $req) {
    Auth::require('student');
    $id   = (int)Auth::user()['id'];
    $data = (new StudentRepository(db(), viewYear()))->getProfileData($id);
    $data ? Response::json($data) : Response::notFound();
});

$router->post('/enroll-subject', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $studentId = (int)($body['studentId'] ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    if ($studentId <= 0 || $subjectId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    $student = (new StudentRepository(db(), viewYear()))->getById($studentId);
    if (!$student) { Response::notFound(); return; }
    (new StudentRepository(db(), viewYear()))->enrollStudentInSubject($studentId, $subjectId);
    Response::json(['ok' => true]);
});

$router->post('/unenroll-subject', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $studentId = (int)($body['studentId'] ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    if ($studentId <= 0 || $subjectId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new StudentRepository(db(), viewYear()))->unenrollStudentFromSubject($studentId, $subjectId);
    Response::json(['ok' => true]);
});

// ===========================================================================
// DATA APIs — role-aware GET endpoints
// ===========================================================================

$router->get('/mydata', function (Request $req) {
    Auth::require('user');
    $user = Auth::user();
    if ($user['type'] === 'parent') {
        $sid = (int)($user['active_student_id'] ?? 0);
        if ($sid <= 0) { Response::forbidden(); return; }
        $data = (new StudentRepository(db(), viewYear()))->getFullData($sid);
        $data ? Response::json($data) : Response::notFound();
        return;
    }
    if ($user['type'] !== 'student') { Response::forbidden(); return; }
    $data = (new StudentRepository(db(), viewYear()))->getFullData((int)$user['id']);
    $data ? Response::json($data) : Response::notFound();
});

$router->get('/myclasses', function (Request $req) {
    Auth::require('user');
    $user = Auth::user();

    if ($user['type'] === 'student') {
        $repo = new StudentRepository(db(), viewYear());
        $row  = $repo->getById((int)$user['id']);
        if (!$row) { Response::notFound(); return; }
        Response::json([[
            'id'    => (int)$row['class_id'],
            'label' => $row['class_label'],
            'grade' => (int)$row['class_grade'],
        ]]);
    } else {
        Response::json((new TeacherRepository(db()))->getClassesForTeacher((int)$user['id']));
    }
});

$router->get('/rooms', function (Request $req) {
    Auth::require('user');
    Response::json(array_map(fn($r) => [
        'label'        => $r['label'],
        'minimumLevel' => $r['minimumLevel'],
        'studentCount' => (int)$r['studentCount'],
    ], db()->fetchAll(
        'SELECT r.label, r.minimum_level AS minimumLevel, COUNT(s.id) AS studentCount
         FROM rooms r LEFT JOIN students s ON s.current_room = r.label
         GROUP BY r.label, r.minimum_level ORDER BY r.label'
    )));
});

$router->get('/subjects', function (Request $req) {
    Auth::require('user');
    Response::json((new TeacherRepository(db()))->getAllSubjects());
});

$router->get('/classes', function (Request $req) {
    Auth::require('user');
    Response::json((new TeacherRepository(db()))->getAllClasses());
});

// ===========================================================================
// DATA APIs — POST (JSON body)
// ===========================================================================


// --- Student lists ---

$router->post('/student-list', function (Request $req) {
    Auth::require('teacher');
    $body    = $req->json() ?? [];
    $classId = (int)($body['classId'] ?? 0);
    Response::json((new TeacherRepository(db()))->getStudentsInClass($classId));
});

// --- Teacher's own classes/subjects (by explicit teacherId, for admin views) ---

$router->post('/teacher-classes', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $teacherId = (int)($body['teacherId'] ?? 0);
    Response::json((new TeacherRepository(db()))->getClassesForTeacher($teacherId));
});

$router->post('/teacher-subjects', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $teacherId = (int)($body['teacherId'] ?? 0);
    Response::json((new TeacherRepository(db()))->getSubjectsForTeacher($teacherId));
});

// --- Student mutations ---

$router->post('/change-graduation-level', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $studentId = (int)($body['studentId']      ?? 0);
    $level     = (int)($body['graduationLevel'] ?? 0);
    (new TeacherRepository(db()))->changeGraduationLevel($studentId, $level);
    Response::text('OK');
});

$router->post('/update-room', function (Request $req) {
    Auth::require('user');
    $body = $req->json() ?? [];
    $sid  = (int)($body['studentId'] ?? 0);
    assertCanActOnStudent($sid);
    try {
        (new StudentRepository(db(), viewYear()))->updateRoom($sid, (string)($body['room'] ?? ''));
    } catch (\RuntimeException $e) {
        Response::json(['error' => $e->getMessage()], 400); return;
    }
    Response::text('OK');
});

// ===========================================================================
// TEACHER — own profile data
// ===========================================================================

$router->get('/api/my-teacher-data', function (Request $req) {
    Auth::require('teacher');
    $db  = db();
    $row = $db->fetch(
        'SELECT first_name AS firstName, last_name AS lastName,
                email, current_room AS room
         FROM teachers WHERE id = ?',
        [Auth::effectiveTeacherId()]
    );
    if (!$row) { Response::notFound(); return; }
    $rooms = $db->fetchAll(
        'SELECT r.label, COUNT(s.id) AS studentCount
         FROM rooms r LEFT JOIN students s ON s.current_room = r.label
         GROUP BY r.label ORDER BY r.label'
    );
    $row['availableRooms'] = array_map(fn($r) => ['label' => $r['label'], 'studentCount' => (int)$r['studentCount']], $rooms);
    Response::json($row);
});

$router->post('/update-my-room', function (Request $req) {
    Auth::require('teacher');
    $body = $req->json() ?? [];
    $room = isset($body['room']) ? ($body['room'] ?: null) : null;
    db()->execute('UPDATE teachers SET current_room = ? WHERE id = ?',
        [$room, (int)Auth::user()['id']]);
    Response::json(['ok' => true]);
});

// ===========================================================================
// LERNGRUPPEN TASK SETS
// ===========================================================================

// Teacher: get own Lerngruppen with task sets
$router->get('/my-lerngruppen', function (Request $req) {
    Auth::require('teacher');
    $teacherId = Auth::effectiveTeacherId();
    Response::json((new LerngruppeRepository(db()))->getLerngruppenWithTaskSets($teacherId));
});

// Teacher: get all unique students across own Lerngruppen
$router->get('/my-lerngruppen-students', function (Request $req) {
    Auth::require('teacher');
    $teacherId = Auth::effectiveTeacherId();
    Response::json((new LerngruppeRepository(db()))->getStudentsForTeacher($teacherId));
});

// Teacher: get notifications (subject requests + submissions) per Lerngruppe
$router->get('/my-lerngruppen-notifications', function (Request $req) {
    Auth::require('teacher');
    $teacherId = Auth::effectiveTeacherId();
    Response::json((new LerngruppeRepository(db()))->getNotificationsForTeacher($teacherId));
});

// Teacher: create an Aufgabenset for one of their Lerngruppen
$router->post('/create-lg-taskset', function (Request $req) {
    Auth::require('teacher');
    if (!Auth::isAdmin() && (settings()->get('task_scope', 'grade') === 'grade' || settings()->get('admin_only_tasksets', true))) {
        Response::json(['error' => 'Nur Admins können Aufgabensets erstellen.'], 403); return;
    }
    if (settings()->currentSchoolYear() === '') {
        Response::json(['error' => 'Bitte zuerst das aktuelle Schuljahr in den Einstellungen festlegen.'], 400); return;
    }
    $body      = $req->json() ?? [];
    $classId   = (int)($body['classId']   ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    $name      = trim((string)($body['name'] ?? ''));
    $maxPoints = max(1, (int)($body['maxPoints'] ?? 1));
    $isPassFail = (bool)($body['isPassFail'] ?? false);
    // Admins skip the ownership check; teachers are verified against their lerngruppen.
    $teacherId = Auth::isAdmin() ? 0 : (int)Auth::user()['id'];

    if ($classId <= 0 || $subjectId <= 0 || $name === '') {
        Response::json(['error' => 'Fehlende Parameter.'], 400);
        return;
    }

    $duplicate = db()->fetch(
        'SELECT 1 FROM lg_tasksets WHERE class_id = ? AND subject_id = ? AND name = ? LIMIT 1',
        [$classId, $subjectId, $name]
    );
    if ($duplicate) {
        Response::json(['error' => 'ein Aufgabenset mit diesem Namen existiert bereits.'], 409);
        return;
    }

    $result = (new LerngruppeRepository(db()))->createTaskSet($teacherId, $classId, $subjectId, $name, $maxPoints, settings()->currentSchoolYear(), $isPassFail);
    if ($result === null) {
        Response::json(['error' => 'Diese Lerngruppe ist diesem Lehrer nicht zugewiesen.'], 403);
        return;
    }
    Response::json($result);
});

// Teacher: update name/points of a task set
$router->post('/update-lg-taskset', function (Request $req) {
    Auth::require('teacher');
    if (!Auth::isAdmin() && (settings()->get('task_scope', 'grade') === 'grade' || settings()->get('admin_only_tasksets', true))) {
        Response::json(['error' => 'Nur Admins können Aufgabensets bearbeiten.'], 403); return;
    }
    $body      = $req->json() ?? [];
    $teacherId  = Auth::isAdmin() ? 0 : (int)Auth::user()['id'];
    $taskSetId  = (int)($body['tasksetId'] ?? 0);
    $name       = trim((string)($body['name'] ?? ''));
    $maxPoints  = max(1, (int)($body['maxPoints'] ?? 1));
    $isPassFail = (bool)($body['isPassFail'] ?? false);
    if ($taskSetId <= 0 || $name === '') {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $ts = db()->fetch('SELECT class_id, subject_id, name AS oldName FROM lg_tasksets WHERE id = ?', [$taskSetId]);
    if ($ts && $name !== $ts['oldName']) {
        $duplicate = db()->fetch(
            'SELECT 1 FROM lg_tasksets WHERE class_id = ? AND subject_id = ? AND name = ? AND id != ? LIMIT 1',
            [$ts['class_id'], $ts['subject_id'], $name, $taskSetId]
        );
        if ($duplicate) {
            Response::json(['error' => 'ein Aufgabenset mit diesem Namen existiert bereits.'], 409); return;
        }
    }
    $ok = (new LerngruppeRepository(db()))->updateTaskSet($taskSetId, $teacherId, $name, $maxPoints, $isPassFail);
    $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'Nicht gefunden.'], 404);
});

// Teacher: toggle active state of a task set
$router->post('/toggle-lg-taskset', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $teacherId = Auth::isAdmin() ? 0 : (int)Auth::user()['id'];
    $taskSetId = (int)($body['tasksetId'] ?? 0);
    if ($taskSetId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    $active = (new LerngruppeRepository(db()))->toggleTaskSet($taskSetId, $teacherId);
    if ($active === null) { Response::json(['error' => 'Nicht gefunden.'], 404); return; }
    Response::json(['active' => $active]);
});

// Teacher: delete a task set (ownership is verified in the repository)
$router->post('/delete-lg-taskset', function (Request $req) {
    Auth::require('teacher');
    if (!Auth::isAdmin() && (settings()->get('task_scope', 'grade') === 'grade' || settings()->get('admin_only_tasksets', true))) {
        Response::json(['error' => 'Nur Admins können Aufgabensets entfernen.'], 403); return;
    }
    $body      = $req->json() ?? [];
    $teacherId = Auth::isAdmin() ? 0 : (int)Auth::user()['id'];
    $taskSetId = (int)($body['tasksetId'] ?? 0);
    if ($taskSetId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new LerngruppeRepository(db()))->deleteTaskSet($taskSetId, $teacherId);
    Response::json(['ok' => true]);
});

// Teacher: save grading scale thresholds for a Lerngruppe
$router->post('/save-lg-grading-scale', function (Request $req) {
    Auth::require('teacher');
    if (!Auth::isAdmin() && (settings()->get('task_scope', 'grade') === 'grade' || settings()->get('admin_only_tasksets', true))) {
        Response::forbidden(); return;
    }
    $body       = $req->json() ?? [];
    $classId    = (int)($body['classId']    ?? 0);
    $subjectId  = (int)($body['subjectId']  ?? 0);
    $thresholds = $body['thresholds'] ?? [];
    if ($classId <= 0 || $subjectId <= 0 || count($thresholds) !== 5) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    if (!Auth::isAdmin()) {
        $owns = db()->fetch(
            'SELECT 1 FROM teacher_lerngruppen WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
            [(int)Auth::user()['id'], $classId, $subjectId]
        );
        if (!$owns) { Response::json(['error' => 'Nicht autorisiert.'], 403); return; }
    }
    (new LerngruppeRepository(db()))->saveGradingScaleForClass(
        $classId, $subjectId, array_map('floatval', $thresholds)
    );
    Response::json(['ok' => true]);
});

// Student/parent: get all task sets for their Lerngruppen (with per-task status)
$router->get('/my-lg-tasks', function (Request $req) {
    Auth::require('user');
    $user       = Auth::user();
    $schoolYear = trim((string)($req->get('year') ?? ''));
    if ($user['type'] === 'parent') {
        $sid = (int)($user['active_student_id'] ?? 0);
        if ($sid <= 0) { Response::forbidden(); return; }
        Response::json((new LerngruppeRepository(db()))->getTaskSetsForStudent($sid, $schoolYear));
        return;
    }
    if ($user['type'] !== 'student') { Response::forbidden(); return; }
    Response::json((new LerngruppeRepository(db()))->getTaskSetsForStudent((int)$user['id'], $schoolYear));
});

// Student/parent: per-subject performance data (task sets with scores + grading scale)
$router->get('/my-lg-performance', function (Request $req) {
    Auth::require('user');
    $user       = Auth::user();
    $schoolYear = trim((string)($req->get('year') ?? ''));
    if ($user['type'] === 'parent') {
        $sid = (int)($user['active_student_id'] ?? 0);
        if ($sid <= 0) { Response::forbidden(); return; }
        Response::json((new LerngruppeRepository(db()))->getPerformanceForStudent($sid, $schoolYear));
        return;
    }
    if ($user['type'] !== 'student') { Response::forbidden(); return; }
    Response::json((new LerngruppeRepository(db()))->getPerformanceForStudent((int)$user['id'], $schoolYear));
});

// Student: available school years for the performance panel
$router->get('/api/my-performance-years', function (Request $req) {
    Auth::require('user');
    $user = Auth::user();
    if ($user['type'] === 'parent') {
        $sid = (int)($user['active_student_id'] ?? 0);
        if ($sid <= 0) { Response::forbidden(); return; }
        Response::json((new LerngruppeRepository(db()))->getAvailableYearsForStudent($sid));
        return;
    }
    if ($user['type'] !== 'student') { Response::forbidden(); return; }
    Response::json((new LerngruppeRepository(db()))->getAvailableYearsForStudent((int)$user['id']));
});

// Parent: switch which child is currently being viewed
$router->post('/parent-set-child', function (Request $req) {
    Auth::require('parent');
    $body      = $req->json() ?? [];
    $studentId = (int)($body['studentId'] ?? 0);
    if (!Auth::setActiveStudent($studentId)) {
        Response::json(['error' => 'Nicht autorisiert.'], 403);
        return;
    }
    Response::json(['ok' => true]);
});

// Teacher/admin: get task sets for a given student (profile view)
$router->get('/api/student-lg-tasks', function (Request $req) {
    Auth::require('teacher');
    $id         = (int)($req->get('id') ?? 0);
    $schoolYear = trim((string)($req->get('year') ?? ''));
    if ($id <= 0) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getTaskSetsForStudent($id, $schoolYear));
});

// Teacher/admin: get performance data for a given student (profile view)
$router->get('/api/student-lg-performance', function (Request $req) {
    Auth::require('teacher');
    $id         = (int)($req->get('id') ?? 0);
    $schoolYear = trim((string)($req->get('year') ?? ''));
    if ($id <= 0) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getPerformanceForStudent($id, $schoolYear));
});

$router->get('/api/student-performance-years', function (Request $req) {
    Auth::require('teacher');
    $id = (int)($req->get('id') ?? 0);
    if ($id <= 0) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getAvailableYearsForStudent($id));
});

// Teacher: update a student's task status (e.g. acknowledge help request)
$router->post('/lg-taskset-status-teacher', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $teacherId = (int)Auth::user()['id'];
    $studentId = (int)($body['studentId'] ?? 0);
    $taskSetId = (int)($body['taskSetId'] ?? 0);
    $status    = (int)($body['status']    ?? 0);
    $ok = (new LerngruppeRepository(db()))->setTaskSetStatusAsTeacher($teacherId, $studentId, $taskSetId, $status);
    if (!$ok) { Response::json(['error' => 'Nicht autorisiert.'], 403); return; }
    Response::json(['ok' => true]);
});

// Teacher: return a submitted task to the student
$router->post('/lg-return-task', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $teacherId = (int)Auth::user()['id'];
    $studentId = (int)($body['studentId'] ?? 0);
    $taskSetId = (int)($body['taskSetId'] ?? 0);
    if ($studentId <= 0 || $taskSetId <= 0) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $ok = (new LerngruppeRepository(db()))->returnTaskSet($teacherId, $studentId, $taskSetId);
    $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'Nicht autorisiert.'], 403);
});

// Teacher: record achieved points for a submitted task set
$router->post('/lg-set-score', function (Request $req) {
    Auth::require('teacher');
    $body           = $req->json() ?? [];
    $teacherId      = (int)Auth::user()['id'];
    $studentId      = (int)($body['studentId']      ?? 0);
    $taskSetId      = (int)($body['taskSetId']       ?? 0);
    $achievedPoints = (int)($body['achievedPoints']  ?? 0);
    if ($studentId <= 0 || $taskSetId <= 0 || $achievedPoints < 0) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $ok = (new LerngruppeRepository(db()))->setAchievedPoints($teacherId, $studentId, $taskSetId, $achievedPoints);
    $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'Nicht autorisiert.'], 403);
});

// Admin: set or clear achieved points for any student's task set
$router->post('/admin/set-student-score', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $studentId = (int)($body['studentId'] ?? 0);
    $taskSetId = (int)($body['taskSetId']  ?? 0);
    $points    = array_key_exists('achievedPoints', $body) && $body['achievedPoints'] !== null
        ? (int)$body['achievedPoints'] : null;
    if ($studentId <= 0 || $taskSetId <= 0) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $ok = (new LerngruppeRepository(db()))->adminSetAchievedPoints($studentId, $taskSetId, $points);
    $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'Ungültige Punkte.'], 400);
});

// Teacher/admin: override a student's task-set status from the class matrix
$router->post('/admin/set-student-task-status', function (Request $req) {
    Auth::require('teacher');
    $body      = $req->json() ?? [];
    $studentId = (int)($body['studentId'] ?? 0);
    $taskSetId = (int)($body['taskSetId'] ?? 0);
    $status    = (int)($body['status']    ?? -1);
    if ($studentId <= 0 || $taskSetId <= 0 || $status < 0 || $status > 4) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    (new LerngruppeRepository(db()))->setTaskSetStatus($studentId, $taskSetId, $status);
    Response::json(['ok' => true]);
});

// Student: update status for an Aufgabenset
$router->post('/lg-taskset-status', function (Request $req) {
    Auth::require('student');
    $body      = $req->json() ?? [];
    $studentId = (int)Auth::user()['id'];
    $taskSetId = (int)($body['tasksetId'] ?? 0);
    $status    = (int)($body['status']    ?? 0);
    if ($taskSetId <= 0 || $status < 0 || $status > 4) {
        Response::json(['error' => 'Fehlende oder ungültige Parameter.'], 400);
        return;
    }
    (new LerngruppeRepository(db()))->setTaskSetStatus($studentId, $taskSetId, $status);
    Response::json(['ok' => true]);
});

// --- Module settings stub (full module system: Chunk 5) ---

$router->post('/get-module', function (Request $req) {
    Auth::require('user');
    $key      = (string)(($req->json() ?? [])['key'] ?? '');
    $defaults = [
        'result_view' => [
            'show_current_grade'    => ['value' => true],
            'show_current_progress' => ['value' => true],
        ],
    ];
    Response::json(['settings' => $defaults[$key] ?? []]);
});

// ===========================================================================
// ADMIN — Pages
// ===========================================================================

$router->get('/manage_students', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_students.php');
});

$router->get('/manage_teachers', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_teachers.php');
});

$router->get('/manage_parents', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_parents.php');
});

// ===========================================================================
// ADMIN — Parent management API
// ===========================================================================

$router->get('/parents', function (Request $req) {
    Auth::require('admin');
    Response::json((new ParentRepository(db()))->getAll());
});

$router->post('/add-parent', function (Request $req) {
    Auth::require('admin');
    $body        = $req->json() ?? [];
    $email       = trim((string)($body['email']       ?? ''));
    $benutzername = trim((string)($body['benutzername'] ?? '')) ?: null;
    if ($email === '') { Response::json(['error' => 'E-Mail fehlt.'], 400); return; }
    try {
        $id = (new ParentRepository(db()))->create($email, $benutzername);
        Response::json(['id' => $id, 'email' => $email]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 409);
    }
});

$router->post('/add-parent-link', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $parentId  = (int)($body['parentId']  ?? 0);
    $studentId = (int)($body['studentId'] ?? 0);
    if ($parentId <= 0 || $studentId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new ParentRepository(db()))->linkStudent($parentId, $studentId);
    Response::json(['ok' => true]);
});

$router->post('/remove-parent-link', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $parentId  = (int)($body['parentId']  ?? 0);
    $studentId = (int)($body['studentId'] ?? 0);
    if ($parentId <= 0 || $studentId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new ParentRepository(db()))->unlinkStudent($parentId, $studentId);
    Response::json(['ok' => true]);
});

$router->post('/reset-parent-password', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $parentId = (int)($body['parentId'] ?? 0);
    if ($parentId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new ParentRepository(db()))->resetPassword($parentId);
    Response::json(['ok' => true]);
});

$router->post('/delete-parent', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $parentId = (int)($body['parentId'] ?? 0);
    if ($parentId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new ParentRepository(db()))->delete($parentId);
    Response::json(['ok' => true]);
});

$router->post('/update-parent', function (Request $req) {
    Auth::require('admin');
    $body         = $req->json() ?? [];
    $id           = (int)($body['id']           ?? 0);
    $email        = trim((string)($body['email']        ?? ''));
    $benutzername = trim((string)($body['benutzername'] ?? '')) ?: null;
    if ($id <= 0 || $email === '') { Response::json(['error' => 'E-Mail fehlt.'], 400); return; }
    try {
        (new ParentRepository(db()))->update($id, $email, $benutzername);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->get('/teacher-profile', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/teacher_profile.html');
});

$router->get('/api/teacher-profile', function (Request $req) {
    Auth::require('admin');
    $id = (int)($req->get('id') ?? 0);
    if ($id <= 0) { Response::notFound(); return; }
    $data = (new AdminRepository(db()))->getTeacherProfileData($id);
    $data ? Response::json($data) : Response::notFound();
});

$router->get('/api/teacher-students', function (Request $req) {
    Auth::require('admin');
    $id = (int)($req->get('id') ?? 0);
    if ($id <= 0) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getStudentsForTeacher($id));
});

$router->get('/api/teacher-lerngruppen', function (Request $req) {
    Auth::require('admin');
    $id = (int)($req->get('id') ?? 0);
    if ($id <= 0) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getLerngruppenWithTaskSets($id));
});

$router->post('/add-lerngruppe', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $teacherId = (int)($body['teacherId'] ?? 0);
    $classId   = (int)($body['classId']   ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    if (!db()->fetch('SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ?', [$classId, $subjectId])) {
        Response::json(['error' => 'Dieser Klasse wurde das Fach noch nicht zugewiesen. Bitte zuerst das Fach in der Klassen-Verwaltung zuweisen.'], 400);
        return;
    }
    (new AdminRepository(db()))->addLerngruppe($teacherId, $classId, $subjectId);
    if (settings()->get('task_scope', 'grade') === 'class') {
        (new LerngruppeRepository(db()))->copyTaskSetsToNewLerngruppe($classId, $subjectId);
    }
    Response::json(['ok' => true]);
});

$router->post('/remove-lerngruppe', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    (new AdminRepository(db()))->removeLerngruppe(
        (int)($body['teacherId']  ?? 0),
        (int)($body['classId']    ?? 0),
        (int)($body['subjectId']  ?? 0)
    );
    Response::json(['ok' => true]);
});

$router->get('/manage_classes', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_classes.php', 200, [
        'allowClassDeletion' => settings()->get('allow_class_deletion', false),
    ]);
});

$router->get('/class-dashboard', function (Request $req) {
    Auth::require('teacher');
    $viewerRole = Auth::isAdmin() ? 'admin' : 'teacher';
    Response::html(__DIR__ . '/../templates/admin/class_dashboard.php', 200, ['viewerRole' => $viewerRole]);
});

$router->get('/student-class-dashboard', function (Request $req) {
    Auth::require('student');
    Response::html(__DIR__ . '/../templates/admin/class_dashboard.php', 200, ['viewerRole' => 'student']);
});

$router->get('/api/class-task-matrix', function (Request $req) {
    Auth::require('teacher');
    $classId = (int)($req->get('id') ?? 0);
    if ($classId <= 0) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getClassTaskMatrix($classId));
});

$router->get('/api/my-class-task-matrix', function (Request $req) {
    Auth::require('student');
    $studentId = (int)Auth::user()['id'];
    $row = db()->fetch('SELECT class AS classId FROM students WHERE id = ?', [$studentId]);
    if (!$row) { Response::notFound(); return; }
    Response::json((new LerngruppeRepository(db()))->getClassTaskMatrix((int)$row['classId']));
});

$router->get('/manage_subjects', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_subjects.php', 200, [
        'allowSubjectDeletion' => settings()->get('allow_subject_deletion', false),
    ]);
});

$router->get('/manage_rooms', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_rooms.php');
});

$router->get('/manage_admins', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_admins.php');
});

$router->get('/manage_tasks', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/manage_tasks.php', 200, [
        'adminOnlyTasksets' => settings()->get('task_scope', 'grade') === 'grade',
        'taskScope'         => settings()->get('task_scope', 'grade'),
    ]);
});

$router->get('/settings', function (Request $req) {
    Auth::require('admin');
    $currentYear = settings()->currentSchoolYear();
    $nextYear    = AppSettings::nextYearLabel($currentYear);
    Response::html(__DIR__ . '/../templates/admin/settings.php', 200, compact('currentYear', 'nextYear'));
});

$router->get('/import', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/import.php');
});

$router->get('/modules', function (Request $req) {
    Auth::require('admin');
    Response::html(__DIR__ . '/../templates/admin/modules.php');
});

// ===========================================================================
// ADMIN — Data APIs
// ===========================================================================

$router->get('/students', function (Request $req) {
    Auth::require('admin');
    Response::json((new AdminRepository(db()))->getAllStudents());
});

$router->get('/teachers', function (Request $req) {
    Auth::require('admin');
    Response::json((new AdminRepository(db()))->getAllTeachers());
});

$router->get('/admins', function (Request $req) {
    Auth::require('admin');
    Response::json((new AdminRepository(db()))->getAllAdmins());
});

// ===========================================================================
// SETTINGS API
// ===========================================================================

$router->get('/api/settings', function (Request $req) {
    Auth::require('admin');
    Response::json(settings()->all());
});

$router->post('/api/settings', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $key  = trim((string)($body['key']   ?? ''));
    $val  = $body['value'] ?? null;
    if ($key === '') { Response::json(['error' => 'Schlüssel fehlt.'], 400); return; }

    if ($key === 'task_scope') {
        $repo = new LerngruppeRepository(db());

        if ($val === 'grade') {
            // class-scope → grade-scope: check consistency first, then merge
            $check = $repo->checkTaskSetConsistency();
            if (!empty($check['hardConflicts'])) {
                Response::json(['error' => 'hard_conflict', 'conflicts' => $check['hardConflicts']], 409);
                return;
            }
            if (!empty($check['softWarnings']) && empty($body['force'])) {
                Response::json(['error' => 'soft_warning', 'warnings' => $check['softWarnings']], 409);
                return;
            }
            $repo->mergeTaskSetsToGrade();
        } elseif ($val === 'class') {
            // grade-scope → class-scope: copy grade-level tasks per class
            $repo->migrateTaskSetsToClass();
        }
    }

    settings()->set($key, $val);
    Response::json(['ok' => true]);
});

$router->post('/api/graduation-config', function (Request $req) {
    Auth::require('admin');
    $body         = $req->json() ?? [];
    $categoryName = trim((string)($body['categoryName'] ?? ''));
    $levels       = $body['levels'] ?? [];

    if ($categoryName === '') {
        Response::json(['error' => 'Kategoriename darf nicht leer sein.'], 400); return;
    }
    if (!is_array($levels) || count($levels) < 1) {
        Response::json(['error' => 'Mindestens eine Stufe erforderlich.'], 400); return;
    }
    $levels = array_values(array_filter(array_map('trim', $levels), fn($s) => $s !== ''));
    if (count($levels) < 1) {
        Response::json(['error' => 'Mindestens eine Stufe erforderlich.'], 400); return;
    }

    settings()->set('graduation_config', ['category_name' => $categoryName, 'levels' => $levels]);

    // Clamp any students whose level now exceeds the new maximum
    $maxLevel = count($levels) - 1;
    db()->execute('UPDATE students SET graduation_level = ? WHERE graduation_level > ?', [$maxLevel, $maxLevel]);

    Response::json(['ok' => true]);
});

// ===========================================================================
// ADMIN — Aufgabensets overview + grade-level operations
// ===========================================================================

$router->get('/api/admin-tasksets', function (Request $req) {
    Auth::require('admin');
    Response::json((new AdminRepository(db()))->getAllTaskSets());
});

// Create task set for every Lerngruppe at a given Stufe + Fach
$router->post('/admin/create-grade-taskset', function (Request $req) {
    Auth::require('admin');
    if (settings()->currentSchoolYear() === '') {
        Response::json(['error' => 'Bitte zuerst das aktuelle Schuljahr in den Einstellungen festlegen.'], 400); return;
    }
    $body       = $req->json() ?? [];
    $grade      = (int)($body['grade']     ?? 0);
    $subjectId  = (int)($body['subjectId'] ?? 0);
    $name       = trim((string)($body['name']      ?? ''));
    $maxPoints  = max(1, (int)($body['maxPoints'] ?? 1));
    $isPassFail = (bool)($body['isPassFail'] ?? false);
    if ($grade <= 0 || $subjectId <= 0 || $name === '') {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $schoolYear = settings()->currentSchoolYear();
    $taskScope  = settings()->get('task_scope', 'grade');

    if ($taskScope === 'class') {
        // Class-scope: one row per class at this grade
        $classes = db()->fetchAll(
            'SELECT DISTINCT c.id AS classId FROM teacher_lerngruppen tl
             JOIN classes c ON tl.class_id = c.id
             WHERE c.grade = ? AND tl.subject_id = ?',
            [$grade, $subjectId]
        );
        if (empty($classes)) {
            Response::json(['error' => 'Keine Klassen für diese Stufe/Fach-Kombination.'], 400); return;
        }
        $duplicate = db()->fetch(
            'SELECT 1 FROM lg_tasksets ts JOIN classes c ON ts.class_id = c.id
             WHERE c.grade = ? AND ts.subject_id = ? AND ts.name = ? LIMIT 1',
            [$grade, $subjectId, $name]
        );
        if ($duplicate) {
            Response::json(['error' => 'ein Aufgabenset mit diesem Namen existiert bereits.'], 409); return;
        }
        $firstId = null;
        foreach ($classes as $cls) {
            db()->execute(
                'INSERT IGNORE INTO lg_tasksets (class_id, subject_id, grade, name, max_points, school_year, is_pass_fail)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$cls['classId'], $subjectId, $grade, $name, $maxPoints, $schoolYear, $isPassFail ? 1 : 0]
            );
            if ($firstId === null) $firstId = db()->lastInsertId();
        }
        Response::json(['ok' => true, 'id' => $firstId]);
    } else {
        // Grade-scope: one shared row (class_id IS NULL)
        $duplicate = db()->fetch(
            'SELECT 1 FROM lg_tasksets WHERE class_id IS NULL AND grade = ? AND subject_id = ? AND name = ? LIMIT 1',
            [$grade, $subjectId, $name]
        );
        if ($duplicate) {
            Response::json(['error' => 'ein Aufgabenset mit diesem Namen existiert bereits.'], 409); return;
        }
        db()->execute(
            'INSERT INTO lg_tasksets (class_id, subject_id, grade, name, max_points, school_year, is_pass_fail)
             VALUES (NULL, ?, ?, ?, ?, ?, ?)',
            [$subjectId, $grade, $name, $maxPoints, $schoolYear, $isPassFail ? 1 : 0]
        );
        Response::json(['ok' => true, 'id' => db()->lastInsertId()]);
    }
});

// Update a task set and all its siblings at the same Stufe + Fach
$router->post('/admin/update-grade-taskset', function (Request $req) {
    Auth::require('admin');
    $body       = $req->json() ?? [];
    $taskSetId  = (int)($body['tasksetId'] ?? 0);
    $name       = trim((string)($body['name']      ?? ''));
    $maxPoints  = max(1, (int)($body['maxPoints'] ?? 1));
    $isPassFail = (bool)($body['isPassFail'] ?? false);
    if ($taskSetId <= 0 || $name === '') {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $canonical = db()->fetch(
        'SELECT ts.name AS oldName, ts.subject_id, COALESCE(c.grade, ts.grade) AS grade
         FROM lg_tasksets ts LEFT JOIN classes c ON ts.class_id = c.id WHERE ts.id = ?',
        [$taskSetId]
    );
    if (!$canonical) { Response::notFound(); return; }
    if ($name !== $canonical['oldName']) {
        $duplicate = db()->fetch(
            'SELECT 1 FROM lg_tasksets WHERE subject_id = ? AND name = ?
             AND (class_id IN (SELECT id FROM classes WHERE grade = ?)
                  OR (class_id IS NULL AND grade = ?)) LIMIT 1',
            [$canonical['subject_id'], $name, $canonical['grade'], $canonical['grade']]
        );
        if ($duplicate) {
            Response::json(['error' => 'ein Aufgabenset mit diesem Namen existiert bereits.'], 409); return;
        }
    }
    db()->execute(
        'UPDATE lg_tasksets SET name = ?, max_points = ?, is_pass_fail = ?
         WHERE subject_id = ? AND name = ?
         AND (class_id IN (SELECT id FROM classes WHERE grade = ?)
              OR (class_id IS NULL AND grade = ?))',
        [$name, $maxPoints, $isPassFail ? 1 : 0, $canonical['subject_id'], $canonical['oldName'], $canonical['grade'], $canonical['grade']]
    );
    Response::json(['ok' => true]);
});

// Toggle active state of a task set and all its siblings
$router->post('/admin/toggle-grade-taskset', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $taskSetId = (int)($body['tasksetId'] ?? 0);
    if ($taskSetId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    $canonical = db()->fetch(
        'SELECT ts.name, ts.subject_id, ts.active, COALESCE(c.grade, ts.grade) AS grade
         FROM lg_tasksets ts LEFT JOIN classes c ON ts.class_id = c.id WHERE ts.id = ?',
        [$taskSetId]
    );
    if (!$canonical) { Response::notFound(); return; }
    $newActive = $canonical['active'] ? 0 : 1;
    db()->execute(
        'UPDATE lg_tasksets SET active = ?
         WHERE subject_id = ? AND name = ?
         AND (class_id IN (SELECT id FROM classes WHERE grade = ?)
              OR (class_id IS NULL AND grade = ?))',
        [$newActive, $canonical['subject_id'], $canonical['name'], $canonical['grade'], $canonical['grade']]
    );
    Response::json(['active' => (bool)$newActive]);
});

// Delete a task set and all its siblings at the same Stufe + Fach
$router->post('/admin/delete-grade-taskset', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $taskSetId = (int)($body['tasksetId'] ?? 0);
    if ($taskSetId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    $canonical = db()->fetch(
        'SELECT ts.name, ts.subject_id, COALESCE(c.grade, ts.grade) AS grade
         FROM lg_tasksets ts LEFT JOIN classes c ON ts.class_id = c.id WHERE ts.id = ?',
        [$taskSetId]
    );
    if (!$canonical) { Response::notFound(); return; }
    db()->execute(
        'DELETE FROM lg_tasksets
         WHERE subject_id = ? AND name = ?
         AND (class_id IN (SELECT id FROM classes WHERE grade = ?)
              OR (class_id IS NULL AND grade = ?))',
        [$canonical['subject_id'], $canonical['name'], $canonical['grade'], $canonical['grade']]
    );
    Response::json(['ok' => true]);
});

$router->post('/admin/save-grade-grading-scale', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $grade     = (int)($body['grade']     ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    $thresholds = $body['thresholds'] ?? [];
    if ($grade <= 0 || $subjectId <= 0 || count($thresholds) !== 5) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    (new LerngruppeRepository(db()))->saveGradingScaleForGrade($grade, $subjectId, array_map('floatval', $thresholds));
    Response::json(['ok' => true]);
});

// ---------------------------------------------------------------------------
// School year management
// ---------------------------------------------------------------------------

$router->get('/api/school-years', function (Request $req) {
    Auth::require('student');
    $rows = db()->fetchAll(
        "SELECT DISTINCT school_year FROM year_archive WHERE school_year <> '' ORDER BY school_year DESC"
    );
    $years   = array_column($rows, 'school_year');
    $current = settings()->currentSchoolYear();
    Response::json(['years' => $years, 'current' => $current]);
});

$router->post('/admin/advance-school-year', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $newLabel = trim((string)($body['label'] ?? ''));
    $password = trim((string)($body['password'] ?? ''));

    $user = Auth::user();
    $row  = db()->fetch('SELECT password_hash FROM admins WHERE username = ?', [$user['username']]);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        Response::json(['error' => 'Falsches Passwort.'], 403); return;
    }
    if ($newLabel === '') {
        Response::json(['error' => 'Kein Schuljahr-Bezeichner angegeben.'], 400); return;
    }
    $exists = db()->fetch(
        'SELECT 1 FROM year_archive WHERE school_year = ? LIMIT 1', [$newLabel]
    );
    if ($exists) {
        Response::json(['error' => 'Das Schuljahr "' . $newLabel . '" enthält bereits Daten und kann nicht erneut verwendet werden.'], 400); return;
    }
    if ($newLabel === settings()->currentSchoolYear()) {
        Response::json(['error' => 'Das angegebene Schuljahr ist bereits das aktuelle.'], 400); return;
    }

    $lgRepo      = new LerngruppeRepository(db());
    $currentYear = settings()->currentSchoolYear();

    // Snapshot all student×taskset data BEFORE promotion so class membership is still accurate
    if ($currentYear !== '') {
        $lgRepo->snapshotCurrentYear($currentYear);
    }

    // Advance each student to the next class (same label, grade + 1)
    $students = db()->fetchAll(
        'SELECT s.id, c.grade, c.label AS class_label
         FROM students s JOIN classes c ON s.class = c.id'
    );
    $advanced = 0;
    $noClass  = 0;
    foreach ($students as $s) {
        $suffix    = substr($s['class_label'], strlen((string)$s['grade']));
        $nextLabel = ((string)((int)$s['grade'] + 1)) . $suffix;
        $next = db()->fetch(
            'SELECT id FROM classes WHERE grade = ? AND label = ? LIMIT 1',
            [(int)$s['grade'] + 1, $nextLabel]
        );
        if ($next) {
            db()->execute('UPDATE students SET class = ? WHERE id = ?', [(int)$next['id'], (int)$s['id']]);
            $advanced++;
        } else {
            $noClass++;
        }
    }

    if ($currentYear !== '') {
        // Reset all student task progress — the archive already holds the scores.
        db()->execute('DELETE FROM lg_taskset_status');

        // Rename the year label on all task sets in place (no duplication needed).
        db()->execute(
            'UPDATE lg_tasksets SET school_year = ? WHERE school_year = ?',
            [$newLabel, $currentYear]
        );
    }

    // Update setting last so any failure above leaves the year unchanged
    settings()->set('current_school_year', $newLabel);

    Response::json([
        'ok'       => true,
        'advanced' => $advanced,
        'noClass'  => $noClass,
        'newYear'  => $newLabel,
    ]);
});

$router->get('/api/year-archives', function (Request $req) {
    Auth::require('admin');
    $archives = (new LerngruppeRepository(db()))->getYearArchives();
    Response::json($archives);
});

$router->post('/admin/delete-year-archive', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $year = trim((string)($body['year'] ?? ''));
    if ($year === '') {
        Response::json(['error' => 'Kein Schuljahr angegeben.'], 400); return;
    }
    (new LerngruppeRepository(db()))->deleteYearArchive($year);
    Response::json(['ok' => true]);
});

$router->get('/admin/download-year-archive', function (Request $req) {
    Auth::require('admin');
    $year = trim((string)($_GET['year'] ?? ''));
    if ($year === '') {
        http_response_code(400); echo 'Kein Schuljahr angegeben.'; return;
    }
    $json     = (new DatabaseManager(db()))->backupYearArchive($year);
    $safe     = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $year);
    $filename = 'archiv-' . $safe . '.json';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
});

$router->get('/all-users', function (Request $req) {
    Auth::require('admin');
    Response::json((new AdminRepository(db()))->getAllUsers());
});

$router->post('/promote-to-admin', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $id   = (int)($body['id']   ?? 0);
    $type = (string)($body['type'] ?? '');
    if ($id <= 0 || $type !== 'teacher') {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    try {
        (new AdminRepository(db()))->promoteUserToAdmin($id, $type);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/reset-user-password', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $id       = (int)($body['id']       ?? 0);
    $type     = (string)($body['type']  ?? '');
    $password = (string)($body['password'] ?? '');
    if ($id <= 0 || !in_array($type, ['student', 'teacher', 'parent'], true) || $password === '') {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    try {
        (new AdminRepository(db()))->resetUserPassword($id, $type, $password);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/add-admin', function (Request $req) {
    Auth::require('admin');
    $body         = $req->json() ?? [];
    $firstName    = trim((string)($body['firstName']    ?? ''));
    $lastName     = trim((string)($body['lastName']     ?? ''));
    $email        = trim((string)($body['email']        ?? ''));
    $benutzername = trim((string)($body['benutzername'] ?? '')) ?: null;
    if ($email === '') { Response::json(['error' => 'E-Mail erforderlich.'], 400); return; }
    try {
        (new AdminRepository(db()))->addAdmin($email, $benutzername, $firstName, $lastName);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/delete-admin', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $username = trim((string)($body['username'] ?? ''));
    if ($username === '') { Response::json(['error' => 'Benutzername fehlt.'], 400); return; }
    $current  = Auth::user()['id'];
    $ok       = (new AdminRepository(db()))->deleteAdmin($username, $current);
    $ok
        ? Response::json(['ok' => true])
        : Response::json(['error' => 'Admin kann nicht gelöscht werden (letzter Admin oder eigenes Konto).'], 400);
});

$router->post('/update-admin', function (Request $req) {
    Auth::require('admin');
    $body         = $req->json() ?? [];
    $username     = trim((string)($body['username']     ?? ''));
    $firstName    = trim((string)($body['firstName']    ?? ''));
    $lastName     = trim((string)($body['lastName']     ?? ''));
    $email        = trim((string)($body['email']        ?? ''));
    $benutzername = trim((string)($body['benutzername'] ?? '')) ?: null;
    if ($username === '' || $email === '') { Response::json(['error' => 'E-Mail fehlt.'], 400); return; }
    try {
        (new AdminRepository(db()))->updateAdmin($username, $email, $benutzername, $firstName, $lastName);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/reset-admin-password', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') { Response::json(['error' => 'Benutzername und Passwort erforderlich.'], 400); return; }
    (new AdminRepository(db()))->resetAdminPassword($username, $password);
    Response::json(['ok' => true]);
});

$router->post('/update-student', function (Request $req) {
    Auth::require('admin');
    $body            = $req->json() ?? [];
    $id              = (int)($body['id']              ?? 0);
    $firstName       = trim((string)($body['firstName']       ?? ''));
    $lastName        = trim((string)($body['lastName']        ?? ''));
    $email           = trim((string)($body['email']           ?? ''));
    $classId         = (int)($body['classId']         ?? 0);
    $graduationLevel = (int)($body['graduationLevel'] ?? 0);
    $benutzername    = trim((string)($body['benutzername']    ?? '')) ?: null;
    if ($id <= 0 || $firstName === '' || $lastName === '' || $email === '' || $classId <= 0) {
        Response::json(['error' => 'Bitte alle Felder ausfüllen.'], 400); return;
    }
    try {
        (new AdminRepository(db()))->updateStudent($id, $firstName, $lastName, $email, $classId, $graduationLevel, $benutzername);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/delete-student', function (Request $req) {
    Auth::require('admin');
    $id = (int)(($req->json() ?? [])['id'] ?? 0);
    if ($id <= 0) { Response::json(['error' => 'Ungültige ID.'], 400); return; }
    (new AdminRepository(db()))->deleteStudent($id);
    Response::json(['ok' => true]);
});

$router->post('/add-student', function (Request $req) {
    Auth::require('admin');
    $body            = $req->json() ?? [];
    $firstName       = trim((string)($body['firstName']       ?? ''));
    $lastName        = trim((string)($body['lastName']        ?? ''));
    $email           = trim((string)($body['email']           ?? ''));
    $classId         = (int)($body['classId']         ?? 0);
    $graduationLevel = (int)($body['graduationLevel'] ?? 0);
    $benutzername    = trim((string)($body['benutzername']    ?? '')) ?: null;

    if ($firstName === '' || $lastName === '' || $email === '' || $classId <= 0) {
        Response::json(['error' => 'Bitte alle Felder ausfüllen.'], 400);
        return;
    }

    try {
        $result = (new AdminRepository(db()))->addStudent($firstName, $lastName, $email, $classId, $graduationLevel, $benutzername);
        Response::json($result);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    } catch (\PDOException $e) {
        Response::json(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500);
    }
});

$router->post('/add-students', function (Request $req) {
    Auth::require('admin');
    $csv = $req->body();
    if (!$csv) { Response::text('No data', 400); return; }
    $result = (new AdminRepository(db()))->addStudentsFromCsv($csv);
    Response::csv($result, 'schueler_passwoerter.csv');
});

$router->post('/add-teachers', function (Request $req) {
    Auth::require('admin');
    $csv = $req->body();
    if (!$csv) { Response::text('No data', 400); return; }
    $result = (new AdminRepository(db()))->addTeachersFromCsv($csv);
    Response::csv($result, 'lehrer_passwoerter.csv');
});

$router->post('/preview-import', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $type = trim((string)($body['type'] ?? ''));
    $csv  = (string)($body['csv']  ?? '');
    if (!$type || !$csv) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    try {
        Response::json((new AdminRepository(db()))->previewImport($type, $csv));
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/execute-import', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $type = trim((string)($body['type'] ?? ''));
    $rows = $body['rows'] ?? [];
    if (!$type || !is_array($rows)) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    try {
        Response::json((new AdminRepository(db()))->executeImport($type, $rows));
    } catch (Exception $e) {
        Response::json(['error' => $e->getMessage()], 500);
    }
});

$router->post('/export-data', function (Request $req) {
    Auth::require('admin');
    $types = $req->json()['types'] ?? [];
    if (empty($types)) { Response::json(['error' => 'Keine Kategorien ausgewählt.'], 400); return; }

    $repo    = new AdminRepository(db());
    $tmpFile = tempnam(sys_get_temp_dir(), 'export_');
    $zip     = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($types as $type) {
        try {
            $zip->addFromString("{$type}.csv", $repo->exportToCsv($type));
        } catch (InvalidArgumentException) {
            // skip unknown types silently
        }
    }
    $zip->close();

    $filename = 'export_' . date('Y-m-d') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
});

$router->post('/update-teacher-room', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    (new AdminRepository(db()))->updateTeacherRoom((int)($body['teacherId'] ?? 0), $body['room'] ?? null);
    Response::text('OK');
});

$router->post('/update-teacher', function (Request $req) {
    Auth::require('admin');
    $body         = $req->json() ?? [];
    $id           = (int)($body['id']           ?? 0);
    $firstName    = trim((string)($body['firstName']    ?? ''));
    $lastName     = trim((string)($body['lastName']     ?? ''));
    $email        = trim((string)($body['email']        ?? ''));
    $benutzername = trim((string)($body['benutzername'] ?? '')) ?: null;
    if ($id <= 0 || $firstName === '' || $lastName === '' || $email === '') {
        Response::json(['error' => 'Bitte alle Felder ausfüllen.'], 400); return;
    }
    try {
        (new AdminRepository(db()))->updateTeacher($id, $firstName, $lastName, $email, $benutzername);
        Response::json(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/delete-teacher', function (Request $req) {
    Auth::require('admin');
    $id = (int)(($req->json() ?? [])['id'] ?? 0);
    if ($id <= 0) { Response::json(['error' => 'Ungültige ID.'], 400); return; }
    (new AdminRepository(db()))->deleteTeacher($id);
    Response::json(['ok' => true]);
});

$router->post('/add-room', function (Request $req) {
    Auth::require('admin');
    $body  = $req->json() ?? [];
    $label = trim((string)($body['label']        ?? ''));
    $level = (int)($body['minimumLevel'] ?? 0);
    if ($label === '') { Response::json(['error' => 'Raumname fehlt.'], 400); return; }
    (new AdminRepository(db()))->addRoom($label, $level);
    Response::json(['ok' => true]);
});

$router->post('/edit-room', function (Request $req) {
    Auth::require('admin');
    $body     = $req->json() ?? [];
    $oldLabel = trim((string)($body['oldLabel']    ?? ''));
    $label    = trim((string)($body['label']       ?? ''));
    $level    = (int)($body['minimumLevel'] ?? 0);
    if ($label === '' || $oldLabel === '') { Response::json(['error' => 'Raumname fehlt.'], 400); return; }
    try {
        (new AdminRepository(db()))->updateRoom($oldLabel, $label, $level);
    } catch (\RuntimeException $e) {
        Response::json(['error' => $e->getMessage()], 400); return;
    }
    Response::json(['ok' => true]);
});

$router->post('/delete-room', function (Request $req) {
    Auth::require('admin');
    $label = trim((string)(($req->json() ?? [])['label'] ?? ''));
    if ($label === '') { Response::json(['error' => 'Raumname fehlt.'], 400); return; }
    (new AdminRepository(db()))->deleteRoom($label);
    Response::json(['ok' => true]);
});

$router->post('/add-teacher', function (Request $req) {
    Auth::require('admin');
    $body         = $req->json() ?? [];
    $firstName    = trim((string)($body['firstName']    ?? ''));
    $lastName     = trim((string)($body['lastName']     ?? ''));
    $email        = trim((string)($body['email']        ?? ''));
    $benutzername = trim((string)($body['benutzername'] ?? '')) ?: null;
    if ($firstName === '' || $lastName === '' || $email === '') {
        Response::json(['error' => 'Bitte alle Felder ausfüllen.'], 400); return;
    }
    try {
        Response::json((new AdminRepository(db()))->addTeacher($firstName, $lastName, $email, $benutzername));
    } catch (InvalidArgumentException $e) {
        Response::json(['error' => $e->getMessage()], 400);
    } catch (\PDOException $e) {
        Response::json(['error' => 'Datenbankfehler: ' . $e->getMessage()], 500);
    }
});

$router->post('/add-rooms', function (Request $req) {
    Auth::require('admin');
    $csv = $req->body();
    if (!$csv) { Response::text('No data', 400); return; }
    (new AdminRepository(db()))->addRoomsFromCsv($csv);
    Response::text('OK');
});

$router->get('/api/all-class-subjects', function (Request $req) {
    Auth::require('admin');
    Response::json((new AdminRepository(db()))->getAllClassSubjects());
});

$router->post('/assign-class-subject', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $classId   = (int)($body['classId']   ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    if ($classId <= 0 || $subjectId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new AdminRepository(db()))->assignSubjectToClass($classId, $subjectId);
    Response::json(['ok' => true]);
});

$router->post('/remove-class-subject', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $classId   = (int)($body['classId']   ?? 0);
    $subjectId = (int)($body['subjectId'] ?? 0);
    if ($classId <= 0 || $subjectId <= 0) { Response::json(['error' => 'Fehlende Parameter.'], 400); return; }
    (new AdminRepository(db()))->removeSubjectFromClass($classId, $subjectId);
    Response::json(['ok' => true]);
});

$router->post('/add-class', function (Request $req) {
    Auth::require('admin');
    $body  = $req->json() ?? [];
    $label = trim((string)($body['className'] ?? ''));
    $grade = (int)($body['grade'] ?? 0);
    if ($label === '' || $grade <= 0) { Response::text('Missing fields', 400); return; }
    $repo      = new AdminRepository(db());
    $classId   = $repo->addClass($label, $grade);
    $subjectIds = array_map('intval', (array)($body['subjectIds'] ?? []));
    foreach ($subjectIds as $sid) {
        if ($sid > 0) $repo->assignSubjectToClass($classId, $sid);
    }
    Response::json(['id' => $classId]);
});

$router->post('/edit-class', function (Request $req) {
    Auth::require('admin');
    $body  = $req->json() ?? [];
    $id    = (int)($body['classId'] ?? 0);
    $label = trim((string)($body['name']  ?? ''));
    $grade = (int)($body['grade'] ?? 0);
    if ($id <= 0 || $label === '') { Response::text('Missing fields', 400); return; }
    (new AdminRepository(db()))->editClass($id, $label, $grade);
    Response::text('OK');
});

$router->post('/delete-class', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) { Response::text('Missing id', 400); return; }
    if (!settings()->get('allow_class_deletion', false)) {
        $count = (int)(db()->fetch('SELECT COUNT(*) AS n FROM students WHERE class = ?', [$id])['n'] ?? 0);
        if ($count > 0) {
            Response::json(['error' => 'Das Löschen von Klassen mit Schülern ist in den Einstellungen deaktiviert.'], 403); return;
        }
    }
    (new AdminRepository(db()))->deleteClass($id);
    Response::text('OK');
});

$router->post('/add-subject', function (Request $req) {
    Auth::require('admin');
    $body  = $req->json() ?? [];
    $name  = trim((string)($body['name']  ?? ''));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : null;
    if ($name === '') { Response::text('Missing name', 400); return; }
    (new AdminRepository(db()))->addSubject($name, $color);
    Response::text('OK');
});

$router->post('/edit-subject', function (Request $req) {
    Auth::require('admin');
    $body  = $req->json() ?? [];
    $id    = (int)($body['id']   ?? 0);
    $name  = trim((string)($body['name'] ?? ''));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : null;
    if ($id <= 0 || $name === '') { Response::text('Missing fields', 400); return; }
    (new AdminRepository(db()))->editSubject($id, $name, $color);
    Response::text('OK');
});

$router->post('/reorder-subject', function (Request $req) {
    Auth::require('admin');
    $body      = $req->json() ?? [];
    $id        = (int)($body['id'] ?? 0);
    $direction = $body['direction'] ?? '';
    if ($id <= 0 || !in_array($direction, ['up', 'down'], true)) {
        Response::json(['error' => 'Fehlende Parameter.'], 400); return;
    }
    $current = db()->fetch('SELECT id, sort_order FROM subjects WHERE id = ?', [$id]);
    if (!$current) { Response::notFound(); return; }
    if ($direction === 'up') {
        $neighbor = db()->fetch(
            'SELECT id, sort_order FROM subjects WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1',
            [$current['sort_order']]
        );
    } else {
        $neighbor = db()->fetch(
            'SELECT id, sort_order FROM subjects WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1',
            [$current['sort_order']]
        );
    }
    if ($neighbor) {
        db()->execute('UPDATE subjects SET sort_order = ? WHERE id = ?', [$neighbor['sort_order'], $current['id']]);
        db()->execute('UPDATE subjects SET sort_order = ? WHERE id = ?', [$current['sort_order'], $neighbor['id']]);
    }
    Response::json(['ok' => true]);
});

$router->post('/delete-subject', function (Request $req) {
    Auth::require('admin');
    $body = $req->json() ?? [];
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) { Response::text('Missing id', 400); return; }
    if (!settings()->get('allow_subject_deletion', false)) {
        $count = (int)(db()->fetch(
            'SELECT COUNT(*) AS n FROM lg_tasksets WHERE subject_id = ?', [$id]
        )['n'] ?? 0);
        if ($count > 0) {
            Response::json(['error' => 'Das Löschen von Fächern mit Aufgabensets ist in den Einstellungen deaktiviert.'], 403); return;
        }
    }
    (new AdminRepository(db()))->deleteSubject($id);
    Response::text('OK');
});

$router->post('/lpt-file', function (Request $req) {
    Auth::require('admin');
    // Stub — LPT import not yet implemented
    Response::text('OK');
});

// ===========================================================================
// Database backup / restore / reset
// ===========================================================================

$router->post('/backup-database', function (Request $req) {
    Auth::require('admin');
    $json     = (new DatabaseManager(db()))->backup();
    $filename = 'lernmonitor-backup-' . date('Y-m-d') . '.json';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
});

$router->post('/restore-database', function (Request $req) {
    Auth::require('admin');

    $password = trim($_POST['password'] ?? '');
    $user     = Auth::user();
    $row      = db()->fetch('SELECT password_hash FROM admins WHERE username = ?', [$user['username']]);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        Response::json(['error' => 'Falsches Passwort.'], 403);
        return;
    }

    $file = $_FILES['backup'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        Response::json(['error' => 'Keine Datei hochgeladen.'], 400);
        return;
    }

    $json = file_get_contents($file['tmp_name']);
    try {
        (new DatabaseManager(db()))->restore($json);
        Response::json(['ok' => true]);
    } catch (\Exception $e) {
        Response::json(['error' => $e->getMessage()], 400);
    }
});

$router->post('/reset-database', function (Request $req) {
    Auth::require('admin');

    $body        = $req->json() ?? [];
    $adminPw     = trim((string)($body['adminPassword'] ?? ''));
    $newEmail    = trim((string)($body['newEmail']      ?? ''));
    $newPassword = trim((string)($body['newPassword']   ?? ''));
    $firstName   = trim((string)($body['firstName']     ?? ''));
    $lastName    = trim((string)($body['lastName']      ?? ''));

    $user = Auth::user();
    $row  = db()->fetch('SELECT password_hash FROM admins WHERE username = ?', [$user['username']]);
    if (!$row || !password_verify($adminPw, $row['password_hash'])) {
        Response::json(['error' => 'Falsches Passwort.'], 403);
        return;
    }
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        Response::json(['error' => 'Ungültige E-Mail-Adresse.'], 400);
        return;
    }
    if (strlen($newPassword) < 6) {
        Response::json(['error' => 'Passwort muss mindestens 6 Zeichen lang sein.'], 400);
        return;
    }
    if ($firstName === '' || $lastName === '') {
        Response::json(['error' => 'Vor- und Nachname sind erforderlich.'], 400);
        return;
    }

    try {
        (new DatabaseManager(db()))->reset($newEmail, $newPassword, $firstName, $lastName);
        Response::json(['ok' => true]);
    } catch (\Exception $e) {
        Response::json(['error' => $e->getMessage()], 500);
    }
});

// ===========================================================================
// Dispatch
// ===========================================================================

$router->dispatch($request);
