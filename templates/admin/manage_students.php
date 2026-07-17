<?php $title = 'Schüler'; include __DIR__ . '/../partials/html-head.php'; ?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="students" style="--page-gradient: var(--student-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/schueler_reversed.svg" alt="">
            Schüler
        </div>
        <div class="page-subtitle">
            Schüler finden, einsehen, und Profile hinzufügen oder bearbeiten
        </div>
    </div>
    <section id="student-list" class="manage-list page-box">
            <div class="filter-bar">
                <input type="search" id="studentSearch" placeholder="Name oder E-Mail suchen …">
                <select id="filterClass">
                    <option value="">Alle Klassen</option>
                </select>
                <select id="filterRoom">
                    <option value="">Alle Räume</option>
                </select>
                <select id="filterGraduation">
                    <option value=""></option>
                </select>
                <button id="toggleAddStudent" class="btn filter-add-btn" title="Schüler hinzufügen" data-collapsible="add-students-wrapper">
                    <img src="/imgs/plus.svg" alt="">
                </button>
            </div>
            <div id="add-students-wrapper" class="add-panel-wrapper" style="height:0;overflow:hidden">
            <section class="card-section bg-gradient" style="--bg-gradient: var(--student-gradient)">
                <div class="bn-section-header">
                    <div class="bn-section-title text-md text-bold">
                        Schüler hinzufügen
                    </div>
                    <div class="bn-section-subtitle text-sm text-light">
                        Hier können Sie einzelne Schüler hinzufügen. Unter <a href="/import">Datenmanagement</a> können Sie mehrere Daten gleichzeitig importieren.
                        Der Benutzername ist optional: wenn gesetzt, muss er statt der E-Mail zum Einloggen verwendet werden.
                    </div>
                </div>
                <form id="add-student-form">
                    <div class="form-grid">
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
                            <label style="visibility:hidden" aria-hidden="true">_</label>
                            <input type="text" id="benutzername" placeholder="Benutzername (optional)" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="form-field">
                            <label for="classSelect">Klasse</label>
                            <select id="classSelect" required></select>
                        </div>
                        <div class="form-field">
                            <label for="graduationLevel" data-graduation-label>Abschlussstufe</label>
                            <select id="graduationLevel" required></select>
                        </div>
                        <div class="form-field form-field-btn" style="grid-column: 3">
                            <button type="submit" id="add-student-btn" class="btn btn-confirm" disabled>
                                <img src="/imgs/plus.svg" alt="">Hinzufügen
                            </button>
                        </div>
                    </div>
                    <p id="add-person-error" class="form-error" style="display:none"></p>
                </form>
                <p id="add-person-result" class="form-success" style="display:none"></p>
            </section>
            </div>
            <table id="studentTable" class="gradient-table">
                <thead>
                <tr>
                    <th>Name</th>
                    <th class="col-email">E-Mail</th>
                    <th>Klasse</th>
                    <th data-graduation-label>Abschlussstufe</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="studentTableBody"></tbody>
            </table>
            <div id="pagination" class="pagination-bar"></div>
        </section>
</section>


