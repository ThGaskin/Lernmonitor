<?php
$title     = 'Datenimport';
$extraHead = '<link rel="stylesheet" href="' . Config::asset('/css/import.css') . '">'
           . '<script src="' . Config::asset('/js/admin/import_data.js') . '" defer></script>';
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="import" style="--page-gradient: var(--import-gradient)">

    <!-- ── Page header ──────────────────────────────────────────────────────── -->
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/data_reversed.svg" width="50px" alt="">
            Datenmanagement
        </div>
        <div class="page-subtitle">
            Daten importieren und exportieren
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         STEP 1 — Upload
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-upload" class="manage-list page-box">

        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">CSV Datenimport</div>
        </div>

        <div id="upload-controls">
            <div class="bn-section-subtitle text-sm text-light">
                Wählen Sie eine Kategorie aus um Daten aus einer .csv-Datei direkt in die Datenbank zu importieren.
                Vor dem Import wird die Datei auf Fehler überprüft, und gegebenenfalls können Sie Änderungen
                vornehmen.
            </div>
            <!-- Type selector dropdown -->
            <div class="import-type-selector">
                <div class="input-box import-type-trigger" id="type-trigger" onclick="toggleTypeDropdown()">
                    <span id="type-trigger-label">Datenkategorie wählen …</span>
                    <img src="/imgs/chevron.svg" class="import-type-chevron" alt="">
                </div>
                <div class="dropdown" id="type-dropdown">
                    <button class="btn dropdown-option" data-type="students"     onclick="selectType('students')">Schüler</button>
                    <button class="btn dropdown-option" data-type="teachers"     onclick="selectType('teachers')">Lehrer</button>
                    <button class="btn dropdown-option" data-type="classes"      onclick="selectType('classes')">Klassen</button>
                    <button class="btn dropdown-option" data-type="subjects"     onclick="selectType('subjects')">Fächer</button>
                    <button class="btn dropdown-option" data-type="rooms"        onclick="selectType('rooms')">Räume</button>
                    <button class="btn dropdown-option" data-type="parents"      onclick="selectType('parents')">Eltern</button>
                    <button class="btn dropdown-option" data-type="aufgabensets" onclick="selectType('aufgabensets')">Aufgabensets</button>
                </div>
            </div>

            <!-- Info + upload card (shown after type selection) -->
            <section id="type-info-card" class="card-section bg-gradient" style="display:none;--bg-gradient:var(--import-gradient)">
                <div class="import-drop-zone" id="dropZone">
                    <div class="bn-item-sub">
                        <span class="text-sm text-bold">Jede Zeile muss die folgenden Einträge enthalten:</span>
                        <ul id="format-columns" class="import-format-list"></ul>
                    </div>
                    <div class="bn-item-sub" id="optional-columns-section" style="display:none">
                        <span class="text-sm text-bold">Die folgenden Einträge sind optional:</span>
                        <ul id="format-optional-columns" class="import-format-list"></ul>
                    </div>
                    <div class="import-drop-zone-header">
                        <span class="text-sm">Datei hier ablegen oder</span>
                        <label for="csvFile" class="btn btn-s" style="cursor:pointer">Datei auswählen</label>
                        <input type="file" id="csvFile" accept=".csv,text/csv,text/plain" style="display:none">
                        <p id="file-name" style="display:none;margin:8px 0 0;font-size:.9rem;font-weight:600"></p>
                    </div>
                </div>
                <p id="upload-error" class="form-error" style="display:none;margin-top:8px"></p>
                <div class="import-buttons">
                <button type="button" class="btn btn-s bn-action-btn" onclick="downloadTemplate()">
                    Leere Vorlage herunterladen
                </button>
                    <button type="button" id="btn-check" class="btn btn-s btn-confirm" disabled onclick="checkFile()">
                        Prüfen
                    </button>
                    <span id="checking-spinner" style="display:none;font-size:.9rem;opacity:.7">Wird geprüft …</span>
                </div>
            </section>
            </div><!-- /upload-controls -->

            <!-- ══════════════════════════════════════════════════════════════════════
         STEP 2 — Preview
        ═══════════════════════════════════════════════════════════════════════ -->
        <div id="step-preview" class="" style="display:none">

            <div class="bn-section-subtitle text-sm text-light" style="margin-bottom: 1rem;">
                Überprüfen Sie hier Ihre Datei, bevor Daten importiert werden. Details werden in der Tablle angezeigt.
            </div>

            <!-- Summary + filter bar -->
            <section class="card-section bg-gradient" style="--bg-gradient: var(--import-gradient)">
                <div id="preview-summary" class="import-summary"></div>
                <div class="filter-bar" id="filter-bar">
                    <button class="btn-s btn import-filter-btn import-filter-btn--active" data-filter="all" onclick="applyFilter('all')">Alle</button>
                    <button class="btn-s btn import-filter-btn" data-filter="new" id="filter-new" onclick="applyFilter('new')">Neu</button>
                    <button class="btn-s btn import-filter-btn" data-filter="update" id="filter-update" onclick="applyFilter('update')">Updates</button>
                    <button class="btn-s btn import-filter-btn" data-filter="existing" id="filter-existing" onclick="applyFilter('existing')">Unverändert</button>
                    <button class="btn-s btn import-filter-btn" data-filter="invalid" id="filter-invalid" onclick="applyFilter('invalid')">Fehler</button>
                </div>
            </section>

        <!-- Preview table -->
        <section class="card-section" style="padding: 0;">
            <div class="import-table-controls">
                <label class="text-sm" style="display:flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="checkbox" id="checkAll" onchange="toggleAll(this.checked)">
                    Alle auswählen / abwählen
                </label>
                <span id="selected-count" style="font-size:.9rem;opacity:.65"></span>
            </div>
            <div class="import-table-wrapper">
                <table class="gradient-table" id="previewTable">
                    <thead id="previewHead"></thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
        </section>

        <div class="import-nav">
            <button class="btn" onclick="showStep('upload')">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="9,5 4,10 9,15"/><line x1="4" y1="10" x2="16" y2="10"/></svg>
                Zurück
            </button>
            <button id="btn-import" class="btn btn-confirm" disabled onclick="executeImport()">
                Import starten (0)
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         STEP 3 — Result
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="step-result" class="page-box" style="display:none; padding:0;">
        <section class="card-section bg-gradient" style="--bg-gradient: var(--import-gradient)">
            <div id="result-content"></div>
        </section>
        <div class="import-nav">
            <button class="btn" onclick="resetWizard()">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="9,5 4,10 9,15"/><line x1="4" y1="10" x2="16" y2="10"/></svg>
                Neuer Import
            </button>
            <a id="result-manage-link" href="#" class="btn btn-confirm" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                Zur Verwaltung
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="11,5 16,10 11,15"/><line x1="4" y1="10" x2="16" y2="10"/></svg>
            </a>
        </div>
    </div>
    </section>





    <!-- ══════════════════════════════════════════════════════════════════════
         EXPORT
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-export" class="manage-list page-box">

        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">
                CSV Datenexport
            </div>
            <div class="bn-section-subtitle text-sm text-light">
                Wählen Sie die Kategorien aus, die Sie exportieren möchten. Die Daten werden als ZIP-Archiv mit je einer .csv-Datei pro Kategorie heruntergeladen.
            </div>
        </div>

        <div class="export-type-list">
            <label class="export-type-row"><input type="checkbox" name="export-type" value="students">      Schüler</label>
            <label class="export-type-row"><input type="checkbox" name="export-type" value="teachers">      Lehrer</label>
            <label class="export-type-row"><input type="checkbox" name="export-type" value="parents">       Eltern</label>
            <label class="export-type-row"><input type="checkbox" name="export-type" value="classes">       Klassen</label>
            <label class="export-type-row"><input type="checkbox" name="export-type" value="subjects">      Fächer</label>
            <label class="export-type-row"><input type="checkbox" name="export-type" value="rooms">         Räume</label>
            <label class="export-type-row"><input type="checkbox" name="export-type" value="aufgabensets">  Aufgabensets</label>
        </div>
        <p id="export-error" class="form-error" style="display:none;margin-top:8px"></p>

        <div class="import-buttons">
            <button type="button" class="btn btn" onclick="exportSelectAll()">Alle auswählen</button>
            <button type="button" id="btn-export" class="btn btn btn-confirm" disabled onclick="runExport()">
                Als ZIP exportieren
            </button>
            <span id="export-spinner" style="display:none;font-size:.9rem;opacity:.7">Wird exportiert …</span>
        </div>

    </section>


    <!-- ══════════════════════════════════════════════════════════════════════
         BACKUP
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-backup" class="manage-list page-box">

        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">Backup erstellen</div>
            <div class="bn-section-subtitle text-sm text-light">
                Exportiert die gesamte Datenbank (inkl. Passwörter und Einstellungen) als JSON-Datei.
                Diese Datei kann zur Wiederherstellung verwendet werden.
            </div>
        </div>

        <div class="import-buttons">
            <button class="btn btn-confirm" id="btn-backup" onclick="runBackup()">Backup erstellen</button>
            <span id="backup-spinner" style="display:none;font-size:.9rem;opacity:.7">Wird erstellt …</span>
        </div>
        <p id="backup-error" class="form-error" style="display:none;margin-top:8px"></p>

    </section>

    <!-- ══════════════════════════════════════════════════════════════════════
         RESTORE
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-restore" class="manage-list page-box">

        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">Aus Backup wiederherstellen</div>
            <div class="bn-section-subtitle text-sm text-light">
                Stellt die Datenbank aus einer Backup-Datei wieder her.
                <strong>Alle aktuellen Daten werden dabei überschrieben.</strong>
                Alle Benutzer werden abgemeldet.
            </div>
        </div>

        <div class="form-grid restore-grid">
            <div class="form-field">
                <label>Backup-Datei (.json)</label>
                <input type="file" id="restore-file" accept=".json,application/json" class="input-box" style="padding:6px">
            </div>
            <div class="form-field">
                <label>Ihr aktuelles Passwort</label>
                <input type="password" id="restore-password" class="input-box" placeholder="Passwort zur Bestätigung">
            </div>
        </div>
        <div class="import-buttons">
            <button class="btn btn-danger" id="btn-restore" onclick="runRestore()" disabled>Wiederherstellen</button>
            <span id="restore-spinner" style="display:none;font-size:.9rem;opacity:.7">Wird wiederhergestellt …</span>
        </div>
        <p id="restore-error" class="form-error" style="display:none;margin-top:8px"></p>

    </section>

    <!-- ══════════════════════════════════════════════════════════════════════
         ARCHIV
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-archiv" class="manage-list page-box">

        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">Archiv</div>
            <div class="bn-section-subtitle text-sm text-light">
                Beim Wechsel des Schuljahres werden alle Aufgaben- und Notenstand-Daten automatisch archiviert.
                Hier können Sie alte Archive löschen, falls Speicherplatz benötigt wird. Um die Datenbank auf ein altes
                Schuljahr zurückzusetzen, laden Sie das Archiv herunter und benutzen Sie dann die "Aus Backup wiederherstellen"
                Funktion.
            </div>
        </div>

        <div id="year-archives-container">
            <p class="text-sm text-light" id="year-archives-loading">Lade Archive…</p>
        </div>

    </section>

    <!-- ══════════════════════════════════════════════════════════════════════
         RESET
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-reset" class="manage-list page-box">

        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold" style="color:var(--c-red)">Datenbank zurücksetzen</div>
            <div class="bn-section-subtitle text-sm text-light">
                Löscht <strong>alle Daten unwiderruflich</strong> und setzt die Datenbank auf den Ausgangszustand zurück.
                Alle Sitzungen werden beendet und ein neues Administratorkonto wird angelegt.
            </div>
        </div>

        <div class="form-grid reset-grid">
            <div class="form-field">
                <label>Neuer Admin-Vorname</label>
                <input type="text" id="reset-firstname" class="input-box" placeholder="Vorname">
            </div>
            <div class="form-field">
                <label>Neuer Admin-Nachname</label>
                <input type="text" id="reset-lastname" class="input-box" placeholder="Nachname">
            </div>
            <div class="form-field">
                <label>Neue Admin-E-Mail</label>
                <input type="email" id="reset-email" class="input-box" placeholder="admin@schule.de">
            </div>
            <div class="form-field">
                <label>Neues Admin-Passwort</label>
                <input type="password" id="reset-new-password" class="input-box" placeholder="Mindestens 6 Zeichen">
            </div>
            <div class="form-field">
                <label>Ihr aktuelles Passwort</label>
                <input type="password" id="reset-admin-password" class="input-box" placeholder="Passwort zur Bestätigung">
            </div>
        </div>
        <div class="import-buttons">
            <button class="btn btn-danger" id="btn-reset" onclick="runReset()" disabled>Zurücksetzen</button>
            <span id="reset-spinner" style="display:none;font-size:.9rem;opacity:.7">Wird zurückgesetzt …</span>
        </div>
        <p id="reset-error" class="form-error" style="display:none;margin-top:8px"></p>

    </section>

