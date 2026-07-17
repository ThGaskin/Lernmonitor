<?php
$adminOnlyTasksets = $adminOnlyTasksets ?? false;
$title     = 'Aufgabensets';
$extraHead = '<link rel="stylesheet" href="' . Config::asset('/css/lehrer-dashboard.css') . '">
<script>window.manageTasksConfig = { adminOnlyTasksets: ' . ($adminOnlyTasksets ? 'true' : 'false') . ' };</script>';
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<?php include __DIR__ . '/../partials/site-header.php'; ?>
<section id="tasks" style="--page-gradient: var(--tasks-gradient)">
    <div class="page-box">
        <div class="page-title">
            <img src="/imgs/tasks_reversed.svg" alt="">
            Aufgabensets
        </div>
        <div class="page-subtitle" id="tasks-subtitle">
            <?= $adminOnlyTasksets
                ? 'Aufgabensets nach Stufe und Fach verwalten'
                : 'Aufgabensets nach Stufe, Fach und Klasse verwalten' ?>
        </div>
    </div>

    <section class="manage-list page-box" id="tasksets-list">
        <p class="lg-empty text-italic" id="tasks-loading">Wird geladen …</p>
    </section>
</section>

<script src="<?= Config::asset('/js/grading-scale.js') ?>"></script>
<script>
// ---------------------------------------------------------------------------
// Page-specific helpers (roundThreshold/fracToAbs/absToFrac are shared
// globals from grading-scale.js)
// ---------------------------------------------------------------------------

const { adminOnlyTasksets } = window.manageTasksConfig;

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
const GS_COLORS = [
    cssVar('--c-grade-6'), cssVar('--c-grade-5'), cssVar('--c-grade-4'),
    cssVar('--c-grade-3'), cssVar('--c-grade-2'), cssVar('--c-grade-1'),
];

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

function buildGradingScale(container, rawThresholds, maxPoints, onSave) {
    container.innerHTML = '';
    if (maxPoints === 0) {
        const p = document.createElement('p');
        p.className = 'lg-empty text-italic';
        p.textContent = 'Keine aktiven Aufgabensets: Notenskala nicht verfügbar.';
        container.appendChild(p);
        return;
    }
    let thresholds;
    if (rawThresholds) {
        const c = clampThresholds(fracToAbs(rawThresholds, maxPoints), maxPoints);
        const valid = c[4] < maxPoints && c.every((t, i) => i === 0 || t > c[i - 1]);
        thresholds = valid ? c : defaultThresholds(maxPoints);
    } else {
        thresholds = defaultThresholds(maxPoints);
    }

    const widget = document.createElement('div');
    widget.className = 'lg-gs-widget';

    const labelsRow = document.createElement('div');
    labelsRow.className = 'lg-gs-labels';

    const bar = document.createElement('div');
    bar.className = 'lg-gs-bar';

    const sectionsEl = document.createElement('div');
    sectionsEl.className = 'lg-gs-sections';
    bar.appendChild(sectionsEl);

    const sectionEls = [6, 5, 4, 3, 2, 1].map((g, i) => {
        const sec = document.createElement('div');
        sec.className = 'text-sm text-bold lg-gs-section';
        sec.style.background = GS_COLORS[i];
        sec.textContent = g;
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
        handle.innerHTML = '<div class="lg-gs-handle-line"></div><div class="lg-gs-handle-grip"></div>';
        bar.appendChild(handle);
        handleEls.push(handle);
    }
    const endLabel = document.createElement('span');
    endLabel.className = 'text-xs text-bold lg-gs-threshold-label';
    endLabel.style.left = '100%';
    endLabel.textContent = maxPoints;
    labelsRow.appendChild(endLabel);
    const endLine = document.createElement('div');
    endLine.className = 'lg-gs-handle';
    endLine.style.cssText = 'left:100%;pointer-events:none';
    endLine.innerHTML = '<div class="lg-gs-handle-line"></div>';
    bar.appendChild(endLine);

    widget.appendChild(labelsRow);
    widget.appendChild(bar);
    container.appendChild(widget);

    function fmtThreshold(v) {
        return Number.isInteger(v) ? String(v) : v.toFixed(1);
    }
    function updateUI() {
        const widths = [
            thresholds[0],
            thresholds[1] - thresholds[0],
            thresholds[2] - thresholds[1],
            thresholds[3] - thresholds[2],
            thresholds[4] - thresholds[3],
            maxPoints     - thresholds[4],
        ];
        sectionEls.forEach((sec, i) => { sec.style.flex = Math.max(0, widths[i]); });
        for (let i = 0; i < 5; i++) {
            const pct = (thresholds[i] / maxPoints) * 100;
            handleEls[i].style.left   = pct + '%';
            labelEls[i].style.left    = pct + '%';
            labelEls[i].textContent   = fmtThreshold(thresholds[i]);
        }
    }
    updateUI();

    handleEls.forEach((handle, idx) => {
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
                if (onSave) onSave(absToFrac([...thresholds], maxPoints));
            }
            handle.addEventListener('pointermove', onMove);
            handle.addEventListener('pointerup', onUp, { once: true });
        });
    });

    labelEls.forEach((lbl, idx) => {
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
                updateUI();
                if (onSave) onSave(absToFrac([...thresholds], maxPoints));
            }
            inp.addEventListener('keydown', e => {
                if (e.key === 'Enter') inp.blur();
                else if (e.key === 'Escape') { done = true; updateUI(); }
            });
            inp.addEventListener('blur', commit);
        });
    });
}

