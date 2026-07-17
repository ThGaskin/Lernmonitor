// student-view.js — shared view for student dashboard and student profile
// Expects: window.studentViewConfig = { studentId: null|number, viewerRole: 'student'|'teacher'|'admin'|'parent' }

const _cfg       = window.studentViewConfig ?? { studentId: null, viewerRole: 'student' };
const viewerRole = _cfg.viewerRole;
const studentId  = _cfg.studentId ?? null;   // null → own dashboard

const isOwn          = studentId === null;
const canSubmitTasks = viewerRole === 'student';
const canEditProfile = viewerRole === 'admin';
const showOwnActions = viewerRole === 'student';

const levels = window.graduationConfig?.levels ?? ["Neustarter", "Starter", "Durchstarter", "Lernprofi"];

let _profileData = null;
let _performance = null;
let _currentLeistungSubjectId = null;

document.addEventListener('DOMContentLoaded', async () => {
    const idQ = studentId ? `?id=${studentId}` : '';

    const [profileData, lgTaskSets, performance] = await Promise.all([
        getJson(studentId ? `/api/student-profile${idQ}` : '/mydata').catch(() => null),
        getJson(studentId ? `/api/student-lg-tasks${idQ}` : '/my-lg-tasks').catch(() => []),
        getJson(studentId ? `/api/student-lg-performance${idQ}` : '/my-lg-performance').catch(() => []),
    ]);

    if (!profileData) {
        const main = document.querySelector('main');
        if (main) main.innerHTML = '<p style="color:rgba(255,255,255,0.7);padding:40px">Schüler nicht gefunden.</p>';
        return;
    }
    _profileData = profileData;

    await renderSidebar(profileData);
    if (isOwn) {
        buildYearPicker(
            document.getElementById('year-picker-container'),
            '/api/my-performance-years',
            year => `/my-lg-performance?year=${encodeURIComponent(year)}`,
            year => `/my-lg-tasks?year=${encodeURIComponent(year)}`
        );
    } else if (studentId) {
        buildYearPicker(
            document.getElementById('year-picker-container'),
            `/api/student-performance-years?id=${studentId}`,
            year => `/api/student-lg-performance?id=${studentId}&year=${encodeURIComponent(year)}`,
            year => `/api/student-lg-tasks?id=${studentId}&year=${encodeURIComponent(year)}`
        );
    }
    if (viewerRole === 'parent' && (_cfg.linkedStudents ?? []).length > 1) {
        setupChildSwitcher(_cfg.linkedStudents);
    }
    setCardHeaders(profileData);
    _performance = performance;
    renderFachfortschritt(lgTaskSets);
    renderLeistungen(performance);

    if (!canSubmitTasks) {
        document.getElementById('fachfortschritt-bars')?.classList.add('fachfortschritt--readonly');
    }

    if (canEditProfile) {
        document.getElementById('admin-sidebar')?.style.removeProperty('display');
        populateEditForm(profileData);
        renderSubjectTags(profileData.subjects);
        renderSubjectDropdown(profileData.availableSubjects);
        setupAdminHandlers();
    }
});

// ---------------------------------------------------------------------------
// Card headers — titles differ between own dashboard and profile view
// ---------------------------------------------------------------------------

function setCardHeaders(data) {
    const aufgabenHeader = document.getElementById('aufgaben-header');
    const aufgabenDesc   = document.getElementById('aufgaben-desc');
    const leistHeader    = document.getElementById('leistungen-header-text');
    const leistDesc      = document.getElementById('leistungen-desc');

    if (isOwn) {
        if (aufgabenHeader) aufgabenHeader.textContent = 'Meine Aufgaben';
        if (aufgabenDesc)   aufgabenDesc.textContent   = 'Klicke auf ein Aufgabenset, um den Status zu aktualisieren.';
        if (leistHeader)    leistHeader.textContent    = 'Meine Leistungen im Fach';
        if (leistDesc)      leistDesc.textContent      = 'Hier siehst du, wieviele Punkte du bisher erreicht hast.';
    } else {
        if (aufgabenHeader) aufgabenHeader.textContent = `Aufgaben`;
        if (aufgabenDesc)   aufgabenDesc.textContent   = 'Klicken Sie auf ein Aufgabenset, um den Status anzusehen.';
        if (leistHeader)    leistHeader.textContent    = 'Leistungen im Fach';
        if (leistDesc)      leistDesc.textContent      = `Wieviele Punkte ${data.firstName} bisher erreicht hat.`;
    }
}

// ---------------------------------------------------------------------------
// Sidebar
// ---------------------------------------------------------------------------

