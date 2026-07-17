<?php
$readOnly        = $readOnly        ?? false;
$activeStudentId = $activeStudentId ?? null;
$linkedStudents  = $linkedStudents  ?? [];

$title     = $readOnly ? 'Elternansicht' : 'Schüler-Dashboard';
$extraHead = '<link rel="stylesheet" href="' . Config::asset('/css/student-dashboard.css') . '">';
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<section id="student-dashboard" style="--page-gradient: var(--student-gradient)">

    <aside class="profile-sidebar blur-sm shadow-sm">

        <!-- Profile info -->
        <div class="profile-card-name">
            <span class="profile-greeting text-lg">Hallo</span>
            <span class="text-xl text-bold" id="profile-first-name">–</span>
        </div>
        <p class="text-md profile-card-email" id="profile-email">–</p>
        <div class="text-xs text-bold profile-card-badges">
            <span class="profile-badge" id="profile-class">–</span>
            <span class="profile-badge" id="profile-level">–</span>
        </div>

        <div class="profile-room-section">
            <span class="text-xs text-bold profile-room-label">Aktueller Raum</span>
            <?php if ($readOnly): ?>
            <span id="roomDisplay" class="profile-badge" style="align-self:flex-start">–</span>
            <?php else: ?>
            <select id="roomSelect">
                <option value="">kein Raum</option>
            </select>
            <?php endif; ?>
        </div>
        <div class="profile-room-section" id="level-section" style="display:none">
            <span class="text-xs text-bold profile-room-label" data-graduation-label>Abschlussstufe</span>
            <select id="levelSelect"></select>
        </div>

        <div id="year-picker-container"></div>

        <!-- Bottom: actions + logo -->
        <div class="profile-sidebar-footer">
            <?php if (!$readOnly): ?>
            <?php if ($showClassDashboard ?? true): ?>
            <button class="btn profile-sidebar-btn" onclick="window.location.href='/student-class-dashboard'">
                <img src="/imgs/classroom.svg" alt="">Meine Klasse
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <button class="btn profile-sidebar-btn" id="changePasswordBtn">
                <img src="/imgs/password.svg" alt="">Passwort ändern
            </button>
            <button class="btn btn-red profile-sidebar-btn" id="logoutBtn">
                <img src="/imgs/logout.svg" alt="">Abmelden
            </button>
            <div class="profile-sidebar-brand">
                <img src="/imgs/logo.svg" alt="Lernmonitor">
                <span>Lernmonitor</span>
            </div>
        </div>

    </aside>

    <main class="dashboard-main-panel">

        <!-- Fachfortschritt -->
        <section class="card-section blur-lg">
            <div class="card-header"><span id="aufgaben-header"><?= $readOnly ? 'Aufgaben' : 'Meine Aufgaben' ?></span></div>
            <?php if (!$readOnly): ?>
            <div class="card-header-description" id="aufgaben-desc">Klicke auf ein Aufgabenset, um den Status zu aktualisieren.</div>
            <?php endif; ?>
            <div id="fachfortschritt-bars"></div>
            <p id="no-tasks-hint" class="lg-empty text-italic" style="display:none">Noch keine Aufgaben verfügbar.</p>
        </section>

        <!-- Leistungen -->
        <section class="card-section blur-lg" id="leistungen-section" style="display:none">
            <div class="card-header"><span id="leistungen-header-text"><?= $readOnly ? 'Leistungen im Fach' : 'Meine Leistungen im Fach' ?></span>
                <select id="leistungen-subject-select" class="leistungen-select"></select>
            </div>
            <div class="card-header-description" id="leistungen-desc">Hier siehst du, wieviele Punkte du bisher erreicht hast.</div>
            <div id="leistungen-content"></div>
        </section>

    </main>

</section>

<?php include __DIR__ . '/../partials/change-password-modal.php'; ?>
<script>
window.studentViewConfig = {
    studentId:         null,
    viewerRole:        <?= $readOnly ? "'parent'" : "'student'" ?>,
    linkedStudents:    <?= $readOnly ? json_encode(array_values($linkedStudents)) : '[]' ?>,
    showGrades:        <?= json_encode($showGrades ?? true) ?>,
    projectionDefault: <?= json_encode($projectionDefault ?? 50) ?>,
};
</script>
<script src="<?= Config::asset('/js/grading-scale.js') ?>"></script>
<script src="<?= Config::asset('/js/student/student-view.js') ?>"></script>
</body>
</html>
