<?php
$title           = 'Admin Dashboard';
$extraHead       = '<link rel="stylesheet" href="' . Config::asset('/css/admin-dashboard.css') . '">';
$schoolYearMissing = $schoolYearMissing ?? false;
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<div id="admin-dashboard">
    <h1 class="welcome-heading">
        <span class="welcome-greeting text-lg text-light">Willkommen</span>
        <span class="welcome-name text-2xl text-bold" id="admin-name">…</span>
    </h1>

    <div id="admin-grid">
        <a href="/manage_students" class="admin-card" style="background: var(--student-gradient)">
            <img src="/imgs/schueler.svg" width='50px' alt="">
            <span>Schüler</span>
        </a>
        <a href="/manage_teachers" class="admin-card" style="background: var(--teacher-gradient)">
            <img src="/imgs/lehrer.svg" width='50px' alt="">
            <span>Lehrer</span>
        </a>
            <a href="/manage_classes" class="admin-card" style="background: var(--class-gradient)">
            <img src="/imgs/classroom.svg" width='50px' alt="">
            <span>Klassen</span>
        </a>
        <a href="/manage_parents" class="admin-card" style="background: var(--parent-gradient)">
            <img src="/imgs/parents.svg" width='50px' alt="">
            <span>Eltern</span>
        </a>

        <a href="/manage_subjects" class="admin-card" style="background: var(--subject-gradient)">
            <img src="/imgs/subject.svg" width='50px' alt="">
            <span>Fächer</span>
        </a>
        <a href="/manage_rooms" class="admin-card" style="background: var(--room-gradient)">
            <img src="/imgs/raum.svg" width='50px' alt=""> <span>Räume</span>
        </a>
        <a href="/manage_tasks" class="admin-card" style="background: var(--tasks-gradient)">
            <img src="/imgs/tasks.svg" width='50px' alt=""> <span>Aufgabensets</span>
        </a>
        <a href="/manage_admins" class="admin-card" style="background: var(--admin-gradient)">
            <img src="/imgs/admin.svg" width='50px' alt="">
            <span>Kontoverwaltung</span>
        </a>
        <a href="/import" class="admin-card" style="background: var(--import-gradient)">
            <img src="/imgs/data_2.svg" width='50px' alt="">
            <span>Datenmanagement</span>
        </a>
        <a href="/settings" class="admin-card" style="background: var(--settings-gradient)">
            <img src="/imgs/settings.svg" width='50px' alt="">
            <span>Einstellungen</span>
        </a>
    </div>

</div>
<?php if ($schoolYearMissing): ?>
<div class="confirm-overlay" id="school-year-warning">
    <div class="confirm-box">
        <p class="confirm-box-header">Kein Schuljahr gesetzt</p>
        <p class="confirm-box-text">
            Es wurde noch kein aktuelles Schuljahr festgelegt. Bitte gehen Sie zu den Einstellungen und tragen Sie das aktuelle Schuljahr ein, bevor Sie Aufgabensets anlegen.
        </p>
        <div class="confirm-actions">
            <button class="btn btn-cancel" onclick="document.getElementById('school-year-warning').remove()">Schließen</button>
            <a href="/settings" class="btn btn-confirm" style="text-decoration:none">Zu den Einstellungen</a>
        </div>
    </div>
</div>
<?php endif; ?>
<script>fetch('/me').then(r => r.json()).then(data => {
    document.getElementById('admin-name').textContent = data.firstName || data.username || '';
});
</script>
</body>
</html>
