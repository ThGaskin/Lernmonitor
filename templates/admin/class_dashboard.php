<?php
$viewerRole = $viewerRole ?? 'admin';
$title      = 'Klassen-Dashboard';
$extraHead  = '<link rel="stylesheet" href="' . Config::asset('/css/class-dashboard.css') . '">
<script>window.classDashboardConfig = { viewerRole: ' . json_encode($viewerRole) . ' };</script>';
include __DIR__ . '/../partials/html-head.php';
?>
<body>
<section id="class-dashboard">

    <aside class="profile-sidebar blur-sm shadow-sm">

        <!-- Class info -->
        <div class="profile-card-name">
            <span class="profile-greeting text-lg">Klasse</span>
            <?php if ($viewerRole === 'teacher'): ?>
            <button id="class-name" type="button" class="dropdown-trigger text-xl text-bold">–</button>
            <?php else: ?>
            <span class="text-xl text-bold" id="class-name">–</span>
            <?php endif; ?>
        </div>
        <div class="profile-card-badges text-xs text-bold">
            <span class="profile-badge" id="class-grade-badge">–</span>
        </div>

        <div class="profile-sidebar-footer">
            <?php if ($viewerRole === 'admin'): ?>
            <button class="btn profile-sidebar-btn" onclick="window.location.href='/manage_classes'">
                <img src="/imgs/classroom_reversed.svg" alt="">Alle Klassen
            </button>
            <?php else: ?>
            <button class="btn profile-sidebar-btn" onclick="window.location.href='/dashboard'">
                <img src="/imgs/<?= $viewerRole === 'teacher' ? 'lehrer' : 'schueler' ?>.svg" alt="">Mein Dashboard
            </button>
            <?php endif; ?>
            <div class="profile-sidebar-brand">
                <img src="/imgs/logo.svg" alt="Lernmonitor">
                <span>Lernmonitor</span>
            </div>
        </div>

    </aside>

    <main class="dashboard-main-panel">

        <section class="card-section blur-lg" id="matrix-card">
            <button class="matrix-expand-btn" id="matrixExpandBtn" title="Vollbild">
                <svg class="icon-expand" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="15,3 21,3 21,9"/><line x1="21" y1="3" x2="14" y2="10"/>
                    <polyline points="9,21 3,21 3,15"/><line x1="3" y1="21" x2="10" y2="14"/>
                </svg>
                <svg class="icon-close" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
            <div class="card-header" style="display:flex;align-items:baseline;gap:20px">
                <?php if ($viewerRole === 'student'): ?>
                <span>Klassenübersicht</span>
                <?php else: ?>
                <button class="matrix-view-tab active" id="tabFach">Fachübersicht</button>
                <button class="matrix-view-tab" id="tabSchueler">Schülerübersicht</button>
                <?php endif; ?>
            </div>
            <div class="card-header-description" id="matrixDesc">Lernfortschritt aller Schüler nach Fach und Aufgabenset.</div>
            <div id="matrix-legend">
                <div class="matrix-legend-item"><div class="matrix-legend-disc" style="background:rgba(171,210,250,1)"></div>Begonnen</div>
                <div class="matrix-legend-item"><div class="matrix-legend-disc" style="background:rgba(255,216,160,1)"></div>Braucht Hilfe</div>
                <div class="matrix-legend-item"><div class="matrix-legend-disc" style="background:rgba(155,121,169,1)"></div>Sucht Partner</div>
                <div class="matrix-legend-item"><div class="matrix-legend-disc" style="background:rgba(156,214,187,1)"></div>Fertig</div>
                <div class="matrix-legend-item"><div class="matrix-legend-disc" style="background:var(--c-green)"></div>Bewertet</div>
            </div>
            <div id="fachView">
                <p id="matrix-empty" class="lg-empty text-italic" style="display:none">Keine aktiven Aufgabensets für diese Klasse.</p>
                <div id="matrixScroll" style="overflow: auto">
                    <div id="matrixGrid"></div>
                </div>
            </div>
            <div id="schuelerView" style="display:none">
                <div id="schuelerGrid"></div>
                <p id="schueler-empty" class="lg-empty text-italic" style="display:none">Keine aktiven Aufgabensets für diese Klasse.</p>
            </div>
        </section>
        <div id="matrix-backdrop"></div>

        <?php if ($viewerRole !== 'student'): ?>
        <section class="card-section blur-lg">
            <div class="card-header">Schüler</div>
            <div class="card-header-description">Alle Schüler dieser Klasse.</div>
            <div class="filter-bar">
                <input type="search" id="studentSearch" placeholder="Name<?= $viewerRole === 'admin' ? ' oder E-Mail' : '' ?> suchen …">
            </div>
            <table id="studentTable" class="gradient-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <?php if ($viewerRole === 'admin'): ?><th class="col-email">E-Mail</th><?php endif; ?>
                        <th data-graduation-label>Abschlussstufe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="studentTableBody"></tbody>
            </table>
            <div id="studentPagination" class="pagination-bar"></div>
        </section>
        <?php endif; ?>

    </main>