async function renderSidebar(data) {
    const greetingEl = document.getElementById('profile-greeting');
    const nameEl     = document.getElementById('profile-first-name');
    if (greetingEl) greetingEl.style.display = isOwn ? '' : 'none';
    if (nameEl) { nameEl.textContent = isOwn ? data.firstName : `${data.firstName} ${data.lastName}`; fitTextToWidth(nameEl); }

    // Normalize shape: /mydata returns schoolClass + currentRoom as object; /api/student-profile returns class + currentRoom as string
    const classLabel     = data.class?.label ?? data.schoolClass?.label ?? '–';
    const currentRoomStr = typeof data.currentRoom === 'string' ? (data.currentRoom ?? '') : (data.currentRoom?.label ?? '');

    document.getElementById('profile-email').textContent = data.email;
    document.getElementById('profile-class').textContent = `Klasse ${classLabel}`;
    document.getElementById('profile-level').textContent =
        `${levels[data.graduationLevel] ?? data.graduationLevel} (Stufe ${Number(data.graduationLevel) + 1})`;

    const nativeSelect = document.getElementById('roomSelect');
    if (!nativeSelect) {
        // Parent read-only view: show current room as plain text
        const roomDisplay = document.getElementById('roomDisplay');
        if (roomDisplay) roomDisplay.textContent = currentRoomStr || window.defaultRoom || 'kein Raum';
        return;
    }

    const rooms     = await fetchRooms();
    const sid       = studentId ?? data.id;
    const available = rooms.filter(r => Number(r.minimumLevel) <= Number(data.graduationLevel));
    let currentValue = currentRoomStr;
    const options   = [
        { value: '', label: window.defaultRoom || 'kein Raum', studentCount: null },
        ...available.map(r => ({ value: r.label, label: r.label, studentCount: r.studentCount ?? null })),
    ];

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'dropdown-trigger';
    trigger.textContent = options.find(o => o.value === currentValue)?.label ?? (window.defaultRoom || 'kein Raum');
    nativeSelect.replaceWith(trigger);

    let roomDropdown = null;

    function closeRoomDropdown() {
        if (!roomDropdown) return;
        const el = roomDropdown;
        roomDropdown = null;
        el.style.pointerEvents = 'none';
        el.classList.remove('dropdown--visible');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
    }

    function openRoomDropdown() {
        if (roomDropdown) { closeRoomDropdown(); return; }
        roomDropdown = document.createElement('div');
        roomDropdown.className = 'dropdown';
        document.body.appendChild(roomDropdown);

        options.forEach(o => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dropdown-option dropdown-option--spread' + (o.value === currentValue ? ' dropdown-option--active' : '');
            if (o.studentCount !== null) {
                btn.innerHTML = `<span>${o.label}</span><span class="dropdown-room-count"><img src="/imgs/person_grey.svg" alt="" width="14" height="14"> ${o.studentCount}</span>`;
            } else {
                btn.textContent = o.label;
            }
            btn.addEventListener('click', e => {
                e.stopPropagation();
                const prevValue = currentValue;
                currentValue = o.value;
                trigger.textContent = o.label;
                roomDropdown?.querySelectorAll('.dropdown-option').forEach(b =>
                    b.classList.toggle('dropdown-option--active', b === btn)
                );
                if (prevValue !== o.value) {
                    const prev = options.find(opt => opt.value === prevValue);
                    const next = options.find(opt => opt.value === o.value);
                    if (prev?.studentCount != null) prev.studentCount--;
                    if (next?.studentCount != null) next.studentCount++;
                }
                updateRoom(sid, o.value || null);
                closeRoomDropdown();
            });
            roomDropdown.appendChild(btn);
        });

        const r = trigger.getBoundingClientRect();
        roomDropdown.style.position = 'fixed';
        roomDropdown.style.left     = r.left + 'px';
        roomDropdown.style.top      = (r.bottom + 8) + 'px';
        roomDropdown.style.minWidth = Math.max(r.width, 160) + 'px';

        requestAnimationFrame(() => roomDropdown?.classList.add('dropdown--visible'));
        setTimeout(() => {
            document.addEventListener('click', closeRoomDropdown, { once: true });
            window.addEventListener('scroll', closeRoomDropdown, { once: true, capture: true });
        }, 0);
    }

    trigger.addEventListener('click', e => { e.stopPropagation(); openRoomDropdown(); });

    if (viewerRole === 'teacher' || viewerRole === 'admin') {
        initLevelDropdown(data, (newLevel) => {
            const newAvailable = rooms.filter(r => Number(r.minimumLevel) <= newLevel);
            options.length = 1; // keep 'kein Raum' entry
            options.push(...newAvailable.map(r => ({ value: r.label, label: r.label, studentCount: r.studentCount ?? null })));
            if (currentValue && !newAvailable.find(r => r.label === currentValue)) {
                currentValue = '';
                trigger.textContent = window.defaultRoom || 'kein Raum';
            }
        });
    }
}

function initLevelDropdown(data, onLevelChange) {
    const section = document.getElementById('level-section');
    const nativeSelect = document.getElementById('levelSelect');
    if (!section || !nativeSelect) return;
    section.style.display = '';

    const sid = studentId ?? data.id;
    let currentLevel = Number(data.graduationLevel);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'dropdown-trigger';
    trigger.textContent = levels[currentLevel] ?? '–';
    nativeSelect.replaceWith(trigger);

    let levelDropdown = null;

    function closeLevelDropdown() {
        if (!levelDropdown) return;
        const el = levelDropdown;
        levelDropdown = null;
        el.style.pointerEvents = 'none';
        el.classList.remove('dropdown--visible');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
    }

    function openLevelDropdown() {
        if (levelDropdown) { closeLevelDropdown(); return; }
        levelDropdown = document.createElement('div');
        levelDropdown.className = 'dropdown';
        document.body.appendChild(levelDropdown);

        levels.forEach((label, value) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dropdown-option' + (value === currentLevel ? ' dropdown-option--active' : '');
            btn.textContent = label;
            btn.addEventListener('click', e => {
                e.stopPropagation();
                currentLevel = value;
                trigger.textContent = label;
                levelDropdown?.querySelectorAll('.dropdown-option').forEach(b =>
                    b.classList.toggle('dropdown-option--active', b === btn)
                );
                const levelBadge = document.getElementById('profile-level');
                if (levelBadge) levelBadge.textContent = `${label} (Stufe ${value + 1})`;
                post('/change-graduation-level', { studentId: sid, graduationLevel: value });
                if (onLevelChange) onLevelChange(value);
                closeLevelDropdown();
            });
            levelDropdown.appendChild(btn);
        });

        const r = trigger.getBoundingClientRect();
        levelDropdown.style.position = 'fixed';
        levelDropdown.style.left     = r.left + 'px';
        levelDropdown.style.top      = (r.bottom + 8) + 'px';
        levelDropdown.style.minWidth = Math.max(r.width, 160) + 'px';

        requestAnimationFrame(() => levelDropdown?.classList.add('dropdown--visible'));
        setTimeout(() => {
            document.addEventListener('click', closeLevelDropdown, { once: true });
            window.addEventListener('scroll', closeLevelDropdown, { once: true, capture: true });
        }, 0);
    }

    trigger.addEventListener('click', e => { e.stopPropagation(); openLevelDropdown(); });
}

