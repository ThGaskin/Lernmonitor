<?php $title = 'Administratoren'; include __DIR__ . '/../partials/html-head.php'; ?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="admins" style="--page-gradient: var(--admin-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/admin_reversed.svg" width="50px" alt="">
            Kontoverwaltung
        </div>
        <div class="page-subtitle">
            Administratorkonten verwalten und Passwörter zurücksetzen
        </div>
    </div>

    <section id="admin-list" class="manage-list page-box">
        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">
                Administratoren
            </div>
            <div class="bn-section-subtitle text-sm text-light">
                Hier finden Sie alle Personen mit Administrator-Rechten im Lernmonitor. Sie können neue Administrator-Konten erstellen
                oder Benutzern die Administrator-Rechte entziehen. Es muss mindestens ein Administrator-Konto vorhanden sein,
                und Sie können sich nicht selbst die Administrator-Rechte entziehen.
            </div>
        </div>
        <div class="filter-bar">
            <input type="search" id="adminSearch" placeholder="Benutzername suchen …">
            <button id="toggleAddAdmin" class="btn filter-add-btn" title="Administrator hinzufügen" data-collapsible="add-admins-wrapper">
                <img src="/imgs/plus.svg" width="15px" alt="">
            </button>
        </div>
        <div id="add-admins-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
            <section class="card-section bg-gradient" style="--bg-gradient: var(--admin-gradient)">
                <div class="bn-section-header">
                    <div class="bn-section-title text-md text-bold">
                        Administrator erstellen
                    </div>
                    <div class="bn-section-subtitle text-sm text-light">
                        Hier können Sie ein neues Administratorkonto anlegen. Bestehende Benutzer können unter 'Alle Benutzer' zum Admin gemacht werden.
                        Der Benutzername ist optional: wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.
                    </div>
                </div>
                <form id="add-admin-form">
                    <div class="form-grid" style="grid-template-columns: repeat(2, 1fr)">
                        <div class="form-field">
                            <input type="text" class="input-box" id="adminFirstName" placeholder="Vorname(n)" required>
                        </div>
                        <div class="form-field">
                            <input type="text" class="input-box" id="adminLastName" placeholder="Nachname" required>
                        </div>
                        <div class="form-field">
                            <input type="email" class="input-box" id="adminEmail" placeholder="E-Mail" required>
                        </div>
                        <div class="form-field">
                            <input type="text" class="input-box" id="adminBenutzername" placeholder="Benutzername (optional)" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="form-field form-field-btn" style="grid-column: 2">
                            <button type="submit" id="add-admin-btn" class="btn btn-confirm" disabled>
                                <img src="/imgs/plus.svg" width="10px" alt="">
                                Hinzufügen
                            </button>
                        </div>
                    </div>
                    <p id="add-admin-error" class="form-error" style="display:none"></p>
                    <p id="add-admin-success" class="form-success" style="display:none"></p>
                </form>
            </section>
        </div>
        <table id="adminTable" class="gradient-table">
            <thead><tr><th>Name</th><th class="col-email">E-Mail</th><th>Benutzername</th><th class="col-actions"><span class="col-width-probe" aria-hidden="true"><span class="btn-s">Passwort ändern</span><span class="btn-s">Bearbeiten</span><span class="btn-s">Als Admin entfernen</span></span></th></tr></thead>
            <tbody id="adminTableBody"></tbody>
        </table>
        <div id="admin-pagination" class="pagination-bar"></div>
    </section>

    <section id="user-list" class="manage-list page-box">
        <div class="bn-section-header">
            <div class="bn-section-title text-md text-bold">
                Alle Benutzer
            </div>
            <div class="bn-section-subtitle text-sm text-light">
                Hier finden Sie alle Benutzer des Lernmonitors ohne Administrator-Rechte. Sie können bestehenden Benutzern Administrator-Rechte geben.
            </div>
        </div>
        <div class="filter-bar">
            <input type="search" id="userSearch" placeholder="Name oder E-Mail suchen …">
            <select id="filterUserRole">
                <option value="">Alle Rollen</option>
                <option value="student">Schüler</option>
                <option value="teacher">Lehrer</option>
                <option value="parent">Eltern</option>
            </select>
        </div>
        <table id="userTable" class="gradient-table">
            <thead><tr><th>Name</th><th class="col-email">E-Mail</th><th class="col-rol">Rolle</th><th class="col-actions"><span class="col-width-probe" aria-hidden="true"><span class="btn-s">Passwort ändern</span><span class="btn-s">Als Admin hinzufügen</span></span></th></tr></thead>
            <tbody id="userTableBody"></tbody>
        </table>
        <div id="user-pagination" class="pagination-bar"></div>
    </section>
