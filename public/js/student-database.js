history.scrollRestoration = 'manual';
window.scrollTo(0, 0);

document.addEventListener('click', function(e) {
    if (e.target.closest('#logoutBtn')) {
        fetch('/logout', { method: 'POST' }).then(() => { window.location.href = '/login'; });
    }
});

async function fetchJson(url, options) {
    const res = await fetch(url, options);
    if (res.ok){
        return await res.json();
    }
}

function yearParam() {
    return new URLSearchParams(window.location.search).get('year') || '';
}

function withYearParam(url) {
    const y = yearParam();
    if (!y) return url;
    return url + (url.includes('?') ? '&' : '?') + 'year=' + encodeURIComponent(y);
}

async function getJson(url) {
    return await fetchJson(withYearParam(url));
}
async function getJsonWithPost(url, data) {
    return await fetchJson(withYearParam(url), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
}

async function buildYearPicker(container) {
    if (!container) return;
    const data = await fetchJson('/api/school-years').catch(() => null);
    if (!data || !data.current) return;

    const allYears = [...new Set([data.current, ...data.years])].sort().reverse();
    if (allYears.length <= 1) return;

    const selectedYear = yearParam() || data.current;

    const section = document.createElement('div');
    section.className = 'profile-room-section';

    const label = document.createElement('span');
    label.className = 'text-xs text-bold profile-room-label';
    label.textContent = 'Schuljahr';

    const select = document.createElement('select');
    select.id = 'yearSelect';
    allYears.forEach(y => {
        const opt = document.createElement('option');
        opt.value = y;
        opt.textContent = y === data.current ? `${y} (aktuell)` : y;
        opt.selected = y === selectedYear;
        select.appendChild(opt);
    });
    select.addEventListener('change', () => {
        const params = new URLSearchParams(window.location.search);
        select.value === data.current ? params.delete('year') : params.set('year', select.value);
        const q = params.toString();
        window.location.href = window.location.pathname + (q ? '?' + q : '');
    });

    section.appendChild(label);
    section.appendChild(select);
    container.appendChild(section);
}
async function post(url, data) {
    return await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
}
function openUrlWithPostParams(url, params) {
    const form = document.createElement("form");
    form.setAttribute("method", "post");
    form.setAttribute("action", url);

    Object.keys(params).forEach((key) => {
        const input = document.createElement("input");
        input.setAttribute("type", "hidden")
        input.setAttribute("name", key)
        input.setAttribute("value", params[key])
        form.appendChild(input)
    })

    const submitButton = document.createElement("button")
    submitButton.setAttribute("type", "submit")
    
    form.appendChild(submitButton)
    document.getElementsByTagName("body")[0].appendChild(form)

    submitButton.click()
}
async function fetchClasses() {
    const classes = await fetchJson('/classes');
    return classes;
}
async function fetchMyClasses() {
    const classes = await fetchJson('/myclasses');
    return classes;
}
async function fetchRooms() {
    const rooms = await fetchJson('/rooms');
    return rooms;
}
async function fetchTeacherClasses(teacherId) {
    const classes = await getJsonWithPost('/teacher-classes', { teacherId });
    return classes;
}
async function fetchSubjects(teacherId) {
    const subjects = await getJsonWithPost('/teacher-subjects', { teacherId });
    return subjects;
}
async function fetchAllSubjects() {
    const subjects = await fetchJson('/subjects');
    return subjects;
}
async function fetchModuleSettings(moduleKey) {
    return (await getJsonWithPost('/get-module', { key: moduleKey })).settings;
}

async function changeGraduationLevel(studentId, newLevel) {
    return await post('/change-graduation-level', { studentId: studentId, graduationLevel: newLevel });
}
async function updateRoom(studentId, room) {
    return await post('/update-room', { studentId, room });
}

async function deleteClass(classId) {
    return await post('/delete-class', { id: classId });
}
async function deleteSubject(subjectId) {
    return await post('/delete-subject', { id: subjectId });
}
// Populating functions
function populateClassSelect(classSelect, classes) {
    classSelect.innerHTML = ""; // clear previous options if any
    classes.forEach(cls => {
        const option = document.createElement('option');
        option.value = cls.classId || cls.id;
        option.textContent = cls.name || cls.label;
        classSelect.appendChild(option);
    });
}
async function populateSubjectSelect(subjectSelectId, subjects) {
    const subjectSelect = document.getElementById(subjectSelectId);
    subjectSelect.innerHTML = ''; // Clear existing options
    subjects.forEach(function(subject) {
        const option = document.createElement('option');
        option.value = subject.id;
        option.textContent = subject.name;
        subjectSelect.appendChild(option);
    });
}
async function populateGradeSelect(gradeSelectId, subjectId) {
    const gradeSelect = document.getElementById(gradeSelectId);
    gradeSelect.innerHTML = '';
    const grades = await getJsonWithPost('/grade-list', { subjectId });
    grades.forEach(grade => {
        const option = document.createElement('option');
        option.text = grade;
        option.value = grade;
        gradeSelect.appendChild(option)
    })
}
async function populateRoomSelect(roomSelectId) {
    const roomSelect = document.getElementById(roomSelectId);
    roomSelect.innerHTML = ""; // clear previous options if any
    const rooms = await fetchRooms();
    rooms.forEach(room => {
        const option = document.createElement('option');
        option.value = room.label;
        option.textContent = room.studentCount !== undefined ? `${room.label} (${room.studentCount})` : room.label;
        roomSelect.appendChild(option);
    });
}
async function populateRoomSelectWithLevel(roomSelectId, graduationLevel) {
    const roomSelect = document.getElementById(roomSelectId);
    roomSelect.innerHTML = ""; // clear previous options if any
    const rooms = await fetchRooms();
    rooms.forEach(room => {
        if (Number(room.minimumLevel) <= Number(graduationLevel)){
            const option = document.createElement('option');
            option.value = room.label;
            option.textContent = room.studentCount !== undefined ? `${room.label} (${room.studentCount})` : room.label;
            roomSelect.appendChild(option);
        }
    });
}
async function postDataAndDownload(url, data, filename) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: data
    });

    if (response.ok) {
        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition');
        if (disposition && disposition.includes('filename=')) {
            filename = disposition.split('filename=')[1].split(';')[0].trim();
        }
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        window.location.reload()
    } else if (response.status === 401) {
        alert("Nicht autorisiert! Bitte melden Sie sich an.");
        window.location.href = "/login";
    } else {
        alert("Fehler beim Hochladen der Datei!");
    }
}
// ---------------------------------------------------------------------------
// Lerngruppen task-set bars (student results view)
// ---------------------------------------------------------------------------

