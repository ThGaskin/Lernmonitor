// Shared grading-scale helpers used by the teacher/admin scale editors
// (teacher-view.js, manage_tasks.php) and the student projection tool
// (student-view.js). Grading scales are stored as fractions of maxPoints
// so the same scale can be reused across classes/task sets with different
// point totals; these convert between that stored fraction form and the
// absolute point thresholds used for display and grade computation.

function roundThreshold(v) {
    // Thresholds only ever display with one decimal (fmtThreshold), so they
    // must also be stored rounded to one decimal -- otherwise a value can
    // display as e.g. "4.0" while actually being stored as 4.03, silently
    // shifting the grade boundary away from what the label shows.
    return Math.round(v * 10) / 10;
}

function fracToAbs(scale, maxPoints) {
    if (!scale) return null;
    return scale[0] < 1 ? scale.map(f => roundThreshold(f * maxPoints)) : [...scale];
}

function absToFrac(thresholds, maxPoints) {
    return thresholds.map(t => t / maxPoints);
}

function computeGrade(score, thresholds) {
    if (!thresholds) return null;
    if (score >= thresholds[4]) return 1;
    if (score >= thresholds[3]) return 2;
    if (score >= thresholds[2]) return 3;
    if (score >= thresholds[1]) return 4;
    if (score >= thresholds[0]) return 5;
    return 6;
}
