<?php
$title = 'Klassen';
$allowClassDeletion = $allowClassDeletion ?? false;
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="classes" style="--page-gradient: var(--class-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/classroom_reversed.svg" alt="">
            Klassen
        </div>
        <div class="page-subtitle">
            Klassen finden, einsehen, und bearbeiten
        </div>
    </div>
    <section id="class-list" class="manage-list page-box">
            <div class="filter-bar">
                <input type="search" id="classSearch" placeholder="Klasse suchen …">
                <select id="filterGrade"><option value="">Alle Stufen</option></select>
                <button id="toggleAddClass" class="btn filter-add-btn" title="Klasse hinzufügen" data-collapsible="add-classes-wrapper">
                    <img src="/imgs/plus.svg" width="15px" alt="">
                </button>
            </div>
            <div id="add-classes-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
                <section class="card-section bg-gradient" style="--bg-gradient: var(--class-gradient)">
                    <div class="bn-section-header">
                        <div class="bn-section-title text-md text-bold">
                            Klasse hinzufügen
                        </div>
                        <div class="bn-section-subtitle text-sm text-light">
                            Hier können Sie Klassen hinzufügen und ihnen Fächer zuweisen. Neue Schüler in dieser Klassen werden dann
                             automatisch in diese Fächer eingeschrieben. Unter <a href="/import">Datenmanagement</a> können Sie mehrere Klassen gleichzeitig importieren.
                        </div>
                    </div>
                    <form id="add-class-form">
                        <div class="form-grid" style="grid-template-columns: 1fr 80px 1fr auto">
                            <div class="form-field">
                                <input type="text" id="className" class="input-box" placeholder="Klassenname (z.B. 5A)" required>
                            </div>
                            <div class="form-field">
                                <input type="number" id="grade" class="input-box" placeholder="Stufe" min="1" required>
                            </div>
                            <div class="form-field">
                                <button type="button" id="subjectPickerBtn" class="input-box form-field-dropdown">Fächer wählen …</button>
                            </div>
                            <div class="form-field form-field-btn">
                                <button type="submit" id="add-class-btn" class="btn btn-confirm" disabled>
                                    <img src="/imgs/plus.svg" width="10px" alt="Lernmonitor">
                                    Hinzufügen
                                </button>
                            </div>
                        </div>
                        <p id="add-class-error" class="form-error" style="display:none"></p>
                    </form>
                </section>
            </div>
            <table id="classTable" class="gradient-table">
                <thead><tr><th>Name</th><th>Stufe</th><th></th></tr></thead>
                <tbody id="classTableBody"></tbody>
            </table>
            <div id="pagination" class="pagination-bar"></div>
        </section>

</section>

<script>
const allowClassDeletion = <?= json_encode($allowClassDeletion) ?>;
const newClassSubjects = new Set();

function updateSubjectPickerLabel() {
    const btn = document.getElementById('subjectPickerBtn');
    btn.textContent = newClassSubjects.size
        ? `${newClassSubjects.size} Fach/Fächer gewählt`
        : 'Fächer wählen …';
}

function updateAddBtn() {
    const filled = document.getElementById('className').value.trim() !== '' && document.getElementById('grade').value !== '';
    document.getElementById('add-class-btn').disabled = !filled;
}
['className','grade'].forEach(id => document.getElementById(id).addEventListener('input', updateAddBtn));

let newClassSubjectDropdown = null;
document.getElementById('subjectPickerBtn').addEventListener('click', function(e) {
    if (newClassSubjectDropdown) {
        newClassSubjectDropdown.remove();
        newClassSubjectDropdown = null;
        return;
    }
    const dropdown = document.createElement('div');
    dropdown.className = 'dropdown dropdown--visible';
    allSubjects.forEach(s => {
        const label = document.createElement('label');
        label.className = 'dropdown-option';
        const checkbox = document.createElement('input');
        checkbox.type    = 'checkbox';
        checkbox.checked = newClassSubjects.has(s.id);
        checkbox.addEventListener('change', () => {
            checkbox.checked ? newClassSubjects.add(s.id) : newClassSubjects.delete(s.id);
            updateSubjectPickerLabel();
        });
        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(s.name));
        dropdown.appendChild(label);
    });
    positionDropdown(dropdown, this);
    document.body.appendChild(dropdown);
    newClassSubjectDropdown = dropdown;
    const closeNew = () => { dropdown.remove(); newClassSubjectDropdown = null; };
    setTimeout(() => {
        document.addEventListener('click', function close(ev) {
            if (!dropdown.contains(ev.target) && ev.target !== e.currentTarget) {
                closeNew();
                document.removeEventListener('click', close);
            }
        });
        const onScrollNew = (e) => { if (dropdown.contains(e.target)) return; closeNew(); window.removeEventListener('scroll', onScrollNew, true); };
        window.addEventListener('scroll', onScrollNew, true);
    }, 0);
});