/**
 * Appends Lerngruppe Aufgabenset bars to #charts, grouped by subject.
 * Each taskset is one clickable segment: {id, name, maxPoints, subjectId, subjectName, status}
 */
function loadLgTaskBars(lgTaskSets) {
    const charts = document.getElementById('charts');
    if (!charts) return;

    // Group by subject
    const bySubject = {};
    lgTaskSets.forEach(ts => {
        if (!bySubject[ts.subjectId]) {
            bySubject[ts.subjectId] = { name: ts.subjectName, sets: [] };
        }
        bySubject[ts.subjectId].sets.push(ts);
    });

    Object.values(bySubject).forEach(({ name, sets }) => {
        const section = document.createElement('div');
        section.className = 'lg-tasksets-section';

        const heading = document.createElement('h3');
        heading.textContent = name + ' – Aufgabensets';
        section.appendChild(heading);

        const hint = document.createElement('p');
        hint.className = 'lg-interaction-hint';
        hint.textContent = 'Klicke auf ein Aufgabenset, um den Status zu ändern: offen → In Bearbeitung → Abgegeben';
        section.appendChild(hint);

        // One bar showing all sets as proportional segments
        section.appendChild(createLgBar(sets));
        charts.appendChild(section);
    });
}

/**
 * Creates a single horizontal bar showing all Aufgabensets for one subject
 * as proportional segments.  Clicking a segment cycles its status.
 */