</section>

<script>
// ── Backup ────────────────────────────────────────────────────────────────────
async function runBackup() {
    const btn = document.getElementById('btn-backup');
    const spinner = document.getElementById('backup-spinner');
    const errorEl = document.getElementById('backup-error');
    btn.disabled = true; spinner.style.display = ''; errorEl.style.display = 'none';
    try {
        const resp = await fetch('/backup-database', { method: 'POST' });
        if (!resp.ok) throw new Error('Fehler beim Erstellen des Backups.');
        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `lernmonitor-backup-${new Date().toISOString().slice(0, 10)}.json`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        errorEl.textContent = e.message; errorEl.style.display = '';
    } finally {
        btn.disabled = false; spinner.style.display = 'none';
    }
}

// ── Restore ───────────────────────────────────────────────────────────────────
document.getElementById('restore-file').addEventListener('change', updateRestoreBtn);
document.getElementById('restore-password').addEventListener('input', updateRestoreBtn);

function updateRestoreBtn() {
    document.getElementById('btn-restore').disabled =
        !document.getElementById('restore-file').files.length ||
        !document.getElementById('restore-password').value.trim();
}

async function runRestore() {
    const file     = document.getElementById('restore-file').files[0];
    const password = document.getElementById('restore-password').value.trim();
    if (!file || !password) return;

    showConfirmDialog(
        'Achtung: Alle aktuellen Daten werden unwiderruflich überschrieben und alle Sitzungen beendet. Wirklich fortfahren?',
        'Wiederherstellen',
        async () => {
            const btn     = document.getElementById('btn-restore');
            const spinner = document.getElementById('restore-spinner');
            const errorEl = document.getElementById('restore-error');
            btn.disabled = true; spinner.style.display = ''; errorEl.style.display = 'none';
            const fd = new FormData();
            fd.append('backup', file);
            fd.append('password', password);
            try {
                const resp = await fetch('/restore-database', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!resp.ok) throw new Error(data.error || 'Unbekannter Fehler.');
                document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;flex-direction:column;gap:12px"><p style="font-size:1.2rem;font-weight:600">Wiederhergestellt. Sie werden abgemeldet …</p></div>';
                setTimeout(() => { window.location.href = '/login'; }, 2000);
            } catch (e) {
                errorEl.textContent = e.message; errorEl.style.display = '';
                btn.disabled = false; spinner.style.display = 'none';
            }
        }
    );
}

