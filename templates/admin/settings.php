<?php
$currentYear = $currentYear ?? '';
$nextYear    = $nextYear    ?? '';
$title = 'Einstellungen';
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="settings" style="--page-gradient: var(--settings-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/settings_reversed.svg" alt="">
            Systemeinstellungen
        </div>
        <div class="page-subtitle">
            Globale Einstellungen für den Lernmonitor
        </div>
    </div>

    <section class="manage-list page-box">

            <div class="card-section bg-gradient" style="--bg-gradient: var(--c-lightblue)">
            <div class="bn-section-header">
                <div class="bn-section-title text-md text-bold">Sichtbarkeit</div>
                <div class="bn-section-subtitle text-sm text-light">
                    Legen Sie hier Befugnisse und die Sichtbarkeit von Daten im System fest.
                </div>
            </div>

            <div class="settings-row" id="task-scope-row">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Stufengebundene Aufgabensets</span>
                    <span class="text-sm text-light settings-row-desc">
                        Wenn aktiv, sind Aufgabensets für alle Klassen einer Stufe einheitlich.
                        Wenn inaktiv, können Aufgabensets für jede Klasse einzeln verwaltet werden.
                    </span>
                </div>
                <button class="settings-toggle" id="taskScopeToggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
            </div>

            <div class="settings-row" id="admin-only-tasksets-row">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Nur Admins können Aufgabensets verwalten</span>
                    <span class="text-sm text-light settings-row-desc">
                        Wenn aktiv, können nur Admins Aufgabensets erstellen, bearbeiten und entfernen.
                        Diese Einstellung ist nur relevant wenn Aufgabensets klassenabhängig sind.
                    </span>
                </div>
                <button class="settings-toggle" id="adminOnlyTasksetsToggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
            </div>

            <div class="settings-row settings-row--separator">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Standardleistung im Projektionstool</span>
                    <span class="text-sm text-light settings-row-desc">
                        Stellen Sie ein, welche Leistung den Schülern standardmäßig in ihrem Projektionstool angezeigt wird.
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                    <input type="number" id="projectionDefaultInput" class="input-box" min="0" max="100" style="width:72px;text-align:right">
                    <span class="text-sm">%</span>
                </div>
            </div>

            <div class="settings-row settings-row--separator">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Noten anzeigen</span>
                    <span class="text-sm text-light settings-row-desc">
                        Wenn aktiv, wird Schülern und Eltern ihre aktuelle Note im Dashboard angezeigt.
                    </span>
                </div>
                <button class="settings-toggle" id="showGradesToggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
            </div>

            <div class="settings-row settings-row--separator">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Klassenübersicht für Schüler</span>
                    <span class="text-sm text-light settings-row-desc">
                        Wenn aktiv, können Schüler über ihr Dashboard die Klassenübersicht aufrufen.
                    </span>
                </div>
                <button class="settings-toggle" id="showClassDashboardToggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
            </div>
            </div>

        </div>

        <div class="card-section bg-gradient" style="--bg-gradient: var(--c-lightblue); margin-top: 16px">
            <div class="bn-section-header">
                <div class="bn-section-title text-md text-bold">Räume</div>
                <div class="bn-section-subtitle text-sm text-light">
                    Einstellungen für die Raumzuweisung.
                </div>
            </div>
            <div class="settings-row">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Standard-Raum</span>
                    <span class="text-sm text-light settings-row-desc">
                        Schüler ohne zugewiesenen Raum werden unter diesem Namen angezeigt.
                        Leer lassen für „kein Raum".
                    </span>
                </div>
                <input type="text" id="defaultRoomInput" class="input-box" style="max-width:200px" placeholder="kein Raum">
            </div>
        </div>

        <div class="card-section bg-gradient" style="--bg-gradient: var(--c-lightblue)">
            <div class="bn-section-header">
                <div class="bn-section-title text-md text-bold">Lernniveaus</div>
                <div class="bn-section-subtitle text-sm text-light">
                    Legen Sie hier Anzahl und Bezeichnung der Lernniveaus fest.
                </div>
            </div>

            <div class="settings-row settings-row--column">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Bezeichnung</span>
                    <span class="text-sm text-light settings-row-desc">Dieser Begriff wird im gesamten System als Bezeichnung dieser Eigenschaft angezeigt.
                        Schülerdaten werden dadurch nicht verändert.</span>
                </div>
                <input type="text" id="gradCategoryName" class="input-box" style="max-width:240px" placeholder="z.B. Abschlussstufe">
            </div>

            <div class="settings-row settings-row--column">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Stufen</span>
                    <span class="text-sm text-light settings-row-desc">
                        Setzen Sie hier die Niveaus fest.
                        Die Reihenfolge entspricht den intern zugewiesenen Werten.
                        Wenn Sie die Anzahl der Niveaus reduzieren, werden Schüler auf einem höheren Lernniveau als das
                        neue Maximum automatisch auf das höchstmögliche Niveau gesetzt.
                        </span>
                </div>
                <div id="grad-levels-list" class="settings-level-list"></div>
                <div class="settings-level-row">
                    <span class="settings-level-badge" aria-hidden="true"></span>
                    <button class="btn settings-add-btn" id="gradAddLevelBtn" type="button">
                        + Stufe hinzufügen
                    </button>
                    <button class="btn-s btn-danger settings-ghost" tabindex="-1" aria-hidden="true">
                        <img src="/imgs/x.svg" alt="">
                    </button>
                </div>
            </div>

            <div class="settings-save-bar">
                <button class="btn btn-confirm" id="gradSaveBtn" type="button">Speichern</button>
                <span id="gradSaveResult" class="text-sm settings-result" style="display:none">Gespeichert.</span>
                <span id="gradSaveError" class="form-error text-sm" style="display:none"></span>
            </div>
        </div>

            <div class="card-section bg-gradient" style="--bg-gradient: var(--c-lightblue); margin-top: 16px">
            <div class="bn-section-header">
                <div class="bn-section-title text-md text-bold">Daten löschen</div>
                <div class="bn-section-subtitle text-sm text-light">
                    Legen Sie fest, wie Daten im Lernmonitor gelöscht werden können.
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Admins können Klassen mit Schülern entfernen</span>
                    <span class="text-sm text-light settings-row-desc">
                        Wenn aktiv, können Admins ganze Klassen löschen auch wenn sie Schüler enthalten.
                        Alle Schüler dieser Klasse werden dann unwiderruflich aus der Datenbank entfernt.
                        Falls nicht aktiv, müssen Schülerprofile individuell gelöscht werden bevor eine Klasse
                        entfernt werden kann.
                    </span>
                </div>
                <button class="settings-toggle" id="allowClassDeletionToggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
            </div>

            <div class="settings-row settings-row--separator">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Admins können Fächer mit Aufgabensets entfernen</span>
                    <span class="text-sm text-light settings-row-desc">
                        Wenn aktiv, können Admins Fächer löschen, auch wenn Aufgabensets vorhanden sind.
                        Alle Aufgabensets und zugehörige Schülerdaten werden unwiderruflich entfernt.
                        Falls nicht aktiv, können nur Fächer ohne Aufgabensets gelöscht werden.
                    </span>
                </div>
                <button class="settings-toggle" id="allowSubjectDeletionToggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
            </div>
        </div>

            <div class="card-section bg-gradient" style="--bg-gradient: var(--c-lightblue); margin-top: 16px">
            <div class="bn-section-header">
                <div class="bn-section-title text-md text-bold">Schuljahr</div>
                <div class="bn-section-subtitle text-sm text-light">
                    Verwalten Sie das aktuelle Schuljahr. Beim Wechsel wird das aktuelle Schuljahr archiviert
                    und alle Schüler werden automatisch in die nächste Stufe versetzt.
                    Unter <a href="/import">Datenmanagement</a> können Sie die Archive verwalten.
                </div>
            </div>

            <div class="settings-row">
                <div class="settings-row-label">
                    <span class="text-sm text-bold">Aktuelles Schuljahr</span>
                    <span class="text-sm text-light settings-row-desc" id="currentYearDisplay">
                        <?= $currentYear !== '' ? htmlspecialchars($currentYear) : 'Noch nicht gesetzt' ?>
                    </span>
                </div>
                <button class="btn btn-confirm" id="advanceYearBtn">Neues Schuljahr beginnen</button>
            </div>

            <div id="advanceYearForm" style="display:none; margin-top:12px">
                <div class="form-grid" style="grid-template-columns: 1fr 1fr auto auto; gap: 8px; align-items: end">
                    <div class="form-field">
                        <label class="text-sm">Bezeichnung des neuen Schuljahres</label>
                        <input type="text" id="newYearLabel" placeholder="z.B. 2025/26" value="<?= htmlspecialchars($nextYear) ?>">
                    </div>
                    <div class="form-field">
                        <label class="text-sm">Ihr aktuelles Passwort</label>
                        <input type="password" id="newYearPassword" class="input-box" placeholder="Passwort zur Bestätigung">
                    </div>
                    <div class="form-field form-field-btn">
                        <button class="btn-s btn-confirm" id="confirmAdvanceBtn" disabled>Bestätigen</button>
                    </div>
                    <div class="form-field form-field-btn">
                        <button class="btn-s btn-cancel" id="cancelAdvanceBtn">Abbrechen</button>
                    </div>
                </div>
                <p id="advanceYearError" class="form-error" style="display:none"></p>
            </div>
            <div id="advanceYearResult" class="text-sm settings-result" style="display:none; margin-top:8px"></div>

        </div>

    </section>
