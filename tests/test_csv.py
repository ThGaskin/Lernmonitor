"""
Tests: CSV import — add-students, add-teachers, add-rooms, preview-import,
       execute-import, and export-data / all-student-results.
"""

import io
import os
import zipfile
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


# ---------------------------------------------------------------------------
# Access guards (no DB needed)
# ---------------------------------------------------------------------------

def test_add_students_requires_admin():
    resp = requests.post(url("/add-students"), data="test", allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_add_teachers_requires_admin():
    resp = requests.post(url("/add-teachers"), data="test", allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_preview_import_requires_admin():
    resp = requests.post(url("/preview-import"),
                         json={"type": "rooms", "csv": "Raumname,Mindestlevel\nA,1"},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_execute_import_requires_admin():
    resp = requests.post(url("/execute-import"),
                         json={"type": "rooms", "rows": []},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_export_data_requires_admin():
    resp = requests.post(url("/export-data"),
                         json={"types": ["rooms"]},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

# ---------------------------------------------------------------------------
# Rooms CSV import (no class dependency — simplest import to test)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_add_rooms_from_csv_creates_rooms():
    s = requests.Session()
    login_admin(s)
    csv = "Raumname,Mindestlevel\nTestRaumCSV_A,2\nTestRaumCSV_B,4\n"
    resp = s.post(url("/add-rooms"), data=csv, headers={"Content-Type": "text/plain"})
    assert resp.status_code == 200
    rooms = s.get(url("/rooms")).json()
    labels = [r["label"] for r in rooms]
    assert "TestRaumCSV_A" in labels
    assert "TestRaumCSV_B" in labels


@db_required
@_needs_admin
def test_add_rooms_empty_body_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/add-rooms"), data="", headers={"Content-Type": "text/plain"})
    assert resp.status_code == 400


# ---------------------------------------------------------------------------
# Teachers CSV import
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_add_teachers_from_csv_returns_password_csv():
    s = requests.Session()
    login_admin(s)
    csv = "Vorname,Nachname,Email\nCSVTest,Lehrer99,csvlehrer99@school-test.de\n"
    resp = s.post(url("/add-teachers"), data=csv, headers={"Content-Type": "text/plain"})
    assert resp.status_code == 200
    ct = resp.headers.get("Content-Type", "")
    assert "csv" in ct or "text/plain" in ct or "octet-stream" in ct
    text = resp.text
    assert "CSVTest" in text
    assert "Lehrer99" in text
    # Password column must be present
    data_line = next((l for l in text.splitlines() if "Lehrer99" in l), None)
    assert data_line is not None
    assert len(data_line.split(",")) >= 4


@db_required
@_needs_admin
def test_add_teachers_empty_body_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/add-teachers"), data="", headers={"Content-Type": "text/plain"})
    assert resp.status_code == 400


# ---------------------------------------------------------------------------
# Students CSV import (requires an existing class)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_add_students_from_csv_returns_password_csv():
    s = requests.Session()
    login_admin(s)

    # Create a temporary class for this test
    cls_resp = s.post(url("/add-class"), json={"className": "TEST_CSV_99", "grade": 99})
    assert cls_resp.status_code == 200

    try:
        csv = (
            "ID,Vorname,Nachname,Klasse,Abschlussstufe,Email\n"
            "99901,CSVVorname,CSVNachname,TEST_CSV_99,3,csvstudent99901@test.de\n"
        )
        resp = s.post(url("/add-students"), data=csv, headers={"Content-Type": "text/plain"})
        assert resp.status_code == 200
        ct = resp.headers.get("Content-Type", "")
        assert "csv" in ct or "text/plain" in ct or "octet-stream" in ct

        text = resp.text
        assert "CSVVorname" in text
        assert "CSVNachname" in text
        data_line = next((l for l in text.splitlines() if "CSVNachname" in l), None)
        assert data_line is not None
        assert len(data_line.split(",")) >= 5  # ID, Vorname, Nachname, E-Mail, Passwort

        # Student must appear in the students list
        students = s.get(url("/students")).json()
        assert any(str(st.get("id")) == "99901" for st in students)

    finally:
        s.post(url("/delete-student"), json={"id": 99901})
        classes = s.get(url("/classes")).json()
        match = [c for c in classes if c.get("label") == "TEST_CSV_99"]
        if match:
            s.post(url("/delete-class"), json={"id": match[0]["id"]})


@db_required
@_needs_admin
def test_add_students_empty_body_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/add-students"), data="", headers={"Content-Type": "text/plain"})
    assert resp.status_code == 400


@db_required
@_needs_admin
def test_add_students_unknown_class_is_skipped():
    """Rows referencing a nonexistent class label are silently skipped; response is still 200."""
    s = requests.Session()
    login_admin(s)
    csv = "ID,Vorname,Nachname,Klasse,Abschlussstufe,Email\n99902,X,Y,NONEXISTENT_CLASS,1,x@test.de\n"
    resp = s.post(url("/add-students"), data=csv, headers={"Content-Type": "text/plain"})
    assert resp.status_code == 200
    students = s.get(url("/students")).json()
    assert not any(str(st.get("id")) == "99902" for st in students)


# ---------------------------------------------------------------------------
# preview-import
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_preview_import_rooms_returns_rows():
    s = requests.Session()
    login_admin(s)
    csv = "Raumname,Mindestlevel\nVorschauRaum1,1\nVorschauRaum2,5\n"
    resp = s.post(url("/preview-import"), json={"type": "rooms", "csv": csv})
    assert resp.status_code == 200
    data = resp.json()
    assert "rows" in data
    assert len(data["rows"]) == 2


@db_required
@_needs_admin
def test_preview_import_unknown_type_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/preview-import"), json={"type": "nonexistent", "csv": "a,b\n1,2"})
    assert resp.status_code == 400


@db_required
@_needs_admin
def test_preview_import_missing_params_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/preview-import"), json={"type": "rooms"})
    assert resp.status_code == 400


# ---------------------------------------------------------------------------
# export-data (ZIP)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_export_data_returns_zip_with_requested_files():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/export-data"), json={"types": ["rooms", "subjects"]})
    assert resp.status_code == 200
    ct = resp.headers.get("Content-Type", "")
    assert "zip" in ct or "octet-stream" in ct
    zf = zipfile.ZipFile(io.BytesIO(resp.content))
    assert "rooms.csv" in zf.namelist()
    assert "subjects.csv" in zf.namelist()


@db_required
@_needs_admin
def test_export_data_csv_has_correct_header():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/export-data"), json={"types": ["rooms"]})
    assert resp.status_code == 200
    zf = zipfile.ZipFile(io.BytesIO(resp.content))
    header = zf.read("rooms.csv").decode("utf-8").splitlines()[0]
    assert "Raumname" in header
    assert "Mindestlevel" in header


@db_required
@_needs_admin
def test_export_data_empty_types_returns_400():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/export-data"), json={"types": []})
    assert resp.status_code == 400
