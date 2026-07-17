// teacher-view.js — shared view for teacher dashboard and teacher profile (admin)
// Expects: window.teacherViewConfig = { teacherId: null|number, viewerRole: 'teacher'|'admin' }

const _cfg        = window.teacherViewConfig ?? { teacherId: null, viewerRole: 'teacher' };
const viewerRole  = _cfg.viewerRole;
const teacherId   = _cfg.teacherId ?? null;
const adminOnly   = _cfg.adminOnlyTasksets ?? false;

const isOwn          = teacherId === null;
const canEditProfile = viewerRole === 'admin';

// When admin acts on behalf of a teacher, attach teacherId so the server uses the right ID.
function withTeacher(body) {
    if (!isOwn && teacherId !== null) return { ...body, teacherId };
    return body;
}

let _profileData = null;

document.addEventListener('DOMContentLoaded', async () => {
    if (isOwn) {
        const [profile, lerngruppen, notifications, students] = await Promise.all([
            getJson('/api/my-teacher-data').catch(() => ({})),
            getJson('/my-lerngruppen').catch(() => []),
            getJson('/my-lerngruppen-notifications').catch(() => []),
            getJson('/my-lerngruppen-students').catch(() => []),
        ]);
        _profileData = profile;
        renderSidebar(profile, lerngruppen);
        buildYearPicker(document.getElementById('year-picker-container'));
        buildBenachrichtigungenSection(notifications);
        buildLerngruppenSection(lerngruppen);
        initMyStudentsTable(students);

        setInterval(async () => {
            const fresh = await getJson('/my-lerngruppen-notifications').catch(() => null);
            if (fresh) buildBenachrichtigungenSection(fresh, true);
        }, 30_000);
    } else {
        const [profile, lerngruppen, students] = await Promise.all([
            getJson(`/api/teacher-profile?id=${teacherId}`).catch(() => null),
            getJson(`/api/teacher-lerngruppen?id=${teacherId}`).catch(() => []),
            getJson(`/api/teacher-students?id=${teacherId}`).catch(() => []),
        ]);

        if (!profile) {
            const main = document.querySelector('main');
            if (main) main.innerHTML = '<p style="color:rgba(255,255,255,0.7);padding:40px">Lehrer nicht gefunden.</p>';
            return;
        }
        _profileData = profile;
        renderSidebar(profile, lerngruppen);
        buildLerngruppenSection(lerngruppen, lg => confirmRemoveLg(lg));
        renderAddLerngruppeForm(profile);
        initMyStudentsTable(students);
        document.getElementById('admin-sidebar')?.style.removeProperty('display');
        populateEditForm(profile);
        setupAdminHandlers();
    }
});

// ---------------------------------------------------------------------------
// Sidebar
// ---------------------------------------------------------------------------

function renderSidebar(data, lerngruppen) {
    const greetingEl = document.getElementById('profile-greeting');
    const nameEl     = document.getElementById('profile-first-name');
    if (greetingEl) greetingEl.style.display = isOwn ? '' : 'none';
    if (nameEl) { nameEl.textContent = isOwn ? (data.firstName ?? '–') : `${data.firstName} ${data.lastName}`; fitTextToWidth(nameEl); }

    const emailEl = document.getElementById('profile-email');
    if (emailEl) emailEl.textContent = data.email ?? '–';

    if (isOwn) buildLerngruppenBadges(lerngruppen);

    initRoomDropdown(data);
}

function initRoomDropdown(data) {
    const nativeSelect = document.getElementById('roomSelect');
    if (!nativeSelect) return;

    const rawRooms = data.availableRooms ?? [];
    const rooms    = rawRooms.map(r => typeof r === 'string'
        ? { label: r, studentCount: null }
        : { label: r.label, studentCount: r.studentCount ?? null }
    );
    const options  = [{ value: '', label: 'kein Raum', studentCount: null }, ...rooms.map(r => ({ value: r.label, label: r.label, studentCount: r.studentCount }))];
    let currentValue = data.room ?? '';

    const trigger = document.createElement('button');
    trigger.type      = 'button';
    trigger.className = 'dropdown-trigger';
    trigger.textContent = options.find(o => o.value === currentValue)?.label ?? 'kein Raum';
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
            btn.type      = 'button';
            btn.className = 'dropdown-option dropdown-option--spread' + (o.value === currentValue ? ' dropdown-option--active' : '');
            if (o.studentCount !== null) {
                btn.innerHTML = `<span>${o.label}</span><span class="dropdown-room-count"><img src="/imgs/person_grey.svg" alt="" width="14" height="14"> ${o.studentCount}</span>`;
            } else {
                btn.textContent = o.label;
            }
            btn.addEventListener('click', e => {
                e.stopPropagation();
                currentValue = o.value;
                trigger.textContent = o.label;
                roomDropdown?.querySelectorAll('.dropdown-option').forEach(b =>
                    b.classList.toggle('dropdown-option--active', b === btn)
                );
                if (isOwn) {
                    post('/update-my-room', { room: o.value || null });
                } else {
                    post('/update-teacher-room', { teacherId, room: o.value || null });
                }
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
}

function buildLerngruppenBadges(lerngruppen) {
    const container = document.getElementById('profile-lerngruppen-badges');
    if (!container || lerngruppen.length === 0) return;
    lerngruppen.forEach(lg => {
        const badge = document.createElement('span');
        badge.className   = 'profile-badge';
        badge.textContent = `${lg.classLabel} – ${lg.subjectName}`;
        container.appendChild(badge);
    });
}

// ---------------------------------------------------------------------------
// Benachrichtigungen (own view only)
// ---------------------------------------------------------------------------

const renderedItemKeys = new Set();
const panelControllers = new Map();

function buildBenachrichtigungenSection(lerngruppen, animate = false) {
    const listEl = document.getElementById('notifications-list');
    const hint   = document.getElementById('no-notifications-hint');
    if (!listEl) return;

    const withNotifications = lerngruppen.filter(
        lg => lg.submissions.length > 0 || (lg.helpRequests?.length ?? 0) > 0
    );

    withNotifications.forEach(lg => {
        const lgKey = `${lg.classId}-${lg.subjectId}`;
        if (!panelControllers.has(lgKey)) {
            const ctrl = createBenachrichtigungPanel(lg);
            panelControllers.set(lgKey, ctrl);
            listEl.appendChild(ctrl.panel);
            if (animate) {
                ctrl.panel.style.opacity   = '0';
                ctrl.panel.style.transform = 'translateY(-6px)';
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    ctrl.panel.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    ctrl.panel.style.opacity    = '';
                    ctrl.panel.style.transform  = '';
                }));
            }
        }
        const ctrl = panelControllers.get(lgKey);
        lg.submissions.forEach(s => {
            const key = `sub-${s.studentId}-${s.taskSetId}`;
            if (!renderedItemKeys.has(key)) { renderedItemKeys.add(key); ctrl.addSubmission(s, animate); }
        });
        (lg.helpRequests ?? []).forEach(h => {
            const key = `help-${h.studentId}-${h.taskSetId}`;
            if (!renderedItemKeys.has(key)) { renderedItemKeys.add(key); ctrl.addHelpRequest(h, animate); }
        });
    });

    if (hint) hint.style.display = listEl.hasChildNodes() ? 'none' : '';
}

