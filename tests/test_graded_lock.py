"""
Tests: graded task sets keep their status; points only land on submitted rows.

Regression for rows like status = 4 ("Sucht Partner") with achieved_points set:
a submitted task's status was changed (stale page / Klassenübersicht) and the
teacher then graded it from a stale inbox entry.

  * A graded row's status cannot be changed (student, Klassenübersicht, help ack).
  * Points (teacher and admin) can only be written to status-2 rows.
  * Refusals return 409; submitted-but-ungraded tasks stay changeable.

Reads and resets lg_taskset_status directly, so needs the managed test DB.
"""
import subprocess
import pytest
import requests
from conftest import (url, _MANAGED, _DB_NAME, _mysql_cmd, _run_sql, ADMIN_USER, ADMIN_PASS,
                      TEACHER1_EMAIL, TEACHER1_PASS, STUDENT1_EMAIL, STUDENT1_PASS)

pytestmark = pytest.mark.skipif(not _MANAGED, reason="Needs direct access to the managed test DB")

SID, TSID = 1001, 2   # student1 (5A); 'Aufgabe 2', Mathematik grade 5, owned by teacher1
JSON = {"Accept": "application/json"}


def login(e, p):
    s = requests.Session()
    s.post(url("/login"), json={"username": e, "password": p}, allow_redirects=False)
    return s


def row():
    out = subprocess.run(_mysql_cmd(_DB_NAME) + ["-N", "-B", "-e",
        f"SELECT status, IFNULL(achieved_points,'NULL') FROM lg_taskset_status WHERE student_id={SID} AND taskset_id={TSID}"],
        capture_output=True, check=True).stdout.decode().strip()
    if not out:
        return None
    st, pts = out.split("\t")
    return int(st), None if pts == "NULL" else int(pts)


def set_row(status, pts=None):
    _run_sql(f"DELETE FROM lg_taskset_status WHERE student_id={SID} AND taskset_id={TSID};", _DB_NAME)
    if status:
        _run_sql(f"INSERT INTO lg_taskset_status (student_id,taskset_id,status,achieved_points) "
                 f"VALUES ({SID},{TSID},{status},{'NULL' if pts is None else pts});", _DB_NAME)


@pytest.fixture(autouse=True)
def clean():
    set_row(0); yield; set_row(0)


stu = lambda: login(STUDENT1_EMAIL, STUDENT1_PASS)
t1 = lambda: login(TEACHER1_EMAIL, TEACHER1_PASS)
adm = lambda: login(ADMIN_USER, ADMIN_PASS)
student_status = lambda s, st: s.post(url("/lg-taskset-status"), json={"tasksetId": TSID, "status": st}, headers=JSON)
matrix_status = lambda s, st: s.post(url("/admin/set-student-task-status"), json={"studentId": SID, "taskSetId": TSID, "status": st}, headers=JSON)
help_ack = lambda s: s.post(url("/lg-taskset-status-teacher"), json={"studentId": SID, "taskSetId": TSID, "status": 1}, headers=JSON)
t_score = lambda s, p: s.post(url("/lg-set-score"), json={"studentId": SID, "taskSetId": TSID, "achievedPoints": p}, headers=JSON)
a_score = lambda s, p: s.post(url("/admin/set-student-score"), json={"studentId": SID, "taskSetId": TSID, "achievedPoints": p}, headers=JSON)


# --- unchanged behaviour ---------------------------------------------------
def test_student_open_statuses_still_work():
    s = stu()
    for st in (1, 3, 4, 2):
        assert student_status(s, st).status_code == 200 and row() == (st, None)
    assert student_status(s, 0).status_code == 200 and row() is None


@pytest.mark.parametrize("login_fn", [t1, adm])
@pytest.mark.parametrize("new", [0, 1, 3, 4])
def test_matrix_can_still_change_submitted_ungraded(login_fn, new):
    set_row(2)
    assert matrix_status(login_fn(), new).status_code == 200
    assert row() == ((new, None) if new else None)


def test_grading_submitted_still_works():
    set_row(2)
    assert t_score(t1(), 7).status_code == 200 and row() == (2, 7)
    assert a_score(adm(), 5).status_code == 200 and row() == (2, 5)
    assert a_score(adm(), None).status_code == 200 and row() == (2, None)


def test_regrading_same_value_ok():
    set_row(2, 7)
    assert t_score(t1(), 7).status_code == 200 and row() == (2, 7)


# --- new rule 1: graded rows keep their status ------------------------------
@pytest.mark.parametrize("new", [0, 1, 3, 4])
def test_student_cannot_change_graded(new):
    set_row(2, 4)
    assert student_status(stu(), new).status_code == 409 and row() == (2, 4)


@pytest.mark.parametrize("login_fn", [t1, adm])
@pytest.mark.parametrize("new", [0, 1, 3, 4])
def test_matrix_cannot_change_graded(login_fn, new):
    set_row(2, 4)
    assert matrix_status(login_fn(), new).status_code == 409 and row() == (2, 4)


def test_help_ack_cannot_change_graded():
    set_row(2, 4)
    assert help_ack(t1()).status_code == 409 and row() == (2, 4)


def test_admin_reset_then_status_change():
    set_row(2, 4)
    assert a_score(adm(), None).status_code == 200
    assert matrix_status(adm(), 1).status_code == 200 and row() == (1, None)


# --- new rule 2: points only on submitted rows ------------------------------
@pytest.mark.parametrize("st", [0, 1, 3, 4])
def test_teacher_cannot_grade_unsubmitted(st):
    set_row(st)
    assert t_score(t1(), 4).status_code == 409
    assert row() == ((st, None) if st else None)


@pytest.mark.parametrize("st", [1, 4])
def test_admin_cannot_grade_unsubmitted(st):
    set_row(st)
    assert a_score(adm(), 4).status_code == 409 and row() == (st, None)


def test_melina_sequence():
    """submit → status changed to 4 (stale matrix) → teacher grades from stale inbox."""
    s, t = stu(), t1()
    assert student_status(s, 2).ok
    assert matrix_status(t, 4).ok          # still allowed: not graded yet
    assert t_score(t, 4).status_code == 409  # grade refused
    assert row() == (4, None)