// ── Archiv ────────────────────────────────────────────────────────────────────
(function () {
    const container = document.getElementById('year-archives-container');

    async function loadArchives() {
        const res  = await fetch('/api/year-archives');
        const data = res.ok ? await res.json() : [];
        if (data.length === 0) {
            container.innerHTML = '<p class="lg-empty text-italic">Noch keine archivierten Schuljahre vorhanden.</p>';
            return;
        }
        const table = document.createElement('table');
        table.className = 'gradient-table';
        table.innerHTML = '<thead><tr><th>Schuljahr</th><th>Schüler</th><th></th></tr></thead>';
        const tbody = document.createElement('tbody');
        data.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${row.schoolYear}</td>
                <td>${row.studentCount}</td>
                <td class="row-actions">
                    <a class="btn-s btn-edit" href="/admin/download-year-archive?year=${encodeURIComponent(row.schoolYear)}" download>
                        <img src="/imgs/download.svg" alt=""><span class="btn-label">Herunterladen</span>
                    </a>
                    <button class="btn-s btn-danger" data-year="${row.schoolYear}">
                        <img src="/imgs/remove.svg" alt=""><span class="btn-label">Löschen</span>
                    </button>
                </td>`;
            tr.querySelector('button').addEventListener('click', () => confirmDeleteArchive(row.schoolYear));
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        container.innerHTML = '';
        container.appendChild(table);
    }

    function confirmDeleteArchive(year) {
        showConfirmDialog(
            `Soll das Archiv für das Schuljahr „${year}" wirklich gelöscht werden? Diese Aktion kann nicht rückgängig gemacht werden.`,
            'Löschen',
            async () => {
                await fetch('/admin/delete-year-archive', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ year }),
                });
                await loadArchives();
            }
        );
    }

    loadArchives();
})();