// ---------------------------------------------------------------------------
// Child switcher (parent view only — multiple linked students)
// ---------------------------------------------------------------------------

function setupChildSwitcher(linkedStudents) {
    const nameEl = document.getElementById('profile-first-name');
    if (!nameEl) return;

    /* Wrap name + chevron in an inline-flex row so they sit on the same line */
    const nameRow = document.createElement('div');
    nameRow.className = 'child-switcher-name-row';
    nameEl.replaceWith(nameRow);
    nameRow.appendChild(nameEl);

    const chevron = document.createElement('button');
    chevron.type      = 'button';
    chevron.title     = 'Kind wechseln';
    chevron.className = 'child-switcher-chevron';
    nameRow.appendChild(chevron);

    let dropdownEl = null;

    function closeDropdown() {
        if (!dropdownEl) return;
        const el = dropdownEl;
        dropdownEl = null;
        el.style.pointerEvents = 'none';
        el.classList.remove('dropdown--visible');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
    }

    chevron.addEventListener('click', e => {
        e.stopPropagation();
        if (dropdownEl) { closeDropdown(); return; }

        dropdownEl = document.createElement('div');
        dropdownEl.className = 'dropdown';
        document.body.appendChild(dropdownEl);

        linkedStudents.forEach(child => {
            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'dropdown-option' + (child.firstName === nameEl.textContent ? ' dropdown-option--active' : '');
            btn.textContent = `${child.firstName} ${child.lastName}`;
            btn.addEventListener('click', ev => {
                ev.stopPropagation();
                closeDropdown();
                fetch('/parent-set-child', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ studentId: child.id }),
                }).then(() => location.reload());
            });
            dropdownEl.appendChild(btn);
        });

        const r = chevron.getBoundingClientRect();
        dropdownEl.style.position = 'fixed';
        dropdownEl.style.left     = r.left + 'px';
        dropdownEl.style.top      = (r.bottom + 8) + 'px';
        dropdownEl.style.minWidth = '160px';
        requestAnimationFrame(() => dropdownEl?.classList.add('dropdown--visible'));
        setTimeout(() => {
            document.addEventListener('click', closeDropdown, { once: true });
        }, 0);
    });
}

// ---------------------------------------------------------------------------
// Year picker (Leistungen historical view)
// ---------------------------------------------------------------------------

async function buildYearPicker(container, yearsUrl, perfUrl, tasksUrl) {
    if (!container) return;
    const years = await getJson(yearsUrl).catch(() => []);
    if (!years || years.length <= 1) return;

    const label = document.createElement('span');
    label.className   = 'text-xs text-bold profile-room-label';
    label.textContent = 'Schuljahr';

    const trigger = document.createElement('button');
    trigger.type      = 'button';
    trigger.className = 'dropdown-trigger';
    trigger.textContent = years[0];

    container.appendChild(label);
    container.appendChild(trigger);
    container.className = 'profile-room-section';

    let currentYear = years[0];
    let dropdownEl  = null;

    function closeYearDropdown() {
        if (!dropdownEl) return;
        const el = dropdownEl;
        dropdownEl = null;
        el.style.pointerEvents = 'none';
        el.classList.remove('dropdown--visible');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
    }

    function openYearDropdown() {
        if (dropdownEl) { closeYearDropdown(); return; }
        dropdownEl = document.createElement('div');
        dropdownEl.className = 'dropdown';
        document.body.appendChild(dropdownEl);

        years.forEach(y => {
            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'dropdown-option' + (y === currentYear ? ' dropdown-option--active' : '');
            btn.textContent = y;
            btn.addEventListener('click', async e => {
                e.stopPropagation();
                currentYear       = y;
                trigger.textContent = y;
                dropdownEl?.querySelectorAll('.dropdown-option').forEach(b =>
                    b.classList.toggle('dropdown-option--active', b === btn)
                );
                closeYearDropdown();
                const [perf, tasks] = await Promise.all([
                    getJson(perfUrl(y)).catch(() => []),
                    tasksUrl ? getJson(tasksUrl(y)).catch(() => []) : Promise.resolve(null),
                ]);
                renderLeistungen(perf);
                if (tasks !== null) renderFachfortschritt(tasks);
            });
            dropdownEl.appendChild(btn);
        });

        const r = trigger.getBoundingClientRect();
        dropdownEl.style.position = 'fixed';
        dropdownEl.style.left     = r.left + 'px';
        dropdownEl.style.top      = (r.bottom + 8) + 'px';
        dropdownEl.style.minWidth = Math.max(r.width, 130) + 'px';

        requestAnimationFrame(() => dropdownEl?.classList.add('dropdown--visible'));
        setTimeout(() => {
            document.addEventListener('click', closeYearDropdown, { once: true });
            window.addEventListener('scroll', closeYearDropdown, { once: true, capture: true });
        }, 0);
    }

    trigger.addEventListener('click', e => { e.stopPropagation(); openYearDropdown(); });

    // years[0] is always the current school year, which the caller has already
    // fetched and rendered before calling buildYearPicker — no need to redo it here.
}

// ---------------------------------------------------------------------------
// Fachfortschritt panel
// ---------------------------------------------------------------------------

