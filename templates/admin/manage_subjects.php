<?php
$title = 'Fächer';
$allowSubjectDeletion = $allowSubjectDeletion ?? false;
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="subjects" style="--page-gradient: var(--subject-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/subject_reversed.svg" alt="">
            Fächer
        </div>
        <div class="page-subtitle">
            Fächer finden, einsehen, und bearbeiten
        </div>
    </div>
    <section id="subject-list" class="manage-list page-box">
            <div class="filter-bar">
                <input type="search" id="subjectSearch" placeholder="Fach suchen …">
                <button id="toggleAddSubject" class="btn filter-add-btn" title="Fach hinzufügen" data-collapsible="add-subjects-wrapper">
                    <img src="/imgs/plus.svg" alt="">
                </button>
            </div>
            <div id="add-subjects-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
                <section class="card-section bg-gradient" style="--bg-gradient: var(--subject-gradient)">
                    <div class="bn-section-header">
                        <div class="bn-section-title text-md text-bold">
                            Fach hinzufügen
                        </div>
                        <div class="bn-section-subtitle text-sm text-light">
                            Hier können Sie einzelne Fächer hinzufügen. Unter <a href="/import">Datenmanagement</a> können Sie mehrere Fächer gleichzeitig importieren.
                        </div>
                    </div>
                    <form id="add-subject-form">
                        <div class="form-grid" style="grid-template-columns: 1fr auto">
                            <div style="display:flex;gap:8px;align-items:flex-end">
                                <div class="form-field" style="flex:1;justify-content:flex-end">
                                    <input type="text" id="subjectName" placeholder="Fachname" required>
                                </div>
                                <div class="form-field" style="align-items:center">
                                    <label style="margin-bottom:4px;font-size:0.8rem;opacity:0.7">Farbe</label>
                                    <input type="color" id="subjectColor" value="#94a3b8" style="width:44px;height:36px;padding:2px;border:1px solid var(--c-lightgrey);border-radius:8px;cursor:pointer">
                                </div>
                            </div>
                            <div class="form-field form-field-btn">
                                <button type="submit" id="add-subject-btn" class="btn btn-confirm" disabled>
                                    <img src="/imgs/plus.svg" alt="Lernmonitor">Hinzufügen
                                </button>
                            </div>
                        </div>
                        <p id="add-subject-error" class="form-error" style="display:none"></p>
                    </form>
                </section>
            </div>
            <table id="subjectTable" class="gradient-table">
                <thead><tr><th>Fachname</th><th></th></tr></thead>
                <tbody id="subjectTableBody"></tbody>
            </table>
            <p id="reorderHint" class="text-sm text-light" style="display:none;margin-top:8px;opacity:0.7">
                Reihenfolge bei aktiver Suche nicht änderbar.
            </p>
            <div id="pagination" class="pagination-bar"></div>
        </section>

</section>

<script>
const subjectNameEl = document.getElementById('subjectName');
const addBtn = document.getElementById('add-subject-btn');
subjectNameEl.addEventListener('input', () => addBtn.disabled = subjectNameEl.value.trim() === '');

const allowSubjectDeletion = <?= json_encode($allowSubjectDeletion) ?>;
let allSubjects = [];
const PAGE_SIZE = 10;
let currentPage = 1;

function getFiltered() {
    const search = document.getElementById('subjectSearch').value.toLowerCase();
    return allSubjects.filter(s => !search || s.name.toLowerCase().includes(search));
}