</section>


<div id="reset-modal" class="confirm-overlay" style="display:none">
    <div class="confirm-box">
        <div class="confirm-box-header">Passwort ändern</div>
        <div class="confirm-box-text">Hier können Sie das Passwort für <strong id="reset-modal-username"></strong> zurücksetzen. Wählen Sie ein neues Passwort oder generieren Sie ein neues, sicheres Passwort.</div>
        <div class="form-field" style="margin:12px 0">
            <input type="text" id="reset-pw-input" placeholder="Passwort" style="width:100%;box-sizing:border-box">
        </div>
        <p id="reset-pw-error" class="form-error" style="display:none"></p>
        <div class="confirm-actions">
            <button id="reset-generate-btn" class="btn">Passwort&nbspgenerieren</button>
            <div class="confirm-actions-group">
                <button id="reset-modal-close" class="btn btn-cancel" onclick="document.getElementById('reset-modal').style.display='none'">Abbrechen</button>
                <button id="reset-confirm-btn" class="btn btn-confirm" disabled>Bestätigen</button>
            </div>
        </div>
    </div>
</div>

<div id="promote-modal" class="confirm-overlay" style="display:none">
    <div class="confirm-box">
        <div class="confirm-box-header">Als Administrator hinzufügen</div>
        <div id="promote-modal-text" class="confirm-box-text"></div>
        <p id="promote-error" class="form-error" style="display:none"></p>
        <div class="confirm-actions">
            <button id="promote-cancel-btn" class="btn btn-cancel" onclick="document.getElementById('promote-modal').style.display='none'">Abbrechen</button>
            <button id="promote-confirm-btn" class="btn btn-confirm">Bestätigen</button>
        </div>
    </div>
</div>

<script>
function updateAddBtn() {
    const filled = ['adminFirstName','adminLastName','adminEmail'].every(id => document.getElementById(id).value.trim() !== '');
    document.getElementById('add-admin-btn').disabled = !filled;
}
['adminFirstName','adminLastName','adminEmail'].forEach(id => document.getElementById(id).addEventListener('input', updateAddBtn));