function createBenachrichtigungPanel(lg) {
    let count = 0;
    const panel = document.createElement('div');
    panel.className = 'lg-panel';

    const toggle = document.createElement('button');
    toggle.className  = 'lg-panel-toggle';
    const badge       = document.createElement('span');
    badge.className   = 'bn-badge';
    badge.textContent = count;
    toggle.innerHTML  =
        `<span>${lg.classLabel}<span class="lg-sep"> – </span>${lg.subjectName}</span>`;
    const chevronSpan = document.createElement('span');
    chevronSpan.className = 'lg-chevron-btn';
    chevronSpan.innerHTML = `<img src="/imgs/chevron.svg" class="lg-chevron" alt="">`;
    const rightSlot       = document.createElement('span');
    rightSlot.style.cssText = 'display:flex;align-items:center;gap:8px';
    rightSlot.appendChild(badge);
    rightSlot.appendChild(chevronSpan);
    toggle.appendChild(rightSlot);
    panel.appendChild(toggle);

    const body = document.createElement('div');
    body.className    = 'lg-panel-body';
    body.style.cssText = 'height:0;overflow:hidden';
    const spacer      = document.createElement('div');
    spacer.style.height = '16px';
    body.appendChild(spacer);
    panel.appendChild(body);

    function incrementBadge() { count++; badge.textContent = count; }
    function decrementBadge() {
        count--; badge.textContent = count;
        if (count === 0) {
            panel.style.overflow   = 'hidden';
            panel.style.transition = 'opacity 0.5s ease, transform 0.5s ease, max-height 0.6s cubic-bezier(0.4,0,0.2,1), margin-bottom 0.6s ease';
            panel.style.maxHeight  = panel.offsetHeight + 'px';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                panel.style.opacity     = '0';
                panel.style.transform   = 'scale(0.98)';
                panel.style.maxHeight   = '0';
                panel.style.marginBottom = '0';
            }));
            panel.addEventListener('transitionend', () => panel.remove(), { once: true });
        }
    }

    const sectionLists = {};
    function getOrCreateSectionList(key, title, description) {
        if (!sectionLists[key]) {
            const header = makePanelSection(title, description);
            const list   = document.createElement('div');
            list.className = 'bn-list';
            body.insertBefore(list, spacer);
            body.insertBefore(header, list);
            sectionLists[key] = list;
        }
        return sectionLists[key];
    }

    function animateItemIn(item) {
        item.style.opacity   = '0';
        item.style.transform = 'translateY(-4px)';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            item.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            item.style.opacity    = '';
            item.style.transform  = '';
        }));
    }

    function addSubmission(s, animate = false) {
        const list = getOrCreateSectionList('submissions', 'Eingereichte Aufgaben',
            'Sie können Aufgaben bewerten oder zur Weiterbearbeitung zurücklegen.');
        const item = document.createElement('div');
        item.className = 'bn-item bn-item-submitted';

        const info     = document.createElement('div');
        info.className = 'bn-item-info';
        const roomText = s.currentRoom ? ` · ${s.currentRoom}` : '';
        info.innerHTML =
            `<span class="bn-item-name">${s.name}</span>` +
            `<span class="bn-item-sub">${s.taskSetName}${roomText}</span>`;

        const actions     = document.createElement('div');
        actions.className = 'bn-actions';
        function removeItem() { item.remove(); decrementBadge(); }

        const bewertenBtn     = document.createElement('button');
        bewertenBtn.className = 'btn-s bn-action-btn';
        bewertenBtn.textContent = 'Bewerten';
        bewertenBtn.addEventListener('click', () => {
            actions.innerHTML = '';
            if (s.isPassFail) {
                const bestandenBtn = document.createElement('button');
                bestandenBtn.className = 'btn-s btn-green';
                bestandenBtn.textContent = 'Bestanden';
                bestandenBtn.addEventListener('click', async () => {
                    const resp = await post('/lg-set-score', { studentId: s.studentId, taskSetId: s.taskSetId, achievedPoints: s.maxPoints });
                    if (resp.ok) removeItem();
                });
                const nichtBtn = document.createElement('button');
                nichtBtn.className = 'btn-s btn-red';
                nichtBtn.textContent = 'Nicht bestanden';
                nichtBtn.addEventListener('click', async () => {
                    const resp = await post('/lg-set-score', { studentId: s.studentId, taskSetId: s.taskSetId, achievedPoints: 0 });
                    if (resp.ok) removeItem();
                });
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'btn-s btn-cancel';
                cancelBtn.textContent = 'Abbrechen';
                cancelBtn.addEventListener('click', () => { actions.innerHTML = ''; actions.appendChild(bewertenBtn); actions.appendChild(returnBtn); });
                actions.appendChild(bestandenBtn); actions.appendChild(nichtBtn); actions.appendChild(cancelBtn);
            } else {
                const input       = document.createElement('input');
                input.type = 'number'; input.min = '0'; input.max = s.maxPoints;
                input.placeholder = `0–${s.maxPoints} Pkt.`;
                input.className   = 'input-box';
                const saveBtn     = document.createElement('button');
                saveBtn.className = 'btn-round btn-tick';
                saveBtn.innerHTML = '<img src="/imgs/tick.svg" alt="✓">';
                saveBtn.addEventListener('click', async () => {
                    const pts = parseInt(input.value);
                    if (isNaN(pts) || pts < 0 || pts > s.maxPoints) return;
                    const resp = await post('/lg-set-score', { studentId: s.studentId, taskSetId: s.taskSetId, achievedPoints: pts });
                    if (resp.ok) removeItem();
                });
                const cancelBtn     = document.createElement('button');
                cancelBtn.className = 'btn-round btn-x';
                cancelBtn.innerHTML = '<img src="/imgs/x.svg" alt="✕">';
                cancelBtn.addEventListener('click', () => { actions.innerHTML = ''; actions.appendChild(bewertenBtn); actions.appendChild(returnBtn); });
                actions.appendChild(input); actions.appendChild(saveBtn); actions.appendChild(cancelBtn);
                input.focus();
            }
        });

        const returnBtn     = document.createElement('button');
        returnBtn.className = 'btn-s bn-action-btn bn-action-btn--return';
        returnBtn.textContent = 'Zurücklegen';
        returnBtn.addEventListener('click', async () => {
            const resp = await post('/lg-return-task', { studentId: s.studentId, taskSetId: s.taskSetId });
            if (resp.ok) removeItem();
        });

        actions.appendChild(bewertenBtn);
        actions.appendChild(returnBtn);
        item.appendChild(info);
        item.appendChild(actions);
        list.appendChild(item);
        if (animate) animateItemIn(item);
        incrementBadge();
    }

    function addHelpRequest(h, animate = false) {
        const list = getOrCreateSectionList('helpRequests', 'Brauche Hilfe',
            'Schüler, die bei einem Aufgabenset Hilfe benötigen.');
        const item  = document.createElement('div');
        item.className = 'bn-item bn-item-help';
        const info     = document.createElement('div');
        info.className = 'bn-item-info';
        const roomText = h.currentRoom ? ` · ${h.currentRoom}` : '';
        info.innerHTML =
            `<span class="bn-item-name">${h.name}</span>` +
            `<span class="bn-item-sub">${h.taskSetName}${roomText}</span>`;
        const actions  = document.createElement('div');
        actions.className = 'bn-actions';
        const ackBtn   = document.createElement('button');
        ackBtn.className  = 'btn-s bn-action-btn bn-action-btn--confirm';
        ackBtn.textContent = 'Bestätigen';
        ackBtn.addEventListener('click', async () => {
            const resp = await post('/lg-taskset-status-teacher', { studentId: h.studentId, taskSetId: h.taskSetId, status: 1 });
            if (resp.ok) { item.remove(); decrementBadge(); }
        });
        actions.appendChild(ackBtn);
        item.appendChild(info);
        item.appendChild(actions);
        list.appendChild(item);
        if (animate) animateItemIn(item);
        incrementBadge();
    }

    const EASING = 'cubic-bezier(0.4,0,0.2,1)', DURATION = '0.3s';
    let open = false;
    toggle.addEventListener('click', () => {
        const chevronBtn = toggle.querySelector('.lg-chevron-btn');
        body.style.transition = `height ${DURATION} ${EASING}`;
        if (open) {
            body.style.height = body.scrollHeight + 'px';
            requestAnimationFrame(() => requestAnimationFrame(() => { body.style.height = '0'; }));
            body.addEventListener('transitionend', () => { body.style.transition = ''; }, { once: true });
            chevronBtn.classList.remove('lg-chevron-btn--open');
        } else {
            body.style.height = body.scrollHeight + 'px';
            body.addEventListener('transitionend', () => { body.style.height = 'auto'; body.style.transition = ''; }, { once: true });
            chevronBtn.classList.add('lg-chevron-btn--open');
        }
        open = !open;
    });

    return { panel, addSubmission, addHelpRequest };
}