function renderTable() {
    const searchActive = document.getElementById('subjectSearch').value.trim() !== '';
    document.getElementById('reorderHint').style.display = searchActive ? '' : 'none';
    const filtered = getFiltered();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const tbody = document.getElementById('subjectTableBody'); tbody.innerHTML = '';
    page.forEach(s => {
        const fullIdx  = allSubjects.findIndex(x => x.id === s.id);
        const isFirst  = fullIdx === 0;
        const isLast   = fullIdx === allSubjects.length - 1;
        const swatchBg = s.color ?? '#e2e8f0';
        const swatch = `<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${swatchBg};flex-shrink:0"></span>`;
        const tr = document.createElement('tr'); tr.dataset.id = s.id;
        tr.innerHTML = `<td><div style="display:flex;align-items:center;gap:8px">${swatch}<span>${s.name}</span></div></td><td class="row-actions">
            <button class="btn-s btn-up" ${searchActive || isFirst ? 'disabled' : ''} title="Nach oben"><img src="/imgs/up.svg" alt="↑" style="height:0.85rem;width:auto;vertical-align:middle"></button>
            <button class="btn-s btn-down" ${searchActive || isLast ? 'disabled' : ''} title="Nach unten"><img src="/imgs/down.svg" alt="↓" style="height:0.85rem;width:auto;vertical-align:middle"></button>
            <button class="btn-s btn-edit"><img src="/imgs/edit_blue.svg" alt=""><span class="btn-label">Bearbeiten</span></button>
            <button class="btn-s btn-danger" ${allowSubjectDeletion || s.taskSetCount == 0 ? '' : 'disabled title="Löschen von Fächern mit Aufgabensets ist in den Einstellungen deaktiviert"'}><img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span></button></td>`;
        tr.querySelector('.btn-up').addEventListener('click', () => reorderSubject(s.id, 'up'));
        tr.querySelector('.btn-down').addEventListener('click', () => reorderSubject(s.id, 'down'));
        tr.querySelector('.btn-edit').addEventListener('click', () => toggleEditRow(tr, s.id));
        tr.querySelector('.btn-danger').addEventListener('click', () => confirmDelete(s));
        tbody.appendChild(tr);

        const editTr = document.createElement('tr');
        editTr.className = 'edit-row'; editTr.dataset.editFor = s.id; editTr.style.display = 'none';
        editTr.innerHTML = `<td colspan="2"><div class="edit-form">
            <div class="form-grid" style="grid-template-columns: 1fr auto">
                <div style="display:flex;gap:8px;align-items:flex-end">
                    <div class="form-field" style="flex:1"><label>Fachname</label><input class="ef-name" value="${s.name}"></div>
                    <div class="form-field" style="align-items:center">
                        <label style="margin-bottom:4px;font-size:0.8rem;opacity:0.7">Farbe</label>
                        <input type="color" class="ef-color" value="${s.color ?? '#94a3b8'}" style="width:40px;height:36px;padding:2px;border:1px solid var(--c-lightgrey);border-radius:8px;cursor:pointer">
                    </div>
                </div>
                <div class="form-field form-field-btn"><label>&nbsp;</label><button class="ef-save btn btn-confirm">Speichern</button></div>
            </div>
            <p class="ef-error form-error" style="display:none"></p>
        </div></td>`;
        editTr.querySelector('.ef-save').addEventListener('click', () => saveSubject(editTr, s));
        tbody.appendChild(editTr);
    });
    renderPagination('pagination', totalPages, filtered.length, currentPage, p => { currentPage = p; renderTable(); }, 'Fächer');
}

async function saveSubject(editTr, s) {
    const errorEl = editTr.querySelector('.ef-error'); errorEl.style.display = 'none';
    const body = { id: s.id, name: editTr.querySelector('.ef-name').value.trim(), color: editTr.querySelector('.ef-color').value };
    const resp = await fetch('/edit-subject', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!resp.ok) { errorEl.textContent = 'Fehler beim Speichern.'; errorEl.style.display = ''; return; }
    await loadAll();
}

function confirmDelete(s) {
    showConfirmDialog(`Soll das Fach „${s.name}" wirklich entfernt werden?${s.taskSetCount > 0 ? ` ${s.taskSetCount} Aufgabenset(s) sowie alle zugehörigen Schülerdaten werden unwiderruflich gelöscht.` : ''}`, 'Entfernen', async () => {
        await fetch('/delete-subject', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: s.id}) });
        await loadAll();
    });
}

document.getElementById('subjectSearch').addEventListener('input', () => { currentPage = 1; renderTable(); });

async function reorderSubject(id, direction) {
    await fetch('/reorder-subject', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, direction }),
    });
    await loadAll();
}

async function loadAll() {
    const res = await fetch('/subjects', { cache: 'no-store' });
    allSubjects = res.ok ? await res.json() : [];
    renderTable();
}

document.getElementById('add-subject-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errorEl = document.getElementById('add-subject-error'); errorEl.style.display = 'none';
    const body = { name: document.getElementById('subjectName').value.trim(), color: document.getElementById('subjectColor').value };
    const resp = await fetch('/add-subject', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!resp.ok) { errorEl.textContent = 'Fehler beim Hinzufügen.'; errorEl.style.display = ''; return; }
    e.target.reset(); addBtn.disabled = true; await loadAll();
});

document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