const EASING   = 'cubic-bezier(0.4, 0, 0.2, 1)';
const DURATION = '0.3s';

function makeCollapsible(toggle, body) {
    let open = false;
    toggle.addEventListener('click', () => {
        const chevronBtn = toggle.querySelector('.lg-chevron-btn');
        body.style.transition = `height ${DURATION} ${EASING}`;
        if (open) {
            body.style.height = body.scrollHeight + 'px';
            requestAnimationFrame(() => requestAnimationFrame(() => { body.style.height = '0'; }));
            body.addEventListener('transitionend', () => { body.style.transition = ''; }, { once: true });
            if (chevronBtn) chevronBtn.classList.remove('lg-chevron-btn--open');
        } else {
            body.style.height = body.scrollHeight + 'px';
            body.addEventListener('transitionend', () => {
                body.style.height = 'auto'; body.style.transition = '';
            }, { once: true });
            if (chevronBtn) chevronBtn.classList.add('lg-chevron-btn--open');
        }
        open = !open;
    });
}

function makePanelSection(title, description) {
    const el = document.createElement('div');
    el.className = 'bn-section-header';
    el.innerHTML = `<div class="bn-section-title text-sm text-bold">${title}</div><div class="bn-section-subtitle text-sm">${description}</div>`;
    return el;
}

// ---------------------------------------------------------------------------
// Task set row (used in both admin-only and per-class views)
// ---------------------------------------------------------------------------

