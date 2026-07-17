"""
Chunk 5 tests: admin features — pages, CRUD APIs, CSV import/export.

Set env vars:
    TEST_ADMIN_USER=admin
    TEST_ADMIN_PASS=password
    APP_URL=http://localhost:8080

Run only tests that don't need DB:
    pytest test_admin.py -v -m "not db"
"""

import os
import pytest
import requests
from conftest import url, db_required

_admin_user = os.environ.get("TEST_ADMIN_USER")
_admin_pass = os.environ.get("TEST_ADMIN_PASS")
_has_admin  = bool(_admin_user and _admin_pass)
_needs_admin = pytest.mark.skipif(
    not _has_admin, reason="Set TEST_ADMIN_USER and TEST_ADMIN_PASS"
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def login_admin(session: requests.Session) -> requests.Response:
    return session.post(
        url("/login"),
        json={"username": _admin_user, "password": _admin_pass},
        allow_redirects=False,
    )


# ---------------------------------------------------------------------------
# Access guards — no DB needed
# ---------------------------------------------------------------------------

def test_manage_students_requires_auth():
    resp = requests.get(url("/manage_students"), allow_redirects=False)
    assert resp.status_code == 302
    assert "/login" in resp.headers.get("Location", "")

def test_manage_teachers_requires_auth():
    resp = requests.get(url("/manage_teachers"), allow_redirects=False)
    assert resp.status_code == 302

def test_manage_classes_requires_auth():
    resp = requests.get(url("/manage_classes"), allow_redirects=False)
    assert resp.status_code == 302

def test_manage_subjects_requires_auth():
    resp = requests.get(url("/manage_subjects"), allow_redirects=False)
    assert resp.status_code == 302

def test_manage_rooms_requires_auth():
    resp = requests.get(url("/manage_rooms"), allow_redirects=False)
    assert resp.status_code == 302

def test_modules_requires_auth():
    resp = requests.get(url("/modules"), allow_redirects=False)
    assert resp.status_code == 302

def test_students_api_requires_admin():
    resp = requests.get(url("/students"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_teachers_api_requires_admin():
    resp = requests.get(url("/teachers"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_add_admin_requires_admin():
    resp = requests.post(url("/add-admin"),
                         json={"username": "x", "password": "y"},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_add_class_requires_admin():
    resp = requests.post(url("/add-class"),
                         json={"className": "10A", "grade": 10},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_add_subject_requires_admin():
    resp = requests.post(url("/add-subject"),
                         json={"name": "Testfach"},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_delete_class_requires_admin():
    resp = requests.post(url("/delete-class"), json={"id": 1}, allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_delete_subject_requires_admin():
    resp = requests.post(url("/delete-subject"), json={"id": 1}, allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

# ---------------------------------------------------------------------------
# Static assets
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Authenticated admin tests (require DB)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_admin_dashboard_loads():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 200
    assert "text/html" in resp.headers.get("Content-Type", "")

@db_required
@_needs_admin
def test_manage_students_page_loads():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/manage_students"), allow_redirects=False)
    assert resp.status_code == 200

@db_required
@_needs_admin
def test_manage_teachers_page_loads():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/manage_teachers"), allow_redirects=False)
    assert resp.status_code == 200

@db_required
@_needs_admin
def test_manage_classes_page_loads():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/manage_classes"), allow_redirects=False)
    assert resp.status_code == 200

@db_required
@_needs_admin
def test_manage_subjects_page_loads():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/manage_subjects"), allow_redirects=False)
    assert resp.status_code == 200

@db_required
@_needs_admin
def test_manage_rooms_page_loads():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/manage_rooms"), allow_redirects=False)
    assert resp.status_code == 200

@db_required
@_needs_admin
def test_students_api_returns_list():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/students"))
    assert resp.status_code == 200
    assert isinstance(resp.json(), list)

@db_required
@_needs_admin
def test_teachers_api_returns_list():
    s = requests.Session()
    login_admin(s)
    resp = s.get(url("/teachers"))
    assert resp.status_code == 200
    assert isinstance(resp.json(), list)

@db_required
@_needs_admin
def test_add_and_delete_class_roundtrip():
    s = requests.Session()
    login_admin(s)

    # Add
    resp = s.post(url("/add-class"), json={"className": "TEST_99Z", "grade": 99})
    assert resp.status_code == 200

    # Verify it appears
    classes = s.get(url("/classes")).json()
    match = [c for c in classes if c.get("label") == "TEST_99Z"]
    assert match, "Added class not found in /classes"
    class_id = match[0]["id"]

    # Delete
    resp = s.post(url("/delete-class"), json={"id": class_id})
    assert resp.status_code == 200

    # Verify gone
    classes = s.get(url("/classes")).json()
    assert not any(c.get("label") == "TEST_99Z" for c in classes)

@db_required
@_needs_admin
def test_add_and_delete_subject_roundtrip():
    s = requests.Session()
    login_admin(s)

    resp = s.post(url("/add-subject"), json={"name": "TestfachXXX"})
    assert resp.status_code == 200

    subjects = s.get(url("/subjects")).json()
    match = [sub for sub in subjects if sub.get("name") == "TestfachXXX"]
    assert match, "Added subject not found in /subjects"
    subject_id = match[0]["id"]

    resp = s.post(url("/delete-subject"), json={"id": subject_id})
    assert resp.status_code == 200

    subjects = s.get(url("/subjects")).json()
    assert not any(sub.get("name") == "TestfachXXX" for sub in subjects)

@db_required
@_needs_admin
def test_add_rooms_from_csv():
    s = requests.Session()
    login_admin(s)
    csv_data = "Raumname,Mindestlevel\nTestRaum99,3\n"
    resp = s.post(url("/add-rooms"),
                  data=csv_data,
                  headers={"Content-Type": "text/plain"})
    assert resp.status_code == 200

    rooms = s.get(url("/rooms")).json()
    assert any(r.get("label") == "TestRaum99" for r in rooms)

@db_required
@_needs_admin
def test_add_teacher_single():
    s = requests.Session()
    login_admin(s)

    # Clean up any leftover from a previous run
    teachers = s.get(url("/teachers")).json()
    existing = next((t for t in teachers if t.get("email") == "test.lehrer99@school.de"), None)
    if existing:
        s.post(url("/delete-teacher"), json={"id": existing["id"]})

    resp = s.post(url("/add-teacher"), json={
        "firstName": "Test",
        "lastName": "Lehrer99",
        "email": "test.lehrer99@school.de",
    })
    assert resp.status_code == 200
    data = resp.json()
    assert data.get("firstName") == "Test"
    assert data.get("lastName") == "Lehrer99"
    assert "id" in data

    # Clean up
    s.post(url("/delete-teacher"), json={"id": data["id"]})

@db_required
@_needs_admin
def test_add_teacher_missing_fields():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/add-teacher"), json={"firstName": "Only"})
    assert resp.status_code == 400

@db_required
@_needs_admin
def test_add_class_missing_fields():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/add-class"), json={"className": "NoGrade"})
    assert resp.status_code == 400