function createLgBar(sets, readonly = false, onPillClick = null) {
    const totalPoints = sets.reduce((s, ts) => s + ts.maxPoints, 0);

    const bar = document.createElement('div');
    bar.className = 'lg-bar-container';

    sets.forEach(ts => {
        const seg = document.createElement('div');
        seg.className = 'lg-bar-task';
        seg.style.width = (100 * ts.maxPoints / totalPoints) + '%';
        updateLgSegmentClass(seg, ts.status);
        if (ts.achievedPoints !== null && ts.achievedPoints !== undefined) seg.classList.add('lg-graded');

        const lbl = document.createElement('span');
        lbl.className = 'lg-bar-task-label';
        lbl.textContent = ts.name;
        seg.appendChild(lbl);

        seg.addEventListener('click', e => {
            e.stopPropagation();
            if (onPillClick) { onPillClick(seg, ts); return; }
            const existing = document.getElementById('lg-status-dropdown');
            if (existing && existing._anchor === seg) {
                closeLgStatusDropdown();
            } else {
                openLgStatusDropdown(seg, ts, readonly);
            }
        });

        bar.appendChild(seg);
    });

    return bar;
}

function openLgStatusDropdown(anchor, ts, readonly = false) {
    closeLgStatusDropdown();
    anchor.classList.add('lg-bar-task--open');
    anchor.closest('.lg-bar-container')?.classList.add('lg-bar-container--has-open');
    document.body.classList.add('lg-bars-active');

    const dropdown = document.createElement('div');
    dropdown.id = 'lg-status-dropdown';
    dropdown.className = 'dropdown lg-status-dropdown';

    const title = document.createElement('div');
    title.className = 'lg-status-dropdown-title text-sm text-bold';
    title.textContent = ts.name;

    const isGraded = ts.achievedPoints !== null && ts.achievedPoints !== undefined;
    if (!isGraded) {
        const points = document.createElement('div');
        points.className = 'lg-status-dropdown-points text-sm text-light';
        // Pass/fail task sets can only score 0 or maxPoints, so "max." is misleading.
        points.textContent = ts.isPassFail
            ? `${ts.maxPoints} Punkt${ts.maxPoints !== 1 ? 'e' : ''}`
            : `max. ${ts.maxPoints} Punkt${ts.maxPoints !== 1 ? 'e' : ''}`;
        title.appendChild(points);
    }

    dropdown.appendChild(title);

    const options = [
        { label: 'Beginnen',       status: 1 },
        { label: 'Brauche Hilfe',  status: 3 },
        { label: 'Suche Partner',  status: 4 },
        { label: 'Fertig',         status: 2 },
    ];

    const isLocked = ts.status === 2;

    if (readonly) {
        const statusLabels = { 0: 'Nicht begonnen', 1: 'In Bearbeitung', 2: 'Abgegeben', 3: 'Braucht Hilfe', 4: 'Sucht Partner' };
        const note = document.createElement('p');
        note.className = 'lg-status-locked-note';
        note.textContent = isGraded
            ? `Bewertet mit ${ts.achievedPoints}/${ts.maxPoints} Punkten`
            : `Status: ${statusLabels[ts.status] ?? ts.status}`;
        dropdown.appendChild(note);
    } else if (isLocked) {
        const lockedNote = document.createElement('p');
        lockedNote.className = 'lg-status-locked-note';
        lockedNote.textContent = isGraded
            ? `Bewertet mit ${ts.achievedPoints} / ${ts.maxPoints} Punkten`
            : 'Du hast diese Aufgabe abgegeben.';
        dropdown.appendChild(lockedNote);
    }

    if (!readonly && !isLocked) options.forEach(({ label, status }) => {
        const btn = document.createElement('button');
        btn.className = 'dropdown-option lg-status-option lg-status-option--s' + status + (ts.status === status ? ' dropdown-option--active lg-status-option--active' : '');
        btn.textContent = label;

        if (isLocked) {
            btn.disabled = true;
            btn.classList.add('lg-status-option--disabled');
        } else {
            btn.addEventListener('click', async e => {
                e.stopPropagation();
                const next = ts.status === status ? 0 : status;

                const doUpdate = async () => {
                    const resp = await post('/lg-taskset-status', { tasksetId: ts.id, status: next });
                    if (resp.ok) {
                        ts.status = next;
                        updateLgSegmentClass(anchor, ts.status);
                        dropdown.querySelectorAll('.lg-status-option').forEach((b, i) => {
                            const isActive = options[i].status === next;
                            b.classList.toggle('lg-status-option--active', isActive);
                            b.classList.toggle('dropdown-option--active', isActive);
                        });
                        if (next === 2) {
                            closeLgStatusDropdown();
                        }
                    }
                };

                if (status === 2 && next === 2) {
                    closeLgStatusDropdown();
                    showConfirmDialog(
                        'Sicher, dass du die Aufgabe abgeben möchtest? Du kannst dann keine Änderungen mehr vornehmen.',
                        'Abgeben',
                        doUpdate
                    );
                } else {
                    await doUpdate();
                }
            });
        }
        dropdown.appendChild(btn);
    });

    // Position relative to the page so backdrop-filter works outside card stacking context
    dropdown._anchor = anchor;
    const container = anchor.closest('.lg-bar-container');
    dropdown.style.position = 'fixed';
    document.body.appendChild(dropdown);

    // After paint, compute position from anchor's viewport rect, then flip if clipped
    requestAnimationFrame(() => {
        const ar = anchor.getBoundingClientRect();
        const cx = ar.left + ar.width / 2;
        dropdown.style.left = cx + 'px';
        dropdown.style.top  = (ar.bottom + 14) + 'px';

        const dr = dropdown.getBoundingClientRect();
        if (dr.bottom > window.innerHeight - 8) {
            dropdown.style.top = (ar.top - dropdown.offsetHeight - 14) + 'px';
        }
        const topVal = parseFloat(dropdown.style.top);
        if (topVal < 8) dropdown.style.top = '8px';
        const clampedLeft = Math.max(8, Math.min(cx, window.innerWidth - dropdown.offsetWidth - 8));
        if (clampedLeft !== cx) dropdown.style.left = clampedLeft + 'px';
        requestAnimationFrame(() => dropdown.classList.add('dropdown--visible'));
    });

    setTimeout(() => {
        document.addEventListener('click', closeLgStatusDropdown, { once: true });
        window.addEventListener('scroll', closeLgStatusDropdown, { once: true, capture: true });
    }, 0);
}