</section>

<script>
// ---------------------------------------------------------------------------
// Status colours — exact rgba values from colors.css
// ---------------------------------------------------------------------------
const STATUS_COLORS = {
    0: 'rgba(99,29,118,0.10)',   // lg-none:        faint purple
    1: 'rgba(171,210,250,1)',    // lg-in-progress: light blue
    2: 'rgba(156,214,187,1)',    // lg-submitted:   mint
    3: 'rgba(255,216,160,1)',    // lg-needs-help:  orange
    4: 'rgba(155,121,169,1)',    // lg-partner:      lavender
};
const COLOR_GRADED  = getComputedStyle(document.documentElement).getPropertyValue('--c-green').trim();
const STATUS_LABELS = { 0: 'Nicht begonnen', 1: 'In Bearbeitung', 2: 'Fe', 3: 'Braucht Hilfe', 4: 'Suche Partner' };

function discFill(status, graded) {
    return graded ? COLOR_GRADED
        : status === null ? 'rgba(160,174,192,0.15)'
        : (STATUS_COLORS[status] ?? STATUS_COLORS[0]);
}

function makeDisc(fill) {
    const d = document.createElement('div');
    d.style.cssText = `
        width:22px; height:22px; border-radius:50%; background:${fill};
        flex-shrink:0; overflow:hidden; white-space:nowrap; margin:5px 0;
        font-size:0; color:rgba(255,255,255,0.95); font-weight:600;
        display:flex; align-items:center; justify-content:center;
        transition:width .18s,height .18s,border-radius .18s,font-size .12s,padding .18s,margin .18s;
        box-sizing:border-box; pointer-events:none; text-overflow:ellipsis;
    `;
    return d;
}

// ---------------------------------------------------------------------------
// Matrix (CSS grid: rows = task sets, columns = students)
// ---------------------------------------------------------------------------