function generatePassword() {
    const bytes = new Uint8Array(6);
    crypto.getRandomValues(bytes);
    return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

// ── Admin table ──────────────────────────────────────────────────────────────

let allAdmins = [];
let currentUsername = null;
const PAGE_SIZE = 10;
let currentPage = 1;

function getFilteredAdmins() {
    const search = document.getElementById('adminSearch').value.toLowerCase();
    return allAdmins.filter(a => !search ||
        `${a.firstName ?? ''} ${a.lastName ?? ''}`.toLowerCase().includes(search) ||
        a.username.toLowerCase().includes(search) ||
        (a.email ?? '').toLowerCase().includes(search) ||
        (a.benutzername ?? '').toLowerCase().includes(search));
}

function renderAdminTable() {
    const filtered = getFilteredAdmins();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (currentPage > totalPages) currentPage = totalPages;
    const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const tbody = document.getElementById('adminTableBody'); tbody.innerHTML = '';
    const isLast = allAdmins.length <= 1;
    page.forEach(a => {
        const isSelf    = a.username === currentUsername;
        const canDelete = !isSelf && !isLast;
        const displayName = (a.firstName || a.lastName) ? `${a.firstName ?? ''} ${a.lastName ?? ''}`.trim() : '<span style="opacity:.35">—</span>';
        const displayEmail = a.email ?? a.username;
        const bnDisplay = a.benutzername ? a.benutzername : '<span style="opacity:.35">—</span>';
        const tr = document.createElement('tr'); tr.dataset.id = a.username;
        tr.innerHTML = `
            <td>${displayName}${isSelf ? ' <span style="opacity:.5;font-size:.85em">(Ich)</span>' : ''}</td>
            <td class="col-email">${displayEmail}</td>
            <td>${bnDisplay}</td>
            <td class="row-actions">
                <button class="btn-s btn-edit btn-reset-pw">Passwort ändern</button>
                <button class="btn-s btn-edit btn-edit-data"><img src="/imgs/edit_blue.svg" alt=""><span class="btn-label">Bearbeiten</span></button>
                <button class="btn-s btn-danger btn-del"${canDelete ? '' : ' disabled title="' + (isSelf ? 'Eigenes Konto' : 'Letzter Admin') + '"'}><img src="/imgs/remove.svg" alt=""><span class="btn-label">Als Admin entfernen</span></button>
            </td>`;
        tr.querySelector('.btn-reset-pw').addEventListener('click', () => openResetModal({type: 'admin', username: a.username}));
        tr.querySelector('.btn-edit-data').addEventListener('click', () => toggleEditRow(tr, a.username));
        if (canDelete) tr.querySelector('.btn-del').addEventListener('click', () => confirmDelete(a.username));
        tbody.appendChild(tr);

        const editTr = document.createElement('tr');
        editTr.className = 'edit-row'; editTr.dataset.editFor = a.username; editTr.style.display = 'none';
        editTr.innerHTML = `<td colspan="4"><div class="edit-form">
            <div class="form-grid" style="grid-template-columns: repeat(2, 1fr)">
                <div class="form-field"><label>Vorname(n)</label><input class="ef-fn input-box" type="text" value="${a.firstName ?? ''}"></div>
                <div class="form-field"><label>Nachname</label><input class="ef-ln input-box" type="text" value="${a.lastName ?? ''}"></div>
                <div class="form-field"><label>E-Mail</label><input class="ef-email input-box" type="email" value="${a.email ?? a.username}" required></div>
                <div class="form-field"><label>Benutzername (optional)</label><input class="ef-bn input-box" type="text" value="${a.benutzername ?? ''}" autocomplete="off" spellcheck="false"></div>
                <div class="form-field form-field-btn" style="grid-column: 2"><label>&nbsp;</label><button class="ef-save btn btn-confirm">Speichern</button></div>
            </div>
            <p class="ef-error form-error" style="display:none"></p>
        </div></td>`;
        editTr.querySelector('.ef-save').addEventListener('click', () => saveAdmin(editTr, a.username));
        tbody.appendChild(editTr);
    });
    renderPagination('admin-pagination', totalPages, filtered.length, currentPage,
        p => { currentPage = p; renderAdminTable(); },
        `Administrator${filtered.length !== 1 ? 'en' : ''}`);
}

async function saveAdmin(editTr, username) {
    const errorEl = editTr.querySelector('.ef-error'); errorEl.style.display = 'none';
    const firstName = editTr.querySelector('.ef-fn').value.trim();
    const lastName  = editTr.querySelector('.ef-ln').value.trim();
    const email     = editTr.querySelector('.ef-email').value.trim();
    const bn        = editTr.querySelector('.ef-bn').value.trim();
    if (!email) { errorEl.textContent = 'E-Mail darf nicht leer sein.'; errorEl.style.display = ''; return; }
    const resp = await fetch('/update-admin', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ username, firstName, lastName, email, benutzername: bn }),
    });
    const data = await resp.json();
    if (!resp.ok) { errorEl.textContent = data.error ?? 'Fehler beim Speichern.'; errorEl.style.display = ''; return; }
    await loadAll();
}