function closeLgStatusDropdown() {
    const dropdown = document.getElementById('lg-status-dropdown');
    if (dropdown) {
        const anchor = dropdown._anchor;
        anchor?.classList.remove('lg-bar-task--open');
        anchor?.closest('.lg-bar-container')?.classList.remove('lg-bar-container--has-open');
        document.body.classList.remove('lg-bars-active');
        dropdown.remove();
    }
}

// Position a dropdown element relative to its trigger button.
// Scrolls the page first if the trigger is too close to the bottom of the viewport.
function positionDropdown(el, trigger) {
    const MARGIN   = 32;  // gap between dropdown bottom and viewport edge
    const IDEAL    = 260; // try to ensure at least this much usable space below

    const r0 = trigger.getBoundingClientRect();
    const shortfall = (IDEAL + MARGIN) - (window.innerHeight - r0.bottom);
    if (shortfall > 0) window.scrollBy(0, shortfall);

    const r          = trigger.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom - MARGIN;
    const spaceAbove = r.top - MARGIN;
    const flipUp     = spaceBelow < 100 && spaceAbove > spaceBelow;
    const maxH       = Math.min(320, flipUp ? spaceAbove : spaceBelow);

    el.style.position  = 'fixed';
    el.style.left      = r.left + 'px';
    el.style.minWidth  = r.width + 'px';
    el.style.maxHeight = maxH + 'px';
    el.style.overflowY = 'auto';
    if (flipUp) {
        el.style.bottom = (window.innerHeight - r.top + 4) + 'px';
        el.style.top    = '';
    } else {
        el.style.top    = (r.bottom + 6) + 'px';
        el.style.bottom = '';
    }
}