function renderTaskSetRows(container, taskSets, { onEdit, onToggle, onDelete, onRefresh }) {
    container.innerHTML = '';

    if (taskSets.length === 0) {
        const p = document.createElement('p');
        p.className = 'lg-empty text-italic';
        p.textContent = 'Noch keine Aufgabensets vorhanden.';
        container.appendChild(p);
        return;
    }

    taskSets.forEach(ts => {
        const row = document.createElement('div');
        row.className = 'lg-panel bn-item' + (ts.active ? ' lg-active-task' : '');
        container.appendChild(row);

        function renderView() {
            row.innerHTML = '';

            const info = document.createElement('span');
            info.className = 'lg-taskset-info';
            info.innerHTML = `<strong>${ts.name}</strong> — ${ts.maxPoints} Punkt${ts.maxPoints !== 1 ? 'e' : ''}`;
            row.appendChild(info);

            const editBtn = document.createElement('button');
            editBtn.className = 'btn-s bn-action-btn lg-btn-edit';
            editBtn.innerHTML = '<img src="/imgs/edit.svg" alt=""><span class="btn-label">Bearbeiten</span>';
            editBtn.addEventListener('click', renderEditForm);
            row.appendChild(editBtn);

            const toggleBtn = document.createElement('button');
            toggleBtn.className = ts.active
                ? 'btn-s bn-action-btn lg-btn-deactivate'
                : 'btn-s bn-action-btn lg-btn-activate';
            toggleBtn.innerHTML = ts.active
                ? '<img src="/imgs/activate.svg" alt=""><span class="btn-label">Deaktivieren</span>'
                : '<img src="/imgs/activate.svg" alt=""><span class="btn-label">Aktivieren</span>';
            toggleBtn.addEventListener('click', async () => {
                const data = await onToggle(ts);
                if (data === null) return;
                ts.active = data.active;
                row.className = 'lg-panel bn-item' + (ts.active ? ' lg-active-task' : '');
                renderView();
                onRefresh?.();
            });
            row.appendChild(toggleBtn);

            const delBtn = document.createElement('button');
            delBtn.className = 'btn-s btn-danger';
            delBtn.innerHTML = '<img src="/imgs/remove.svg" alt=""><span class="btn-label">Entfernen</span>';
            delBtn.addEventListener('click', () => {
                showConfirmDialog(`Aufgabenset „${ts.name}" wirklich entfernen?`, 'Entfernen', async () => {
                    const ok = await onDelete(ts);
                    if (ok) {
                        taskSets.splice(taskSets.indexOf(ts), 1);
                        renderTaskSetRows(container, taskSets, { onEdit, onToggle, onDelete, onRefresh });
                        onRefresh?.();
                    }
                });
            });
            row.appendChild(delBtn);
        }

        function renderEditForm() {
            row.innerHTML = '';
            row.classList.add('lg-taskset-row--editing');

            const nameInput = document.createElement('input');
            nameInput.type      = 'text';
            nameInput.className = 'input-box lg-edit-input';
            nameInput.value     = ts.name;

            const pointsInput = document.createElement('input');
            pointsInput.type      = 'number';
            pointsInput.className = 'input-box lg-edit-input lg-edit-points';
            pointsInput.value     = ts.maxPoints;
            pointsInput.min       = '1';

            const saveBtn = document.createElement('button');
            saveBtn.className   = 'btn-s lg-save-btn';
            saveBtn.style.margin = '0';
            saveBtn.textContent = 'Speichern';

            const passfailRow = document.createElement('div');
            passfailRow.className = 'lg-edit-passfail';
            passfailRow.style.cssText = 'display:flex;align-items:center;gap:10px;padding-top:4px;width:100%';
            const passfailSpan = document.createElement('span');
            passfailSpan.textContent = 'Diese Aufgabe kann nur bestanden oder nicht bestanden werden';
            const passfailToggle = document.createElement('button');
            passfailToggle.type = 'button';
            passfailToggle.className = 'font-sm settings-toggle' + (ts.isPassFail ? ' settings-toggle--on' : '');
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

            const errorEl = document.createElement('p');
            errorEl.className = 'lg-create-error';
            errorEl.style.cssText = 'display:none;width:100%;color:var(--c-red)';

            saveBtn.addEventListener('click', async () => {
                const name       = nameInput.value.trim();
                const maxPoints  = Math.max(1, parseInt(pointsInput.value, 10) || 1);
                const isPassFail = passfailToggle.dataset.active === 'true';
                if (!name) { nameInput.focus(); return; }
                errorEl.style.display = 'none';
                const err = await onEdit(ts, name, maxPoints, isPassFail);
                if (err) {
                    errorEl.textContent = err;
                    errorEl.style.display = '';
                    return;
                }
                ts.name = name; ts.maxPoints = maxPoints; ts.isPassFail = isPassFail; onRefresh?.();
                row.classList.remove('lg-taskset-row--editing');
                renderView();
            });

            const cancelBtn = document.createElement('button');
            cancelBtn.className   = 'btn-s btn-red lg-cancel-btn';
            cancelBtn.style.margin = '0';
            cancelBtn.textContent = 'Abbrechen';
            cancelBtn.addEventListener('click', () => {
                row.classList.remove('lg-taskset-row--editing');
                renderView();
            });

            const btnGroup = document.createElement('div');
            btnGroup.className = 'lg-btn-group';
            btnGroup.appendChild(saveBtn);
            btnGroup.appendChild(cancelBtn);

            row.appendChild(nameInput);
            row.appendChild(pointsInput);
            row.appendChild(btnGroup);
            row.appendChild(passfailRow);
            row.appendChild(errorEl);
            nameInput.focus(); nameInput.select();
        }

        renderView();
    });
}

// ---------------------------------------------------------------------------
// Add-area
// ---------------------------------------------------------------------------