function renderFachfortschritt(lgTaskSets) {
    const container = document.getElementById('fachfortschritt-bars');
    const hint      = document.getElementById('no-tasks-hint');

    if (container) container.innerHTML = '';
    if (hint) hint.style.display = 'none';

    if (!Array.isArray(lgTaskSets) || lgTaskSets.length === 0) {
        if (hint) hint.style.display = '';
        return;
    }

    const lgBySubject = new Map();
    lgTaskSets.forEach(ts => {
        if (!lgBySubject.has(ts.subjectId)) lgBySubject.set(ts.subjectId, { name: ts.subjectName, color: ts.subjectColor, sets: [] });
        lgBySubject.get(ts.subjectId).sets.push(ts);
    });

    lgBySubject.forEach(({ name, color, sets }) => {
        const header = document.createElement('div');
        header.className = 'lg-section-header';
        const dot = color ? `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};margin-right:7px;flex-shrink:0"></span>` : '';
        header.innerHTML = `<div class="text-md text-bold" style="display:flex;align-items:center">${dot}${name}</div>`;
        container.appendChild(header);

        const adminClick = viewerRole === 'admin' ? openAdminGradeDropdown : null;
        const bar = createLgBar(sets, !canSubmitTasks, adminClick);
        const gap = 5, n = sets.length;
        bar.querySelectorAll('.lg-bar-task').forEach((seg, i) => {
            const pct = parseFloat(seg.style.width);
            seg.style.width        = `calc(${pct}% - ${gap * (n - 1) / n}px)`;
            seg.style.animationDelay = `${0.3 + i * 0.06}s`;
        });
        container.appendChild(bar);
    });
}

// ---------------------------------------------------------------------------
// Admin: grade editing dropdown (shown when admin clicks a task pill)
// ---------------------------------------------------------------------------

function _syncPerformanceScore(taskSetId, achievedPoints) {
    if (!_performance || _currentLeistungSubjectId === null) return;
    for (const sub of _performance) {
        const pTs = sub.taskSets?.find(t => t.id === taskSetId);
        if (pTs) { pTs.achievedPoints = achievedPoints; break; }
    }
    renderLeistungForSubject(_performance, _currentLeistungSubjectId);
}

function openAdminGradeDropdown(anchor, ts) {
    closeLgStatusDropdown();
    anchor.classList.add('lg-bar-task--open');
    anchor.closest('.lg-bar-container')?.classList.add('lg-bar-container--has-open');
    document.body.classList.add('lg-bars-active');

    const dropdown = document.createElement('div');
    dropdown.id = 'lg-status-dropdown';
    dropdown.className = 'dropdown lg-status-dropdown';
    dropdown.addEventListener('click', e => e.stopPropagation());

    const title = document.createElement('div');
    title.className = 'lg-status-dropdown-title text-sm text-bold';
    title.textContent = ts.name;
    const ptsLabel = document.createElement('div');
    ptsLabel.className = 'lg-status-dropdown-points text-sm text-light';
    // Pass/fail task sets can only score 0 or maxPoints, so "max." is misleading.
    ptsLabel.textContent = ts.isPassFail
        ? `${ts.maxPoints} Punkt${ts.maxPoints !== 1 ? 'e' : ''}`
        : `max. ${ts.maxPoints} Punkt${ts.maxPoints !== 1 ? 'e' : ''}`;
    title.appendChild(ptsLabel);
    dropdown.appendChild(title);

    const isGraded    = ts.achievedPoints !== null && ts.achievedPoints !== undefined;
    const isSubmitted = ts.status === 2;

    if (isSubmitted) {
        async function saveScore(val) {
            const resp = await fetch('/admin/set-student-score', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ studentId, taskSetId: ts.id, achievedPoints: val }),
            });
            if (resp.ok) {
                ts.achievedPoints = val;
                anchor.classList.toggle('lg-graded', val !== null);
                closeLgStatusDropdown();
                _syncPerformanceScore(ts.id, val);
            }
        }

        const btnRow = document.createElement('div');
        btnRow.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-top:8px;';

        if (ts.isPassFail) {
            const activeOutline = 'outline:2px solid rgba(0,0,0,0.35);outline-offset:-2px;';

            const bestandenBtn = document.createElement('button');
            bestandenBtn.className   = 'btn btn-green';
            bestandenBtn.textContent = 'Bestanden';
            if (isGraded && ts.achievedPoints === ts.maxPoints) bestandenBtn.style.cssText = activeOutline;
            bestandenBtn.addEventListener('click', () => saveScore(ts.maxPoints));

            const nichtBtn = document.createElement('button');
            nichtBtn.className   = 'btn btn-red';
            nichtBtn.textContent = 'Nicht bestanden';
            if (isGraded && ts.achievedPoints === 0) nichtBtn.style.cssText = activeOutline;
            nichtBtn.addEventListener('click', () => saveScore(0));

            btnRow.appendChild(bestandenBtn);
            btnRow.appendChild(nichtBtn);
        } else {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:6px;margin-top:8px;';

            const input = document.createElement('input');
            input.type        = 'number';
            input.className   = 'input-box';
            input.min         = 0;
            input.max         = ts.maxPoints;
            input.value       = isGraded ? ts.achievedPoints : '';
            input.placeholder = '–';
            input.style.cssText = 'width:64px;text-align:right;';

            const maxLbl = document.createElement('span');
            maxLbl.className   = 'text-sm';
            maxLbl.textContent = `/ ${ts.maxPoints}`;

            row.appendChild(input);
            row.appendChild(maxLbl);
            dropdown.appendChild(row);

            const saveBtn = document.createElement('button');
            saveBtn.className   = 'btn btn-confirm';
            saveBtn.textContent = 'Speichern';
            saveBtn.addEventListener('click', () => {
                const val = parseInt(input.value, 10);
                if (isNaN(val) || val < 0 || val > ts.maxPoints) {
                    input.style.outline = '2px solid var(--c-red)';
                    return;
                }
                input.style.outline = '';
                saveScore(val);
            });
            btnRow.appendChild(saveBtn);
        }

        if (isGraded) {
            const resetBtn = document.createElement('button');
            resetBtn.className   = 'btn';
            resetBtn.textContent = 'Zurücksetzen';
            resetBtn.addEventListener('click', () => saveScore(null));
            btnRow.insertBefore(resetBtn, btnRow.firstChild);
        }

        dropdown.appendChild(btnRow);
    } else {
        const statusLabels = { 0: 'Nicht begonnen', 1: 'In Bearbeitung', 3: 'Braucht Hilfe', 4: 'Sucht Partner' };
        const note = document.createElement('p');
        note.className   = 'lg-status-locked-note';
        note.textContent = `Status: ${statusLabels[ts.status] ?? ts.status}`;
        dropdown.appendChild(note);
    }

    dropdown._anchor = anchor;
    dropdown.style.position = 'fixed';
    document.body.appendChild(dropdown);

    requestAnimationFrame(() => {
        const ar     = anchor.getBoundingClientRect();
        const margin = 8;
        const cx     = ar.left + ar.width / 2;
        const left   = Math.max(margin, Math.min(cx, window.innerWidth - dropdown.offsetWidth - margin));
        dropdown.style.left = left + 'px';
        dropdown.style.top  = (ar.bottom + 14) + 'px';
        const dr = dropdown.getBoundingClientRect();
        if (dr.bottom > window.innerHeight - 8) {
            dropdown.style.top = (ar.top - dropdown.offsetHeight - 14) + 'px';
        }
        requestAnimationFrame(() => dropdown.classList.add('dropdown--visible'));
    });

    setTimeout(() => {
        document.addEventListener('click', closeLgStatusDropdown, { once: true });
        window.addEventListener('scroll', closeLgStatusDropdown, { once: true, capture: true });
    }, 0);
}

