"""
Chunk 3 tests: student features — pages, data APIs, task management.

Most tests require a live DB with a test student account.
Set env vars before running:
    TEST_STUDENT_EMAIL=student@school.de
    TEST_STUDENT_PASS=password
    APP_URL=http://localhost:8080

Run only tests that don't need DB:
    pytest test_student.py -v -m "not db"
"""

import os
import pytest
import requests
from conftest import url, db_required

_student_email = os.environ.get("TEST_STUDENT_EMAIL")
_student_pass  = os.environ.get("TEST_STUDENT_PASS")
_has_student   = bool(_student_email and _student_pass)
_needs_student = pytest.mark.skipif(not _has_student,
    reason="Set TEST_STUDENT_EMAIL and TEST_STUDENT_PASS")

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def login_student(session: requests.Session) -> requests.Response:
    return session.post(url("/login"),
                        json={"username": _student_email, "password": _student_pass},
                        allow_redirects=False)

# ---------------------------------------------------------------------------
# Page access guards (no DB needed)
# ---------------------------------------------------------------------------

def test_dashboard_unauthenticated_redirects():
    resp = requests.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 302
    assert "/login" in resp.headers.get("Location", "")

def test_mydata_unauthenticated_returns_401_or_302():
    resp = requests.get(url("/mydata"), allow_redirects=False)
    assert resp.status_code in (302, 401)

def test_rooms_unauthenticated_returns_302():
    resp = requests.get(url("/rooms"), allow_redirects=False)
    assert resp.status_code in (302, 401)

# ---------------------------------------------------------------------------
# Static assets
# ---------------------------------------------------------------------------

def test_css_is_served():
    resp = requests.get(url("/css/style.css"))
    assert resp.status_code == 200
    assert "text/css" in resp.headers.get("Content-Type", "")

def test_js_student_database_is_served():
    resp = requests.get(url("/js/student-database.js"))
    assert resp.status_code == 200
    assert "javascript" in resp.headers.get("Content-Type", "")

# ---------------------------------------------------------------------------
# Authenticated student tests (require DB + test student account)
# ---------------------------------------------------------------------------

@db_required
@_needs_student
def test_dashboard_loads_after_login():
    s = requests.Session()
    login_student(s)
    resp = s.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 200
    assert "text/html" in resp.headers.get("Content-Type", "")

@db_required
@_needs_student
def test_mydata_returns_json():
    s = requests.Session()
    login_student(s)
    resp = s.get(url("/mydata"))
    assert resp.status_code == 200
    data = resp.json()
    assert "id" in data
    assert "firstName" in data
    assert "schoolClass" in data

@db_required
@_needs_student
def test_rooms_returns_list():
    s = requests.Session()
    login_student(s)
    resp = s.get(url("/rooms"))
    assert resp.status_code == 200
    data = resp.json()
    assert isinstance(data, list)
    if data:
        assert "label" in data[0]
        assert "minimumLevel" in data[0]

@db_required
@_needs_student
def test_get_module_returns_settings():
    s = requests.Session()
    login_student(s)
    resp = s.post(url("/get-module"),
                  json={"key": "result_view"},
                  headers={"Accept": "application/json"})
    assert resp.status_code == 200
    data = resp.json()
    assert "settings" in data

@db_required
@_needs_student
def test_student_cannot_act_on_other_student():
    """A student must get 403 if they try to act on another student's ID."""
    s = requests.Session()
    login_student(s)
    # Use a studentId that is unlikely to be the test student (very large fake ID)
    resp = s.post(url("/update-room"),
                  json={"studentId": 999999999, "room": ""},
                  headers={"Accept": "application/json"})
    assert resp.status_code == 403
