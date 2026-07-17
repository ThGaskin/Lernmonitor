'use strict';

/* ── State ────────────────────────────────────────────────────────────── */
let currentType  = null;
let previewData  = null;

/* ── Type metadata ────────────────────────────────────────────────────── */
const TYPE_META = {
    students: {
        label: 'Schüler', manageUrl: '/manage_students', hasPasswords: true, gradient: 'var(--student-gradient)',
        columns: ['ID','Vorname','Nachname','E-Mail','Klasse','Abschlussstufe'],
        optionalColumns: ['Benutzername'],
        columnDescriptions: {
            'ID':            'Eindeutige Schüler-ID',
            'Vorname':       'Vorname(n) des Schülers / der Schülerin',
            'Nachname':      'Nachname des Schülers / der Schülerin',
            'E-Mail':        'E-Mail-Adresse für den Login',
            'Klasse':        'Zugehörige Klasse. Die Klasse muss in der Datenbank vorhanden sein.',
            'Abschlussstufe':'Angestrebter Schulabschluss',
            'Benutzername':  'Optionaler Benutzername. Wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.',
        },
    },
    teachers: {
        label: 'Lehrkräfte', manageUrl: '/manage_teachers', hasPasswords: false, gradient: 'var(--teacher-gradient)',
        columns: ['ID','Vorname','Nachname','E-Mail'],
        optionalColumns: ['Benutzername'],
        columnDescriptions: {
            'ID':           'Eindeutige Lehrer-ID',
            'Vorname':      'Vorname der Lehrkraft',
            'Nachname':     'Nachname der Lehrkraft',
            'E-Mail':       'E-Mail-Adresse für den Login',
            'Benutzername': 'Optionaler Benutzername. Wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.',
        },
    },
    classes: {
        label: 'Klassen', manageUrl: '/manage_classes', hasPasswords: false, gradient: 'var(--class-gradient)',
        columns: ['Bezeichnung','Klassenstufe'],
        columnDescriptions: {
            'Bezeichnung':  'Klassenbezeichnung, z.B. 10a',
            'Klassenstufe': 'Jahrgangsstufe als Zahl, z.B. 10',
        },
    },
    subjects: {
        label: 'Fächer', manageUrl: '/manage_subjects', hasPasswords: false, gradient: 'var(--subject-gradient)',
        columns: ['Name'],
        columnDescriptions: {
            'Name': 'Name des Fachs, z.B. Mathematik',
        },
    },
    rooms: {
        label: 'Räume', manageUrl: '/manage_rooms', hasPasswords: false, gradient: 'var(--room-gradient)',
        columns: ['Raumname','Mindestlevel'],
        columnDescriptions: {
            'Raumname':    'Name oder Nummer des Raums, z.B. A204',
            'Mindestlevel':'Mindest-Zugangslevel für den Raum (Zahl)',
        },
    },
    aufgabensets: {
        label: 'Aufgabensets', manageUrl: '/manage_tasks', hasPasswords: false, gradient: 'var(--tasks-gradient)',
        columns: ['ID', 'Schuljahr', 'Fach', 'Klasse', 'Klassenstufe', 'Name', 'Max. Punkte', 'Aktiv', 'Bestehensmodus'],
        columnDescriptions: {
            'ID':             'Interne ID des Aufgabensets. Bei bestehenden IDs werden die Daten aktualisiert; bei leerer ID wird ein neues Set angelegt.',
            'Schuljahr':      'Schuljahr im Format JJJJ/JJ, z.B. 2024/25',
            'Fach':           'Name des Fachs — muss exakt mit einem vorhandenen Fach übereinstimmen',
            'Klasse':         'Klassenbezeichnung (nur bei klassenspezifischem Modus). Leer lassen für stufenspezifische Sets.',
            'Klassenstufe':   'Jahrgangsstufe als Zahl',
            'Name':           'Name des Aufgabensets',
            'Max. Punkte':    'Maximal erreichbare Punkte (mindestens 1)',
            'Aktiv':          '1 wenn aktiv, 0 wenn inaktiv',
            'Bestehensmodus': '1 für Bestanden/Nicht bestanden, 0 für Punktewertung',
        },
    },
    parents: {
        label: 'Eltern', manageUrl: '/manage_parents', hasPasswords: false, gradient: 'var(--parent-gradient)',
        columns: ['E-Mail'],
        optionalColumns: ['Benutzername', 'Schüler-IDs'],
        columnDescriptions: {
            'E-Mail':       'E-Mail-Adresse des Elternteils für den Login',
            'Benutzername': 'Optionaler Benutzername. Wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.',
            'Schüler-IDs':  'Semikolongetrennte Schüler-IDs (z.B. 1001;1002) mit denen das Elternkonto verknüpft werden soll. Verknüpfungen können nachträglich im Elternpanel hinzugefügt werden.',
        },
    },
};