// ---------------------------------------------------------------------------
// Leistungen panel
// ---------------------------------------------------------------------------

function renderLeistungen(subjects) {
    const section      = document.getElementById('leistungen-section');
    if (!section) return;

    if (!Array.isArray(subjects) || subjects.length === 0) {
        section.style.display = 'none';
        const content = document.getElementById('leistungen-content');
        if (content) content.innerHTML = '';
        _currentLeistungSubjectId = null;
        return;
    }

    // First call: placeholder element exists. Subsequent calls: it was replaced by the trigger button.
    const selContainer = document.getElementById('leistungen-subject-select')
                      ?? document.getElementById('leistungen-subject-trigger');
    if (!selContainer) return;

    section.style.display = '';

    let currentId = subjects[0].subjectId;
    _currentLeistungSubjectId = currentId;

    const trigger = document.createElement('button');
    trigger.id        = 'leistungen-subject-trigger';
    trigger.className = 'dropdown-trigger';
    trigger.type      = 'button';
    trigger.textContent = subjects[0].subjectName;
    selContainer.replaceWith(trigger);

    let dropdownEl = null;

    function closeDropdown() {
        if (!dropdownEl) return;
        const el = dropdownEl;
        dropdownEl = null;
        el.style.pointerEvents = 'none';
        el.classList.remove('dropdown--visible');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
    }

    function openDropdown() {
        if (dropdownEl) { closeDropdown(); return; }
        dropdownEl = document.createElement('div');
        dropdownEl.className = 'dropdown';
        document.body.appendChild(dropdownEl);

        subjects.forEach(s => {
            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'dropdown-option' + (s.subjectId === currentId ? ' dropdown-option--active' : '');
            btn.textContent = s.subjectName;
            btn.addEventListener('click', e => {
                e.stopPropagation();
                currentId = s.subjectId;
                _currentLeistungSubjectId = currentId;
                trigger.textContent = s.subjectName;
                dropdownEl?.querySelectorAll('.dropdown-option').forEach(b =>
                    b.classList.toggle('dropdown-option--active', b === btn)
                );
                renderLeistungForSubject(subjects, currentId);
                closeDropdown();
            });
            dropdownEl.appendChild(btn);
        });

        const r = trigger.getBoundingClientRect();
        dropdownEl.style.position = 'fixed';
        dropdownEl.style.left     = r.left + 'px';
        dropdownEl.style.top      = (r.bottom + 8) + 'px';
        dropdownEl.style.minWidth = Math.max(r.width, 160) + 'px';

        requestAnimationFrame(() => dropdownEl?.classList.add('dropdown--visible'));
        setTimeout(() => {
            document.addEventListener('click', closeDropdown, { once: true });
            window.addEventListener('scroll', closeDropdown, { once: true, capture: true });
        }, 0);
    }

    trigger.addEventListener('click', e => { e.stopPropagation(); openDropdown(); });
    renderLeistungForSubject(subjects, currentId);
}

