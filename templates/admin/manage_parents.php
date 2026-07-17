<?php $title = 'Eltern'; include __DIR__ . '/../partials/html-head.php'; ?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="parents" style="--page-gradient: var(--parent-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/parents_reversed.svg" width="50px" alt="">
            Eltern
        </div>
        <div class="page-subtitle">
            Elternzugänge verwalten und mit Schülern verknüpfen
        </div>
    </div>
    <section id="parent-list" class="manage-list page-box">
        <div class="filter-bar">
            <input type="search" id="parentSearch" placeholder="E-Mail suchen …">
            <button id="toggleAddParent" class="btn filter-add-btn" title="Elternteil hinzufügen" data-collapsible="add-parents-wrapper">
                <img src="/imgs/plus.svg" alt="">
            </button>
        </div>
        <div id="add-parents-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
            <section class="card-section bg-gradient" style="--bg-gradient: var(--parent-gradient)">
                <div class="bn-section-header">
                    <div class="bn-section-title text-md text-bold">Elternkonto hinzufügen</div>
                    <div class="bn-section-subtitle text-sm text-light">
                        Das Konto wird ohne Passwort erstellt. Beim ersten Login kann ein eigenes Passwort gewählt werden.
                        Der Benutzername ist optional: wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.
                    </div>
                </div>
                <form id="add-parent-form">
                    <div class="form-grid" style="grid-template-columns: repeat(3, minmax(0, 200px)) auto">
                        <div class="form-field">
                            <input type="email" id="parentEmail" placeholder="E-Mail" required>
                        </div>
                        <div class="form-field">
                            <input type="text" id="parentBenutzername" placeholder="Benutzername (optional)" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="form-field form-field-btn">
                            <button type="submit" id="add-parent-btn" class="btn btn-confirm" disabled>
                                <img src="/imgs/plus.svg" width="10px" alt="">
                                Hinzufügen
                            </button>
                        </div>
                    </div>
                    <p id="add-parent-error" class="form-error" style="display:none"></p>
                </form>
                <p id="add-parent-success" class="form-success" style="display:none"></p>
            </section>
        </div>
        <table id="parentTable" class="gradient-table">
            <thead>
                <tr>
                    <th class="col-email">E-Mail</th>
                    <th>Benutzername</th>
                    <th>Verknüpfte Schüler</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="parentTableBody"></tbody>
        </table>
        <div id="pagination" class="pagination-bar"></div>
    </section>
</section>


<script>
let allParents  = [];
let allStudents = [];
let currentPage = 1;
const PAGE_SIZE = 10;

/* ── Filtering & rendering ─────────────────────────────────────────── */

function getFiltered() {
    const search = document.getElementById('parentSearch').value.toLowerCase();
    return allParents.filter(p =>
        !search ||
        p.email.toLowerCase().includes(search) ||
        (p.benutzername ?? '').toLowerCase().includes(search)
    );
}

function renderTable() {
    const filtered   = getFiltered();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const tbody = document.getElementById('parentTableBody');
    tbody.innerHTML = '';

    page.forEach(p => {
        const tr = document.createElement('tr');
        tr.dataset.id = p.id;

        /* Linked-students cell */
        const studentBadges = (p.students ?? []).map(s =>
            `<span class="profile-badge text-xs text-bold" style="display:inline-flex;align-items:center;gap:4px">
                ${s.firstName} ${s.lastName}
                <button class="btn-unenroll" title="Verknüpfung entfernen"
                    onclick="removeLink(${p.id}, ${s.id}, this)"
                    >✕</button>
            </span>`
        ).join(' ');

        const bnDisplay = p.benutzername ? p.benutzername : '<span style="opacity:.35">—</span>';
        tr.innerHTML = `
            <td class="col-email">${p.email}</td>
            <td>${bnDisplay}</td>
            <td class="parent-students-cell">
                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                    ${studentBadges}
                    <div class="parent-link-picker" style="display:inline-flex;gap:4px;align-items:center;position:relative">
                        <input type="search" class="input-box-s link-student-search" placeholder="Schüler*in suchen ..."
                            style="display:none;width:180px" autocomplete="off">
                        <button class="btn btn-s btn-round link-add-btn" title="Schüler*in verknüpfen">
                            <img src="/imgs/plus.svg" width="10" alt="">
                        </button>
                    </div>
                </div>
            </td>
            <td class="row-actions">
                <button class="btn-s btn-edit btn-edit-data"><img src="/imgs/edit_blue.svg" alt=""><span class="btn-label">Bearbeiten</span></button>
                <button class="btn btn-s btn-danger" onclick="deleteParent(${p.id}, this)">
                    <img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span>
                </button>
            </td>`;

        /* Link-picker: search input + dropdown */
        const picker     = tr.querySelector('.parent-link-picker');
        const searchInput = picker.querySelector('.link-student-search');
        const addBtn     = picker.querySelector('.link-add-btn');
        const linkedIds  = new Set((p.students ?? []).map(s => s.id));
        let pickerDropdown = null;

        function closePickerDropdown() {
            if (!pickerDropdown) return;
            pickerDropdown.remove();
            pickerDropdown = null;
        }

        function renderPickerDropdown(query) {
            closePickerDropdown();
            const q = query.toLowerCase();
            const matches = allStudents.filter(s =>
                !linkedIds.has(s.id) &&
                (`${s.firstName} ${s.lastName} ${s.email}`).toLowerCase().includes(q)
            ).slice(0, 8);
            if (!matches.length) return;

            pickerDropdown = document.createElement('div');
            pickerDropdown.className = 'dropdown dropdown--visible';
            matches.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dropdown-option';
                btn.textContent = `${s.firstName} ${s.lastName} (${s.email})`;
                btn.addEventListener('mousedown', async e => {
                    e.preventDefault();
                    closePickerDropdown();
                    searchInput.value = '';
                    searchInput.style.display = 'none';
                    await linkStudent(p.id, s.id);
                });
                pickerDropdown.appendChild(btn);
            });

            positionDropdown(pickerDropdown, searchInput);
            document.body.appendChild(pickerDropdown);
        }

        addBtn.addEventListener('click', () => {
            if (searchInput.style.display === 'none') {
                searchInput.style.display = '';
                searchInput.focus();
            } else {
                searchInput.style.display = 'none';
                closePickerDropdown();
            }
        });

        searchInput.addEventListener('input', () => renderPickerDropdown(searchInput.value));
        searchInput.addEventListener('blur', () => setTimeout(closePickerDropdown, 150));
        searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.style.display = 'none'; closePickerDropdown(); } });

        tr.querySelector('.btn-edit-data').addEventListener('click', () => toggleEditRow(tr, p.id));
        tbody.appendChild(tr);

        const editTr = document.createElement('tr');
        editTr.className = 'edit-row'; editTr.dataset.editFor = p.id; editTr.style.display = 'none';
        editTr.innerHTML = `<td colspan="4"><div class="edit-form">
            <div class="form-grid" style="grid-template-columns:1fr 1fr auto">
                <div class="form-field"><label>E-Mail</label><input class="ef-email input-box" type="email" value="${p.email}" required></div>
                <div class="form-field"><label>Benutzername (optional)</label><input class="ef-bn input-box" type="text" value="${p.benutzername ?? ''}" autocomplete="off" spellcheck="false"></div>
                <div class="form-field form-field-btn"><label>&nbsp;</label><button class="ef-save btn btn-confirm">Speichern</button></div>
            </div>
            <p class="ef-error form-error" style="display:none"></p>
        </div></td>`;
        editTr.querySelector('.ef-save').addEventListener('click', () => saveParent(editTr, p.id));
        tbody.appendChild(editTr);
    });

    renderPagination('pagination', totalPages, filtered.length, currentPage,
        pg => { currentPage = pg; renderTable(); }, 'Elternkonten');
}