const STATUS_META = {
    new:      { label: 'Neu'         },
    update:   { label: 'Update'      },
    existing: { label: 'Unverändert' },
    invalid:  { label: 'Fehler'      },
};

/* ── Step navigation ─────────────────────────────────────────────────── */
function showStep(name) {
    ['preview', 'result'].forEach(s => {
        document.getElementById(`step-${s}`).style.display = (s === name) ? '' : 'none';
    });
    // Keep step-upload visible (shows the title) but hide the controls when not on upload step
    document.getElementById('upload-controls').style.display = (name === 'upload') ? '' : 'none';
}

/* ── Step 1: Type selection ──────────────────────────────────────────── */
function toggleTypeDropdown() {
    document.getElementById('type-dropdown').classList.toggle('dropdown--visible');
}

function selectType(type) {
    currentType = type;

    /* Update trigger label */
    const meta = TYPE_META[type];
    document.getElementById('type-trigger-label').textContent = meta.label;

    /* Mark active option */
    document.querySelectorAll('#type-dropdown .dropdown-option').forEach(btn => {
        btn.classList.toggle('dropdown-option--active', btn.dataset.type === type);
    });

    /* Close dropdown */
    document.getElementById('type-dropdown').classList.remove('dropdown--visible');

    /* Show info card with type-specific gradient and format hint */
    const card = document.getElementById('type-info-card');
    card.style.setProperty('--bg-gradient', meta.gradient);
    document.getElementById('format-columns').innerHTML = meta.columns.map(col =>
        `<li><code>${col}</code> — ${meta.columnDescriptions[col]}</li>`
    ).join('');

    const optionalCols = meta.optionalColumns ?? [];
    const optionalSection = document.getElementById('optional-columns-section');
    if (optionalCols.length) {
        document.getElementById('format-optional-columns').innerHTML = optionalCols.map(col =>
            `<li><code>${col}</code> — ${meta.columnDescriptions[col]}</li>`
        ).join('');
        optionalSection.style.display = '';
    } else {
        optionalSection.style.display = 'none';
    }
    card.style.display = '';

    updateCheckBtn();
}

function downloadTemplate() {
    if (!currentType) return;
    const meta = TYPE_META[currentType];
    const cols = [...meta.columns, ...(meta.optionalColumns ?? [])];
    const blob = new Blob([cols.join(',') + '\n'], { type: 'text/csv;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const a    = Object.assign(document.createElement('a'), { href: url, download: `vorlage_${currentType}.csv` });
    a.click();
    URL.revokeObjectURL(url);
}

function updateCheckBtn() {
    const hasType = currentType !== null;
    const hasFile = document.getElementById('csvFile').files.length > 0;
    document.getElementById('btn-check').disabled = !(hasType && hasFile);
}

/* ── File input + drag-and-drop ──────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    /* Close type dropdown when clicking outside */
    document.addEventListener('click', e => {
        const selector = document.getElementById('type-trigger')?.closest('.import-type-selector');
        if (selector && !selector.contains(e.target)) {
            document.getElementById('type-dropdown').classList.remove('dropdown--visible');
        }
    });

    const input    = document.getElementById('csvFile');
    const dropZone = document.getElementById('dropZone');

    input.addEventListener('change', () => {
        const file   = input.files[0];
        const nameEl = document.getElementById('file-name');
        if (file) {
            nameEl.textContent = file.name;
            nameEl.style.display = '';
        } else {
            nameEl.style.display = 'none';
        }
        document.getElementById('upload-error').style.display = 'none';
        updateCheckBtn();
    });

    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (!file) return;
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
    });

    /* ── Dev preview: ?preview=result ───────────────────────────────────── */
    if (new URLSearchParams(location.search).get('preview') === 'result') {
        currentType = new URLSearchParams(location.search).get('type') || 'students';
        renderResult({
            imported: 3, updated: 1,
            passwords: [
                { id: 1001, name: 'Max Muster',  email: 'max@example.com',  password: 'abc123xy' },
                { id: 1002, name: 'Jana Schulz', email: 'jana@example.com', password: 'zyx987ab' },
            ],
        });
        showStep('result');
    }
});