function renderLeistungForSubject(subjects, subjectId) {
    const subject = subjects.find(s => s.subjectId === subjectId);
    if (!subject) return;

    const container = document.getElementById('leistungen-content');
    container.innerHTML = '';

    const { taskSets }   = subject;
    const submitted      = taskSets.filter(ts => ts.status === 2);
    // "Pending" for slider purposes means "not yet graded" -- a submitted
    // task set with no achievedPoints yet (awaiting Bewertung) still counts,
    // so the student can keep projecting a grade for it until it's graded.
    const pending        = taskSets.filter(ts => ts.status !== 2 || ts.achievedPoints === null);
    const totalMax       = taskSets.reduce((s, ts) => s + ts.maxPoints, 0);
    const maxPoints      = taskSets.reduce((m, ts) => Math.max(m, ts.maxPoints), 0);
    const achieved       = submitted.reduce((s, ts) => s + (ts.achievedPoints ?? 0), 0);
    const gradingScale   = subject.gradingScale
        ? fracToAbs(subject.gradingScale, totalMax)
        : (totalMax > 0 ? defaultThresholds(totalMax) : null);
    const curGrade       = gradingScale ? computeGrade(achieved, gradingScale) : null;

    // Top row: pie chart + submitted task lines
    const topRow = document.createElement('div');
    topRow.className = 'leistungen-top-row';

    const pieWrap = document.createElement('div');
    pieWrap.className = 'leistungen-pie-wrap';
    const { svg: pieSvg, playArc } = drawPieChart(achieved, totalMax);
    pieWrap.appendChild(pieSvg);
    topRow.appendChild(pieWrap);

    const linesWrap = document.createElement('div');
    linesWrap.className = 'leistungen-lines-wrap';
    const playFills = [];
    if (submitted.length > 0) {
        submitted.forEach(ts => {
            const { wrap, playFill } = createTaskLine(ts, maxPoints);
            linesWrap.appendChild(wrap);
            if (playFill) playFills.push(playFill);
        });
    } else {
        const empty = document.createElement('p');
        empty.className = 'lg-empty text-italic';
        empty.textContent = 'Noch keine Aufgabensets eingereicht.';
        linesWrap.appendChild(empty);
    }
    topRow.appendChild(linesWrap);
    container.appendChild(topRow);

    const playAll = () => { playArc?.(); playFills.forEach(f => f()); };
    const section = document.getElementById('leistungen-section');
    const anim    = section?.getAnimations().find(a => a.playState !== 'finished');
    if (anim) {
        function waitForVisible() {
            if (parseFloat(getComputedStyle(section).opacity) >= 0.5) playAll();
            else requestAnimationFrame(waitForVisible);
        }
        requestAnimationFrame(waitForVisible);
    } else {
        playAll();
    }

    // Current grade sentence (always, if visible) + sliders for pending tasks
    const gradeVisible = curGrade !== null &&
        (viewerRole === 'teacher' || viewerRole === 'admin' || window.studentViewConfig?.showGrades);

    if (gradeVisible || pending.length > 0) {
        const name = isOwn ? 'du' : (_profileData?.firstName ?? '');
        const verb = isOwn ? 'kannst' : 'kann';
        const stehst = isOwn ? 'stehst du' : `steht ${name}`;
        const regler = isOwn ? 'Verändere die Regler' : 'Verändern Sie die Regler';

        const sliderTitle = document.createElement('p');
        sliderTitle.className = 'leistungen-title card-header-description';
        let titleText = gradeVisible
            ? `Aktuell ${stehst} auf Note <span class="grade-badge grade-badge--${curGrade}">${curGrade}</span>.`
            : '';
        if (pending.length > 0) {
            if (titleText) titleText += ' ';
            titleText += `${regler}, um zu sehen, welche Note ${name} erreichen ${verb}:`;
        }
        sliderTitle.innerHTML = titleText;
        container.appendChild(sliderTitle);
    }

    if (pending.length > 0) {
        const name = isOwn ? 'du' : (_profileData?.firstName ?? '');

        const slidersWrap  = document.createElement('div');
        slidersWrap.className = 'leistungen-sliders-wrap';
        const projectedEl  = document.createElement('p');
        projectedEl.className = 'leistungen-projected card-header-description';
        const sliderValues = {};
        const defaultFrac  = (_cfg.projectionDefault ?? 50) / 100;
        pending.forEach(ts => { sliderValues[ts.id] = Math.round(ts.maxPoints * defaultFrac); });

        function updateProjection() {
            if (!gradingScale) return;
            const extra     = Object.values(sliderValues).reduce((s, v) => s + v, 0);
            const projected = computeGrade(achieved + extra, gradingScale);
            projectedEl.innerHTML = `Mit diesen Leistungen ${isOwn ? 'erreichst du' : `erreicht ${name}`} die Note <span class="grade-badge grade-badge--${projected}">${projected}</span>.`;
        }

        pending.forEach(ts => {
            slidersWrap.appendChild(createTaskSlider(ts, maxPoints, val => {
                sliderValues[ts.id] = val;
                updateProjection();
            }));
        });

        container.appendChild(slidersWrap);
        if (gradingScale) {
            updateProjection();
            container.appendChild(projectedEl);
        }
    }
}

// ---------------------------------------------------------------------------
// Chart helpers
// ---------------------------------------------------------------------------

function defaultThresholds(maxPoints) {
    const result = [];
    for (let i = 0; i < 5; i++) {
        const raw = Math.round(maxPoints * (i + 1) / 6);
        const min = i === 0 ? 1 : result[i - 1] + 1;
        const max = maxPoints - (4 - i);
        result.push(Math.max(min, Math.min(max, raw)));
    }
    return result;
}

// roundThreshold, fracToAbs, computeGrade are shared globals from grading-scale.js

