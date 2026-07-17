<?php $title = 'Raumverwaltung'; include __DIR__ . '/../partials/html-head.php'; ?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="rooms" style="--page-gradient: var(--room-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/raum_reversed.svg" alt="">
            Räume
        </div>
        <div class="page-subtitle">
            Räume finden, einsehen, und bearbeiten
        </div>
    </div>
    <section id="room-list" class="manage-list page-box">
            <div class="filter-bar">
                <input type="search" id="roomSearch" placeholder="Raum suchen …">
                <button id="toggleAddRoom" class="btn filter-add-btn" title="Raum hinzufügen" data-collapsible="add-rooms-wrapper">
                    <img src="/imgs/plus.svg" alt="">
                </button>
            </div>
            <div id="add-rooms-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
                <section class="card-section bg-gradient" style="--bg-gradient: var(--room-gradient)">
                    <div class="bn-section-header">
                        <div class="bn-section-title text-md text-bold">
                            Raum hinzufügen
                        </div>
                    </div>
                    <form id="add-room-form">
                        <div class="form-grid">
                            <div class="form-field">
                                <input type="text" id="roomLabel" placeholder="Raumname (z.B. Raum 101)" required>
                            </div>
                            <div class="form-field">
                                <select id="minLevel" required></select>
                            </div>
                            <div class="form-field form-field-btn">
                                <button type="submit" id="add-room-btn" class="btn btn-confirm" disabled>
                                    <img src="/imgs/plus.svg" alt="Lernmonitor">
                                    Hinzufügen
                                </button>
                            </div>
                        </div>
                        <p id="add-room-error" class="form-error" style="display:none"></p>
                    </form>
                </section>
            </div>
            <table id="roomTable" class="gradient-table">
                <thead><tr><th>Raumname</th><th id="minLevelColHeader">Mindestlevel</th><th></th></tr></thead>
                <tbody id="roomTableBody"></tbody>
            </table>
            <div id="pagination" class="pagination-bar"></div>
        </section>

</section>

<script>
const _levels   = window.graduationConfig?.levels ?? ["Neustarter","Starter","Durchstarter","Lernprofi"];
const _catName  = window.graduationConfig?.categoryName ?? "Abschlussstufe";

// Set column header and populate add-form select
document.getElementById('minLevelColHeader').textContent = 'Mindest-' + _catName;
(function () {
    const sel = document.getElementById('minLevel');
    const placeholder = document.createElement('option');
    placeholder.value = ''; placeholder.textContent = 'Mindest-' + _catName;
    placeholder.disabled = true; placeholder.selected = true;
    sel.appendChild(placeholder);
    _levels.forEach((name, i) => {
        const opt = document.createElement('option');
        opt.value = i; opt.textContent = (i + 1) + ' – ' + name;
        sel.appendChild(opt);
    });
})();

function levelLabel(i) {
    const idx = Number(i);
    return (_levels[idx] ?? '–') + ' (Stufe ' + (idx + 1) + ')';
}

function updateAddBtn() {
    const sel = document.getElementById('minLevel');
    document.getElementById('add-room-btn').disabled =
        document.getElementById('roomLabel').value.trim() === '' || sel.value === '';
}
document.getElementById('roomLabel').addEventListener('input', updateAddBtn);
document.getElementById('minLevel').addEventListener('change', updateAddBtn);

let allRooms = [];
const PAGE_SIZE = 10;
let currentPage = 1;

function getFiltered() {
    const search = document.getElementById('roomSearch').value.toLowerCase();
    return allRooms.filter(r => !search || r.label.toLowerCase().includes(search));
}