function showConfirmDialog(message, confirmLabel, onConfirm, onCancel) {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';

    const box = document.createElement('div');
    box.className = 'confirm-box';

    const msg = document.createElement('p');
    msg.className = 'confirm-box-text';
    msg.textContent = message;

    const btnRow = document.createElement('div');
    btnRow.className = 'confirm-actions';

    const dismiss = () => { overlay.remove(); onCancel?.(); };

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-cancel';
    cancelBtn.textContent = 'Abbrechen';
    cancelBtn.addEventListener('click', dismiss);

    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn btn-danger';
    confirmBtn.textContent = confirmLabel;
    confirmBtn.addEventListener('click', () => { overlay.remove(); onConfirm(); });

    btnRow.appendChild(cancelBtn);
    btnRow.appendChild(confirmBtn);
    box.appendChild(msg);
    box.appendChild(btnRow);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    overlay.addEventListener('click', e => { if (e.target === overlay) dismiss(); });
}

function updateLgSegmentClass(seg, status) {
    seg.classList.remove('lg-none', 'lg-in-progress', 'lg-submitted', 'lg-needs-help', 'lg-partner');
    if      (status === 1) seg.classList.add('lg-in-progress');
    else if (status === 2) seg.classList.add('lg-submitted');
    else if (status === 3) seg.classList.add('lg-needs-help');
    else if (status === 4) seg.classList.add('lg-partner');
    else                   seg.classList.add('lg-none');
}

// Shared pagination renderer — call with: renderPagination(barId, totalPages, total, page, onPageChange, label)
// onPageChange receives the new page number; barId is the element ID of the .pagination-bar div
function renderPagination(barId, totalPages, total, page, onPageChange, label) {
    const bar = document.getElementById(barId); bar.innerHTML = '';
    if (totalPages <= 1) return;
    const info = document.createElement('span'); info.className = 'pagination-info';
    info.textContent = `${total} ${label} – Seite ${page} von ${totalPages}`; bar.appendChild(info);
    const btns = document.createElement('div'); btns.className = 'pagination-btns'; bar.appendChild(btns);
    const prev = document.createElement('button'); prev.className = 'pagination-btn'; prev.disabled = page === 1;
    prev.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>';
    prev.addEventListener('click', () => onPageChange(page - 1)); btns.appendChild(prev);
    for (let p = 1; p <= totalPages; p++) {
        if (totalPages > 7 && Math.abs(p - page) > 2 && p !== 1 && p !== totalPages) {
            if (p === 2 || p === totalPages - 1) { const d = document.createElement('span'); d.className = 'pagination-dots'; d.textContent = '…'; btns.appendChild(d); } continue;
        }
        const btn = document.createElement('button'); btn.className = 'pagination-btn' + (p === page ? ' active' : '');
        btn.textContent = p; btn.addEventListener('click', () => onPageChange(p)); btns.appendChild(btn);
    }
    const next = document.createElement('button'); next.className = 'pagination-btn'; next.disabled = page === totalPages;
    next.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';
    next.addEventListener('click', () => onPageChange(page + 1)); btns.appendChild(next);
}

// Shared inline edit-row toggle — pass the row element and the data-edit-for key value
function toggleEditRow(tr, key) {
    const editTr = document.querySelector(`tr[data-edit-for="${key}"]`);
    const isOpen = editTr.style.display !== 'none';
    document.querySelectorAll('.edit-row').forEach(r => r.style.display = 'none');
    document.querySelectorAll('tr[data-id]').forEach(r => r.classList.remove('editing'));
    if (!isOpen) { editTr.style.display = ''; tr.classList.add('editing'); }
}