/* ── Step 2: Preview ─────────────────────────────────────────────────── */
async function checkFile() {
    const file = document.getElementById('csvFile').files[0];
    if (!file || !currentType) return;

    const btn = document.getElementById('btn-check');
    btn.disabled = true;
    document.getElementById('checking-spinner').style.display = '';
    document.getElementById('upload-error').style.display = 'none';

    try {
        const csv  = await file.text();
        const resp = await fetch('/preview-import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: currentType, csv }),
        });
        const data = await resp.json();
        if (!resp.ok) {
            showUploadError(data.error || 'Fehler beim Prüfen der Datei.');
            return;
        }
        if (!data.rows || data.rows.length === 0) {
            showUploadError('Die Datei enthält keine verwertbaren Zeilen.');
            return;
        }
        previewData = data;
        renderPreview(data);
        showStep('preview');
    } catch (e) {
        showUploadError('Netzwerkfehler: ' + e.message);
    } finally {
        btn.disabled = false;
        document.getElementById('checking-spinner').style.display = 'none';
        updateCheckBtn();
    }
}

function showUploadError(msg) {
    const el = document.getElementById('upload-error');
    el.textContent = msg;
    el.style.display = '';
}

function renderPreview(data) {
    const { columns, rows, summary } = data;

    /* Summary badges */
    const summaryEl = document.getElementById('preview-summary');
    summaryEl.innerHTML = '';
    const counts = [
        { status: 'new',      count: summary.new,      label: `${summary.new} Neu`                                          },
        { status: 'update',   count: summary.update,   label: `${summary.update} Update${summary.update !== 1 ? 's' : ''}` },
        { status: 'existing', count: summary.existing, label: `${summary.existing} Unverändert`                             },
        { status: 'invalid',  count: summary.invalid,  label: `${summary.invalid} Fehler`                                   },
    ];
    counts.forEach(({ status, count, label }) => {
        if (count === 0) return;
        const span = document.createElement('span');
        span.className = `import-badge badge-${status}`;
        span.textContent = label;
        summaryEl.appendChild(span);
    });

    /* Filter button labels */
    document.getElementById('filter-new').textContent      = `Neu (${summary.new})`;
    document.getElementById('filter-update').textContent   = `Updates (${summary.update})`;
    document.getElementById('filter-existing').textContent = `Unverändert (${summary.existing})`;
    document.getElementById('filter-invalid').textContent  = `Fehler (${summary.invalid})`;

    /* Table head */
    const thead   = document.getElementById('previewHead');
    const headRow = document.createElement('tr');
    headRow.innerHTML = '<th style="width:36px"></th><th>Status</th>';
    columns.forEach(col => {
        const th = document.createElement('th');
        th.textContent = col;
        headRow.appendChild(th);
    });
    const thChanges = document.createElement('th');
    thChanges.className   = 'import-changes-header';
    thChanges.textContent = 'Änderungen / Fehler';
    headRow.appendChild(thChanges);
    thead.innerHTML = '';
    thead.appendChild(headRow);

    /* Table rows */
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';

    rows.forEach((row, idx) => {
        const canSelect = row.status === 'new' || row.status === 'update';
        const tr = document.createElement('tr');
        tr.dataset.status = row.status;
        tr.dataset.index  = idx;
        tr.className      = `import-row--${row.status}`;

        /* Checkbox */
        const tdCheck = document.createElement('td');
        if (canSelect) {
            const cb   = document.createElement('input');
            cb.type    = 'checkbox';
            cb.checked = true;
            cb.addEventListener('change', updateImportButton);
            tdCheck.appendChild(cb);
        }
        tr.appendChild(tdCheck);

        /* Status badge */
        const tdStatus = document.createElement('td');
        const badge    = document.createElement('span');
        badge.className    = `import-badge badge-${row.status}`;
        badge.style.cssText = 'padding:2px 8px;font-size:.78rem';
        badge.textContent  = STATUS_META[row.status]?.label ?? row.status;
        tdStatus.appendChild(badge);
        tr.appendChild(tdStatus);

        /* Data cells */
        (row.data ?? []).forEach(val => {
            const td = document.createElement('td');
            td.textContent = val ?? '';
            tr.appendChild(td);
        });

        /* Changes / errors cell */
        const tdInfo    = document.createElement('td');
        tdInfo.className = 'import-changes';
        if (row.status === 'update' && row.changes && Object.keys(row.changes).length) {
            tdInfo.textContent = Object.entries(row.changes)
                .map(([field, oldVal]) => {
                    const colIdx = columns.indexOf(field);
                    let newVal = colIdx >= 0 ? (row.data?.[colIdx] ?? '?') : '?';
                    if (field === 'Benutzername' && newVal === '') newVal = '(keiner)';
                    return `${field}: ${oldVal} → ${newVal}`;
                })
                .join(' · ');
        } else if (row.status === 'invalid' && row.errors?.length) {
            tdInfo.textContent = row.errors.join('; ');
            tdInfo.classList.add('import-error-text');
        } else {
            tdInfo.textContent = '—';
            tdInfo.style.opacity = '0.3';
        }
        tr.appendChild(tdInfo);

        tbody.appendChild(tr);
    });

    applyFilter('all');
    updateImportButton();
}

