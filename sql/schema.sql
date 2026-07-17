-- student-database MySQL schema
-- Translated from SQLite; utf8mb4 for full Unicode (emoji, etc.) support
-- Note: students use externally-assigned IDs (bulk import); teachers auto-increment


CREATE TABLE IF NOT EXISTS classes (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    grade INT NOT NULL,
    UNIQUE (label, grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rooms must exist before students (students.current_room FK references rooms.label)
CREATE TABLE IF NOT EXISTS rooms (
    label         VARCHAR(100) PRIMARY KEY,
    minimum_level INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- students: ID is assigned externally (from school administration system)
CREATE TABLE IF NOT EXISTS students (
    id             INT PRIMARY KEY,
    first_name     VARCHAR(100) NOT NULL,
    last_name      VARCHAR(100) NOT NULL,
    email          VARCHAR(255) NOT NULL UNIQUE,
    password       VARCHAR(255) NULL DEFAULT NULL,
    benutzername   VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    class          INT NOT NULL,
    graduation_level INT NOT NULL,
    current_room   VARCHAR(100) NULL DEFAULT NULL,
    UNIQUE KEY unique_benutzername (benutzername),
    FOREIGN KEY (class) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (current_room) REFERENCES rooms(label) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    username      VARCHAR(100) PRIMARY KEY,
    password_hash VARCHAR(255) NULL DEFAULT NULL,
    email         VARCHAR(255) NULL DEFAULT NULL UNIQUE,
    benutzername  VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    first_name    VARCHAR(100) NULL DEFAULT NULL,
    last_name     VARCHAR(100) NULL DEFAULT NULL,
    UNIQUE KEY unique_benutzername (benutzername)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teachers (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    first_name           VARCHAR(100) NOT NULL,
    last_name            VARCHAR(100) NOT NULL,
    email                VARCHAR(255) UNIQUE NOT NULL,
    password             VARCHAR(255) NULL DEFAULT NULL,
    benutzername         VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    current_room         VARCHAR(100) NULL DEFAULT NULL,
    linked_admin_username VARCHAR(100) NULL DEFAULT NULL,
    UNIQUE KEY unique_benutzername (benutzername),
    FOREIGN KEY (current_room) REFERENCES rooms(label) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (linked_admin_username) REFERENCES admins(username) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    color      VARCHAR(7) NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A Lerngruppe is a class+subject combination taught by a teacher, e.g. "5A – Mathe"
CREATE TABLE IF NOT EXISTS teacher_lerngruppen (
    teacher_id INT NOT NULL,
    class_id   INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (teacher_id, class_id, subject_id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Convenience views over teacher_lerngruppen for per-teacher class/subject lookups
CREATE OR REPLACE VIEW teacher_classes AS
    SELECT DISTINCT teacher_id, class_id FROM teacher_lerngruppen;

CREATE OR REPLACE VIEW teacher_subjects AS
    SELECT DISTINCT teacher_id, subject_id FROM teacher_lerngruppen;

-- Which subjects a student is enrolled in. Gates task-set/grade visibility
-- on the student's own dashboard and the admin's profile view of them (see
-- LerngruppeRepository::getTaskSetsForStudent/getPerformanceForStudent) --
-- lets e.g. individual students be excused from a subject (such as one of
-- several parallel religious-education tracks) without touching task data.
CREATE TABLE IF NOT EXISTS student_topics (
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (student_id, subject_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Class-subject assignments ────────────────────────────────────────────────
-- Tracks which subjects are assigned to a class for auto-enrollment of new students.

CREATE TABLE IF NOT EXISTS class_subjects (
    class_id   INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (class_id, subject_id),
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Parent accounts ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS parents (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL UNIQUE,
    password     VARCHAR(255) NULL DEFAULT NULL,
    benutzername VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    UNIQUE KEY unique_benutzername (benutzername)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many: one parent can have multiple children; one child can have multiple parents
CREATE TABLE IF NOT EXISTS parent_student (
    parent_id  INT NOT NULL,
    student_id INT NOT NULL,
    PRIMARY KEY (parent_id, student_id),
    FOREIGN KEY (parent_id)  REFERENCES parents(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Key-value settings store ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lerngruppen task sets ────────────────────────────────────────────────────
-- class_id IS NULL for grade-level task sets shared across all classes in a grade.

CREATE TABLE IF NOT EXISTS lg_tasksets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    class_id    INT NULL,
    subject_id  INT NOT NULL,
    grade       INT NOT NULL DEFAULT 0,
    name        VARCHAR(255) NOT NULL,
    max_points  INT NOT NULL DEFAULT 1,
    active       TINYINT(1)  NOT NULL DEFAULT 0,
    school_year  VARCHAR(9)  NOT NULL DEFAULT '',
    is_pass_fail TINYINT(1)  NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student progress per Aufgabenset; status: 1=in_progress, 2=submitted
CREATE TABLE IF NOT EXISTS lg_taskset_status (
    student_id       INT NOT NULL,
    taskset_id       INT NOT NULL,
    status           INT NOT NULL,
    achieved_points  INT NULL,
    scored_at        TIMESTAMP NULL,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, taskset_id),
    FOREIGN KEY (student_id) REFERENCES students(id)    ON DELETE CASCADE,
    FOREIGN KEY (taskset_id) REFERENCES lg_tasksets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grading scale per class+subject; thresholds = JSON array of 5 ints [t1..t5]
-- Grade 6: 0–t1, Grade 5: t1–t2, ..., Grade 1: t5–maxPoints
CREATE TABLE IF NOT EXISTS lg_grading_scales (
    class_id   INT NOT NULL,
    subject_id INT NOT NULL,
    thresholds JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (class_id, subject_id),
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Year archive: snapshot of all student×taskset data at year-end ──────────
-- Written BEFORE student promotion so class membership is still accurate.
-- Denormalised: names/grade stored to survive future deletions of source rows.
-- Append-only: each year-end adds new rows; previous years are never touched.

CREATE TABLE IF NOT EXISTS year_archive (
    school_year     VARCHAR(9)   NOT NULL,
    student_id      INT          NOT NULL,
    taskset_id      INT          NOT NULL,
    status          INT          NOT NULL DEFAULT 0,
    achieved_points INT          NULL,
    taskset_name    VARCHAR(255) NOT NULL,
    max_points      INT          NOT NULL,
    is_pass_fail    TINYINT(1)   NOT NULL DEFAULT 0,
    subject_id      INT          NOT NULL,
    subject_name    VARCHAR(255) NOT NULL,
    student_grade   INT          NOT NULL,
    PRIMARY KEY (school_year, student_id, taskset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-student grading scale snapshot (class-specific scales differ between e.g. 5A and 5B)
CREATE TABLE IF NOT EXISTS year_archive_scale (
    school_year  VARCHAR(9)   NOT NULL,
    student_id   INT          NOT NULL,
    subject_id   INT          NOT NULL,
    scale_json   TEXT         NOT NULL,
    PRIMARY KEY (school_year, student_id, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Password reset tokens ────────────────────────────────────────────────────
-- token_hash = SHA-256 of the random token mailed to the user; the plain token
-- is never stored. Rows are single-use (deleted on success) and short-lived
-- (expires_at set a few minutes out) — no cleanup job needed, expired rows are
-- simply ignored by the lookup and overwritten by the next request for that email.
CREATE TABLE IF NOT EXISTS password_resets (
    token_hash CHAR(64)     PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