function buildAddArea(onSave) {
    const area = document.createElement('div');
    area.className = 'lg-add-area';

    const triggerSleeve = document.createElement('div');
    triggerSleeve.className = 'lg-trigger-sleeve';
    const trigger = document.createElement('div');
    trigger.className = 'lg-add-trigger';
    const triggerInner = document.createElement('div');
    triggerInner.className = 'btn lg-add-trigger-inner';
    triggerInner.textContent = '+ Aufgabenset hinzufügen';
    trigger.appendChild(triggerInner);
    triggerSleeve.appendChild(trigger);

    const formSleeve = document.createElement('div');
    formSleeve.className = 'lg-form-sleeve';
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
                <span class="font-sm">Diese Aufgabe kann nur bestanden oder nicht bestanden werden</span>
            </div>
            <p class="lg-create-error text-xs" style="display:none;color:var(--c-red)"></p>
        </div>`;
    formSleeve.appendChild(form);

    const nameInput = form.querySelector('.lg-taskset-name');
    const saveBtn   = form.querySelector('.lg-save-btn');
    const errorEl   = form.querySelector('.lg-create-error');
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
        formSleeve.addEventListener('transitionend', () => nameInput.focus(), { once: true });
    }
    function close() {
        nameInput.value = ''; saveBtn.disabled = true;
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
        const newTs = await onSave(name, maxPoints, isPassFail, errorEl);
        if (newTs) close();
    });

    area.appendChild(triggerSleeve);
    area.appendChild(formSleeve);
    return area;
}

// ---------------------------------------------------------------------------
// Admin-only mode: Stufe → Fach → task sets (one set per grade+subject)
// ---------------------------------------------------------------------------

function buildAdminOnlyView(grades, container) {
    grades.forEach(g => {
        // Stufe panel
        const gradePanel = document.createElement('div');
        gradePanel.className = 'lg-panel';

        const gradeToggle = document.createElement('button');
        gradeToggle.className = 'lg-panel-toggle';
        gradeToggle.innerHTML =
            `<span>Stufe ${g.grade}</span>` +
            `<span class="lg-chevron-btn"><img src="/imgs/chevron.svg" class="lg-chevron" alt=""></span>`;
        gradePanel.appendChild(gradeToggle);

        const gradeBody = document.createElement('div');
        gradeBody.className = 'lg-panel-body';
        gradeBody.style.cssText = 'height:0;overflow:hidden';
        gradePanel.appendChild(gradeBody);

        if (g.subjects.length === 0) {
            const hint = document.createElement('p');
            hint.className = 'lg-empty text-italic';
            hint.textContent = 'Noch keine Fächer zugeordnet.';
            gradeBody.appendChild(hint);
        }

        g.subjects.forEach(sub => {
            const adminClass = sub.classes.find(c => c.classId == 0);
            const uniqueTaskSets = adminClass?.taskSets ?? [];

            const subPanel = document.createElement('div');
            subPanel.className = 'lg-panel';
            subPanel.style.margin = '8px 16px';

            const subToggle = document.createElement('button');
            subToggle.className = 'lg-panel-toggle';
            subToggle.innerHTML =
                `<span>${sub.subjectName}</span>` +
                `<span class="lg-chevron-btn"><img src="/imgs/chevron.svg" class="lg-chevron" alt=""></span>`;
            subPanel.appendChild(subToggle);

            const subBody = document.createElement('div');
            subBody.className = 'lg-panel-body';
            subBody.style.cssText = 'height:0;overflow:hidden;padding:0 16px';
            subPanel.appendChild(subBody);

            const taskSetsList = document.createElement('div');
            taskSetsList.className = 'lg-tasksets-list';

            const handlers = {
                onEdit: async (ts, name, maxPoints, isPassFail) => {
                    const resp = await fetch('/admin/update-grade-taskset', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ tasksetId: ts.id, name, maxPoints, isPassFail }),
                    });
                    if (resp.ok) return null;
                    const data = await resp.json().catch(() => ({}));
                    return data.error || 'Fehler beim Speichern.';
                },
                onToggle: async (ts) => {
                    const resp = await fetch('/admin/toggle-grade-taskset', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ tasksetId: ts.id }),
                    });
                    return resp.ok ? resp.json() : null;
                },
                onDelete: async (ts) => {
                    const resp = await fetch('/admin/delete-grade-taskset', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ tasksetId: ts.id }),
                    });
                    return resp.ok;
                },
            };

            const gsContainerAdmin = document.createElement('div');
            let currentScaleAdmin = adminClass?.gradingScale ?? null;
            function refreshScaleAdmin() {
                const mp = uniqueTaskSets.filter(ts => ts.active).reduce((s, ts) => s + ts.maxPoints, 0);
                buildGradingScale(gsContainerAdmin, currentScaleAdmin, mp, t => {
                    currentScaleAdmin = t;
                    fetch('/admin/save-grade-grading-scale', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ grade: g.grade, subjectId: sub.subjectId, thresholds: t }),
                    });
                });
            }
            handlers.onRefresh = refreshScaleAdmin;
            subBody.appendChild(makePanelSection(
                'Aufgaben',
                'Hier können Sie Aufgabensets erstellen. Nur aktivierte Aufgaben erscheinen im Lernfortschritt der Schülerinnen und Schüler.'
            ));
            renderTaskSetRows(taskSetsList, uniqueTaskSets, handlers);
            subBody.appendChild(taskSetsList);

            subBody.appendChild(buildAddArea(async (name, maxPoints, isPassFail, errorEl) => {
                const resp = await fetch('/admin/create-grade-taskset', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ grade: g.grade, subjectId: sub.subjectId, name, maxPoints, isPassFail }),
                });
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    errorEl.textContent = err.error || 'Fehler beim Speichern.';
                    errorEl.style.display = '';
                    return null;
                }
                const data = await resp.json();
                const newTs = { id: data.id ?? Date.now(), name, maxPoints, isPassFail, active: false };
                uniqueTaskSets.push(newTs);
                renderTaskSetRows(taskSetsList, uniqueTaskSets, handlers);
                return newTs;
            }));

            subBody.appendChild(makePanelSection(
                'Notenvergabe',
                'Hier können Sie die Notenvergabe durch Verschieben der Trennlinien festlegen. Die maximale Punktzahl ergibt sich aus allen aktivierten Aufgabensets.'
            ));
            subBody.appendChild(gsContainerAdmin);

            refreshScaleAdmin();

            const adminSpacer = document.createElement('div');
            adminSpacer.style.height = '16px';
            subBody.appendChild(adminSpacer);

            makeCollapsible(subToggle, subBody);
            gradeBody.appendChild(subPanel);
        });

        makeCollapsible(gradeToggle, gradeBody);
        container.appendChild(gradePanel);
    });
}

// ---------------------------------------------------------------------------
// Per-class mode: Stufe → Fach → Klasse → task sets
// ---------------------------------------------------------------------------

function buildPerClassView(grades, container) {
    grades.forEach(g => {
        const gradePanel = document.createElement('div');
        gradePanel.className = 'lg-panel';

        const gradeToggle = document.createElement('button');
        gradeToggle.className = 'lg-panel-toggle';
        gradeToggle.innerHTML =
            `<span>Stufe ${g.grade}</span>` +
            `<span class="lg-chevron-btn"><img src="/imgs/chevron.svg" class="lg-chevron" alt=""></span>`;
        gradePanel.appendChild(gradeToggle);

        const gradeBody = document.createElement('div');
        gradeBody.className = 'lg-panel-body';
        gradeBody.style.cssText = 'height:0;overflow:hidden';
        gradePanel.appendChild(gradeBody);

        if (g.subjects.length === 0) {
            const hint = document.createElement('p');
            hint.className = 'lg-empty text-italic';
            hint.textContent = 'Noch keine Fächer zugeordnet.';
            gradeBody.appendChild(hint);
        }

        g.subjects.forEach(sub => {
            const subPanel = document.createElement('div');
            subPanel.className = 'lg-panel';
            subPanel.style.margin = '8px 16px';

            const subToggle = document.createElement('button');
            subToggle.className = 'lg-panel-toggle';
            subToggle.innerHTML =
                `<span>${sub.subjectName}</span>` +
                `<span class="lg-chevron-btn"><img src="/imgs/chevron.svg" class="lg-chevron" alt=""></span>`;
            subPanel.appendChild(subToggle);

            const subBody = document.createElement('div');
            subBody.className = 'lg-panel-body';
            subBody.style.cssText = 'height:0;overflow:hidden;padding:0 16px';
            subPanel.appendChild(subBody);

            const realClasses = sub.classes.filter(cls => cls.classId !== 0);

            if (realClasses.length === 0) {
                const hint = document.createElement('p');
                hint.className = 'lg-empty text-italic';
                hint.style = 'color:var(--c-mediumgrey)';
                hint.textContent = 'Kein Lehrer für dieses Fach zugewiesen – bitte zuerst einen Lehrer zuweisen.';
                subBody.appendChild(hint);
            }

            realClasses.forEach(cls => {
                const clsPanel = document.createElement('div');
                clsPanel.className = 'lg-panel';
                clsPanel.style.margin = '8px 0';

                const clsToggle = document.createElement('button');
                clsToggle.className = 'lg-panel-toggle';
                clsToggle.innerHTML =
                    `<span>${cls.classLabel}<span class="lg-sep"> – </span>${cls.teacherNames.length ? cls.teacherNames.join('; ') : '<em>kein Lehrer</em>'}</span>` +
                    `<span class="lg-chevron-btn"><img src="/imgs/chevron.svg" class="lg-chevron" alt=""></span>`;
                clsPanel.appendChild(clsToggle);

                const clsBody = document.createElement('div');
                clsBody.className = 'lg-panel-body';
                clsBody.style.cssText = 'height:0;overflow:hidden;padding:0 16px';
                clsPanel.appendChild(clsBody);

                const taskSetsList = document.createElement('div');
                taskSetsList.className = 'lg-tasksets-list';

                const handlers = {
                    onEdit: async (ts, name, maxPoints, isPassFail) => {
                        const resp = await fetch('/update-lg-taskset', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ tasksetId: ts.id, name, maxPoints, isPassFail }),
                        });
                        if (resp.ok) return null;
                        const data = await resp.json().catch(() => ({}));
                        return data.error || 'Fehler beim Speichern.';
                    },
                    onToggle: async (ts) => {
                        const resp = await fetch('/toggle-lg-taskset', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ tasksetId: ts.id }),
                        });
                        return resp.ok ? resp.json() : null;
                    },
                    onDelete: async (ts) => {
                        const resp = await fetch('/delete-lg-taskset', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ tasksetId: ts.id }),
                        });
                        return resp.ok;
                    },
                };

                const gsContainerCls = document.createElement('div');
                let currentScaleCls = cls.gradingScale ?? null;
                function refreshScaleCls() {
                    const mp = cls.taskSets.filter(ts => ts.active).reduce((s, ts) => s + ts.maxPoints, 0);
                    buildGradingScale(gsContainerCls, currentScaleCls, mp, t => {
                        currentScaleCls = t;
                        fetch('/save-lg-grading-scale', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ classId: cls.classId, subjectId: sub.subjectId, thresholds: t }),
                        });
                    });
                }
                handlers.onRefresh = refreshScaleCls;

                clsBody.appendChild(makePanelSection(
                    'Aufgaben',
                    'Hier können Sie Aufgabensets erstellen. Nur aktivierte Aufgaben erscheinen im Lernfortschritt der Schülerinnen und Schüler.'
                ));

                renderTaskSetRows(taskSetsList, cls.taskSets, handlers);
                clsBody.appendChild(taskSetsList);

                clsBody.appendChild(buildAddArea(async (name, maxPoints, isPassFail, errorEl) => {
                    const resp = await fetch('/create-lg-taskset', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            classId: cls.classId, subjectId: sub.subjectId,
                            name, maxPoints, isPassFail,
                        }),
                    });
                    if (!resp.ok) {
                        const err = await resp.json().catch(() => ({}));
                        errorEl.textContent = err.error || 'Fehler beim Speichern.';
                        errorEl.style.display = '';
                        return null;
                    }
                    const newTs = await resp.json();
                    cls.taskSets.push(newTs);
                    renderTaskSetRows(taskSetsList, cls.taskSets, handlers);
                    return newTs;
                }));

                clsBody.appendChild(makePanelSection(
                    'Notenvergabe',
                    'Hier können Sie die Notenvergabe durch Verschieben der Trennlinien festlegen. Die maximale Punktzahl ergibt sich aus allen aktivierten Aufgabensets.'
                ));
                clsBody.appendChild(gsContainerCls);

                refreshScaleCls();

                const clsSpacer = document.createElement('div');
                clsSpacer.style.height = '16px';
                clsBody.appendChild(clsSpacer);
                makeCollapsible(clsToggle, clsBody);
                subBody.appendChild(clsPanel);
            });

            makeCollapsible(subToggle, subBody);
            gradeBody.appendChild(subPanel);
        });

        makeCollapsible(gradeToggle, gradeBody);
        container.appendChild(gradePanel);
    });
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

(async function () {
    const container = document.getElementById('tasksets-list');
    const loading   = document.getElementById('tasks-loading');

    const resp = await fetch('/api/admin-tasksets');
    const grades = resp.ok ? await resp.json() : [];
    loading.style.display = 'none';

    if (grades.length === 0) {
        const p = document.createElement('p');
        p.className = 'lg-empty text-italic';
        p.textContent = 'Keine Lerngruppen vorhanden.';
        container.appendChild(p);
        return;
    }

    if (adminOnlyTasksets) {
        buildAdminOnlyView(grades, container);
    } else {
        buildPerClassView(grades, container);
    }
})();
</script>
</body>
</html>