</section>

<script>
const _currentYear = <?= json_encode($currentYear) ?>;
const _nextYear    = <?= json_encode($nextYear) ?>;
</script>
<script>
// ---------------------------------------------------------------------------
// Abschlussstufen config
// ---------------------------------------------------------------------------
(function () {
    const categoryInput = document.getElementById('gradCategoryName');
    const levelsList    = document.getElementById('grad-levels-list');
    const addBtn        = document.getElementById('gradAddLevelBtn');
    const saveBtn       = document.getElementById('gradSaveBtn');
    const resultEl      = document.getElementById('gradSaveResult');
    const errorEl       = document.getElementById('gradSaveError');

    function addLevelRow(name) {
        const row = document.createElement('div');
        row.className = 'settings-level-row';
        const idx = levelsList.children.length;
        const badge = document.createElement('span');
        badge.className   = 'settings-level-badge text-xs text-light';
        badge.textContent = idx + 1;
        const input = document.createElement('input');
        input.type        = 'text';
        input.className   = 'input-box';
        input.value       = name ?? '';
        input.placeholder = 'Stufenname';
        const removeBtn = document.createElement('button');
        removeBtn.type      = 'button';
        removeBtn.className = 'btn-s btn-danger';
        removeBtn.innerHTML = '<img src="/imgs/x.svg" alt="">';
        removeBtn.title     = 'Stufe entfernen';
        removeBtn.addEventListener('click', () => {
            row.remove();
            Array.from(levelsList.children).forEach((r, i) => r.querySelector('span').textContent = i + 1);
        });
        row.appendChild(badge);
        row.appendChild(input);
        row.appendChild(removeBtn);
        levelsList.appendChild(row);
    }

    // Initialise from window.graduationConfig (injected server-side)
    const cfg = window.graduationConfig ?? { categoryName: 'Abschlussstufe', levels: [] };
    categoryInput.value = cfg.categoryName;
    (cfg.levels ?? []).forEach(name => addLevelRow(name));

    addBtn.addEventListener('click', () => addLevelRow(''));

    saveBtn.addEventListener('click', async () => {
        resultEl.style.display = errorEl.style.display = 'none';
        const categoryName = categoryInput.value.trim();
        const levels = Array.from(levelsList.querySelectorAll('input')).map(i => i.value.trim()).filter(Boolean);

        if (!categoryName) { errorEl.textContent = 'Kategoriename darf nicht leer sein.'; errorEl.style.display = ''; return; }
        if (levels.length === 0) { errorEl.textContent = 'Mindestens eine Stufe erforderlich.'; errorEl.style.display = ''; return; }

        const res  = await fetch('/api/graduation-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ categoryName, levels }),
        });
        const data = await res.json();
        if (!res.ok) {
            errorEl.textContent  = data.error ?? 'Fehler beim Speichern.';
            errorEl.style.display = '';
            return;
        }
        // Update live globals so labels on the page reflect the new name immediately
        window.graduationConfig = { categoryName, levels };
        document.querySelectorAll('[data-graduation-label]').forEach(el => { el.textContent = categoryName; });
        resultEl.style.display = '';
        setTimeout(() => { resultEl.style.display = 'none'; }, 3000);
    });
})();
</script>
<script>
(async function () {
    const resp = await fetch('/api/settings');
    const s    = resp.ok ? await resp.json() : {};

    // ── task_scope toggle ────────────────────────────────────────────────────
    const taskScopeToggle = document.getElementById('taskScopeToggle');
    const adminOnlyRow    = document.getElementById('admin-only-tasksets-row');

    function setTaskScopeState(isGrade) {
        taskScopeToggle.dataset.active = isGrade ? 'true' : 'false';
        taskScopeToggle.ariaPressed    = isGrade ? 'true' : 'false';
        taskScopeToggle.classList.toggle('settings-toggle--on', isGrade);
        // admin_only_tasksets is only relevant in class-scope
        adminOnlyRow.style.opacity       = isGrade ? '0.45' : '1';
        adminOnlyRow.style.pointerEvents = isGrade ? 'none' : '';
    }

    let currentTaskScope = (s.task_scope ?? 'grade') === 'grade';
    setTaskScopeState(currentTaskScope);

    // ── admin_only_tasksets toggle ────────────────────────────────────────────
    const toggle = document.getElementById('adminOnlyTasksetsToggle');

    function setToggleState(active) {
        toggle.dataset.active  = active ? 'true' : 'false';
        toggle.ariaPressed     = active ? 'true' : 'false';
        toggle.classList.toggle('settings-toggle--on', active);
    }

    setToggleState(s.admin_only_tasksets !== false);

    // ── default_room input ───────────────────────────────────────────────────
    const defaultRoomInput = document.getElementById('defaultRoomInput');
    defaultRoomInput.value = s.default_room ?? '';
    defaultRoomInput.addEventListener('change', async () => {
        const val = defaultRoomInput.value.trim();
        defaultRoomInput.value = val;
        await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'default_room', value: val }),
        });
    });

    // ── projection_default input ──────────────────────────────────────────────
    const projectionDefaultInput = document.getElementById('projectionDefaultInput');
    projectionDefaultInput.value = s.projection_default ?? 50;
    projectionDefaultInput.addEventListener('change', async () => {
        const val = Math.max(0, Math.min(100, parseInt(projectionDefaultInput.value, 10) || 0));
        projectionDefaultInput.value = val;
        await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'projection_default', value: val }),
        });
    });

    // Show grades toggle
    const showGradesToggle = document.getElementById('showGradesToggle');
    function setShowGradesState(active) {
        showGradesToggle.dataset.active = active ? 'true' : 'false';
        showGradesToggle.ariaPressed    = active ? 'true' : 'false';
        showGradesToggle.classList.toggle('settings-toggle--on', active);
    }
    setShowGradesState(s.show_grades !== false); // default true
    showGradesToggle.addEventListener('click', async () => {
        const nowActive = showGradesToggle.dataset.active !== 'true';
        const res = await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'show_grades', value: nowActive }),
        });
        if (res.ok) setShowGradesState(nowActive);
    });

    // Show class dashboard toggle
    const showClassDashboardToggle = document.getElementById('showClassDashboardToggle');
    function setShowClassDashboardState(active) {
        showClassDashboardToggle.dataset.active = active ? 'true' : 'false';
        showClassDashboardToggle.ariaPressed    = active ? 'true' : 'false';
        showClassDashboardToggle.classList.toggle('settings-toggle--on', active);
    }
    setShowClassDashboardState(s.show_class_dashboard !== false); // default true
    showClassDashboardToggle.addEventListener('click', async () => {
        const nowActive = showClassDashboardToggle.dataset.active !== 'true';
        const res = await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'show_class_dashboard', value: nowActive }),
        });
        if (res.ok) setShowClassDashboardState(nowActive);
    });

    // Allow class deletion toggle
    const classDeletionToggle = document.getElementById('allowClassDeletionToggle');
    function setClassDeletionState(active) {
        classDeletionToggle.dataset.active = active ? 'true' : 'false';
        classDeletionToggle.ariaPressed    = active ? 'true' : 'false';
        classDeletionToggle.classList.toggle('settings-toggle--on', active);
    }
    setClassDeletionState(s.allow_class_deletion === true);
    classDeletionToggle.addEventListener('click', async () => {
        const nowActive = classDeletionToggle.dataset.active !== 'true';
        const res = await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'allow_class_deletion', value: nowActive }),
        });
        if (res.ok) setClassDeletionState(nowActive);
    });

    const subjectDeletionToggle = document.getElementById('allowSubjectDeletionToggle');
    function setSubjectDeletionState(active) {
        subjectDeletionToggle.dataset.active = active ? 'true' : 'false';
        subjectDeletionToggle.ariaPressed    = active ? 'true' : 'false';
        subjectDeletionToggle.classList.toggle('settings-toggle--on', active);
    }
    setSubjectDeletionState(s.allow_subject_deletion === true);
    subjectDeletionToggle.addEventListener('click', async () => {
        const nowActive = subjectDeletionToggle.dataset.active !== 'true';
        const res = await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'allow_subject_deletion', value: nowActive }),
        });
        if (res.ok) setSubjectDeletionState(nowActive);
    });

    function makeConflictDialog(header, intro, items, buttons) {
        const overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';
        const box = document.createElement('div');
        box.className = 'confirm-box';

        const h = document.createElement('p');
        h.className = 'confirm-box-header';
        h.textContent = header;

        const p = document.createElement('p');
        p.className = 'confirm-box-text';
        p.textContent = intro;

        const ul = document.createElement('ul');
        ul.style.cssText = 'margin: 4px 0 8px 16px; padding: 0; font-size: 0.9rem; max-height: 240px; overflow-y: auto;';
        items.forEach(text => {
            const li = document.createElement('li');
            li.textContent = text;
            ul.appendChild(li);
        });

        const actions = document.createElement('div');
        actions.className = 'confirm-actions';
        buttons.forEach(({ label, className, onClick }) => {
            const btn = document.createElement('button');
            btn.className = className;
            btn.textContent = label;
            btn.addEventListener('click', () => { overlay.remove(); onClick?.(); });
            actions.appendChild(btn);
        });

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        box.append(h, p, ul, actions);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    function groupByGrade(entries) {
        const byGrade = {};
        entries.forEach(c => { (byGrade[c.grade] ??= []).push(c.subjectName); });
        return Object.entries(byGrade).map(([grade, subjects]) => `Stufe ${grade}: ${subjects.join(', ')}`);
    }

    // ── task_scope toggle handler ────────────────────────────────────────────
    async function applyTaskScope(toGrade, force = false) {
        const newScope = toGrade ? 'grade' : 'class';
        let res, data;
        try {
            res  = await fetch('/api/settings', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body:    JSON.stringify({ key: 'task_scope', value: newScope, force }),
            });
            data = await res.json();
        } catch (_) {
            makeConflictDialog('Verbindungsfehler', 'Die Einstellung konnte nicht gespeichert werden.', [],
                [{ label: 'Schließen', className: 'btn btn-cancel' }]);
            return;
        }

        if (!res.ok && data.error === 'hard_conflict') {
            makeConflictDialog(
                'Zusammenführen nicht möglich',
                'Beim Wechsel zu Stufen-gebundenen Aufgabensets werden alle Klassen einer Stufe zusammengeführt. Dazu müssen Namen und Punktzahlen einheitlich sein. Konflikte in:',
                groupByGrade(data.conflicts ?? []),
                [{ label: 'Schließen', className: 'btn btn-cancel' }]
            );
            return;
        }

        if (!res.ok && data.error === 'soft_warning') {
            makeConflictDialog(
                'Notenskalen unterschiedlich',
                'Folgende Stufen haben unterschiedliche Notenskalen. Beim Fortfahren werden alle Notenskalen zurückgesetzt:',
                groupByGrade(data.warnings ?? []),
                [
                    { label: 'Abbrechen', className: 'btn btn-cancel' },
                    { label: 'Trotzdem fortfahren', className: 'btn btn-danger', onClick: () => applyTaskScope(toGrade, true) },
                ]
            );
            return;
        }

        if (!res.ok) {
            makeConflictDialog('Fehler', data.error ?? 'Unbekannter Fehler.', [],
                [{ label: 'Schließen', className: 'btn btn-cancel' }]);
            return;
        }

        currentTaskScope = toGrade;
        setTaskScopeState(toGrade);
    }

    taskScopeToggle.addEventListener('click', () => applyTaskScope(taskScopeToggle.dataset.active !== 'true'));

    // ── admin_only_tasksets toggle handler ────────────────────────────────────
    toggle.addEventListener('click', async () => {
        const nowActive = toggle.dataset.active !== 'true';
        const res = await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ key: 'admin_only_tasksets', value: nowActive }),
        });
        if (res.ok) setToggleState(nowActive);
    });
})();