<script>
	function updateAddBtn() {
		const btn = document.getElementById('add-student-btn');
		const filled = ['firstName', 'lastName', 'email', 'classSelect'].every(id => document.getElementById(id).value.trim() !== '');
		btn.disabled = !filled;
	}

	let allStudents = [];
	let currentPage = 1;
	const PAGE_SIZE = 10;
	const levels = window.graduationConfig?.levels ?? ["Neustarter", "Starter", "Durchstarter", "Lernprofi"];

	// Populate graduation level selects
	(function () {
		const cat = window.graduationConfig?.categoryName ?? 'Abschlussstufe';
		const filterSel = document.getElementById('filterGraduation');
		filterSel.options[0].textContent = 'Alle ' + cat;
		const addSel = document.getElementById('graduationLevel');
		levels.forEach((name, i) => {
			const o1 = document.createElement('option');
			o1.value = i; o1.textContent = (i + 1) + ' – ' + name;
			filterSel.appendChild(o1);
			const o2 = document.createElement('option');
			o2.value = i; o2.textContent = (i + 1) + ' – ' + name;
			addSel.appendChild(o2);
		});
	})();

	function getFiltered() {
		const search = document.getElementById('studentSearch').value.toLowerCase();
		const classFilter = document.getElementById('filterClass').value;
		const roomFilter = document.getElementById('filterRoom').value;
		const gradFilter = document.getElementById('filterGraduation').value;
		return allStudents.filter(s => {
			const name = `${s.firstName} ${s.lastName}`.toLowerCase();
			if (search && !name.includes(search) && !s.email.toLowerCase().includes(search)) return false;
			if (classFilter && String(s.classId) !== classFilter) return false;
			if (roomFilter && (s.room ?? '') !== roomFilter) return false;
			if (gradFilter !== '' && String(s.graduationLevel) !== gradFilter) return false;
			return true;
		});
	}

	function renderStudentTable() {
		const filtered = getFiltered();
		const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
		if (currentPage > totalPages) currentPage = totalPages;

		const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
		const tbody = document.getElementById('studentTableBody');
		tbody.innerHTML = '';

		page.forEach(s => {
			const tr = document.createElement('tr');
			tr.dataset.id = s.id;
			tr.innerHTML = `
            <td>${s.firstName} ${s.lastName}</td>
            <td class="col-email">${s.email}</td>
            <td>${s.classLabel}</td>
            <td>${levels[s.graduationLevel] ?? s.graduationLevel}</td>
            <td class="row-actions">
                <button class="btn-s btn-profile" id="btn-student-profile" aria-label="Profil"><img src="/imgs/profile.svg" alt=""><span class="btn-label">Profil</span></button>
            </td>`;
			tr.querySelector('.btn-profile').addEventListener('click', () => {
				window.location.href = `/student-profile?id=${s.id}`;
			});
			tbody.appendChild(tr);
		});

		renderPagination('pagination', totalPages, filtered.length, currentPage, p => { currentPage = p; renderStudentTable(); }, 'Schüler');
	}

	function resetAndRender() {
		currentPage = 1;
		renderStudentTable();
	}

	async function loadAllStudents() {
		const resp = await fetch('/students');
		allStudents = await resp.json();
		resetAndRender();
	}

	document.addEventListener('DOMContentLoaded', async () => {
		const classes = await fetchClasses();
		populateClassSelect(document.getElementById('classSelect'), classes);

		['firstName', 'lastName', 'email', 'classSelect', 'graduationLevel'].forEach(id => {
			document.getElementById(id).addEventListener('input', updateAddBtn);
			document.getElementById(id).addEventListener('change', updateAddBtn);
		});
		updateAddBtn();

		const filterClass = document.getElementById('filterClass');
		classes.forEach(c => {
			const opt = document.createElement('option');
			opt.value = c.classId || c.id;
			opt.textContent = c.label || c.name;
			filterClass.appendChild(opt);
		});

		const roomsResp = await fetch('/rooms');
		const rooms = await roomsResp.json();
		const filterRoom = document.getElementById('filterRoom');
		rooms.forEach(r => {
			const opt = document.createElement('option');
			opt.value = r.label;
			opt.textContent = r.studentCount !== undefined ? `${r.label} (${r.studentCount})` : r.label;
			filterRoom.appendChild(opt);
		});

		['studentSearch', 'filterClass', 'filterRoom', 'filterGraduation'].forEach(id => {
			document.getElementById(id).addEventListener('input', resetAndRender);
			document.getElementById(id).addEventListener('change', resetAndRender);
		});

		const params = new URLSearchParams(window.location.search);
		if (params.get('class')) document.getElementById('filterClass').value = params.get('class');
		if (params.get('room')) document.getElementById('filterRoom').value = params.get('room');

		await loadAllStudents();
	});

	document.getElementById('add-student-form').addEventListener('submit', async function (e) {
		e.preventDefault();
		const errorEl = document.getElementById('add-person-error');
		const resultEl = document.getElementById('add-person-result');
		errorEl.style.display = 'none';
		resultEl.style.display = 'none';

		const bn = document.getElementById('benutzername').value.trim();
		const body = {
			firstName: document.getElementById('firstName').value.trim(),
			lastName: document.getElementById('lastName').value.trim(),
			email: document.getElementById('email').value.trim(),
			classId: Number(document.getElementById('classSelect').value),
			graduationLevel: Number(document.getElementById('graduationLevel').value),
			...(bn ? { benutzername: bn } : {}),
		};

		const resp = await fetch('/add-student', {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(body),
		});

		const data = await resp.json();

		if (!resp.ok) {
			errorEl.textContent = data.error ?? 'Unbekannter Fehler.';
			errorEl.style.display = '';
			return;
		}

		resultEl.textContent = bn
			? `Konto erstellt. Die Schüler*in muss sich mit dem Benutzernamen „${bn}" anmelden und kann beim ersten Login ein Passwort wählen.`
			: 'Konto erstellt. Die Schüler*in kann sich mit ihrer E-Mail anmelden und ein Passwort wählen.';
		resultEl.style.display = '';
		e.target.reset();
		updateAddBtn();

		await loadAllStudents();
	});
</script>
</body>
</html>