// ── Status popover (teacher/admin in expanded view) ────────────────────────
let _activePopover = null;
let _activePopoverCell = null;
let _collapseHoveredCol = null;
let _matrixData = null;
let _roomsData = null;
function _closePopover() {
    if (!_activePopover) return;
    _activePopover.remove();
    _activePopover = null;
    if (_activePopoverCell?._disc) _activePopoverCell._disc.style.outline = '';
    _activePopoverCell = null;
    if (_collapseHoveredCol) _collapseHoveredCol();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') _closePopover(); });

function _openStatusPopover(cell) {
    if (_activePopoverCell === cell) { _closePopover(); return; }
    _closePopover();
    const entry = cell._entry;
    if (!entry) return;

    const pop = document.createElement('div');
    pop.className = 'dropdown';
    pop.style.cssText = 'position:fixed;z-index:2000;min-width:140px';

    [{ label: 'Beginnen', status: 1 }, { label: 'Braucht Hilfe', status: 3 }, { label: 'Sucht Partner', status: 4 }, { label: 'Fertig', status: 2 }]
        .forEach(({ label, status }) => {
            const btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'dropdown-option' + (entry.status === status ? ' dropdown-option--active' : '');
            btn.textContent = label;
            btn.style.setProperty('--option-status-color', STATUS_COLORS[status] ?? '');
            btn.addEventListener('click', async ev => {
                ev.stopPropagation();
                const newStatus = entry.status === status ? 0 : status;
                const resp = await post('/admin/set-student-task-status', {
                    studentId: cell._studentId, taskSetId: entry.taskSetId, status: newStatus,
                });
                if (resp.ok) {
                    entry.status = newStatus;
                    _refreshCellDisc(cell);
                    _closePopover();
                    if (_matrixData) renderStudentOverview(_matrixData, _roomsData ?? []);
                }
            });
            pop.appendChild(btn);
        });

    document.body.appendChild(pop);
    _activePopover = pop;
    _activePopoverCell = cell;

    requestAnimationFrame(() => {
        const MARGIN = 8, GAP = 4;
        const r     = cell.getBoundingClientRect();
        const popW  = pop.offsetWidth;
        const popH  = pop.offsetHeight;

        // Vertical: default below the cell; flip above if it would overflow the bottom edge
        let top = (r.bottom + GAP + popH <= window.innerHeight - MARGIN)
            ? r.bottom + GAP
            : r.top - GAP - popH;
        top = Math.max(MARGIN, Math.min(top, window.innerHeight - popH - MARGIN));

        // Horizontal: default left-aligned to the cell; flip to right-aligned if it would overflow the right edge
        let left = (r.left + popW <= window.innerWidth - MARGIN)
            ? r.left
            : r.right - popW;
        left = Math.max(MARGIN, Math.min(left, window.innerWidth - popW - MARGIN));

        pop.style.top  = top + 'px';
        pop.style.left = left + 'px';
        pop.classList.add('dropdown--visible');
    });

    setTimeout(() => {
        document.addEventListener('click', _closePopover, { once: true });
        window.addEventListener('scroll', _closePopover, { once: true, capture: true });
    }, 0);
}

function _refreshCellDisc(cell) {
    const e = cell._entry;
    const graded = e && e.achievedPoints !== null && e.achievedPoints !== undefined;
    const status = e?.status ?? null;
    if (cell._disc) cell._disc.style.background = discFill(status, graded);
    cell.title = status === null
        ? `${cell._studentName} — nicht eingeschrieben`
        : graded
            ? `${cell._studentName} — Bewertet (${e.achievedPoints}/${e.maxPoints})`
            : `${cell._studentName} — ${STATUS_LABELS[status]}`;
}

function renderMatrix(data, viewerRole) {
    const interactive = viewerRole === 'teacher' || viewerRole === 'admin';
    const card = document.getElementById('matrix-card');

    const { subjects, students } = data;
    const grid = document.getElementById('matrixGrid');
    grid.innerHTML = '';

    if (subjects.length === 0 || students.length === 0) {
        document.getElementById('matrix-empty').style.display = '';
        return;
    }

    const N         = students.length;
    const LABEL_W   = 160;   // px — task-set name column
    const availW    = Math.max(0, (card.clientWidth || 640) - 48); // 48 = card padding 24px×2
    const COL_W     = Math.min(60, Math.max(30, N > 0 ? Math.floor((availW - LABEL_W) / N) : 60));
    const HEADER_H  = 100;   // px — height of rotated name headers
    const ROW_BORDER = '1px solid rgba(110,126,147,0.12)';
    const colTemplate = `${LABEL_W}px repeat(${N}, ${COL_W}px)`;

    // Track cells per column for hover highlight
    const colCells = Array.from({ length: N }, () => []);
    const expandedColTemplate = `${LABEL_W}px repeat(${N}, 1fr)`;
    let _hoveredCol = -1;

    function highlightCol(colIdx, on) {
        const isExpanded = card.classList.contains('expanded');
        colCells[colIdx]?.forEach(c => {
            if (!c._disc) return;
            if (on && isExpanded) {
                const graded = c._entry && c._entry.achievedPoints !== null && c._entry.achievedPoints !== undefined;
                c._disc.style.width        = 'calc(100% - 6px)';
                c._disc.style.height       = '32px';
                c._disc.style.margin       = '0';
                c._disc.style.borderRadius = '10px';
                c._disc.style.fontSize     = '0.8rem';
                c._disc.style.color        = (graded || c._entry?.status === 4) ? 'rgba(255,255,255,0.95)' : 'var(--c-mediumgrey)';
                c._disc.style.padding      = '0 5px';
            } else {
                c._disc.style.width        = '22px';
                c._disc.style.height       = '22px';
                c._disc.style.margin       = '5px 0';
                c._disc.style.borderRadius = '50%';
                c._disc.style.fontSize     = '0';
                c._disc.style.padding      = '0';
            }
        });
        if (!isExpanded) return;
        const headerCell = colCells[colIdx]?.[0];
        if (headerCell?._nameEl) {
            headerCell._nameEl.style.transform = on ? 'rotate(-60deg) scale(1.3)' : 'rotate(-60deg) scale(1)';
        }
        const cols = Array.from({ length: N }, (_, i) => i === colIdx && on ? '1.5fr' : '1fr');
        const t = `${LABEL_W}px ${cols.join(' ')}`;
        grid.style.gridTemplateColumns = t;
    }

    grid.style.cssText = `
        display: grid;
        grid-template-columns: ${colTemplate};
        width: fit-content;
        transition: grid-template-columns 0.2s ease;
    `;
    if (interactive) {
        const collapseCol = () => {
            if (_hoveredCol >= 0) { highlightCol(_hoveredCol, false); _hoveredCol = -1; }
        };
        _collapseHoveredCol = collapseCol;
        grid.addEventListener('mouseleave', () => {
            if (_activePopover) return;
            collapseCol();
        });
    }

    // ── Header row: blank corner + rotated student names ──────────────────
    // Single sticky wrapper spanning all columns so the whole row sticks together.
    const headerRow = document.createElement('div');
    headerRow.id = 'matrixHeaderRow';
    headerRow.style.cssText = `
        display: grid;
        grid-column: 1 / -1;
        grid-template-columns: subgrid;
    `;
    grid.appendChild(headerRow);

    const corner = document.createElement('div');
    corner.style.cssText = `height:${HEADER_H}px; border-bottom: ${ROW_BORDER};`;
    headerRow.appendChild(corner);

    // Max text width before rotation: available vertical space / sin(60°)
    const MAX_NAME_W = Math.floor((HEADER_H - 15) / Math.sin(Math.PI / 3));
    const _nameProbe = document.createElement('span');
    _nameProbe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font-size:0.72rem;font-weight:600;';
    document.body.appendChild(_nameProbe);
    function truncateName(firstName, lastInitial) {
        const suffix = ` ${lastInitial}`;
        _nameProbe.textContent = firstName + suffix;
        if (_nameProbe.scrollWidth <= MAX_NAME_W) return firstName + suffix;
        let len = firstName.length - 1;
        while (len > 0) {
            _nameProbe.textContent = firstName.slice(0, len) + '…' + suffix;
            if (_nameProbe.scrollWidth <= MAX_NAME_W) return firstName.slice(0, len) + '…' + suffix;
            len--;
        }
        return lastInitial;
    }

    students.forEach((s, colIdx) => {
        const cell = document.createElement('div');
        cell.style.cssText = `position:relative; height:${HEADER_H}px; overflow:visible; border-bottom:${ROW_BORDER};`;
        colCells[colIdx].push(cell);

        if (interactive) {
            cell.addEventListener('mouseenter', () => {
                if (_hoveredCol === colIdx) return;
                if (_hoveredCol >= 0) highlightCol(_hoveredCol, false);
                _hoveredCol = colIdx;
                highlightCol(colIdx, true);
            });
        }

        const anchor = document.createElement('div');
        anchor.style.cssText = 'position:absolute; bottom:10px; left:50%; width:0; overflow:visible;';

        const name = document.createElement('span');
        name.textContent = truncateName(s.firstName, `${s.lastName[0]}.`);
        name.style.cssText = `
            display: block;
            white-space: nowrap;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--c-darkgrey);
            transform: rotate(-60deg);
            transform-origin: left bottom;
            transition: transform 0.15s, color 0.15s;
        `;
        cell._nameEl = name;
        anchor.appendChild(name);
        cell.appendChild(anchor);
        headerRow.appendChild(cell);
    });
    document.body.removeChild(_nameProbe);

    // ── Subject groups ─────────────────────────────────────────────────────
    subjects.forEach((sub, subIdx) => {
        const subHeader = document.createElement('div');
        subHeader.className = 'matrix-subject-header';
        subHeader.style.cssText = `
            grid-column: 1 / -1;
            padding: 12px 0 4px;
            ${subIdx > 0 ? 'margin-top: 24px;' : ''}
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--c-darkgrey);
            border-top: 1.5px solid rgba(110,126,147,0.2);
        `;

        const toggle = document.createElement('div');
        toggle.className = 'matrix-subject-toggle';

        if (sub.color) {
            const dot = document.createElement('span');
            dot.style.cssText = `
                display: inline-block;
                width: 1rem;
                height: 1rem;
                border-radius: 50%;
                background: ${sub.color};
                flex-shrink: 0;
            `;
            toggle.appendChild(dot);
        }
        toggle.appendChild(document.createTextNode(sub.name));

        const chevronImg = document.createElement('img');
        chevronImg.src = '/imgs/chevron.svg';
        chevronImg.className = 'matrix-sub-chevron open';
        chevronImg.alt = '';
        toggle.appendChild(chevronImg);

        subHeader.appendChild(toggle);
        grid.appendChild(subHeader);

        const rowsWrapper = document.createElement('div');
        rowsWrapper.style.cssText = `
            display: grid;
            grid-column: 1 / -1;
            grid-template-columns: subgrid;
            overflow: hidden;
            transition: height 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        `;
        grid.appendChild(rowsWrapper);

        let subOpen = true;
        let _toggling = false;
        toggle.addEventListener('click', () => {
            if (_toggling) return;
            subOpen = !subOpen;
            chevronImg.classList.toggle('open', subOpen);
            _toggling = true;
            if (subOpen) {
                const targetH = rowsWrapper.scrollHeight;
                rowsWrapper.style.height = targetH + 'px';
                rowsWrapper.addEventListener('transitionend', () => {
                    rowsWrapper.style.height = 'auto';
                    _toggling = false;
                }, { once: true });
            } else {
                rowsWrapper.style.height = rowsWrapper.scrollHeight + 'px';
                requestAnimationFrame(() => {
                    rowsWrapper.style.height = '0';
                    rowsWrapper.addEventListener('transitionend', () => {
                        _toggling = false;
                    }, { once: true });
                });
            }
        });

        sub.taskSets.forEach((ts, i) => {
            const isLast   = i === sub.taskSets.length - 1;
            const border   = isLast ? 'none' : ROW_BORDER;

            const label = document.createElement('div');
            label.textContent = ts.name;
            label.title       = ts.name;
            label.style.cssText = `
                display: flex;
                align-items: center;
                padding: 4px 12px 4px 0;
                font-size: 0.80rem;
                color: var(--c-darkgrey);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                border-bottom: ${border};
                box-sizing: border-box;
            `;
            rowsWrapper.appendChild(label);

            students.forEach((student, colIdx) => {
                const entry  = student.cells[sub.id]?.find(t => t.taskSetId === ts.id);
                const status = entry?.status ?? null;
                const graded = entry?.achievedPoints !== null && entry?.achievedPoints !== undefined;
                const canEdit = interactive && entry && !graded;

                const cell = document.createElement('div');
                cell._entry       = entry ?? null;
                cell._studentId   = student.studentId;
                cell._studentName = `${student.firstName} ${student.lastName}`;
                cell.title = status === null
                    ? `${student.firstName} ${student.lastName} — nicht eingeschrieben`
                    : graded
                        ? `${student.firstName} ${student.lastName} — Bewertet (${entry.achievedPoints}/${entry.maxPoints})`
                        : `${student.firstName} ${student.lastName} — ${STATUS_LABELS[status]}`;
                cell.style.cssText = `
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 4px 3px;
                    border-bottom: ${border};
                    transition: background-color 0.12s;
                `;
                if (canEdit) cell.classList.add('matrix-edit-cell');

                const disc = makeDisc(discFill(status, graded));
                disc.textContent = ts.name;
                cell._disc = disc;
                cell.appendChild(disc);
                colCells[colIdx].push(cell);

                if (interactive) {
                    cell.addEventListener('mouseenter', () => {
                        if (cell._disc && card.classList.contains('expanded'))
                            cell._disc.style.outline = '2px solid rgba(110,126,147,0.4)';
                        if (_hoveredCol === colIdx) return;
                        if (_activePopover) _closePopover();
                        if (_hoveredCol >= 0) highlightCol(_hoveredCol, false);
                        _hoveredCol = colIdx;
                        highlightCol(colIdx, true);
                    });
                    cell.addEventListener('mouseleave', () => {
                        if (cell._disc && cell !== _activePopoverCell) cell._disc.style.outline = '';
                    });
                }
                if (canEdit) {
                    cell.addEventListener('click', e => {
                        if (!card.classList.contains('expanded')) return;
                        e.stopPropagation();
                        _openStatusPopover(cell);
                    });
                }

                rowsWrapper.appendChild(cell);
            });
        });
    });
}

// ---------------------------------------------------------------------------
// Schülerübersicht (rows = students, columns = subjects, cells = active tasks)
// ---------------------------------------------------------------------------

function renderStudentOverview(data, rooms = []) {
    const { subjects, students } = data;
    function roomOptionsFor(s) {
        const available = rooms.filter(r => Number(r.minimumLevel) <= Number(s.graduationLevel));
        return [
            { value: '', label: window.defaultRoom || 'kein Raum', studentCount: null },
            ...available.map(r => ({ value: r.label, label: r.label, studentCount: r.studentCount ?? null })),
        ];
    }
    const container = document.getElementById('schuelerGrid');
    const emptyEl   = document.getElementById('schueler-empty');
    container.innerHTML = '';

    if (!students.length || !subjects.length) {
        emptyEl.style.display = '';
        return;
    }
    emptyEl.style.display = 'none';

    const table = document.createElement('table');
    table.className = 'matrix-table';
    table.style.width = '100%';

    // Header
    const thead = document.createElement('thead');
    const hr    = document.createElement('tr');
    const thName = document.createElement('th');
    hr.appendChild(thName);
    subjects.forEach(sub => {
        const th = document.createElement('th');
        th.style.whiteSpace = 'nowrap';
        const thInner = document.createElement('div');
        thInner.style.cssText = 'display:flex;align-items:center;gap:6px';
        if (sub.color) {
            const dot = document.createElement('span');
            dot.style.cssText = `display:inline-block;width:1rem;height:1rem;border-radius:50%;background:${sub.color};flex-shrink:0`;
            thInner.appendChild(dot);
        }
        thInner.appendChild(document.createTextNode(sub.name));
        th.appendChild(thInner);
        hr.appendChild(th);
    });
    thead.appendChild(hr);
    table.appendChild(thead);

    // Rows
    let _openRoomDd = null;
    let _openRoomBadge = null;
    function closeRoomDd() {
        if (!_openRoomDd) return;
        _openRoomDd.remove();
        _openRoomDd = null;
        _openRoomBadge = null;
    }

    const tbody = document.createElement('tbody');
    students.forEach(s => {
        const tr = document.createElement('tr');

        const tdName = document.createElement('td');
        tdName.style.cssText = 'font-weight:600;white-space:nowrap;font-size:0.72rem;color:var(--c-darkgrey);';
        const nameSpan = document.createElement('span');
        nameSpan.textContent = `${s.firstName} ${s.lastName[0]}.`;
        tdName.appendChild(nameSpan);

        const badge = document.createElement('span');
        badge.className = 'room-badge';
        badge.textContent = s.currentRoom || window.defaultRoom || 'kein Raum';
        const hasRoom = s.currentRoom != null && s.currentRoom !== '';
        badge.style.cssText = 'display:inline-block;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:500;margin-left:7px;vertical-align:middle;' +
            (hasRoom ? 'background:rgba(110,126,147,0.15);color:inherit;' : 'background:transparent;border:1px dashed rgba(110,126,147,0.4);color:rgba(110,126,147,0.6);');
        tdName.appendChild(badge);

        badge.addEventListener('click', e => {
            e.stopPropagation();
            if (_openRoomBadge === badge) { closeRoomDd(); return; }
            closeRoomDd();
            const dd = document.createElement('div');
            dd.className = 'dropdown';
            _openRoomDd   = dd;
            _openRoomBadge = badge;
            const roomOptions = roomOptionsFor(s);
            roomOptions.forEach(o => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dropdown-option dropdown-option--spread' + (o.value === (s.currentRoom ?? '') ? ' dropdown-option--active' : '');
                if (o.studentCount !== null) {
                    btn.innerHTML = `<span>${o.label}</span><span class="dropdown-room-count"><img src="/imgs/person_grey.svg" alt="" width="12" height="12"> ${o.studentCount}</span>`;
                } else {
                    btn.textContent = o.label;
                }
                btn.addEventListener('click', async ev => {
                    ev.stopPropagation();
                    closeRoomDd();
                    const prevRoom = s.currentRoom ?? '';
                    if (prevRoom === o.value) return;
                    const resp = await post('/update-room', { studentId: s.studentId, room: o.value });
                    if (!resp.ok) {
                        const err = await resp.json().catch(() => ({}));
                        alert(err.error || 'Fehler beim Speichern.');
                        return;
                    }
                    const prev = rooms.find(r => r.label === prevRoom);
                    const next = rooms.find(r => r.label === o.value);
                    if (prev?.studentCount != null) prev.studentCount--;
                    if (next?.studentCount != null) next.studentCount++;
                    s.currentRoom = o.value || null;
                    badge.textContent = o.value || window.defaultRoom || 'kein Raum';
                    const nowHasRoom = !!o.value;
                    badge.style.cssText = 'display:inline-block;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:500;margin-left:7px;vertical-align:middle;' +
                        (nowHasRoom ? 'background:rgba(110,126,147,0.15);color:inherit;' : 'background:transparent;border:1px dashed rgba(110,126,147,0.4);color:rgba(110,126,147,0.6);');
                });
                dd.appendChild(btn);
            });
            dd.style.position = 'fixed';
            dd.style.minWidth = '200px';
            document.body.appendChild(dd);

            requestAnimationFrame(() => {
                const MARGIN = 8, GAP = 4;
                const br  = badge.getBoundingClientRect();
                const ddW = dd.offsetWidth;
                const ddH = dd.offsetHeight;

                // Vertical: default below the pill; flip above if it would overflow the bottom edge
                let top = (br.bottom + GAP + ddH <= window.innerHeight - MARGIN)
                    ? br.bottom + GAP
                    : br.top - GAP - ddH;
                top = Math.max(MARGIN, Math.min(top, window.innerHeight - ddH - MARGIN));

                // Horizontal: default left-aligned to the pill; flip to right-aligned if it would overflow the right edge
                let left = (br.left + ddW <= window.innerWidth - MARGIN)
                    ? br.left
                    : br.right - ddW;
                left = Math.max(MARGIN, Math.min(left, window.innerWidth - ddW - MARGIN));

                dd.style.top    = top + 'px';
                dd.style.left   = left + 'px';
                dd.style.bottom = '';
                dd.classList.add('dropdown--visible');
            });

            setTimeout(() => {
                document.addEventListener('click', closeRoomDd, { once: true });
                window.addEventListener('scroll', closeRoomDd, { once: true, capture: true });
            }, 0);
        });
        tr.appendChild(tdName);

        subjects.forEach(sub => {
            const td    = document.createElement('td');
            td.style.cssText = 'padding:4px 8px;';
            const tasks = (s.cells[sub.id] ?? []).filter(t => t.status === 1 || t.status === 3 || t.status === 4);
            tasks.forEach(t => {
                const pill = document.createElement('span');
                pill.textContent = t.name;
                pill.title       = t.name;
                pill.style.cssText = `display:inline-block;padding:2px 8px;margin:2px;border-radius:12px;background:${STATUS_COLORS[t.status]};font-size:0.72rem;font-weight:600;white-space:nowrap;max-width:160px;overflow:hidden;text-overflow:ellipsis;vertical-align:middle;${t.status === 4 ? 'color:white;' : ''}`;
                td.appendChild(pill);
            });
            tr.appendChild(td);
        });

        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    container.appendChild(table);
}

// ---------------------------------------------------------------------------
// Matrix tab switcher
// ---------------------------------------------------------------------------

function switchMatrixTab(tab) {
    const isFach = tab === 'fach';
    document.getElementById('fachView').style.display     = isFach ? '' : 'none';
    document.getElementById('schuelerView').style.display = isFach ? 'none' : '';
    document.getElementById('tabFach').classList.toggle('active', isFach);
    document.getElementById('tabSchueler').classList.toggle('active', !isFach);
    document.getElementById('matrixDesc').textContent = isFach
        ? 'Lernfortschritt aller Schüler nach Fach und Aufgabenset.'
        : 'Aktuelle Aufgaben und Raum aller Schüler nach Fach.';
}

// ---------------------------------------------------------------------------
// Student table (admin: firstName/lastName/email; teacher: name only)
// ---------------------------------------------------------------------------

function renderStudentTable(students, viewerRole) {
    const LEVELS    = window.graduationConfig?.levels ?? ['Neustarter', 'Starter', 'Durchstarter', 'Lernprofi'];
    const PAGE_SIZE = 10;
    let currentPage = 1;
    const showEmail = viewerRole === 'admin';

    const tbody    = document.getElementById('studentTableBody');
    const searchEl = document.getElementById('studentSearch');

    function fullName(s) {
        return showEmail ? `${s.firstName} ${s.lastName}` : s.name;
    }

    function getFiltered() {
        const search = searchEl.value.toLowerCase();
        if (!search) return students;
        return students.filter(s => {
            const n = fullName(s).toLowerCase();
            return n.includes(search) || (showEmail && s.email.toLowerCase().includes(search));
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
            const nameTd  = `<td>${fullName(s)}</td>`;
            const emailTd = showEmail ? `<td class="col-email">${s.email}</td>` : '';
            const levelTd = `<td>${LEVELS[s.graduationLevel] ?? s.graduationLevel}</td>`;
            const actTd   = `<td class="row-actions"><button class="btn-s btn-profile" aria-label="Profil" style="background:var(--student-gradient);color:#fff;border:none"><img src="/imgs/profile.svg" alt=""><span class="btn-label">Profil</span></button></td>`;
            tr.innerHTML  = nameTd + emailTd + levelTd + actTd;
            tr.querySelector('.btn-profile').addEventListener('click', () => {
                window.location.href = `/student-profile?id=${s.id}`;
            });
            tbody.appendChild(tr);
        });

        renderPagination('studentPagination', totalPages, filtered.length, currentPage,
            p => { currentPage = p; render(); }, 'Schüler');
    }

    searchEl.addEventListener('input', () => { currentPage = 1; render(); });
    render();
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

(async function () {
    const { viewerRole } = window.classDashboardConfig;

    // ── Student: locked to own class, matrix only ──────────────────────────
    if (viewerRole === 'student') {
        const [classResp, matrixResp] = await Promise.all([
            fetch('/myclasses'),
            fetch('/api/my-class-task-matrix'),
        ]);
        const classes = await classResp.json();
        const matrix  = await matrixResp.json();
        const cls = classes[0];
        if (cls) {
            document.getElementById('class-name').textContent        = cls.label;
            document.getElementById('class-grade-badge').textContent = `Stufe ${cls.grade}`;
            document.title = `Klasse ${cls.label}`;
        }
        renderMatrix(matrix, viewerRole);
        // hide tabs for student self-view
        document.getElementById('tabFach').style.display     = 'none';
        document.getElementById('tabSchueler').style.display = 'none';
        return;
    }

    // ── Admin / Teacher: class switcher ────────────────────────────────────
    // Both admins and teachers see all classes — teachers may cover any class as a substitute.
    const allClasses = await fetch('/classes').then(r => r.json());

    const params  = new URLSearchParams(location.search);
    let classId   = params.get('id') ?? '';

    if (!classId && allClasses.length > 0) {
        classId = allClasses[0].id;
        history.replaceState(null, '', `/class-dashboard?id=${classId}`);
    }
    if (!classId) return;

    // Teacher class-switcher dropdown on the class name
    if (viewerRole === 'teacher') {
        const trigger = document.getElementById('class-name');
        let classDropdownEl = null;

        function closeClassDropdown() {
            if (!classDropdownEl) return;
            const el = classDropdownEl;
            classDropdownEl = null;
            el.style.pointerEvents = 'none';
            el.classList.remove('dropdown--visible');
            el.addEventListener('transitionend', () => el.remove(), { once: true });
        }

        function openClassDropdown() {
            if (classDropdownEl) { closeClassDropdown(); return; }
            classDropdownEl = document.createElement('div');
            classDropdownEl.className = 'dropdown';
            document.body.appendChild(classDropdownEl);

            allClasses.forEach(c => {
                const btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'dropdown-option' + (c.id == classId ? ' dropdown-option--active' : '');
                btn.textContent = `${c.label} (Stufe ${c.grade})`;
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    window.location.href = `/class-dashboard?id=${c.id}`;
                });
                classDropdownEl.appendChild(btn);
            });

            const r = trigger.getBoundingClientRect();
            classDropdownEl.style.position = 'fixed';
            classDropdownEl.style.left     = r.left + 'px';
            classDropdownEl.style.top      = (r.bottom + 8) + 'px';
            classDropdownEl.style.minWidth = Math.max(r.width, 180) + 'px';

            requestAnimationFrame(() => classDropdownEl?.classList.add('dropdown--visible'));
            setTimeout(() => {
                document.addEventListener('click', closeClassDropdown, { once: true });
                window.addEventListener('scroll', closeClassDropdown, { once: true, capture: true });
            }, 0);
        }

        trigger.addEventListener('click', e => { e.stopPropagation(); openClassDropdown(); });
    }

    // Fetch matrix, student list, and rooms in parallel
    const [matrixResp, studentsResp, roomsResp] = await Promise.all([
        fetch(`/api/class-task-matrix?id=${classId}`),
        viewerRole === 'admin'
            ? fetch('/students')
            : fetch('/student-list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ classId }),
              }),
        fetch('/rooms'),
    ]);
    const matrix   = await matrixResp.json();
    const students = await studentsResp.json();
    const rooms    = await roomsResp.json();

    const cls = allClasses.find(c => c.id == classId);
    if (cls) {
        document.getElementById('class-name').textContent        = cls.label;
        document.getElementById('class-grade-badge').textContent = `Stufe ${cls.grade}`;
        document.title = `Klasse ${cls.label}`;
    }

    _matrixData = matrix;
    _roomsData  = rooms;
    renderMatrix(matrix, viewerRole);
    renderStudentOverview(matrix, rooms);
    document.getElementById('tabFach').addEventListener('click',     () => switchMatrixTab('fach'));
    document.getElementById('tabSchueler').addEventListener('click', () => switchMatrixTab('schueler'));

    const classStudents = viewerRole === 'admin'
        ? students.filter(s => s.classId == classId)
        : students;
    renderStudentTable(classStudents, viewerRole);
})();