// ── Reset ─────────────────────────────────────────────────────────────────────
document.getElementById('reset-firstname').addEventListener('input', updateResetBtn);
document.getElementById('reset-lastname').addEventListener('input', updateResetBtn);
document.getElementById('reset-email').addEventListener('input', updateResetBtn);
document.getElementById('reset-new-password').addEventListener('input', updateResetBtn);
document.getElementById('reset-admin-password').addEventListener('input', updateResetBtn);

function updateResetBtn() {
    document.getElementById('btn-reset').disabled =
        !document.getElementById('reset-firstname').value.trim() ||
        !document.getElementById('reset-lastname').value.trim() ||
        !document.getElementById('reset-email').value.trim() ||
        !document.getElementById('reset-new-password').value ||
        !document.getElementById('reset-admin-password').value.trim();
}

async function runReset() {
    const firstName    = document.getElementById('reset-firstname').value.trim();
    const lastName     = document.getElementById('reset-lastname').value.trim();
    const newEmail     = document.getElementById('reset-email').value.trim();
    const newPassword  = document.getElementById('reset-new-password').value;
    const adminPassword = document.getElementById('reset-admin-password').value.trim();
    if (!firstName || !lastName || !newEmail || !newPassword || !adminPassword) return;

    showConfirmDialog(
        'Achtung: ALLE Daten werden unwiderruflich gelöscht — Schüler, Lehrer, Aufgaben, Ergebnisse und Einstellungen. Wirklich fortfahren?',
        'Zurücksetzen',
        async () => {
            const btn     = document.getElementById('btn-reset');
            const spinner = document.getElementById('reset-spinner');
            const errorEl = document.getElementById('reset-error');
            btn.disabled = true; spinner.style.display = ''; errorEl.style.display = 'none';
            try {
                const resp = await fetch('/reset-database', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ adminPassword, newEmail, newPassword, firstName, lastName }),
                });
                const data = await resp.json();
                if (!resp.ok) throw new Error(data.error || 'Unbekannter Fehler.');
                document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;flex-direction:column;gap:12px"><p style="font-size:1.2rem;font-weight:600">Datenbank zurückgesetzt. Sie werden abgemeldet …</p></div>';
                setTimeout(() => { window.location.href = '/login'; }, 2000);
            } catch (e) {
                errorEl.textContent = e.message; errorEl.style.display = '';
                btn.disabled = false; spinner.style.display = 'none';
            }
        }
    );
}
</script>

</body>
</html>