let allClasses = [];
let allSubjects = [];
let classSubjectMap = new Map(); // classId → Set of subjectIds
const PAGE_SIZE = 10;
let currentPage = 1;

function getFiltered() {
    const search = document.getElementById('classSearch').value.toLowerCase();
    const grade = document.getElementById('filterGrade').value;
    return allClasses.filter(c => {
        if (search && !c.label.toLowerCase().includes(search)) return false;
        if (grade && String(c.grade) !== grade) return false;
        return true;
    });
}

function renderTable() {
    const filtered = getFiltered();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const tbody = document.getElementById('classTableBody');
    tbody.innerHTML = '';
    page.forEach(c => {
        const tr = document.createElement('tr');
        tr.dataset.id = c.id;
        tr.innerHTML = `<td>${c.label}</td><td>${c.grade}</td>
            <td class="row-actions">
                <button class="btn-s btn-profile" style="background:var(--class-gradient);color:#fff;border:none" onclick="window.location.href='/class-dashboard?id=${c.id}'">Übersicht</button>
                <button class="btn-s btn-edit btn-edit-data"><img src="/imgs/edit_blue.svg" alt=""><span class="btn-label">Bearbeiten</span></button>
                <button class="btn-s btn-danger" ${allowClassDeletion || c.studentCount == 0 ? '' : 'disabled title="Löschen von Klassen mit Schülern ist in den Einstellungen deaktiviert"'}><img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span></button>
            </td>`;
        tr.querySelector('.btn-edit').addEventListener('click', () => toggleEditRow(tr, c.id));
        tr.querySelector('.btn-danger').addEventListener('click', () => confirmDelete(c));
        tbody.appendChild(tr);

        const editTr = document.createElement('tr');
        editTr.className = 'edit-row'; editTr.dataset.editFor = c.id; editTr.style.display = 'none';
        const subjectCount = classSubjectMap.get(c.id)?.size ?? 0;
        editTr.innerHTML = `<td colspan="3"><div class="edit-form">
            <div class="form-grid" style="grid-template-columns: 1fr 80px 1fr auto">
                <div class="form-field"><label>Klassenname</label><input class="ef-name" value="${c.label}"></div>
                <div class="form-field"><label>Stufe</label><input type="number" class="ef-grade" value="${c.grade}"></div>
                <div class="form-field"><label>Fächer</label><button type="button" class="ef-subjects input-box dropdown-trigger">${subjectCount ? `${subjectCount} Fach/Fächer` : 'Fächer wählen …'}</button></div>
                <div class="form-field form-field-btn"><label>&nbsp;</label><button class="ef-save btn btn-confirm">Speichern</button></div>
            </div>
            <p class="ef-error form-error" style="display:none"></p>
        </div></td>`;
        editTr.querySelector('.ef-save').addEventListener('click', () => saveClass(editTr, c));
        editTr.querySelector('.ef-subjects').addEventListener('click', function() { openSubjectDropdown(this, c.id); });
        tbody.appendChild(editTr);
    });
    renderPagination('pagination', totalPages, filtered.length, currentPage, p => { currentPage = p; renderTable(); }, 'Klassen');
}

async function saveClass(editTr, c) {
    const errorEl = editTr.querySelector('.ef-error'); errorEl.style.display = 'none';
    const body = { classId: c.id, name: editTr.querySelector('.ef-name').value.trim(), grade: Number(editTr.querySelector('.ef-grade').value) };
    const resp = await fetch('/edit-class', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!resp.ok) { errorEl.textContent = 'Fehler beim Speichern.'; errorEl.style.display = ''; return; }
    await loadAll();
}

function confirmDelete(c) {
    showConfirmDialog(`Soll Klasse „${c.label}" wirklich entfernt werden? Alle ${c.studentCount > 0 ? c.studentCount + ' zugehörigen Schüler sowie ihre ' : ''}gespeicherten Daten werden unwiderruflich gelöscht.`, 'Entfernen', async () => {
        await fetch('/delete-class', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: c.id}) });
        await loadAll();
    });
}