// ---------------------------------------------------------------------------
// Schuljahr
// ---------------------------------------------------------------------------
(function () {
    const advanceBtn    = document.getElementById('advanceYearBtn');
    const form          = document.getElementById('advanceYearForm');
    const confirmBtn    = document.getElementById('confirmAdvanceBtn');
    const cancelBtn     = document.getElementById('cancelAdvanceBtn');
    const labelInput    = document.getElementById('newYearLabel');
    const passwordInput = document.getElementById('newYearPassword');
    const errorEl       = document.getElementById('advanceYearError');
    const resultEl      = document.getElementById('advanceYearResult');
    const displayEl     = document.getElementById('currentYearDisplay');

    function updateConfirmBtn() {
        confirmBtn.disabled = !labelInput.value.trim() || !passwordInput.value.trim();
    }
    labelInput.addEventListener('input', updateConfirmBtn);
    passwordInput.addEventListener('input', updateConfirmBtn);

    advanceBtn.addEventListener('click', () => {
        form.style.display   = '';
        resultEl.style.display = 'none';
        errorEl.style.display  = 'none';
        passwordInput.value = '';
        updateConfirmBtn();
        labelInput.focus();
        labelInput.select();
    });

    cancelBtn.addEventListener('click', () => { form.style.display = 'none'; });

    confirmBtn.addEventListener('click', async () => {
        const label    = labelInput.value.trim();
        const password = passwordInput.value.trim();
        if (!label || !password) return;
        errorEl.style.display = 'none';
        confirmBtn.disabled = true;

        showConfirmDialog(
            `Schuljahr wirklich auf „${label}" setzen? Alle Schüler werden in die nächste Stufe versetzt und alle Anfragen gelöscht.`,
            'Schuljahr wechseln',
            async () => {
                const resp = await fetch('/admin/advance-school-year', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ label, password }),
                });
                const data = await resp.json();
                if (!resp.ok) {
                    errorEl.textContent    = data.error ?? 'Fehler beim Schuljahrwechsel.';
                    errorEl.style.display  = '';
                    passwordInput.value = '';
                    updateConfirmBtn();
                    return;
                }
                form.style.display = 'none';
                displayEl.textContent = label;
                resultEl.textContent  = `Schuljahr gewechselt zu „${label}". ${data.advanced} Schüler versetzt` +
                    (data.noClass > 0 ? `, ${data.noClass} ohne passende Folgeklasse (bitte manuell prüfen).` : '.');
                resultEl.style.display = '';
            },
            () => { updateConfirmBtn(); }
        );
    });
})();
</script>
</body>
</html>