function renderTable() {
    const filtered = getFiltered();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const tbody = document.getElementById('roomTableBody'); tbody.innerHTML = '';
    page.forEach(r => {
        const tr = document.createElement('tr'); tr.dataset.id = r.label;
        tr.innerHTML = `<td>${r.label}</td><td>${levelLabel(r.minimumLevel)}</td><td class="row-actions">
            <button class="btn-s btn-profile" style="background:var(--student-gradient);color:#fff;border:none" onclick="window.location.href='/manage_students?room=${encodeURIComponent(r.label)}'"><img src="/imgs/profile.svg" alt="" style="height:0.85rem;vertical-align:middle"><span class="btn-label"> Schüler</span></button>
            <button class="btn-s btn-profile" style="background:var(--teacher-gradient);color:#fff;border:none" onclick="window.location.href='/manage_teachers?room=${encodeURIComponent(r.label)}'"><img src="/imgs/profile.svg" alt="" style="height:0.85rem;vertical-align:middle"><span class="btn-label"> Lehrer</span></button>
            <button class="btn-s btn-edit"><img src="/imgs/edit_blue.svg" alt=""><span class="btn-label">Bearbeiten</span></button>
            <button class="btn-s btn-danger"><img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span></button></td>`;
        tr.querySelector('.btn-edit').addEventListener('click', () => toggleEditRow(tr, r.label));
        tr.querySelector('.btn-danger').addEventListener('click', () => confirmDelete(r));
        tbody.appendChild(tr);

        const editTr = document.createElement('tr');
        editTr.className = 'edit-row'; editTr.dataset.editFor = r.label; editTr.style.display = 'none';
        const levelOpts = _levels.map((name, i) =>
            `<option value="${i}" ${i == r.minimumLevel ? 'selected' : ''}>${i + 1} – ${name}</option>`
        ).join('');
        editTr.innerHTML = `<td colspan="3"><div class="edit-form">
            <div class="form-grid">
                <div class="form-field"><label>Raumname</label><input class="ef-label" value="${r.label}"></div>
                <div class="form-field"><label>Mindest-${_catName}</label><select class="ef-level">${levelOpts}</select></div>
                <div class="form-field form-field-btn"><label>&nbsp;</label><button class="ef-save btn btn-confirm">Speichern</button></div>
            </div>
            <p class="ef-error form-error" style="display:none"></p>
        </div></td>`;
        editTr.querySelector('.ef-save').addEventListener('click', () => saveRoom(editTr, r));
        tbody.appendChild(editTr);
    });
    renderPagination('pagination', totalPages, filtered.length, currentPage, p => { currentPage = p; renderTable(); }, 'Räume');
}

async function saveRoom(editTr, r) {
    const errorEl = editTr.querySelector('.ef-error'); errorEl.style.display = 'none';
    const newLabel = editTr.querySelector('.ef-label').value.trim();
    if (newLabel === '') { errorEl.textContent = 'Raumname fehlt.'; errorEl.style.display = ''; return; }
    const body = { oldLabel: r.label, label: newLabel, minimumLevel: Number(editTr.querySelector('.ef-level').value) };
    const resp = await fetch('/edit-room', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!resp.ok) {
        const data = await resp.json().catch(() => ({}));
        errorEl.textContent = data.error || 'Fehler beim Speichern.'; errorEl.style.display = ''; return;
    }
    await loadAll();
}

function confirmDelete(r) {
    showConfirmDialog(`Soll Raum ${r.label} wirklich entfernt werden?`, 'Entfernen', async () => {
        await fetch('/delete-room', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({label: r.label}) });
        await loadAll();
    });
}

document.getElementById('roomSearch').addEventListener('input', () => { currentPage = 1; renderTable(); });

async function loadAll() {
    const resp = await fetch('/rooms');
    allRooms = await resp.json();
    currentPage = 1; renderTable();
}

document.getElementById('add-room-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errorEl = document.getElementById('add-room-error'); errorEl.style.display = 'none';
    const body = { label: document.getElementById('roomLabel').value.trim(), minimumLevel: Number(document.getElementById('minLevel').value) };
    const resp = await fetch('/add-room', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!resp.ok) { errorEl.textContent = 'Fehler beim Hinzufügen.'; errorEl.style.display = ''; return; }
    e.target.reset(); updateAddBtn(); await loadAll();
});

document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
