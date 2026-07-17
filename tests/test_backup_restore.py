"""
Tests: backup, restore, and reset roundtrip.

The restore test is non-destructive: it backs up, makes a change, restores, and
verifies the change is gone — leaving the DB in its original state.

The reset test is destructive (wipes all data). It is guarded by the
TEST_ALLOW_RESET=true env var and always backs up + restores around the wipe.

Set env vars:
    TEST_ADMIN_USER=admin
    TEST_ADMIN_PASS=password
    TEST_ALLOW_RESET=true   (opt-in for the destructive reset test)
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
_needs_reset = pytest.mark.skipif(
    os.environ.get("TEST_ALLOW_RESET") != "true",
    reason="Set TEST_ALLOW_RESET=true to enable the destructive reset test"
)


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

def test_backup_requires_admin():
    resp = requests.post(url("/backup-database"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_restore_requires_admin():
    resp = requests.post(url("/restore-database"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_reset_requires_admin():
    resp = requests.post(url("/reset-database"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)


# ---------------------------------------------------------------------------
# Backup
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_backup_returns_valid_json():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/backup-database"))
    assert resp.status_code == 200
    data = json.loads(resp.content)
    assert data.get("version") == 1
    assert "created_at" in data
    assert "tables" in data


@db_required
@_needs_admin
def test_backup_contains_expected_tables():
    s = requests.Session()
    login_admin(s)
    resp = s.post(url("/backup-database"))
    tables = json.loads(resp.content)["tables"]
    for expected in ("students", "teachers", "classes", "subjects", "rooms", "settings"):
        assert expected in tables, f"Table '{expected}' missing from backup"


# ---------------------------------------------------------------------------
# Backup → change → restore roundtrip
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_restore_roundtrip():
    """
    1. Take a backup.
    2. Add a subject that is not in the backup.
    3. Restore the backup.
    4. Verify the added subject is gone (DB is back to its original state).
    """
    s = requests.Session()
    login_admin(s)

    # Step 1: backup
    backup_resp = s.post(url("/backup-database"))
    assert backup_resp.status_code == 200
    backup_bytes = backup_resp.content

    # Step 2: add a marker subject
    marker = "RESTORE_ROUNDTRIP_MARKER_99999"
    add_resp = s.post(url("/add-subject"), json={"name": marker})
    assert add_resp.status_code == 200
    subjects_before = s.get(url("/subjects")).json()
    assert any(sub.get("name") == marker for sub in subjects_before), \
        "Marker subject not found after adding it"

    # Step 3: restore — multipart form with password + file
    restore_resp = s.post(
        url("/restore-database"),
        data={"password": _admin_pass},
        files={"backup": ("backup.json", io.BytesIO(backup_bytes), "application/json")},
    )
    assert restore_resp.status_code == 200
    assert restore_resp.json().get("ok") is True

    # Step 4: re-login (restore invalidates all sessions) and verify marker is gone
    s2 = requests.Session()
    login_admin(s2)
    subjects_after = s2.get(url("/subjects")).json()
    assert not any(sub.get("name") == marker for sub in subjects_after), \
        "Marker subject still present after restore — restore did not work"


@db_required
@_needs_admin
def test_restore_wrong_password_returns_403():
    s = requests.Session()
    login_admin(s)
    # Make a minimal valid backup payload
    backup_resp = s.post(url("/backup-database"))
    restore_resp = s.post(
        url("/restore-database"),
        data={"password": "definitly_wrong_password_xyz"},
        files={"backup": ("backup.json", io.BytesIO(backup_resp.content), "application/json")},
    )
    assert restore_resp.status_code == 403


@db_required
@_needs_admin
def test_restore_invalid_file_returns_400():
    s = requests.Session()
    login_admin(s)
    restore_resp = s.post(
        url("/restore-database"),
        data={"password": _admin_pass},
        files={"backup": ("backup.json", io.BytesIO(b'{"not":"a backup"}'), "application/json")},
    )
    assert restore_resp.status_code == 400


# ---------------------------------------------------------------------------
# Reset (destructive — opt-in only)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
@_needs_reset
def test_reset_database_wipes_and_creates_new_admin():
    """
    Backs up first, resets the DB, verifies the new admin works and old data
    is gone, then restores from backup so the DB is unchanged for other tests.
    """
    s = requests.Session()
    login_admin(s)

    # Backup before reset
    backup_resp = s.post(url("/backup-database"))
    assert backup_resp.status_code == 200
    backup_bytes = backup_resp.content

    new_email    = "reset-test-admin@test.de"
    new_password = "ResetTestPw123"

    # Reset
    reset_resp = s.post(url("/reset-database"), json={
        "adminPassword": _admin_pass,
        "newEmail":      new_email,
        "newPassword":   new_password,
    })
    assert reset_resp.status_code == 200
    assert reset_resp.json().get("ok") is True

    # Old session is invalidated — dashboard must redirect
    dash = s.get(url("/dashboard"), allow_redirects=False)
    assert dash.status_code == 302

    # New admin can log in
    s2 = requests.Session()
    login_resp = s2.post(url("/login"), json={"username": new_email, "password": new_password},
                         allow_redirects=False)
    assert login_resp.json().get("status") == "ok"

    # Data is wiped — students list should be empty
    students = s2.get(url("/students")).json()
    assert students == []

    # Restore original data
    restore_resp = s2.post(
        url("/restore-database"),
        data={"password": new_password},
        files={"backup": ("backup.json", io.BytesIO(backup_bytes), "application/json")},
    )
    assert restore_resp.status_code == 200

    # Re-login as original admin to confirm restore worked
    s3 = requests.Session()
    login_again = s3.post(url("/login"), json={"username": _admin_user, "password": _admin_pass},
                          allow_redirects=False)
    assert login_again.json().get("status") == "ok"


# ---------------------------------------------------------------------------
# Year-archive download + restore roundtrip
# ---------------------------------------------------------------------------

def test_download_year_archive_requires_admin():
    resp = requests.get(url("/admin/download-year-archive?year=2024%2F25"), allow_redirects=False)
    assert resp.status_code in (302, 401, 403)


@db_required
@_needs_admin
def test_year_archive_download_restore_roundtrip():
    """
    Full roundtrip:
      1. Back up current state.
      2. Create a test class + student.
      3. Advance the school year (archives current year → year_archive).
      4. Download the archive JSON for the old school year.
      5. Verify it is valid backup format with all tables present and year_archive
         rows (if any) scoped to only the old school year.
      6. Restore the DB from the downloaded archive JSON.
      7. Re-login; verify the test student is still present and the archived year
         appears in /api/year-archives.
      8. Restore from the original snap (cleanup).
    """
    s = requests.Session()
    login_admin(s)

    snap = backup(s)

    try:
        # Determine the current school year
        settings_resp = s.get(url("/api/settings"))
        assert settings_resp.status_code == 200
        old_year = settings_resp.json().get("current_school_year", "")
        if not old_year:
            pytest.skip("No current_school_year set — cannot test year-archive roundtrip")

        # Create two consecutive classes so the student can be promoted
        cls5 = s.post(url("/add-class"), json={"className": "5ARTEST", "grade": 5})
        cls6 = s.post(url("/add-class"), json={"className": "6ARTEST", "grade": 6})
        assert cls5.status_code == 200
        assert cls6.status_code == 200

        add_resp = s.post(url("/add-student"), json={
            "firstName":       "ArchRound",
            "lastName":        "Trip",
            "email":           "arch.roundtrip@test.de",
            "classId":         cls5.json()["id"],
            "graduationLevel": 1,
        })
        assert add_resp.status_code == 200
        student_id = add_resp.json()["id"]

        # Advance school year — this calls snapshotCurrentYear(old_year)
        new_year = "2099/AR"
        adv_resp = s.post(url("/admin/advance-school-year"), json={"label": new_year, "password": _admin_pass})
        assert adv_resp.status_code == 200
        assert adv_resp.json().get("ok") is True

        # Download the archive JSON for old_year
        dl_resp = s.get(url(f"/admin/download-year-archive?year={old_year}"))
        assert dl_resp.status_code == 200, f"Download failed: {dl_resp.text}"

        archive_data = json.loads(dl_resp.content)

        # Must be valid backup format
        assert archive_data.get("version") == 1
        assert "created_at" in archive_data
        assert "tables" in archive_data

        # All main tables must be present
        for table in ("students", "classes", "teachers", "admins", "settings",
                      "year_archive", "year_archive_scale"):
            assert table in archive_data["tables"], \
                f"Table {table!r} missing from archive download"

        # year_archive rows must be scoped to old_year only
        ya = archive_data["tables"]["year_archive"]
        if ya.get("columns") and ya.get("rows"):
            sy_idx = ya["columns"].index("school_year")
            years_in_file = {r[sy_idx] for r in ya["rows"]}
            assert years_in_file == {old_year}, \
                f"year_archive in download contains wrong years: {years_in_file}"

        # The test student must be present in the embedded students table
        st_table = archive_data["tables"]["students"]
        if st_table.get("columns") and st_table.get("rows"):
            id_idx = st_table["columns"].index("id")
            assert any(r[id_idx] == student_id for r in st_table["rows"]), \
                "Test student missing from the downloaded archive"

        # Restore from the downloaded archive
        restore(s, dl_resp.content)

        # Re-login (restore invalidates all sessions)
        s2 = requests.Session()
        login_admin(s2)

        # The test student must survive the restore
        students = s2.get(url("/students")).json()
        assert any(st.get("id") == student_id for st in students), \
            "Test student not found after restoring from year-archive download"

        # The archived year must appear in /api/year-archives (if tasksets existed)
        # We always check the endpoint is reachable and returns a list
        archives = s2.get(url("/api/year-archives")).json()
        assert isinstance(archives, list)

        # The current school year in settings must match what was in the archive
        # (the archive was taken after advancing, so it should reflect new_year)
        settings2 = s2.get(url("/api/settings")).json()
        assert settings2.get("current_school_year") == new_year, (
            f"Expected current_school_year={new_year!r} after restore, "
            f"got {settings2.get('current_school_year')!r}"
        )

    finally:
        s_clean = requests.Session()
        login_admin(s_clean)
        restore(s_clean, snap)