function applyFilter(filter) {
    document.querySelectorAll('.import-filter-btn').forEach(btn => {
        btn.classList.toggle('import-filter-btn--active', btn.dataset.filter === filter);
    });
    document.querySelectorAll('#previewBody tr').forEach(tr => {
        tr.style.display = (filter === 'all' || tr.dataset.status === filter) ? '' : 'none';
    });
    syncCheckAll();
}

function toggleAll(checked) {
    document.querySelectorAll('#previewBody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb) cb.checked = checked;
    });
    updateImportButton();
}

function syncCheckAll() {
    const visibleCbs = [...document.querySelectorAll('#previewBody tr')]
        .filter(tr => tr.style.display !== 'none')
        .map(tr => tr.querySelector('input[type="checkbox"]'))
        .filter(Boolean);
    const master = document.getElementById('checkAll');
    if (!master) return;
    master.indeterminate = false;
    if (visibleCbs.length === 0) { master.checked = false; return; }
    const checked = visibleCbs.filter(cb => cb.checked).length;
    if (checked === 0)                      { master.checked = false; }
    else if (checked === visibleCbs.length) { master.checked = true;  }
    else                                    { master.indeterminate = true; }
}

function updateImportButton() {
    const count = document.querySelectorAll('#previewBody input[type="checkbox"]:checked').length;
    const btn   = document.getElementById('btn-import');
    btn.disabled    = count === 0;
    btn.textContent = `Import starten (${count})`;
    document.getElementById('selected-count').textContent =
        `${count} ${count !== 1 ? 'Einträge' : 'Eintrag'} ausgewählt`;
    syncCheckAll();
}

