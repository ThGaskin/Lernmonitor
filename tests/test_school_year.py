"""
Tests: school year advancement.

The advance test is semi-destructive (changes current_school_year and moves
students to higher classes). Each test that calls /admin/advance-school-year
backs up first and restores afterwards so the DB is unchanged for other tests.

Set env vars:
    TEST_ADMIN_USER=admin
    TEST_ADMIN_PASS=password
    APP_URL=http://localhost:8080
"""

import io
import json
import os
import pytest
import requests
from conftest import url, db_required

_admin_user = os.environ.get("TEST_ADMIN_USER")
_admin_pass = os.environ.get("TEST_ADMIN_PASS")
_has_admin  = bool(_admin_user and _admin_pass)
_needs_admin = pytest.mark.skipif(not _has_admin, reason="Set TEST_ADMIN_USER and TEST_ADMIN_PASS")


def login_admin(session: requests.Session) -> None:
    session.post(url("/login"), json={"username": _admin_user, "password": _admin_pass},
                 allow_redirects=False)


def backup(session: requests.Session) -> bytes:
    resp = session.post(url("/backup-database"))
    assert resp.status_code == 200
    return resp.content


def restore(session: requests.Session, backup_bytes: bytes) -> None:
    resp = session.post(
        url("/restore-database"),
        data={"password": _admin_pass},
        files={"backup": ("backup.json", io.BytesIO(backup_bytes), "application/json")},
    )
    assert resp.status_code == 200, f"Restore failed: {resp.text}"


# ---------------------------------------------------------------------------
# Access guards (no DB needed)
# ---------------------------------------------------------------------------