// ── Matrix expand / collapse ──────────────────────────────────────────────
(function () {
    const btn      = document.getElementById('matrixExpandBtn');
    const card     = document.getElementById('matrix-card');
    const backdrop = document.getElementById('matrix-backdrop');


    function setGridColumns(cols, width) {
        const grid = document.getElementById('matrixGrid');
        if (!grid) return;
        grid.style.gridTemplateColumns = cols;
        grid.style.width               = width;
    }

    let _origParent      = null;
    let _origNextSibling = null;

    function expand() {
        // Move card to <body> to escape any ancestor transform/backdrop-filter
        // that would break position:fixed containment
        _origParent      = card.parentNode;
        _origNextSibling = card.nextSibling;
        document.body.appendChild(card);

        card.classList.add('expanded');
        backdrop.classList.add('visible');
        btn.querySelector('.icon-expand').style.display = 'none';
        btn.querySelector('.icon-close').style.display  = '';
        btn.title = 'Schließen';
        document.body.style.overflow = 'hidden';
        const grid = document.getElementById('matrixGrid');
        if (grid && grid.style.gridTemplateColumns) {
            const orig     = grid.style.gridTemplateColumns;
            const expanded = orig.replace(/repeat\((\d+), \d+px\)/, 'repeat($1, 1fr)');
            grid.dataset.origColumns = orig;
            setGridColumns(expanded, '100%');
        }
    }

    function collapse() {
        card.classList.remove('expanded');
        backdrop.classList.remove('visible');
        btn.querySelector('.icon-expand').style.display = '';
        btn.querySelector('.icon-close').style.display  = 'none';
        btn.title = 'Vollbild';
        document.body.style.overflow = '';
        // Fire synthetic leave on the grid so renderMatrix can reset any hovered column
        document.getElementById('matrixGrid')?.dispatchEvent(new MouseEvent('mouseleave'));
        const grid = document.getElementById('matrixGrid');
        if (grid && grid.dataset.origColumns) {
            setGridColumns(grid.dataset.origColumns, 'fit-content');
            delete grid.dataset.origColumns;
        }
        // Move card back to its original position in the DOM
        if (_origParent) {
            _origParent.insertBefore(card, _origNextSibling);
            _origParent      = null;
            _origNextSibling = null;
        }
    }

    btn.addEventListener('click', () => card.classList.contains('expanded') ? collapse() : expand());
    backdrop.addEventListener('click', collapse);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && card.classList.contains('expanded')) collapse(); });
})();
</script>
</body>
</html>