// ---------------------------------------------------------------------------
// Lerngruppen — own view (full task-set management)
// ---------------------------------------------------------------------------

function buildLerngruppenSection(lerngruppen, onRemove = null) {
    const list = document.getElementById('lerngruppen-list');
    const hint = document.getElementById('no-lerngruppen-hint');
    if (!list) return;
    if (lerngruppen.length === 0) { if (hint) hint.style.display = ''; return; }
    lerngruppen.forEach(lg => list.appendChild(createLerngruppePanel(lg, onRemove)));
}

function createLerngruppePanel(lg, onRemove = null) {
    const panel = document.createElement('div');
    panel.className = 'lg-panel';

    const toggle = document.createElement('button');
    toggle.className = 'lg-panel-toggle';
    toggle.innerHTML = `<span>${lg.classLabel}<span class="lg-sep"> – </span>${lg.subjectName}</span>`;

    const rightSlot = document.createElement('span');
    rightSlot.style.cssText = 'display:flex;align-items:center;gap:8px;flex-shrink:0';

    if (onRemove) {
        const removeBtn = document.createElement('button');
        removeBtn.className   = 'btn-s btn-danger';
        removeBtn.setAttribute('aria-label', 'Entfernen');
        removeBtn.innerHTML   = '<img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span>';
        removeBtn.addEventListener('click', e => { e.stopPropagation(); onRemove(lg); });
        rightSlot.appendChild(removeBtn);
    }

    const chevronBtn = document.createElement('span');
    chevronBtn.className = 'lg-chevron-btn';
    chevronBtn.innerHTML = `<img src="/imgs/chevron.svg" class="lg-chevron" alt="">`;
    rightSlot.appendChild(chevronBtn);
    toggle.appendChild(rightSlot);
    panel.appendChild(toggle);

    const body = document.createElement('div');
    body.className    = 'lg-panel-body';
    body.style.cssText = 'height:0;overflow:hidden';
    panel.appendChild(body);

    body.appendChild(makePanelSection('Aufgaben',
        (adminOnly ? '' : 'Hier können Sie die Aufgabensets erstellen. ') +
        'Nur aktivierte Aufgaben erscheinen im Lernfortschritt der Schüler.'));

    const taskSetsList = document.createElement('div');
    taskSetsList.className = 'lg-tasksets-list';

    const gsContainer = document.createElement('div');
    function refreshScale() { buildGradingScale(gsContainer, lg); }

    renderTaskSetRows(taskSetsList, lg, refreshScale);
    body.appendChild(taskSetsList);
    if (!adminOnly) {
        body.appendChild(buildAddArea(lg, taskSetsList, refreshScale));
    }

    body.appendChild(makePanelSection('Notenvergabe',
        adminOnly
            ? 'Die Notenvergabe wird vom Administrator verwaltet.'
            : 'Hier können Sie die Notenvergabe durch Verschieben der Trennlinien festlegen.'));
    body.appendChild(gsContainer);
    refreshScale();

    const spacer = document.createElement('div');
    spacer.style.height = '24px';
    body.appendChild(spacer);

    const EASING = 'cubic-bezier(0.4,0,0.2,1)', DURATION = '0.3s';
    let open = false;
    toggle.addEventListener('click', () => {
        const chevronBtn = toggle.querySelector('.lg-chevron-btn');
        body.style.transition = `height ${DURATION} ${EASING}`;
        if (open) {
            body.style.height = body.scrollHeight + 'px';
            requestAnimationFrame(() => requestAnimationFrame(() => { body.style.height = '0'; }));
            body.addEventListener('transitionend', () => { body.style.transition = ''; }, { once: true });
            chevronBtn.classList.remove('lg-chevron-btn--open');
        } else {
            body.style.height = body.scrollHeight + 'px';
            body.addEventListener('transitionend', () => { body.style.height = 'auto'; body.style.transition = ''; }, { once: true });
            chevronBtn.classList.add('lg-chevron-btn--open');
        }
        open = !open;
    });

    return panel;
}