function drawPieChart(achieved, totalMax) {
    const r = 70, cx = 90, cy = 90;
    const circ = 2 * Math.PI * r;
    const frac = totalMax > 0 ? Math.min(1, achieved / totalMax) : 0;

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 180 180');
    svg.setAttribute('width', '180');
    svg.setAttribute('height', '180');
    svg.classList.add('pie-chart');

    const bg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    bg.setAttribute('cx', cx); bg.setAttribute('cy', cy); bg.setAttribute('r', r);
    bg.classList.add('pie-chart-bg');
    svg.appendChild(bg);

    let playArc = null;
    if (frac > 0) {
        const arc = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        arc.setAttribute('cx', cx); arc.setAttribute('cy', cy); arc.setAttribute('r', r);
        arc.setAttribute('stroke-dasharray', circ);
        arc.setAttribute('stroke-dashoffset', circ);
        arc.setAttribute('transform', `rotate(-90 ${cx} ${cy})`);
        arc.classList.add('pie-chart-arc');
        svg.appendChild(arc);
        playArc = () => requestAnimationFrame(() =>
            requestAnimationFrame(() => arc.setAttribute('stroke-dashoffset', circ * (1 - frac)))
        );
    }

    const mainText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    mainText.setAttribute('x', cx); mainText.setAttribute('y', cy - 3);
    mainText.classList.add('pie-chart-value', 'text-lg');
    mainText.textContent = achieved;
    svg.appendChild(mainText);

    const subText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    subText.setAttribute('x', cx); subText.setAttribute('y', cy + 14);
    subText.classList.add('pie-chart-label', 'text-xs');
    subText.textContent = `/ ${totalMax} Pkt.`;
    svg.appendChild(subText);

    return { svg, playArc };
}

function createTaskLine(ts, maxPoints) {
    const wrap = document.createElement('div');
    wrap.className = 'task-line-wrap';

    const header = document.createElement('div');
    header.className = 'task-line-header';
    const label = document.createElement('span');
    label.className = 'task-line-label text-sm';
    label.textContent = ts.name;
    const pts = document.createElement('span');
    pts.className = 'task-line-pts text-sm';
    pts.textContent = ts.achievedPoints !== null ? `${ts.achievedPoints} / ${ts.maxPoints}` : `– / ${ts.maxPoints}`;
    header.appendChild(label);
    header.appendChild(pts);

    const track = document.createElement('div');
    track.className = 'task-line-track';
    track.style.width = (maxPoints > 0 ? (ts.maxPoints / maxPoints) * 100 : 100) + '%';
    const fill = document.createElement('div');
    fill.className = 'task-line-fill';
    fill.style.width = '0%';
    track.appendChild(fill);

    let playFill = null;
    if (ts.achievedPoints !== null) {
        const frac = ts.maxPoints > 0 ? ts.achievedPoints / ts.maxPoints : 0;
        playFill = () => requestAnimationFrame(() =>
            requestAnimationFrame(() => { fill.style.width = (frac * 100) + '%'; })
        );
    }

    wrap.appendChild(header);
    wrap.appendChild(track);
    return { wrap, playFill };
}

function createTaskSlider(ts, maxPoints, onChange) {
    const wrap = document.createElement('div');
    wrap.className = 'task-slider-wrap';

    const header = document.createElement('div');
    header.className = 'task-line-header';
    const label = document.createElement('span');
    label.className = 'task-line-label text-sm';
    label.textContent = ts.name;
    header.appendChild(label);

    const trackRow = document.createElement('div');
    trackRow.className = 'task-slider-track-row';

    const val = document.createElement('span');
    const defaultFrac = (_cfg.projectionDefault ?? 50) / 100;
    val.className = 'task-line-pts text-sm';
    val.textContent = `${Math.round(ts.maxPoints * defaultFrac)} / ${ts.maxPoints}`;

    const track = document.createElement('div');
    track.className = 'task-line-track task-line-track--slider';
    track.style.width = (maxPoints > 0 ? (ts.maxPoints / maxPoints) * 100 : 100) + '%';

    const fill = document.createElement('div');
    fill.className = 'task-line-fill task-line-fill--slider';
    fill.style.width = (defaultFrac * 100) + '%';
    track.appendChild(fill);

    const dot = document.createElement('div');
    dot.className = 'task-line-dot task-line-dot--slider';
    dot.style.left = (defaultFrac * 100) + '%';
    track.appendChild(dot);

    dot.addEventListener('pointerdown', e => {
        e.preventDefault();
        dot.setPointerCapture(e.pointerId);
        function move(ev) {
            const rect  = track.getBoundingClientRect();
            const frac  = Math.max(0, Math.min(1, (ev.clientX - rect.left) / rect.width));
            const score = Math.round(frac * ts.maxPoints);
            dot.style.left   = (frac * 100) + '%';
            fill.style.width = (frac * 100) + '%';
            val.textContent  = `${score} / ${ts.maxPoints}`;
            onChange(score);
        }
        dot.addEventListener('pointermove', move);
        dot.addEventListener('pointerup', () => dot.removeEventListener('pointermove', move), { once: true });
    });

    onChange(Math.round(ts.maxPoints * defaultFrac));

    trackRow.appendChild(track);
    trackRow.appendChild(val);
    wrap.appendChild(header);
    wrap.appendChild(trackRow);
    return wrap;
}

// ---------------------------------------------------------------------------
// Admin: profile editing, subject management, delete
// ---------------------------------------------------------------------------

function populateEditForm(data) {
    document.getElementById('ef-firstName').value = data.firstName;
    document.getElementById('ef-lastName').value  = data.lastName;
    document.getElementById('ef-email').value     = data.email;
    document.getElementById('ef-bn').value        = data.benutzername ?? '';
    const efGrad = document.getElementById('ef-grad');
    if (efGrad && efGrad.options.length === 0) {
        levels.forEach((name, i) => {
            const opt = document.createElement('option');
            opt.value = i; opt.textContent = (i + 1) + ' – ' + name;
            efGrad.appendChild(opt);
        });
    }
    efGrad.value = data.graduationLevel;

    fetchClasses().then(classes => {
        const sel = document.getElementById('ef-class');
        if (!sel) return;
        sel.innerHTML = '';
        classes.forEach(c => {
            const opt = document.createElement('option');
            opt.value       = c.classId || c.id;
            opt.textContent = c.label   || c.name;
            opt.selected    = String(opt.value) === String(data.class?.id ?? data.schoolClass?.id);
            sel.appendChild(opt);
        });
    });
}

