"""
Chunk 4 tests: teacher features — pages, data APIs, student management.

Requires a running DB with a test teacher and at least one student.
Set env vars:
    TEST_TEACHER_EMAIL=teacher@school.de
    TEST_TEACHER_PASS=password
    TEST_STUDENT_EMAIL=student@school.de   (to test student-data endpoint)
    APP_URL=http://localhost:8080

Run only tests that don't need DB:
    pytest test_teacher.py -v -m "not db"
"""

import os
import pytest
import requests
from conftest import url, db_required

_teacher_email = os.environ.get("TEST_TEACHER_EMAIL")
_teacher_pass  = os.environ.get("TEST_TEACHER_PASS")
_has_teacher   = bool(_teacher_email and _teacher_pass)
_needs_teacher = pytest.mark.skipif(not _has_teacher,
    reason="Set TEST_TEACHER_EMAIL and TEST_TEACHER_PASS")

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def login_teacher(session: requests.Session) -> requests.Response:
    return session.post(url("/login"),
                        json={"username": _teacher_email, "password": _teacher_pass},
                        allow_redirects=False)

# ---------------------------------------------------------------------------
# Access guards (no DB needed)
# ---------------------------------------------------------------------------

def test_student_list_requires_auth():
    resp = requests.post(url("/student-list"), json={"classId": 1}, allow_redirects=False)
    assert resp.status_code in (302, 401)

# ---------------------------------------------------------------------------
# Static assets
# ---------------------------------------------------------------------------

def test_teacher_js_served():
    resp = requests.get(url("/js/teacher/teacher-view.js"))
    assert resp.status_code == 200

def test_subjects_endpoint_requires_auth():
    resp = requests.get(url("/subjects"), allow_redirects=False)
    assert resp.status_code in (302, 401)

def test_classes_endpoint_requires_auth():
    resp = requests.get(url("/classes"), allow_redirects=False)
    assert resp.status_code in (302, 401)

# ---------------------------------------------------------------------------
# Authenticated teacher tests
# ---------------------------------------------------------------------------

@db_required
@_needs_teacher
def test_teacher_dashboard_loads():
    s = requests.Session()
    login_teacher(s)
    resp = s.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 200
    assert "text/html" in resp.headers.get("Content-Type", "")

@db_required
@_needs_teacher
def test_myclasses_returns_list():
    s = requests.Session()
    login_teacher(s)
    resp = s.get(url("/myclasses"))
    assert resp.status_code == 200
    assert isinstance(resp.json(), list)


@db_required
@_needs_teacher
def test_all_subjects_returns_list():
    s = requests.Session()
    login_teacher(s)
    resp = s.get(url("/subjects"))
    assert resp.status_code == 200
    data = resp.json()
    assert isinstance(data, list)
    if data:
        assert "id" in data[0] and "name" in data[0]

@db_required
@_needs_teacher
def test_all_classes_returns_list():
    s = requests.Session()
    login_teacher(s)
    resp = s.get(url("/classes"))
    assert resp.status_code == 200
    assert isinstance(resp.json(), list)

@db_required
@_needs_teacher
def test_student_cannot_call_change_graduation_level():
    """Students must get 302/401/403 from teacher-only endpoints."""
    _student_email = os.environ.get("TEST_STUDENT_EMAIL")
    _student_pass  = os.environ.get("TEST_STUDENT_PASS")
    if not (_student_email and _student_pass):
        pytest.skip("Set TEST_STUDENT_EMAIL and TEST_STUDENT_PASS")
    s = requests.Session()
    s.post(url("/login"), json={"username": _student_email, "password": _student_pass},
           allow_redirects=False)
    resp = s.post(url("/change-graduation-level"),
                  json={"studentId": 1, "graduationLevel": 2},
                  allow_redirects=False)
    assert resp.status_code in (302, 401, 403)
