<?php $title = 'Lehrer'; include __DIR__ . '/../partials/html-head.php'; ?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="teachers" style="--page-gradient: var(--teacher-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/lehrer_reversed.svg" alt="">
            Lehrer
        </div>
        <div class="page-subtitle">
            Lehrkräfte finden, einsehen, und Profile hinzufügen oder bearbeiten
        </div>
    </div>
    <section id="teacher-list" class="manage-list page-box">
            <div class="filter-bar">
                <input type="search" id="teacherSearch" placeholder="Name oder E-Mail suchen …">
                <select id="filterRoom">
                    <option value="">Alle Räume</option>
                </select>
                <button id="toggleAddTeacher" class="btn filter-add-btn" title="Lehrkraft hinzufügen" data-collapsible="add-teachers-wrapper">
                    <img src="/imgs/plus.svg" alt="">
                </button>
            </div>
            <div id="add-teachers-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
                <section class="card-section bg-gradient" style="--bg-gradient: var(--teacher-gradient)">
                    <div class="bn-section-header">
                        <div class="bn-section-title text-md text-bold">
                            Lehrkraft hinzufügen
                        </div>
                        <div class="bn-section-subtitle text-sm text-light">
                            Hier können Sie einzelne Lehrpersonen hinzufügen oder einem Admin eine Lehrerrolle geben.
                            Der Benutzername ist optional: wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.
                            Unter <a href="/import">Datenmanagement</a> können Sie mehrere Personen gleichzeitig importieren.
                        </div>
                    </div>
                    <form id="add-teacher-form">
                        <div class="form-grid" style="grid-template-columns: repeat(2, 1fr)">
                            <div class="form-field" style="grid-column: 1 / -1">
                                <select id="adminSelect">
                                    <option value="">Adminkonto wählen (optional)</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <input type="text" id="firstName" placeholder="Vorname(n)" required>
                            </div>
                            <div class="form-field">
                                <input type="text" id="lastName" placeholder="Nachname" required>
                            </div>
                            <div class="form-field">
                                <input type="email" id="email" placeholder="E-Mail" required>
                            </div>
                            <div class="form-field">
                                <input type="text" id="benutzername" placeholder="Benutzername (optional)" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="form-field form-field-btn" style="grid-column: 2">
                                <button type="submit" id="add-teacher-btn" class="btn btn-confirm" disabled>
                                    <img src="/imgs/plus.svg" width="10px" alt="Lernmonitor">
                                    Hinzufügen
                                </button>
                            </div>
                        </div>
                        <p id="add-person-error" class="form-error" style="display:none"></p>
                    </form>
                    <p id="add-person-result" class="form-success" style="display:none"></p>
                </section>
            </div>
            <table id="teacherTable" class="gradient-table">
                <thead><tr><th>Name</th><th class="col-email">E-Mail</th><th></th></tr></thead>
                <tbody id="teacherTableBody"></tbody>
            </table>
            <div id="pagination" class="pagination-bar"></div>
        </section>


</section>

<script>
function updateAddBtn() {
    const filled = ['firstName','lastName','email'].every(id => document.getElementById(id).value.trim() !== '');
    document.getElementById('add-teacher-btn').disabled = !filled;
}
['firstName','lastName','email'].forEach(id => {
    document.getElementById(id).addEventListener('input', updateAddBtn);
});

let allTeachers = [];
let allAdminsForLinking = [];
const PAGE_SIZE = 10;
let currentPage = 1;

function getFiltered() {
    const search = document.getElementById('teacherSearch').value.toLowerCase();
    const roomFilter = document.getElementById('filterRoom').value;
    return allTeachers.filter(t => {
        const name = `${t.firstName} ${t.lastName}`.toLowerCase();
        if (search && !name.includes(search) && !t.email.toLowerCase().includes(search)) return false;
        if (roomFilter && (t.room ?? '') !== roomFilter) return false;
        return true;
    });
}