function renderTaskSetRows(container, lg, onChanged) {
    container.innerHTML = '';
    if (lg.taskSets.length === 0) {
        const p = document.createElement('p');
        p.className   = 'lg-empty text-italic';
        p.textContent = 'Noch keine Aufgabensets vorhanden.';
        container.appendChild(p);
        return;
    }
    lg.taskSets.forEach(ts => {
        const row = document.createElement('div');
        row.className = 'lg-panel bn-item' + (ts.active ? ' lg-active-task' : '');
        container.appendChild(row);

        function renderView() {
            row.innerHTML = '';
            const info = document.createElement('span');
            info.className = 'lg-taskset-info';
            info.innerHTML = `<strong>${ts.name}</strong> — ${ts.maxPoints} Punkt${ts.maxPoints !== 1 ? 'e' : ''}`;
            row.appendChild(info);

            if (!adminOnly) {
                const editBtn = document.createElement('button');
                editBtn.className   = 'btn-s bn-action-btn lg-btn-edit';
                editBtn.setAttribute('aria-label', 'Bearbeiten');
                editBtn.innerHTML   = '<img src="/imgs/edit.svg" alt=""><span class="btn-label">Bearbeiten</span>';
                editBtn.addEventListener('click', renderEditForm);
                row.appendChild(editBtn);
            }

            if (!adminOnly) {
                const toggleBtn = document.createElement('button');
                toggleBtn.className   = ts.active ? 'btn-s bn-action-btn lg-btn-deactivate' : 'btn-s bn-action-btn lg-btn-activate';
                toggleBtn.setAttribute('aria-label', ts.active ? 'Deaktivieren' : 'Aktivieren');
                toggleBtn.innerHTML   = `<img src="/imgs/activate.svg" alt=""><span class="btn-label">${ts.active ? 'Deaktivieren' : 'Aktivieren'}</span>`;
                toggleBtn.addEventListener('click', async () => {
                    const resp = await post('/toggle-lg-taskset', withTeacher({ tasksetId: ts.id }));
                    if (!resp.ok) return;
                    const data = await resp.json();
                    ts.active  = data.active;
                    row.className = 'lg-panel bn-item' + (ts.active ? ' lg-active-task' : '');
                    renderView();
                    if (onChanged) onChanged();
                });
                row.appendChild(toggleBtn);
            }

            if (!adminOnly) {
                const delBtn = document.createElement('button');
                delBtn.className   = 'btn-s btn-danger';
                delBtn.setAttribute('aria-label', 'Entfernen');
                delBtn.innerHTML   = '<img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span>';
                delBtn.addEventListener('click', () => {
                    showConfirmDialog(`Aufgabenset „${ts.name}" wirklich entfernen?`, 'Entfernen', async () => {
                        await post('/delete-lg-taskset', withTeacher({ tasksetId: ts.id }));
                        lg.taskSets = lg.taskSets.filter(t => t.id !== ts.id);
                        renderTaskSetRows(container, lg, onChanged);
                        if (onChanged) onChanged();
                    });
                });
                row.appendChild(delBtn);
            }
        }

        function renderEditForm() {
            row.innerHTML = '';
            row.classList.add('lg-taskset-row--editing');
            const nameInput   = document.createElement('input');
            nameInput.type    = 'text';
            nameInput.className = 'input-box lg-edit-input';
            nameInput.value   = ts.name;
            const pointsInput = document.createElement('input');
            pointsInput.type  = 'number';
            pointsInput.className = 'input-box lg-edit-input lg-edit-points';
            pointsInput.value = ts.maxPoints;
            pointsInput.min   = '1';
            const saveBtn     = document.createElement('button');
            saveBtn.className = 'btn-s lg-save-btn';
            saveBtn.style.margin = '0';
            saveBtn.textContent  = 'Speichern';
            const errorEl = document.createElement('p');
            errorEl.className = 'lg-create-error';
            errorEl.style.cssText = 'display:none;width:100%;color:var(--c-red)';
            const passfailRow = document.createElement('div');
            passfailRow.style.cssText = 'display:flex;align-items:center;gap:10px;padding-top:4px;width:100%';
            const passfailSpan = document.createElement('span');
            passfailSpan.style.fontSize = '0.85em';
            passfailSpan.textContent = 'Diese Aufgabe kann nur bestanden oder nicht bestanden werden';
            const passfailToggle = document.createElement('button');
            passfailToggle.type = 'button';
            passfailToggle.className = 'settings-toggle' + (ts.isPassFail ? ' settings-toggle--on' : '');
            passfailToggle.dataset.active = ts.isPassFail ? 'true' : 'false';
            passfailToggle.ariaPressed    = ts.isPassFail ? 'true' : 'false';
            passfailToggle.innerHTML = '<span class="settings-toggle-knob"></span>';
            passfailToggle.addEventListener('click', function() {
                const on = this.dataset.active !== 'true';
                this.classList.toggle('settings-toggle--on', on);
                this.dataset.active = on ? 'true' : 'false';
                this.ariaPressed    = on ? 'true' : 'false';
            });
            passfailRow.appendChild(passfailToggle);
            passfailRow.appendChild(passfailSpan);

            saveBtn.addEventListener('click', async () => {
                const name      = nameInput.value.trim();
                const maxPoints = Math.max(1, parseInt(pointsInput.value, 10) || 1);
                const isPassFail = passfailToggle.dataset.active === 'true';
                if (!name) { nameInput.focus(); return; }
                errorEl.style.display = 'none';
                const resp = await post('/update-lg-taskset', withTeacher({ tasksetId: ts.id, name, maxPoints, isPassFail }));
                if (!resp.ok) {
                    const data = await resp.json().catch(() => ({}));
                    errorEl.textContent = data.error || 'Fehler beim Speichern.';
                    errorEl.style.display = '';
                    return;
                }
                ts.name = name; ts.maxPoints = maxPoints; ts.isPassFail = isPassFail;
                row.classList.remove('lg-taskset-row--editing');
                renderView();
                if (onChanged) onChanged();
            });
            const cancelBtn     = document.createElement('button');
            cancelBtn.className = 'btn-s btn-red lg-cancel-btn';
            cancelBtn.style.margin = '0';
            cancelBtn.textContent  = 'Abbrechen';
            cancelBtn.addEventListener('click', () => { row.classList.remove('lg-taskset-row--editing'); renderView(); });
            const btnGroup = document.createElement('div');
            btnGroup.className = 'lg-btn-group';
            btnGroup.appendChild(saveBtn);
            btnGroup.appendChild(cancelBtn);
            row.appendChild(nameInput); row.appendChild(pointsInput);
            row.appendChild(btnGroup);  row.appendChild(passfailRow); row.appendChild(errorEl);
            nameInput.focus(); nameInput.select();
        }

        renderView();
    });
}