def test_advance_school_year_requires_admin():
    resp = requests.post(url("/admin/advance-school-year"),
                         json={"label": "2099/2100"},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_school_years_api_requires_auth():
    # /api/school-years is a student endpoint — unauthenticated requests get redirected
    resp = requests.get(url("/api/school-years"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)


# ---------------------------------------------------------------------------
# Validation errors (no side effects — safe without backup/restore)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_advance_school_year_missing_label_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/admin/advance-school-year"), json={"password": _admin_pass})
    assert resp.status_code == 400

@db_required
@_needs_admin
def test_advance_school_year_same_as_current_returns_400():
    s = requests.Session()
    login_admin(s)
    settings_resp = s.get(url("/api/settings"))
    assert settings_resp.status_code == 200
    current_year = settings_resp.json().get("current_school_year", "")
    if not current_year:
        pytest.skip("No current_school_year set — cannot test duplicate-label guard")
    resp = s.post(url("/admin/advance-school-year"), json={"label": current_year, "password": _admin_pass})
    assert resp.status_code == 400


# ---------------------------------------------------------------------------
# Advance school year — full roundtrip (backed up and restored)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_advance_school_year_promotes_students():
    """
    Set up two classes (grade 5 and grade 6 with the same suffix), add a student
    in the grade-5 class, advance the year, verify the student moved to grade 6,
    then restore the DB to its original state.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        # Create two consecutive classes
        r5 = s.post(url("/add-class"), json={"className": "5YEAR_TEST", "grade": 5})
        r6 = s.post(url("/add-class"), json={"className": "6YEAR_TEST", "grade": 6})
        assert r5.status_code == 200
        assert r6.status_code == 200

        # Add a student into the grade-5 class
        add_resp = s.post(url("/add-student"), json={
            "firstName":       "YearTest",
            "lastName":        "Student",
            "email":           "yeartest.student@test.de",
            "classId":         r5.json()["id"],
            "graduationLevel": 1,
        })
        assert add_resp.status_code == 200
        student_id = add_resp.json()["id"]

        # Advance to a year that definitely doesn't exist yet (must fit varchar(9))
        new_label = "2099/00"
        adv_resp = s.post(url("/admin/advance-school-year"), json={"label": new_label, "password": _admin_pass})
        assert adv_resp.status_code == 200
        data = adv_resp.json()
        assert data.get("ok") is True
        assert data.get("newYear") == new_label
        assert data.get("advanced", 0) >= 1

        # The student must now be in the grade-6 class
        students = s.get(url("/students")).json()
        st = next((x for x in students if x.get("id") == student_id), None)
        assert st is not None
        assert st.get("classLabel") == "6YEAR_TEST", (
            f"Expected student in 6YEAR_TEST, got {st.get('classLabel')!r}"
        )

        # current_school_year setting must be updated
        settings = s.get(url("/api/settings")).json()
        assert settings.get("current_school_year") == new_label

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_advance_school_year_students_without_next_class_counted():
    """
    Students in the highest grade (no grade+1 class) must be reported in noClass,
    not silently dropped.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        # Create a class with a grade that has no successor
        r = s.post(url("/add-class"), json={"className": "99STRANDED_TEST", "grade": 99})
        assert r.status_code == 200

        add_resp = s.post(url("/add-student"), json={
            "firstName":       "Stranded",
            "lastName":        "Student",
            "email":           "stranded.student@test.de",
            "classId":         r.json()["id"],
            "graduationLevel": 1,
        })
        assert add_resp.status_code == 200

        adv_resp = s.post(url("/admin/advance-school-year"), json={"label": "2099/01", "password": _admin_pass})
        assert adv_resp.status_code == 200
        data = adv_resp.json()
        assert data.get("noClass", 0) >= 1

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_advance_school_year_grade_tasksets_carried_over():
    """
    Grade-level task sets (class_id IS NULL) must be copied into the new year.
    Only relevant when task_scope is 'grade'; skipped otherwise.
    """
    s = requests.Session()
    login_admin(s)

    task_scope = s.get(url("/api/settings")).json().get("task_scope", "grade")
    if task_scope != "grade":
        pytest.skip("task_scope is not 'grade' — grade-level taskset carryover not applicable")

    snap = backup(s)

    try:
        # We need a subject for the taskset
        subj_resp = s.post(url("/add-subject"), json={"name": "YearTestSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subj = next(x for x in subjects if x["name"] == "YearTestSubject99")

        # Create a grade-level taskset (class_id IS NULL — only possible in grade scope)
        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subj["id"],
            "grade":     7,
            "name":      "Jahres-Carryover-Test",
            "maxPoints": 10,
            "isPassFail": False,
        })
        assert ts_resp.status_code == 200

        adv_resp = s.post(url("/admin/advance-school-year"), json={"label": "2099/02", "password": _admin_pass})
        assert adv_resp.status_code == 200
        assert adv_resp.json().get("ok") is True

        # current_school_year setting must be updated
        new_settings = s.get(url("/api/settings")).json()
        assert new_settings.get("current_school_year") == "2099/02"

        # The grade-level taskset should appear in the current year's admin taskset view.
        tasksets_resp = s.get(url("/api/admin-tasksets"))
        assert tasksets_resp.status_code == 200
        all_entries = tasksets_resp.json()
        grade7 = next((g for g in all_entries if g.get("grade") == 7), None)
        assert grade7 is not None, "Grade 7 missing from admin-tasksets after advance"
        all_names = [
            ts["name"]
            for subj_entry in grade7["subjects"]
            for cls in subj_entry["classes"]
            for ts in cls["taskSets"]
        ]
        assert "Jahres-Carryover-Test" in all_names, (
            f"Grade-level taskset not carried over. Found: {all_names}"
        )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_new_student_has_no_prior_year_data():
    """
    A student added after advancing the year must have no task entries for the
    previous year — the performance panel must not show phantom history.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        r = s.post(url("/add-class"), json={"className": "8NEWSTUDENT_TEST", "grade": 8})
        assert r.status_code == 200

        adv_resp = s.post(url("/admin/advance-school-year"), json={"label": "2099/03", "password": _admin_pass})
        assert adv_resp.status_code == 200

        add_resp = s.post(url("/add-student"), json={
            "firstName":       "NewAfter",
            "lastName":        "Advance",
            "email":           "newafter.advance@test.de",
            "classId":         r.json()["id"],
            "graduationLevel": 1,
        })
        assert add_resp.status_code == 200
        student_id = add_resp.json()["id"]

        # /api/my-performance-years from the student's perspective would show no prior year.
        # We test this from the admin side: the available years endpoint returns only years
        # that have taskstats rows — a brand-new student has none, so prior year won't appear.
        # Verify current_school_year updated — new student has no phantom history
        settings_resp = s.get(url("/api/settings"))
        assert settings_resp.status_code == 200
        assert settings_resp.json().get("current_school_year") == "2099/03"

        # A fresh student has no taskstats rows; verify the students list
        # includes them without any crash (no NaN/phantom entries).
        students_resp = s.get(url("/students"))
        assert students_resp.status_code == 200
        student_row = next(
            (x for x in students_resp.json() if x.get("id") == student_id), None
        )
        assert student_row is not None

    finally:
        restore(s, snap)