function renderTable() {
    const filtered = getFiltered();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const tbody = document.getElementById('teacherTableBody');
    tbody.innerHTML = '';
    page.forEach(t => {
        const tr = document.createElement('tr');
        tr.dataset.id = t.id;
        tr.innerHTML = `
        <td>${t.firstName} ${t.lastName}</td><td class="col-email">${t.email}</td>
        <td class="row-actions">
                <button class="btn-s btn-profile" id="btn-teacher-profile" aria-label="Profil"><img src="/imgs/profile.svg" alt=""><span class="btn-label">Profil</span></button>
            </td>`;

        tr.querySelector('.btn-profile').addEventListener('click', () => {
		window.location.href = `/teacher-profile?id=${t.id}`;
		});
        tbody.appendChild(tr);
    });
    renderPagination('pagination', totalPages, filtered.length, currentPage, p => { currentPage = p; renderTable(); }, 'Lehrer');
}

['teacherSearch', 'filterRoom'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => { currentPage = 1; renderTable(); });
    document.getElementById(id).addEventListener('change', () => { currentPage = 1; renderTable(); });
});

async function loadAll() {
    const [teachersResp, roomsResp, adminsResp] = await Promise.all([fetch('/teachers'), fetch('/rooms'), fetch('/admins')]);
    allTeachers = await teachersResp.json();
    const rooms = await roomsResp.json();
    allAdminsForLinking = await adminsResp.json();

    const filterRoom = document.getElementById('filterRoom');
    const current = filterRoom.value;
    filterRoom.innerHTML = '<option value="">Alle Räume</option>';
    rooms.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.label; opt.textContent = r.label;
        opt.selected = r.label === current;
        filterRoom.appendChild(opt);
    });
    const preset = new URLSearchParams(window.location.search).get('room');
    if (preset) filterRoom.value = preset;

    const adminSelect = document.getElementById('adminSelect');
    const selectedAdmin = adminSelect.value;
    adminSelect.innerHTML = '<option value="">Adminkonto wählen (optional)</option>';
    allAdminsForLinking.filter(a => !a.hasLinkedTeacher).forEach(a => {
        const name = (a.firstName || a.lastName)
            ? `${a.lastName ?? ''}, ${a.firstName ?? ''}`.replace(/^,\s*/, '')
            : (a.email ?? a.username);
        const opt = document.createElement('option');
        opt.value = a.username;
        opt.textContent = name;
        if (a.username === selectedAdmin) opt.selected = true;
        adminSelect.appendChild(opt);
    });

    currentPage = 1;
    renderTable();
}

document.getElementById('adminSelect').addEventListener('change', function() {
    if (!this.value) return;
    const admin = allAdminsForLinking.find(a => a.username === this.value);
    if (!admin) return;
    document.getElementById('firstName').value  = admin.firstName ?? '';
    document.getElementById('lastName').value   = admin.lastName  ?? '';
    document.getElementById('email').value      = admin.email ?? admin.username;
    document.getElementById('benutzername').value = admin.benutzername ?? '';
    updateAddBtn();
});

document.getElementById('add-teacher-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errorEl = document.getElementById('add-person-error');
    const resultEl = document.getElementById('add-person-result');
    errorEl.style.display = 'none'; resultEl.style.display = 'none';
    const bn = document.getElementById('benutzername').value.trim();
    const body = {
        firstName: document.getElementById('firstName').value.trim(),
        lastName: document.getElementById('lastName').value.trim(),
        email: document.getElementById('email').value.trim(),
        ...(bn ? { benutzername: bn } : {}),
    };
    const resp = await fetch('/add-teacher', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    const data = await resp.json();
    if (!resp.ok) { errorEl.textContent = data.error ?? 'Fehler.'; errorEl.style.display = ''; return; }
    resultEl.textContent = data.linked
        ? 'Lehrerkonto mit dem Admin-Konto verknüpft. Beim nächsten Login stehen beide Dashboards zur Verfügung.'
        : bn
            ? `Konto erstellt. Die Lehrkraft muss sich mit dem Benutzernamen „${bn}" anmelden und kann beim ersten Login ein Passwort wählen.`
            : 'Konto erstellt. Die Lehrkraft kann sich mit ihrer E-Mail anmelden und ein Passwort wählen.';
    resultEl.style.display = '';
    e.target.reset(); updateAddBtn();
    await loadAll();
});

document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