function buildAddArea(lg, taskSetsList, onChanged) {
    const area        = document.createElement('div');
    area.className    = 'lg-add-area';
    const trigger     = document.createElement('div');
    trigger.className = 'lg-add-trigger';
    const triggerInner = document.createElement('div');
    triggerInner.className   = 'btn lg-add-trigger-inner';
    triggerInner.textContent = '+ Aufgabenset hinzufügen';
    trigger.appendChild(triggerInner);

    const form = document.createElement('div');
    form.className = 'lg-create-form';
    form.innerHTML = `
        <div class="lg-panel bn-item lg-form-inner">
            <input type="text"   class="input-box lg-taskset-name"   placeholder="Name">
            <input type="number" class="input-box lg-edit-points lg-taskset-points" min="1" placeholder="Punkte">
            <div class="lg-btn-group">
                <button class="btn-s lg-save-btn">Speichern</button>
                <button class="btn-s btn-red lg-cancel-btn">Abbrechen</button>
            </div>
            <div style="display:flex;align-items:center;gap:10px;padding-top:4px;width:100%">
                <button type="button" class="settings-toggle lg-passfail-toggle" data-active="false" aria-pressed="false">
                    <span class="settings-toggle-knob"></span>
                </button>
                <span style="font-size:0.85em">Diese Aufgabe kann nur bestanden oder nicht bestanden werden</span>
            </div>
            <p class="lg-create-error" style="display:none"></p>
        </div>`;

    const errorEl       = form.querySelector('.lg-create-error');
    const triggerSleeve = document.createElement('div');
    triggerSleeve.className = 'lg-trigger-sleeve';
    triggerSleeve.appendChild(trigger);
    const formSleeve    = document.createElement('div');
    formSleeve.className = 'lg-form-sleeve';
    formSleeve.appendChild(form);

    const nameInput = form.querySelector('.lg-taskset-name');
    const saveBtn   = form.querySelector('.lg-save-btn');
    saveBtn.disabled = true;
    nameInput.addEventListener('input', () => { saveBtn.disabled = nameInput.value.trim() === ''; });
    form.querySelector('.lg-passfail-toggle').addEventListener('click', function() {
        const on = this.dataset.active !== 'true';
        this.classList.toggle('settings-toggle--on', on);
        this.dataset.active = on ? 'true' : 'false';
        this.ariaPressed    = on ? 'true' : 'false';
    });

    function open() {
        trigger.style.visibility = 'hidden';
        triggerSleeve.classList.add('lg-trigger-sleeve--hidden');
        formSleeve.classList.add('lg-form-sleeve--open');
        formSleeve.addEventListener('transitionend', () => { form.querySelector('.lg-taskset-name').focus(); }, { once: true });
    }
    function close() {
        nameInput.value  = '';
        saveBtn.disabled = true;
        form.querySelector('.lg-taskset-points').value = '';
        const pft = form.querySelector('.lg-passfail-toggle');
        pft.classList.remove('settings-toggle--on'); pft.dataset.active = 'false'; pft.ariaPressed = 'false';
        errorEl.style.display = 'none';
        formSleeve.classList.remove('lg-form-sleeve--open');
        triggerSleeve.classList.remove('lg-trigger-sleeve--hidden');
        triggerSleeve.addEventListener('transitionend', () => { trigger.style.visibility = ''; }, { once: true });
    }

    triggerInner.addEventListener('click', open);
    form.querySelector('.lg-cancel-btn').addEventListener('click', close);
    saveBtn.addEventListener('click', async () => {
        errorEl.style.display = 'none';
        const name       = nameInput.value.trim();
        const maxPoints  = parseInt(form.querySelector('.lg-taskset-points').value, 10) || 1;
        const isPassFail = form.querySelector('.lg-passfail-toggle').dataset.active === 'true';
        const resp = await post('/create-lg-taskset', withTeacher({ classId: lg.classId, subjectId: lg.subjectId, name, maxPoints, isPassFail }));
        if (!resp.ok) { const data = await resp.json().catch(() => ({})); errorEl.textContent = data.error ?? 'Fehler beim Speichern.'; errorEl.style.display = ''; return; }
        const newTs = await resp.json();
        lg.taskSets.push(newTs);
        renderTaskSetRows(taskSetsList, lg, onChanged);
        if (onChanged) onChanged();
        close();
    });

    area.appendChild(triggerSleeve);
    area.appendChild(formSleeve);
    return area;
}