/* ── Step 3: Execute ─────────────────────────────────────────────────── */
async function executeImport() {
    const selectedRows = [];
    document.querySelectorAll('#previewBody tr').forEach(tr => {
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb?.checked) {
            const rowData = previewData.rows[parseInt(tr.dataset.index, 10)]?.data;
            if (rowData) selectedRows.push(rowData);
        }
    });
    if (selectedRows.length === 0) return;

    const btn = document.getElementById('btn-import');
    btn.disabled    = true;
    btn.textContent = 'Wird importiert …';

    try {
        const resp = await fetch('/execute-import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: currentType, rows: selectedRows }),
        });
        const data = await resp.json();
        if (!resp.ok) {
            alert(data.error || 'Importfehler');
            btn.disabled = false;
            updateImportButton();
            return;
        }
        renderResult(data);
        showStep('result');
    } catch (e) {
        alert('Netzwerkfehler: ' + e.message);
        btn.disabled = false;
        updateImportButton();
    }
}

function renderResult(data) {
    const meta = TYPE_META[currentType] ?? {};

    const parts = [];
    parts.push(`<div class="bn-section-header" style="margin-top:0">
        <div class="bn-section-title text-md text-bold" style="color:#fff; display:flex; align-items:center; gap:8px;">
            <img src="/imgs/tick_circled.svg" style="height: 1.5rem" alt=""/>
            Import abgeschlossen
        </div>
    </div>`);

    const lines = [];
    if (data.imported > 0) lines.push(`${data.imported} ${data.imported === 1 ? 'Eintrag' : 'Einträge'} neu erstellt`);
    if (data.updated  > 0) lines.push(`${data.updated} ${data.updated === 1 ? 'Eintrag' : 'Einträge'} aktualisiert`);
    if (lines.length)
        parts.push(`<p style="color:rgba(255,255,255,.9);margin:4px 0 16px">${lines.join(' · ')}</p>`);

    document.getElementById('result-content').innerHTML = parts.join('');
    document.getElementById('result-manage-link').href = meta.manageUrl ?? '/';
}

function resetWizard() {
    currentType = null;
    previewData = null;
    document.getElementById('type-trigger-label').textContent = 'Datenkategorie wählen …';
    document.getElementById('type-dropdown').classList.remove('dropdown--visible');
    document.querySelectorAll('#type-dropdown .dropdown-option').forEach(b => b.classList.remove('dropdown-option--active'));
    document.getElementById('type-info-card').style.display   = 'none';
    document.getElementById('csvFile').value                  = '';
    document.getElementById('file-name').style.display        = 'none';
    document.getElementById('upload-error').style.display     = 'none';
    document.getElementById('btn-check').disabled             = true;
    showStep('upload');
}

/* ── Export ──────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[name="export-type"]').forEach(cb => {
        cb.addEventListener('change', () => {
            const any = [...document.querySelectorAll('input[name="export-type"]')].some(c => c.checked);
            document.getElementById('btn-export').disabled = !any;
        });
    });
});

function exportSelectAll() {
    document.querySelectorAll('input[name="export-type"]').forEach(cb => cb.checked = true);
    document.getElementById('btn-export').disabled = false;
}

async function runExport() {
    const types = [...document.querySelectorAll('input[name="export-type"]:checked')].map(c => c.value);
    if (!types.length) return;

    const btn     = document.getElementById('btn-export');
    const spinner = document.getElementById('export-spinner');
    const errEl   = document.getElementById('export-error');
    btn.disabled = true;
    spinner.style.display = '';
    errEl.style.display   = 'none';

    try {
        const resp = await fetch('/export-data', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ types }),
        });
        if (!resp.ok) {
            const data = await resp.json().catch(() => ({}));
            errEl.textContent   = data.error || 'Exportfehler.';
            errEl.style.display = '';
            return;
        }
        const blob = await resp.blob();
        const url  = URL.createObjectURL(blob);
        const a    = Object.assign(document.createElement('a'), {
            href: url,
            download: `export_${new Date().toISOString().slice(0,10)}.zip`,
        });
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        errEl.textContent   = 'Netzwerkfehler: ' + e.message;
        errEl.style.display = '';
    } finally {
        btn.disabled          = false;
        spinner.style.display = 'none';
    }
}