function resetAndRender() { currentPage = 1; renderTable(); }

async function saveParent(editTr, id) {
    const errorEl = editTr.querySelector('.ef-error'); errorEl.style.display = 'none';
    const email = editTr.querySelector('.ef-email').value.trim();
    const bn    = editTr.querySelector('.ef-bn').value.trim();
    if (!email) { errorEl.textContent = 'E-Mail darf nicht leer sein.'; errorEl.style.display = ''; return; }
    const resp = await fetch('/update-parent', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, email, benutzername: bn }),
    });
    const data = await resp.json();
    if (!resp.ok) { errorEl.textContent = data.error ?? 'Fehler beim Speichern.'; errorEl.style.display = ''; return; }
    await loadAll();
}

/* ── Data loading ──────────────────────────────────────────────────── */

async function loadAll() {
    const [parentsResp, studentsResp] = await Promise.all([
        fetch('/parents'),
        fetch('/students'),
    ]);
    allParents  = await parentsResp.json();
    allStudents = await studentsResp.json();
    resetAndRender();
}

/* ── Actions ───────────────────────────────────────────────────────── */

async function linkStudent(parentId, studentId) {
    const resp = await fetch('/add-parent-link', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parentId, studentId }),
    });
    if (resp.ok) await loadAll();
}

async function removeLink(parentId, studentId, btn) {
    btn.disabled = true;
    const resp = await fetch('/remove-parent-link', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ parentId, studentId }),
    });
    if (resp.ok) await loadAll();
    else btn.disabled = false;
}


function deleteParent(parentId, btn) {
    showConfirmDialog('Soll dieses Elternkonto wirklich gelöscht werden?', 'Löschen', async () => {
        btn.disabled = true;
        const resp = await fetch('/delete-parent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ parentId }),
        });
        if (resp.ok) await loadAll();
        else btn.disabled = false;
    });
}

/* ── Add-parent form ───────────────────────────────────────────────── */

document.getElementById('parentEmail').addEventListener('input', () => {
    document.getElementById('add-parent-btn').disabled =
        document.getElementById('parentEmail').value.trim() === '';
});

document.getElementById('add-parent-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const errorEl = document.getElementById('add-parent-error');
    const successEl = document.getElementById('add-parent-success');
    errorEl.style.display = 'none';
    successEl.style.display = 'none';

    const email = document.getElementById('parentEmail').value.trim();
    const bn    = document.getElementById('parentBenutzername').value.trim();
    const resp = await fetch('/add-parent', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, ...(bn ? { benutzername: bn } : {}) }),
    });
    const data = await resp.json();

    if (!resp.ok) {
        errorEl.textContent = data.error ?? 'Unbekannter Fehler.';
        errorEl.style.display = '';
        return;
    }

    successEl.textContent = bn
        ? `Konto erstellt. Der Nutzer muss sich mit dem Benutzernamen „${bn}" anmelden und kann beim ersten Login ein Passwort wählen.`
        : 'Konto erstellt. Der Nutzer kann sich mit seiner E-Mail anmelden und ein Passwort wählen.';
    successEl.style.display = '';
    e.target.reset();
    document.getElementById('add-parent-btn').disabled = true;
    await loadAll();
});

document.getElementById('parentSearch').addEventListener('input', resetAndRender);

document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