// ---------------------------------------------------------------------------
// Lerngruppen — admin: add form + remove confirmation
// ---------------------------------------------------------------------------

function renderAddLerngruppeForm(data) {
    const area = document.getElementById('add-lerngruppe-area');
    if (!area) return;

    const classSel = document.createElement('select');
    classSel.className = 'input-box';
    classSel.innerHTML = '<option value="">Klasse</option>';
    (data.availableClasses ?? []).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id; opt.textContent = `${c.label} (Stufe ${c.grade})`;
        classSel.appendChild(opt);
    });

    const subjectSel = document.createElement('select');
    subjectSel.className = 'input-box';
    subjectSel.innerHTML = '<option value="">Fach</option>';
    (data.availableSubjects ?? []).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id; opt.textContent = s.name;
        subjectSel.appendChild(opt);
    });

    const addBtn = document.createElement('button');
    addBtn.className   = 'btn btn-confirm';
    addBtn.textContent = 'Hinzufügen';
    addBtn.disabled    = true;

    const errorEl = document.createElement('p');
    errorEl.className    = 'form-error';
    errorEl.style.display = 'none';

    function checkAddBtn() { addBtn.disabled = !classSel.value || !subjectSel.value; }
    classSel.addEventListener('change', checkAddBtn);
    subjectSel.addEventListener('change', checkAddBtn);

    addBtn.addEventListener('click', async () => {
        errorEl.style.display = 'none';
        const resp = await post('/add-lerngruppe', {
            teacherId,
            classId:   parseInt(classSel.value, 10),
            subjectId: parseInt(subjectSel.value, 10),
        });
        if (!resp.ok) { const msg = await resp.json().catch(() => null); errorEl.textContent = msg?.error ?? 'Fehler beim Hinzufügen.'; errorEl.style.display = ''; return; }
        classSel.value = ''; subjectSel.value = ''; addBtn.disabled = true;
        await reloadLerngruppenAdmin();
    });

    const formRow = document.createElement('div');
    formRow.className     = 'form-grid';
    formRow.style.cssText = 'grid-template-columns:1fr 1fr auto;margin-top:16px;border-top:1px solid var(--c-lightgrey);padding-top:16px';
    formRow.appendChild(classSel);
    formRow.appendChild(subjectSel);
    formRow.appendChild(addBtn);
    area.appendChild(formRow);
    area.appendChild(errorEl);
}

async function reloadLerngruppenAdmin() {
    const list = document.getElementById('lerngruppen-list');
    const hint = document.getElementById('no-lerngruppen-hint');
    if (!list) return;
    const fresh = await getJson(`/api/teacher-lerngruppen?id=${teacherId}`).catch(() => null);
    if (!fresh) return;
    list.innerHTML = '';
    if (hint) hint.style.display = fresh.length === 0 ? '' : 'none';
    fresh.forEach(lg => list.appendChild(createLerngruppePanel(lg, lg2 => confirmRemoveLg(lg2))));
}

function confirmRemoveLg(lg) {
    const modal  = document.getElementById('remove-lg-modal');
    const textEl = document.getElementById('remove-lg-modal-text');
    if (!modal) return;
    textEl.textContent = `Soll die Lerngruppe „${lg.classLabel} – ${lg.subjectName}" wirklich von dieser Lehrkraft entfernt werden?`;
    modal.style.display = '';
    document.getElementById('remove-lg-confirm').onclick = async () => {
        modal.style.display = 'none';
        await post('/remove-lerngruppe', { teacherId, classId: parseInt(lg.classId), subjectId: parseInt(lg.subjectId) });
        await reloadLerngruppenAdmin();
    };
}

// ---------------------------------------------------------------------------
// Students table (both views)
// ---------------------------------------------------------------------------