// ── User table ───────────────────────────────────────────────────────────────

let allUsers = [];
let userPage = 1;

const roleLabel = {student: 'Schüler', teacher: 'Lehrer', parent: 'Eltern'};

function getFilteredUsers() {
    const search = document.getElementById('userSearch').value.toLowerCase();
    const role   = document.getElementById('filterUserRole').value;
    return allUsers.filter(u => {
        if (u.isAdmin) return false;
        const name = `${u.firstName} ${u.lastName}`.toLowerCase();
        if (search && !name.includes(search) && !u.email.toLowerCase().includes(search)) return false;
        if (role && u.type !== role) return false;
        return true;
    });
}

function renderUserTable() {
    const filtered = getFilteredUsers();
    const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (userPage > totalPages) userPage = totalPages;
    const page = filtered.slice((userPage - 1) * PAGE_SIZE, userPage * PAGE_SIZE);
    const tbody = document.getElementById('userTableBody'); tbody.innerHTML = '';
    page.forEach(u => {
        const displayName = u.type === 'parent' ? u.email : `${u.firstName} ${u.lastName}`;
        let promoteHtml = '';
        if (u.type === 'teacher') {
            if (u.isLinked) {
                promoteHtml = `<button class="btn-s btn-promote" disabled title="Bereits mit Admin-Konto verknüpft">Als Admin hinzufügen</button>`;
            } else {
                promoteHtml = `<button class="btn-s btn-red btn-promote">Als Admin hinzufügen</button>`;
            }
        }
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${displayName}</td><td class="col-email">${u.type !== 'parent' ? u.email : ''}</td><td>${roleLabel[u.type] ?? u.type}</td>
            <td class="row-actions">
                ${promoteHtml}
                <button class="btn-s btn-edit btn-reset-pw">Passwort ändern</button>
            </td>`;
        tr.querySelector('.btn-reset-pw').addEventListener('click', () =>
            openResetModal({type: 'user', id: u.id, userType: u.type, name: displayName}));
        const promoteEl = tr.querySelector('.btn-promote');
        if (promoteEl && !u.isLinked) promoteEl.addEventListener('click', () => openPromoteModal(u));
        tbody.appendChild(tr);
    });
    renderPagination('user-pagination', totalPages, filtered.length, userPage,
        p => { userPage = p; renderUserTable(); }, 'Benutzer');
}

document.getElementById('userSearch').addEventListener('input', () => { userPage = 1; renderUserTable(); });
document.getElementById('filterUserRole').addEventListener('change', () => { userPage = 1; renderUserTable(); });

// ── Confirm delete modal ─────────────────────────────────────────────────────

function confirmDelete(username) {
    showConfirmDialog(`Soll ${username} wirklich als Administrator entfernt werden? Die Person verliert damit ihre Administrator-Rechte im Lernmonitor.`, 'Entfernen', async () => {
        const resp = await fetch('/delete-admin', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({username}) });
        if (!resp.ok) { alert('Fehler beim Löschen.'); return; }
        await loadAll();
    });
}

// ── Reset password modal ─────────────────────────────────────────────────────

let resetTarget = null;

function openResetModal(target) {
    resetTarget = target;
    const name = target.type === 'admin' ? target.username : target.name;
    document.getElementById('reset-modal-username').textContent = name;
    document.getElementById('reset-pw-input').value = '';
    document.getElementById('reset-pw-error').style.display = 'none';
    document.getElementById('reset-confirm-btn').disabled = true;
    document.getElementById('reset-modal').style.display = '';
}

document.getElementById('reset-pw-input').addEventListener('input', function() {
    document.getElementById('reset-confirm-btn').disabled = this.value.trim() === '';
});

document.getElementById('reset-generate-btn').addEventListener('click', function() {
    const input = document.getElementById('reset-pw-input');
    input.value = generatePassword();
    document.getElementById('reset-confirm-btn').disabled = false;
});

document.getElementById('reset-confirm-btn').addEventListener('click', async function() {
    const password = document.getElementById('reset-pw-input').value.trim();
    const errorEl  = document.getElementById('reset-pw-error');
    errorEl.style.display = 'none';
    let resp;
    if (resetTarget.type === 'admin') {
        resp = await fetch('/reset-admin-password', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({username: resetTarget.username, password}) });
    } else {
        resp = await fetch('/reset-user-password', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: resetTarget.id, type: resetTarget.userType, password}) });
    }
    if (!resp.ok) { errorEl.textContent = 'Fehler beim Zurücksetzen.'; errorEl.style.display = ''; return; }
    document.getElementById('reset-modal').style.display = 'none';
});

// ── Promote to admin modal ───────────────────────────────────────────────────

let promoteTarget = null;

function openPromoteModal(user) {
    promoteTarget = user;
    document.getElementById('promote-modal-text').textContent =
        `${user.firstName} ${user.lastName} bekommt ein Admin-Konto, das mit ihrem Lehrerkonto verknüpft ist. Nach dem nächsten Login können beide Dashboards über einen Schaltfläche gewechselt werden. Die Zugangsdaten bleiben unverändert.`;
    document.getElementById('promote-error').style.display = 'none';
    document.getElementById('promote-modal').style.display = '';
}

document.getElementById('promote-confirm-btn').addEventListener('click', async function() {
    const errorEl = document.getElementById('promote-error');
    errorEl.style.display = 'none';
    const resp = await fetch('/promote-to-admin', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: promoteTarget.id, type: promoteTarget.type}) });
    const data = await resp.json();
    if (!resp.ok) { errorEl.textContent = data.error ?? 'Fehler.'; errorEl.style.display = ''; return; }
    document.getElementById('promote-modal').style.display = 'none';
    await loadAll();
});

// ── Search / load ────────────────────────────────────────────────────────────

document.getElementById('adminSearch').addEventListener('input', () => { currentPage = 1; renderAdminTable(); });

async function loadAll() {
    const [adminsResp, meResp, usersResp] = await Promise.all([fetch('/admins'), fetch('/me'), fetch('/all-users')]);
    allAdmins = await adminsResp.json();
    const me = await meResp.json();
    currentUsername = me.username;
    allUsers = await usersResp.json();
    currentPage = 1; renderAdminTable();
    userPage = 1; renderUserTable();
}

document.getElementById('add-admin-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const errorEl   = document.getElementById('add-admin-error');
    const successEl = document.getElementById('add-admin-success');
    errorEl.style.display = 'none'; successEl.style.display = 'none';
    const firstName    = document.getElementById('adminFirstName').value.trim();
    const lastName     = document.getElementById('adminLastName').value.trim();
    const email        = document.getElementById('adminEmail').value.trim();
    const benutzername = document.getElementById('adminBenutzername').value.trim();
    const resp = await fetch('/add-admin', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({firstName, lastName, email, ...(benutzername ? {benutzername} : {})}) });
    const data = await resp.json();
    if (!resp.ok) { errorEl.textContent = data.error ?? 'Fehler beim Hinzufügen.'; errorEl.style.display = ''; return; }
    successEl.textContent = benutzername
        ? `Konto erstellt. ${firstName} ${lastName} muss sich mit dem Benutzernamen „${benutzername}" anmelden und kann beim ersten Login ein Passwort wählen.`
        : `Konto erstellt. ${firstName} ${lastName} kann sich mit ihrer E-Mail anmelden und ein Passwort wählen.`;
    successEl.style.display = '';
    e.target.reset(); updateAddBtn(); await loadAll();
});

document.addEventListener('DOMContentLoaded', loadAll);
</script>
</body>
</html>