['classSearch','filterGrade'].forEach(id => document.getElementById(id).addEventListener('change', () => { currentPage = 1; renderTable(); }));
document.getElementById('classSearch').addEventListener('input', () => { currentPage = 1; renderTable(); });

let activeSubjectDropdown = null;

function openSubjectDropdown(trigger, classId) {
    if (activeSubjectDropdown) {
        activeSubjectDropdown.remove();
        activeSubjectDropdown = null;
        return;
    }

    const assigned = classSubjectMap.get(classId) ?? new Set();
    const dropdown = document.createElement('div');
    dropdown.className = 'dropdown dropdown--visible';

    allSubjects.forEach(s => {
        const label = document.createElement('label');
        label.className = 'dropdown-option';
        const checkbox = document.createElement('input');
        checkbox.type    = 'checkbox';
        checkbox.checked = assigned.has(s.id);
        checkbox.addEventListener('change', async () => {
            if (checkbox.checked) {
                await fetch('/assign-class-subject', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ classId, subjectId: s.id }),
                });
                if (!classSubjectMap.has(classId)) classSubjectMap.set(classId, new Set());
                classSubjectMap.get(classId).add(s.id);
            } else {
                checkbox.checked = true; // restore until confirmed
                showConfirmDialog(
                    `Fach „${s.name}" von dieser Klasse entfernen?\n\nBestehende Einschreibungen einzelner Schüler bleiben erhalten – nur neue Schüler werden nicht mehr automatisch eingeschrieben.`,
                    'Entfernen',
                    async () => {
                        checkbox.checked = false;
                        await fetch('/remove-class-subject', {
                            method: 'POST', headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ classId, subjectId: s.id }),
                        });
                        const existing = classSubjectMap.get(classId);
                        if (existing) existing.delete(s.id);
                        const count = classSubjectMap.get(classId)?.size ?? 0;
                        trigger.textContent = count ? `${count} Fach/Fächer` : 'Fächer wählen …';
                    }
                );
                return; // count update handled inside callback
            }
            const count = classSubjectMap.get(classId)?.size ?? 0;
            trigger.textContent = count ? `${count} Fach/Fächer` : 'Fächer wählen …';
        });
        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(s.name));
        dropdown.appendChild(label);
    });

    positionDropdown(dropdown, trigger);
    dropdown.style.minWidth = '180px';
    document.body.appendChild(dropdown);
    activeSubjectDropdown = dropdown;
    const closeActive = () => { dropdown.remove(); activeSubjectDropdown = null; };

    setTimeout(() => {
        document.addEventListener('click', function close(e) {
            if (!dropdown.contains(e.target) && e.target !== trigger) {
                closeActive();
                document.removeEventListener('click', close);
            }
        });
        const onScrollActive = (e) => { if (dropdown.contains(e.target)) return; closeActive(); window.removeEventListener('scroll', onScrollActive, true); };
        window.addEventListener('scroll', onScrollActive, true);
    }, 0);
}

async function loadAll() {
    const [classResp, subjectResp, assignResp] = await Promise.all([
        fetch('/classes'),
        fetch('/subjects'),
        fetch('/api/all-class-subjects'),
    ]);
    allClasses  = await classResp.json();
    allSubjects = await subjectResp.json();
    const assignments = await assignResp.json();
    classSubjectMap = new Map();
    assignments.forEach(({ classId, subjectId }) => {
        if (!classSubjectMap.has(classId)) classSubjectMap.set(classId, new Set());
        classSubjectMap.get(classId).add(subjectId);
    });
    // Populate grade filter
    const grades = [...new Set(allClasses.map(c => c.grade))].sort((a,b) => a-b);
    const sel = document.getElementById('filterGrade');
    const current = sel.value;
    sel.innerHTML = '<option value="">Alle Stufen</option>';
    grades.forEach(g => { const o = document.createElement('option'); o.value = g; o.textContent = `Stufe ${g}`; o.selected = String(g) === current; sel.appendChild(o); });
    currentPage = 1; renderTable();
}

document.getElementById('add-class-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errorEl = document.getElementById('add-class-error'); errorEl.style.display = 'none';
    const body = {
        className:  document.getElementById('className').value.trim(),
        grade:      Number(document.getElementById('grade').value),
        subjectIds: [...newClassSubjects],
    };
    const resp = await fetch('/add-class', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!resp.ok) { errorEl.textContent = 'Fehler beim Hinzufügen.'; errorEl.style.display = ''; return; }
    e.target.reset();
    newClassSubjects.clear();
    updateSubjectPickerLabel();
    updateAddBtn();
    await loadAll();
});

document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