function initMyStudentsTable(students) {
    const tbody    = document.getElementById('myStudentTableBody');
    const searchEl = document.getElementById('myStudentSearch');
    const classEl  = document.getElementById('myStudentFilterClass');
    if (!tbody) return;

    const LEVELS    = window.graduationConfig?.levels ?? ['Neustarter', 'Starter', 'Durchstarter', 'Lernprofi'];
    const PAGE_SIZE = 10;
    let currentPage = 1;

    const classLabels  = [...new Set(students.map(s => s.classLabel))].sort();
    const classOptions = [{ value: '', label: 'Alle Klassen' }, ...classLabels.map(l => ({ value: l, label: l }))];
    let selectedClass  = '';
    let classDropdown  = null;

    function closeClassDropdown() {
        if (!classDropdown) return;
        const el = classDropdown; classDropdown = null;
        el.style.pointerEvents = 'none';
        el.classList.remove('dropdown--visible');
        el.addEventListener('transitionend', () => el.remove(), { once: true });
    }

    function openClassDropdown() {
        if (classDropdown) { closeClassDropdown(); return; }
        classDropdown = document.createElement('div');
        classDropdown.className = 'dropdown';
        document.body.appendChild(classDropdown);

        classOptions.forEach(o => {
            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'dropdown-option' + (o.value === selectedClass ? ' dropdown-option--active' : '');
            btn.textContent = o.label;
            btn.addEventListener('click', e => {
                e.stopPropagation();
                selectedClass = o.value;
                if (classEl) classEl.textContent = o.label;
                resetAndRender();
                closeClassDropdown();
            });
            classDropdown.appendChild(btn);
        });

        const r = classEl.getBoundingClientRect();
        classDropdown.style.position = 'fixed';
        classDropdown.style.left     = r.left + 'px';
        classDropdown.style.top      = (r.bottom + 8) + 'px';
        classDropdown.style.minWidth = Math.max(r.width, 140) + 'px';

        requestAnimationFrame(() => classDropdown?.classList.add('dropdown--visible'));
        setTimeout(() => {
            document.addEventListener('click', closeClassDropdown, { once: true });
            window.addEventListener('scroll', closeClassDropdown, { once: true, capture: true });
        }, 0);
    }

    if (classEl) classEl.addEventListener('click', e => { e.stopPropagation(); openClassDropdown(); });

    function getFiltered() {
        const search = searchEl?.value.toLowerCase() ?? '';
        return students.filter(s => {
            const name = `${s.firstName} ${s.lastName}`.toLowerCase();
            if (search && !name.includes(search) && !s.email.toLowerCase().includes(search)) return false;
            if (selectedClass && s.classLabel !== selectedClass) return false;
            return true;
        });
    }

    function render() {
        const filtered   = getFiltered();
        const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;
        const page = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
        tbody.innerHTML = '';
        page.forEach(s => {
            const tr = document.createElement('tr');
            tr.innerHTML =
                `<td>${s.firstName} ${s.lastName}</td>` +
                (!isOwn ? `<td>${s.email}</td>` : '') +
                `<td>${s.classLabel}</td>` +
                `<td>${LEVELS[s.graduationLevel] ?? s.graduationLevel}</td>` +
                `<td class="row-actions">` +
                `<button class="btn-s btn-profile" id="btn-student-profile" aria-label="Profil"><img src="/imgs/profile.svg" alt=""><span class="btn-label">Profil</span></button>` +
                `</td>`;
            tr.querySelector('.btn-profile').addEventListener('click', () => {
                window.location.href = `/student-profile?id=${s.id}`;
            });
            tbody.appendChild(tr);
        });
        renderPagination('myStudentPagination', totalPages, filtered.length, currentPage,
            p => { currentPage = p; render(); }, 'Schüler');
    }

    function resetAndRender() { currentPage = 1; render(); }
    if (searchEl) searchEl.addEventListener('input', resetAndRender);
    render();
}

// ---------------------------------------------------------------------------
// Admin: edit + delete handlers
// ---------------------------------------------------------------------------

function populateEditForm(data) {
    const fn = document.getElementById('ef-firstName');
    const ln = document.getElementById('ef-lastName');
    const em = document.getElementById('ef-email');
    const bn = document.getElementById('ef-bn');
    if (fn) fn.value = data.firstName;
    if (ln) ln.value = data.lastName;
    if (em) em.value = data.email;
    if (bn) bn.value = data.benutzername ?? '';
}

async function refreshProfile() {
    const fresh = await getJson(`/api/teacher-profile?id=${teacherId}`).catch(() => null);
    if (!fresh) return;
    _profileData = fresh;
    const nameEl = document.getElementById('profile-first-name');
    if (nameEl) { nameEl.textContent = `${fresh.firstName} ${fresh.lastName}`; fitTextToWidth(nameEl); }
    document.getElementById('profile-email').textContent = fresh.email;
    populateEditForm(fresh);
}

function setupAdminHandlers() {
    document.getElementById('edit-teacher-form')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const errorEl = document.getElementById('edit-error');
        if (errorEl) errorEl.style.display = 'none';
        const resp = await fetch('/update-teacher', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id:           teacherId,
                firstName:    document.getElementById('ef-firstName').value.trim(),
                lastName:     document.getElementById('ef-lastName').value.trim(),
                email:        document.getElementById('ef-email').value.trim(),
                benutzername: document.getElementById('ef-bn').value.trim(),
            }),
        });
        const data = await resp.json();
        if (!resp.ok) { if (errorEl) { errorEl.textContent = data.error ?? 'Fehler.'; errorEl.style.display = ''; } return; }
        document.getElementById('edit-modal').style.display = 'none';
        await refreshProfile();
    });

    document.getElementById('editDataBtn')?.addEventListener('click', () =>
        document.getElementById('edit-modal').style.display = '');
    document.getElementById('editModalClose')?.addEventListener('click', () =>
        document.getElementById('edit-modal').style.display = 'none');
    document.getElementById('edit-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    document.getElementById('deleteBtn')?.addEventListener('click', function() {
        const name = _profileData ? `${_profileData.firstName} ${_profileData.lastName}` : 'diese Lehrkraft';
        document.getElementById('confirm-modal-text').textContent =
            `Soll ${name} wirklich endgültig entfernt werden? Diese Aktion kann nicht rückgängig gemacht werden.`;
        document.getElementById('confirm-modal').style.display = '';
        document.getElementById('confirm-delete').onclick = async () => {
            await fetch('/delete-teacher', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: teacherId }),
            });
            window.location.href = '/manage_teachers';
        };
    });

    document.getElementById('confirm-cancel')?.addEventListener('click', () =>
        document.getElementById('confirm-modal').style.display = 'none');
    document.getElementById('confirm-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    document.getElementById('remove-lg-cancel')?.addEventListener('click', () =>
        document.getElementById('remove-lg-modal').style.display = 'none');
    document.getElementById('remove-lg-modal')?.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makePanelSection(title, description) {
    const el = document.createElement('div');
    el.className = 'bn-section-header';
    el.innerHTML = `<div class="bn-section-title text-sm text-bold">${title}</div><div class="bn-section-subtitle text-sm">${description}</div>`;
    return el;
}

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
const GS_COLORS = [
    cssVar('--c-grade-6'), cssVar('--c-grade-5'), cssVar('--c-grade-4'),
    cssVar('--c-grade-3'), cssVar('--c-grade-2'), cssVar('--c-grade-1'),
];