function renderSubjectTags(subjects) {
    const container = document.getElementById('subject-tags');
    if (!container) return;
    container.innerHTML = '';
    (subjects || []).forEach(s => {
        const tag = document.createElement('span');
        tag.className = 'profile-badge';
        tag.innerHTML = `${s.name} <button class="btn-unenroll" title="Abmelden" data-id="${s.id}">✕</button>`;
        tag.querySelector('button').addEventListener('click', () => unenrollSubject(s.id, s.name));
        container.appendChild(tag);
    });

    const addBtn = document.createElement('button');
    addBtn.id = 'addSubjectBtn';
    addBtn.className = 'btn-round';
    addBtn.title = 'Fach hinzufügen';
    addBtn.innerHTML = '<img src="/imgs/plus_purple.svg" alt="Hinzufügen">';
    addBtn.addEventListener('click', () => document.getElementById('enroll-modal').style.display = '');
    container.appendChild(addBtn);
}

function renderSubjectDropdown(available) {
    const sel = document.getElementById('subjectSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="" disabled selected>Fach hinzufügen</option>';
    (available || []).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name;
        sel.appendChild(opt);
    });
    const btn = document.getElementById('enrollBtn');
    if (btn) btn.disabled = true;
    sel.addEventListener('change', () => { if (btn) btn.disabled = sel.value === ''; });
}

async function refreshProfile() {
    const idQ  = studentId ? `?id=${studentId}` : '';
    const data = await getJson(studentId ? `/api/student-profile${idQ}` : '/mydata').catch(() => null);
    if (!data) return;
    _profileData = data;

    const nameEl = document.getElementById('profile-first-name');
    if (nameEl) { nameEl.textContent = isOwn ? data.firstName : `${data.firstName} ${data.lastName}`; fitTextToWidth(nameEl); }
    const classLabel = data.class?.label ?? data.schoolClass?.label ?? '–';
    document.getElementById('profile-email').textContent = data.email;
    document.getElementById('profile-class').textContent = `Klasse ${classLabel}`;
    document.getElementById('profile-level').textContent =
        `${levels[data.graduationLevel] ?? data.graduationLevel} (Stufe ${Number(data.graduationLevel) + 1})`;

    if (canEditProfile) {
        populateEditForm(data);
        renderSubjectTags(data.subjects);
        renderSubjectDropdown(data.availableSubjects);
    }
}

function setupAdminHandlers() {
    document.getElementById('edit-student-form')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const errorEl   = document.getElementById('edit-error');
        const successEl = document.getElementById('edit-success');
        errorEl.style.display = successEl.style.display = 'none';

        const resp = await fetch('/update-student', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id:              studentId,
                firstName:       document.getElementById('ef-firstName').value.trim(),
                lastName:        document.getElementById('ef-lastName').value.trim(),
                email:           document.getElementById('ef-email').value.trim(),
                benutzername:    document.getElementById('ef-bn').value.trim(),
                classId:         Number(document.getElementById('ef-class').value),
                graduationLevel: Number(document.getElementById('ef-grad').value),
            }),
        });
        const data = await resp.json();
        if (!resp.ok) { errorEl.textContent = data.error ?? 'Fehler.'; errorEl.style.display = ''; return; }
        document.getElementById('edit-modal').style.display = 'none';
        await refreshProfile();
    });

    document.getElementById('enrollBtn')?.addEventListener('click', async function() {
        const subjectId = parseInt(document.getElementById('subjectSelect').value, 10);
        const errorEl   = document.getElementById('enroll-error');
        if (errorEl) errorEl.style.display = 'none';
        const resp = await fetch('/enroll-subject', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ studentId, subjectId }),
        });
        if (!resp.ok) {
            const data = await resp.json();
            if (errorEl) { errorEl.textContent = data.error ?? 'Fehler.'; errorEl.style.display = ''; }
            return;
        }
        document.getElementById('enroll-modal').style.display = 'none';
        await refreshProfile();
    });

    document.getElementById('enrollModalClose')?.addEventListener('click', () =>
        document.getElementById('enroll-modal').style.display = 'none');
    document.getElementById('enroll-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    document.getElementById('editDataBtn')?.addEventListener('click', () =>
        document.getElementById('edit-modal').style.display = '');
    document.getElementById('editModalClose')?.addEventListener('click', () =>
        document.getElementById('edit-modal').style.display = 'none');
    document.getElementById('edit-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    document.getElementById('deleteBtn')?.addEventListener('click', function() {
        const name = _profileData ? `${_profileData.firstName} ${_profileData.lastName}` : 'dieser Schüler';
        document.getElementById('confirm-modal-text').textContent =
            `Soll ${name} wirklich endgültig entfernt werden? Diese Aktion kann nicht rückgängig gemacht werden.`;
        document.getElementById('confirm-modal').style.display = '';
        document.getElementById('confirm-delete').onclick = async () => {
            await fetch('/delete-student', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: studentId }),
            });
            window.location.href = '/manage_students';
        };
    });

    document.getElementById('confirm-cancel')?.addEventListener('click', () =>
        document.getElementById('confirm-modal').style.display = 'none');
    document.getElementById('confirm-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
    document.getElementById('unenroll-cancel')?.addEventListener('click', () =>
        document.getElementById('unenroll-modal').style.display = 'none');
    document.getElementById('unenroll-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
}

function unenrollSubject(subjectId, name) {
    document.getElementById('unenroll-modal-text').textContent =
        `Soll ${name} wirklich aus dem Profil entfernt werden?`;
    document.getElementById('unenroll-modal').style.display = '';
    document.getElementById('unenroll-confirm').onclick = async () => {
        document.getElementById('unenroll-modal').style.display = 'none';
        const resp = await fetch('/unenroll-subject', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ studentId, subjectId }),
        });
        if (resp.ok) await refreshProfile();
    };
}
