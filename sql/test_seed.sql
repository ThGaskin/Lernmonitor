-- Test seed data for student_database_test
-- Passwords (all bcrypt-hashed):
--   admin@test.de   → testAdminPass123
--   teacher1@test.de → testTeacher1Pass
--   teacher2@test.de → testTeacher2Pass
--   student1@test.de → testStudent1Pass
--   student2@test.de → testStudent2Pass

-- ============================================================
-- Settings  (values are JSON-encoded, matching AppSettings)
-- ============================================================
INSERT INTO settings (`key`, `value`) VALUES
  ('current_school_year', '"2025/26"'),
  ('task_scope',          '"grade"'),
  ('show_grades',         'false'),
  ('admin_only_tasksets', 'false'),
  ('allow_class_deletion','false');

-- ============================================================
-- Classes  (two at grade 5 to test cross-class operations)
-- ============================================================
INSERT INTO classes (id, label, grade) VALUES
  (1, '5A', 5),
  (2, '5B', 5),
  (3, '6A', 6),
  (4, '7A', 7);

-- ============================================================
-- Rooms
-- ============================================================
INSERT INTO rooms (label, minimum_level) VALUES
  ('Klassenzimmer', 1),
  ('Lernbüro',      2),
  ('Bibliothek',    3);

-- ============================================================
-- Subjects
-- ============================================================
INSERT INTO subjects (id, name, sort_order) VALUES
  (1, 'Mathematik', 1),
  (2, 'Deutsch',    2),
  (3, 'Sport',      3);

-- ============================================================
-- Admins
-- ============================================================
INSERT INTO admins (username, password_hash, email) VALUES
  ('admin@test.de', '$2y$12$4nLSRrY/oVS081ZoC/PZTOVBGPHkUgkUTqrvhtCc35SM81Y/8Fj6K', 'admin@test.de');

-- ============================================================
-- Teachers
-- ============================================================
INSERT INTO teachers (id, first_name, last_name, email, password) VALUES
  (1, 'Anna',   'Müller',  'teacher1@test.de', '$2y$12$/WzE7SwXedUctTLPQv.uFOLhXc5t8rT9QplAyb6v1NVeV7O5UQhl6'),
  (2, 'Hans',   'Schmidt', 'teacher2@test.de', '$2y$12$nfCzMNyjCQHUz6xGqaq/0OZBt9jSdKR5FN/qEUobTNwsmuDCQ8F/C');

-- ============================================================
-- Students
-- ============================================================
INSERT INTO students (id, first_name, last_name, email, password, class, graduation_level) VALUES
  (1001, 'Lea',  'Fischer', 'student1@test.de', '$2y$12$/WNDZ2CxuFQPT7RN7wjU5eznJy4oQy6tanp1cg0o5kjXOPwKvS2D6', 1, 1),
  (1002, 'Max',  'Weber',   'student2@test.de', '$2y$12$zMDgUJxa9UKzeHqSvjp.Ce0lPJusPDpmP.GG3XAw6xW.nCbD9tELC', 1, 2),
  (1003, 'Mia',  'Koch',    'student3@test.de', NULL, 2, 1),
  (1004, 'Tom',  'Braun',   'student4@test.de', NULL, 3, 1),
  (1005, 'Jana', 'Wolf',    'student5@test.de', NULL, 4, 1);

-- ============================================================
-- Class-subject assignments (for auto-enrollment and lerngruppen)
-- ============================================================
INSERT INTO class_subjects (class_id, subject_id) VALUES
  (1, 1), -- 5A → Mathematik
  (1, 2), -- 5A → Deutsch
  (2, 1), -- 5B → Mathematik
  (3, 1), -- 6A → Mathematik
  (4, 1); -- 7A → Mathematik

-- ============================================================
-- Teacher lerngruppen assignments
-- ============================================================
INSERT INTO teacher_lerngruppen (teacher_id, class_id, subject_id) VALUES
  (1, 1, 1), -- teacher1 → 5A → Mathematik
  (1, 2, 1), -- teacher1 → 5B → Mathematik
  (1, 3, 1), -- teacher1 → 6A → Mathematik
  (2, 1, 2); -- teacher2 → 5A → Deutsch

-- ============================================================
-- Grade-level tasksets (task_scope = 'grade')
-- ============================================================
INSERT INTO lg_tasksets (id, class_id, subject_id, grade, name, max_points, active, school_year, is_pass_fail) VALUES
  (1, NULL, 1, 5, 'Aufgabe 1', 10, 1, '2025/26', 0),
  (2, NULL, 1, 5, 'Aufgabe 2', 10, 1, '2025/26', 0),
  (3, NULL, 1, 6, 'Aufgabe 1', 10, 1, '2025/26', 0),
  (4, NULL, 2, 5, 'Aufsatz 1', 20, 1, '2025/26', 0);

-- ============================================================
-- Student subject enrollment (student_topics)
-- ============================================================
INSERT INTO student_topics (student_id, subject_id) VALUES
  (1001, 1), -- student1 enrolled in Mathematik
  (1001, 2), -- student1 enrolled in Deutsch
  (1002, 1), -- student2 enrolled in Mathematik
  (1003, 1), -- student3 enrolled in Mathematik
  (1004, 1), -- student4 enrolled in Mathematik
  (1005, 1); -- student5 enrolled in Mathematik

-- ============================================================
-- Some lg_taskset_status rows so student results aren't empty
-- ============================================================
INSERT INTO lg_taskset_status (student_id, taskset_id, status, achieved_points) VALUES
  (1001, 1, 2, 8),  -- student1 submitted Aufgabe 1 (scored 8/10)
  (1001, 4, 1, NULL), -- student1 in-progress Aufsatz 1
  (1002, 1, 2, 7);  -- student2 submitted Aufgabe 1