function buildGradingScale(container, lg) {
    const maxPoints = lg.taskSets.filter(ts => ts.active).reduce((s, ts) => s + ts.maxPoints, 0);
    container.innerHTML = '';
    if (maxPoints === 0) {
        container.innerHTML = '<p class="lg-empty text-italic">Aktivieren Sie ein Aufgabenset um die Notenskala zu bearbeiten.</p>';
        return;
    }

    let thresholds;
    if (lg.gradingScale) {
        const c = clampThresholds(fracToAbs(lg.gradingScale, maxPoints), maxPoints);
        const valid = c[4] < maxPoints && c.every((t, i) => i === 0 || t > c[i - 1]);
        thresholds = valid ? c : defaultThresholds(maxPoints);
    } else {
        thresholds = defaultThresholds(maxPoints);
    }

    const widget     = document.createElement('div');
    widget.className = 'lg-gs-widget';
    const labelsRow  = document.createElement('div');
    labelsRow.className = 'lg-gs-labels';
    const bar        = document.createElement('div');
    bar.className    = 'lg-gs-bar';
    const sectionsEl = document.createElement('div');
    sectionsEl.className = 'lg-gs-sections';
    bar.appendChild(sectionsEl);

    const sectionEls = [6, 5, 4, 3, 2, 1].map((g, i) => {
        const sec = document.createElement('div');
        sec.className      = 'text-sm text-bold lg-gs-section';
        sec.style.background = GS_COLORS[i];
        sec.textContent    = g;
        sectionsEl.appendChild(sec);
        return sec;
    });

    const labelEls = [], handleEls = [];
    for (let i = 0; i < 5; i++) {
        const lbl = document.createElement('span');
        lbl.className = 'text-xs text-bold lg-gs-threshold-label';
        labelsRow.appendChild(lbl);
        labelEls.push(lbl);
        const handle = document.createElement('div');
        handle.className = 'lg-gs-handle';
        if (adminOnly) {
            handle.style.cssText = 'pointer-events:none;cursor:default';
            handle.innerHTML = '<div class="lg-gs-handle-line"></div>';
        } else {
            handle.innerHTML = '<div class="lg-gs-handle-line"></div><div class="lg-gs-handle-grip"></div>';
        }
        bar.appendChild(handle);
        handleEls.push(handle);
    }

    const endLabel = document.createElement('span');
    endLabel.className    = 'text-xs text-bold lg-gs-threshold-label';
    endLabel.style.left   = '100%';
    endLabel.textContent  = maxPoints;
    labelsRow.appendChild(endLabel);
    const endLine = document.createElement('div');
    endLine.className     = 'lg-gs-handle';
    endLine.style.cssText = 'left:100%;pointer-events:none';
    endLine.innerHTML     = '<div class="lg-gs-handle-line"></div>';
    bar.appendChild(endLine);

    widget.appendChild(labelsRow);
    widget.appendChild(bar);
    container.appendChild(widget);

    function fmtThreshold(v) {
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    }
    function updateUI() {
        const widths = [thresholds[0], thresholds[1]-thresholds[0], thresholds[2]-thresholds[1],
            thresholds[3]-thresholds[2], thresholds[4]-thresholds[3], maxPoints-thresholds[4]];
        sectionEls.forEach((sec, i) => { sec.style.flex = Math.max(0, widths[i]); });
        for (let i = 0; i < 5; i++) {
            const pct = (thresholds[i] / maxPoints) * 100;
            handleEls[i].style.left  = pct + '%';
            labelEls[i].style.left   = pct + '%';
            labelEls[i].textContent  = fmtThreshold(thresholds[i]);
        }
    }
    updateUI();

    if (!adminOnly) handleEls.forEach((handle, idx) => {
        handle.addEventListener('pointerdown', e => {
            e.preventDefault();
            handle.setPointerCapture(e.pointerId);
            const rect = bar.getBoundingClientRect();
            function onMove(e) {
                const frac = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
                const eps  = maxPoints / 200;
                const min  = idx === 0 ? eps : thresholds[idx - 1] + eps;
                const max  = idx === 4 ? maxPoints - eps : thresholds[idx + 1] - eps;
                thresholds[idx] = roundThreshold(Math.max(min, Math.min(max, frac * maxPoints)));
                updateUI();
            }
            function onUp() {
                handle.removeEventListener('pointermove', onMove);
                const fractions = absToFrac(thresholds, maxPoints);
                lg.gradingScale = fractions;
                post('/save-lg-grading-scale', withTeacher({ classId: lg.classId, subjectId: lg.subjectId, thresholds: fractions }));
            }
            handle.addEventListener('pointermove', onMove);
            handle.addEventListener('pointerup', onUp, { once: true });
        });
    });

    if (!adminOnly) labelEls.forEach((lbl, idx) => {
        lbl.classList.add('lg-gs-threshold-label--interactive');
        lbl.addEventListener('click', e => {
            e.stopPropagation();
            const eps = 0.5;
            const min = idx === 0 ? eps : thresholds[idx - 1] + eps;
            const max = idx === 4 ? maxPoints - eps : thresholds[idx + 1] - eps;
            const inp = document.createElement('input');
            inp.type = 'number'; inp.step = 'any'; inp.value = +thresholds[idx].toFixed(1); inp.min = min; inp.max = max;
            inp.className = 'lg-gs-threshold-input';
            lbl.textContent = ''; lbl.appendChild(inp);
            inp.focus(); inp.select();
            let done = false;
            function commit() {
                if (done) return; done = true;
                const parsed = parseFloat(inp.value);
                thresholds[idx] = roundThreshold(Math.max(min, Math.min(max, isNaN(parsed) ? thresholds[idx] : parsed)));
                const fractions = absToFrac(thresholds, maxPoints);
                lg.gradingScale = fractions;
                updateUI();
                post('/save-lg-grading-scale', withTeacher({ classId: lg.classId, subjectId: lg.subjectId, thresholds: fractions }));
            }
            inp.addEventListener('keydown', e => {
                if (e.key === 'Enter') inp.blur();
                else if (e.key === 'Escape') { done = true; updateUI(); }
            });
            inp.addEventListener('blur', commit);
        });
    });
}

function defaultThresholds(maxPoints) {
    return [1, 2, 3, 4, 5].map(i => (i / 6) * maxPoints);
}

function clampThresholds(thresholds, maxPoints) {
    const result = [...thresholds];
    for (let i = 0; i < 5; i++) {
        const min = i === 0 ? 0 : result[i - 1];
        result[i] = Math.max(min, Math.min(maxPoints, result[i]));
    }
    return result;
}

// roundThreshold, fracToAbs, absToFrac are shared globals from grading-scale.js