// Collapsible add-panels and modal wiring — both auto-initialised on DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table.gradient-table').forEach(t => {
        const w = document.createElement('div');
        w.className = 'table-scroll';
        t.parentNode.insertBefore(w, t);
        w.appendChild(t);
    });
    // Modal: backdrop click closes; add data-close-modal to cancel/close buttons
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });
    });
    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-overlay');
            if (modal) modal.style.display = 'none';
        });
    });

    // Collapsible add-panels: add data-collapsible="<wrapper-id>" to the toggle button
    const EASING = 'cubic-bezier(0.4, 0, 0.2, 1)';
    const DURATION = '0.55s';
    document.querySelectorAll('[data-collapsible]').forEach(btn => {
        const wrapper = document.getElementById(btn.dataset.collapsible);
        if (!wrapper) return;
        let open = false;
        btn.addEventListener('click', () => {
            wrapper.style.transition = `height ${DURATION} ${EASING}`;
            if (open) {
                wrapper.style.height = wrapper.scrollHeight + 'px';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    wrapper.style.height = '0';
                }));
                wrapper.addEventListener('transitionend', () => {
                    wrapper.style.transition = '';
                    wrapper.classList.remove('open');
                }, { once: true });
                btn.classList.remove('active');
            } else {
                wrapper.classList.add('open');
                wrapper.style.height = '0';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    wrapper.style.height = wrapper.scrollHeight + 'px';
                }));
                wrapper.addEventListener('transitionend', () => {
                    wrapper.style.height = 'auto';
                    wrapper.style.transition = '';
                }, { once: true });
                btn.classList.add('active');
            }
            open = !open;
        });
    });
});

// Auto-inject current user badge into any page that has #page-topbar or #admin-topbar
document.addEventListener('DOMContentLoaded', async () => {
    const topbar = document.getElementById('page-topbar') || document.getElementById('admin-topbar');
    if (!topbar) return;
    try {
        const res = await fetch('/me');
        if (!res.ok) return;
        const { role, username } = await res.json();
        const roleLabels = { admin: 'Admin', teacher: 'Lehrer', student: 'Schüler' };
        const badge = document.createElement('span');
        badge.style.cssText = 'font-size:0.85rem;font-weight:600;color:#718096;white-space:nowrap;align-self:center;padding:0 4px';
        badge.textContent = `Eingeloggt als ${username} (${roleLabels[role] ?? role})`;
        topbar.insertBefore(badge, topbar.firstChild);
    } catch (_) {}
});

// Shrink font-size of el so the longest space-separated word fits within the container width
function fitTextToWidth(el) {
    if (!el) return;
    const container = el.parentElement;
    if (!container) return;
    const maxWidth = container.clientWidth;
    if (!maxWidth) return;
    // Use a hidden probe span to measure each word individually
    const probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;';
    document.body.appendChild(probe);
    let size = parseFloat(getComputedStyle(el).fontSize);
    const words = el.textContent.trim().split(/\s+/);
    const longestWord = words.reduce((a, b) => a.length >= b.length ? a : b, '');
    probe.textContent = longestWord;
    probe.style.fontSize = size + 'px';
    probe.style.fontFamily = getComputedStyle(el).fontFamily;
    probe.style.fontWeight = getComputedStyle(el).fontWeight;
    while (probe.scrollWidth > maxWidth && size > 8) {
        size -= 0.5;
        probe.style.fontSize = size + 'px';
    }
    document.body.removeChild(probe);
    el.style.fontSize = size + 'px';
    el.style.display = 'block';
}

// Auto-add tooltip to any table cell whose content overflows its visible width
document.addEventListener('mouseover', e => {
    const td = e.target.closest('td, th');
    if (td && !td.title && td.scrollWidth > td.clientWidth) {
        td.title = td.textContent.trim();
    }
});


document.addEventListener('DOMContentLoaded', () => {
    const cat = window.graduationConfig?.categoryName ?? 'Abschlussstufe';
    document.querySelectorAll('[data-graduation-label]').forEach(el => { el.textContent = cat; });
});