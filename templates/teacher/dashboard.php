<?php
$adminOnlyTasksets = $adminOnlyTasksets ?? false;
$title     = 'Lehrer-Dashboard';
$extraHead = '
    <link rel="stylesheet" href="' . Config::asset('/css/lehrer-dashboard.css') . '">
    <script>window.teacherViewConfig = { teacherId: null, viewerRole: "teacher", adminOnlyTasksets: ' . ($adminOnlyTasksets ? 'true' : 'false') . ' };</script>
';
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<section id="teacher-dashboard">

    <aside class="profile-sidebar blur-sm shadow-sm">

        <!-- Profile info -->
        <div class="profile-card-name">
            <span class="profile-greeting text-lg" id="profile-greeting">Hallo</span>
            <span class="text-xl text-bold" id="profile-first-name">–</span>
        </div>
        <p class="profile-card-email text-md" id="profile-email">–</p>
        <div class="profile-card-badges text-xs text-bold" id="profile-lerngruppen-badges"></div>

        <div class="profile-room-section">
            <span class="text-xs text-bold profile-room-label">Aktueller Raum</span>
            <select id="roomSelect">
                <option value="">kein Raum</option>
            </select>
        </div>

        <div id="year-picker-container"></div>

        <div class="profile-sidebar-footer">
            <button class="btn profile-sidebar-btn" onclick="window.location.href='/class-dashboard'">
                <img src="/imgs/classroom.svg" alt="">Klassenübersicht
            </button>
            <button class="btn profile-sidebar-btn" id="changePasswordBtn">
                <img src="/imgs/password.svg" alt="">Passwort ändern
            </button>
            <?php
            $__tchUser = Auth::user();
            if (!empty($__tchUser['linked_teacher_id']) && ($__tchUser['active_role'] ?? '') === 'teacher'):
            ?>
            <form method="post" action="/switch-role" style="display:contents">
                <button type="submit" class="btn profile-sidebar-btn">
                    <img src="/imgs/admin.svg" alt="">Admin Dashboard
                </button>
            </form>
            <?php endif; ?>
            <button class="profile-sidebar-btn btn btn-red" id="logoutBtn">
                <img src="/imgs/logout.svg" alt="">Abmelden
            </button>
            <div class="profile-sidebar-brand">
                <img src="/imgs/logo.svg" alt="Lernmonitor">
                <span>Lernmonitor</span>
            </div>
        </div>

    </aside>

    <main class="dashboard-main-panel">

        <!-- Benachrichtigungen -->
        <section class="card-section blur-lg">
            <div class="card-header">Benachrichtigungen</div>
            <div class="card-header-description">Hier können Sie Aufgabensets bewerten und zurücklegen, und werden benachrichtigt wenn Schüler Hilfe brauchen.</div>
            <div class="notifications-list" id="notifications-list"></div>
            <p class="lg-empty text-italic" id="no-notifications-hint" style="display:none">Keine neuen Benachrichtigungen.</p>
        </section>

        <!-- Meine Aufgabensets -->
        <section class="card-section blur-lg">
            <div class="card-header">Meine Aufgabensets</div>
            <div class="card-header-description"><?= $adminOnlyTasksets ? 'Aufgabensets und Notenskalen einsehen.' : 'Aufgabensets und Notenskalen erstellen und bearbeiten.' ?></div>
            <div class="lerngruppen-list" id="lerngruppen-list"></div>
            <p class="lg-empty text-italic" id="no-lerngruppen-hint" style="display:none">Noch keine Lerngruppen zugewiesen.</p>
        </section>

        <!-- Meine Schüler -->
        <section class="card-section blur-lg">
            <div class="card-header">Meine Schüler</div>
            <div class="card-header-description">Hier finden Sie alle Schüler aus Ihren Lerngruppen und können ihre Profile einsehen.</div>
            <div class="filter-bar">
                <input type="search" id="myStudentSearch" placeholder="Name oder E-Mail suchen …">
                <button type="button" class="dropdown-trigger" id="myStudentFilterClass">Alle Klassen</button>
            </div>
            <table id="myStudentTable" class="gradient-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Klasse</th>
                        <th data-graduation-label>Abschlussstufe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="myStudentTableBody"></tbody>
            </table>
            <div id="myStudentPagination" class="pagination-bar"></div>
        </section>

    </main>

</section>

<?php include __DIR__ . '/../partials/change-password-modal.php'; ?>
<script src="<?= Config::asset('/js/grading-scale.js') ?>"></script>
<script src="<?= Config::asset('/js/teacher/teacher-view.js') ?>"></script>
</body>
</html>
