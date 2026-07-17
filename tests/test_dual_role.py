"""
Tests for dual-role admin/teacher accounts.

Covers:
  - Promoting a teacher to admin creates a linked admin with the teacher's name
  - Deleting a dual-role teacher keeps the admin (now unlinked)
  - Adding the teacher back with matching credentials re-links the admin
  - Deleting the admin keeps the teacher (now unlinked)
  - Full sequential round-trip

All tests are self-contained: they clean up before and after, so they can run
in any order and are safe to re-run against a dirty DB.
"""

import requests
import pytest
from conftest import url, db_required, ADMIN_USER, ADMIN_PASS

_EMAIL = "dual.role.roundtrip@test.de"
_BN    = "dual_rt_bn"
_FIRST = "Dual"
_LAST  = "Roundtrip"


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _admin_session() -> requests.Session:
    s = requests.Session()
    s.post(url("/login"), json={"username": ADMIN_USER, "password": ADMIN_PASS}, allow_redirects=False)
    return s


def _cleanup(s: requests.Session) -> None:
    """Remove any test teacher and admin left from a previous run."""
    for t in s.get(url("/teachers")).json():
        if t.get("email") == _EMAIL:
            s.post(url("/delete-teacher"), json={"id": t["id"]})
    for a in s.get(url("/admins")).json():
        if a.get("email") == _EMAIL:
            s.post(url("/delete-admin"), json={"username": a["username"]})


def _add_teacher(s: requests.Session) -> dict:
    resp = s.post(url("/add-teacher"), json={
        "firstName": _FIRST, "lastName": _LAST,
        "email": _EMAIL, "benutzername": _BN,
    })
    assert resp.status_code == 200, resp.text
    return resp.json()


def _promote(s: requests.Session, teacher_id: int) -> None:
    resp = s.post(url("/promote-to-admin"), json={"id": teacher_id, "type": "teacher"})
    assert resp.status_code == 200, resp.text


def _find_admin(s: requests.Session) -> dict | None:
    return next((a for a in s.get(url("/admins")).json() if a.get("email") == _EMAIL), None)


def _find_teacher(s: requests.Session) -> dict | None:
    return next((t for t in s.get(url("/teachers")).json() if t.get("email") == _EMAIL), None)


# ---------------------------------------------------------------------------
# Individual step tests
# ---------------------------------------------------------------------------

@db_required
def test_promote_teacher_to_admin_creates_linked_account():
    """Promoting a teacher creates an admin with the teacher's name and hasLinkedTeacher=true."""
    s = _admin_session()
    _cleanup(s)

    teacher = _add_teacher(s)
    assert not teacher.get("linked")

    _promote(s, teacher["id"])

    # Teacher still exists
    assert _find_teacher(s) is not None

    # Admin created, name copied, link present
    admin = _find_admin(s)
    assert admin is not None
    assert admin.get("firstName") == _FIRST
    assert admin.get("lastName") == _LAST
    assert admin.get("hasLinkedTeacher")

    _cleanup(s)


@db_required
def test_add_teacher_with_matching_admin_credentials_links_directly():
    """Creating a teacher whose email+BN match an existing admin auto-links them."""
    s = _admin_session()
    _cleanup(s)

    # First create standalone admin
    resp = s.post(url("/add-admin"), json={
        "firstName": _FIRST, "lastName": _LAST,
        "email": _EMAIL, "benutzername": _BN,
    })
    assert resp.status_code == 200

    # Add teacher with matching credentials → should auto-link
    resp = s.post(url("/add-teacher"), json={
        "firstName": _FIRST, "lastName": _LAST,
        "email": _EMAIL, "benutzername": _BN,
    })
    assert resp.status_code == 200
    assert resp.json().get("linked")

    admin = _find_admin(s)
    assert admin is not None
    assert admin.get("hasLinkedTeacher")

    _cleanup(s)


@db_required
def test_delete_dual_role_teacher_keeps_admin_unlinked():
    """Deleting a teacher who has a linked admin removes only the teacher row."""
    s = _admin_session()
    _cleanup(s)

    teacher = _add_teacher(s)
    _promote(s, teacher["id"])

    resp = s.post(url("/delete-teacher"), json={"id": teacher["id"]})
    assert resp.status_code == 200

    assert _find_teacher(s) is None

    admin = _find_admin(s)
    assert admin is not None, "Admin should survive teacher deletion"
    assert not admin.get("hasLinkedTeacher"), "Link should be broken after teacher deleted"

    _cleanup(s)


@db_required
def test_delete_admin_keeps_teacher_unlinked():
    """Deleting an admin who has a linked teacher removes only the admin row."""
    s = _admin_session()
    _cleanup(s)

    teacher = _add_teacher(s)
    _promote(s, teacher["id"])

    admin = _find_admin(s)
    resp = s.post(url("/delete-admin"), json={"username": admin["username"]})
    assert resp.status_code == 200

    assert _find_admin(s) is None

    assert _find_teacher(s) is not None, "Teacher should survive admin deletion"

    _cleanup(s)


@db_required
def test_readd_teacher_after_delete_relinks_admin():
    """After a dual-role teacher is deleted, adding them back with the same credentials re-links."""
    s = _admin_session()
    _cleanup(s)

    teacher = _add_teacher(s)
    _promote(s, teacher["id"])
    s.post(url("/delete-teacher"), json={"id": teacher["id"]})

    assert _find_teacher(s) is None
    assert _find_admin(s) is not None

    # Re-add teacher with the same email + BN
    resp = s.post(url("/add-teacher"), json={
        "firstName": _FIRST, "lastName": _LAST,
        "email": _EMAIL, "benutzername": _BN,
    })
    assert resp.status_code == 200
    assert resp.json().get("linked"), "Re-added teacher should auto-link to existing admin"

    admin = _find_admin(s)
    assert admin is not None
    assert admin.get("hasLinkedTeacher")

    _cleanup(s)


# ---------------------------------------------------------------------------
# Full round-trip
# ---------------------------------------------------------------------------

@db_required
def test_dual_role_full_roundtrip():
    """
    Complete sequence as specified:
      1. Add teacher (no link)
      2. Promote to admin  → teacher + admin, linked
      3. Delete teacher    → admin survives, unlinked
      4. Add teacher back  → auto-linked to existing admin
      5. Delete admin      → teacher survives, unlinked
    """
    s = _admin_session()
    _cleanup(s)

    # 1. Add teacher
    teacher = _add_teacher(s)
    teacher_id = teacher["id"]
    assert not teacher.get("linked")
    assert _find_teacher(s) is not None
    assert _find_admin(s) is None

    # 2. Promote to admin
    _promote(s, teacher_id)
    assert _find_teacher(s) is not None
    admin = _find_admin(s)
    assert admin is not None
    assert admin.get("hasLinkedTeacher")
    assert admin.get("firstName") == _FIRST  # name copied on promotion

    # 3. Delete teacher → admin survives, no longer linked
    s.post(url("/delete-teacher"), json={"id": teacher_id})
    assert _find_teacher(s) is None
    admin = _find_admin(s)
    assert admin is not None
    assert not admin.get("hasLinkedTeacher")

    # 4. Add teacher back with same credentials → auto-links
    resp = s.post(url("/add-teacher"), json={
        "firstName": _FIRST, "lastName": _LAST,
        "email": _EMAIL, "benutzername": _BN,
    })
    assert resp.status_code == 200
    assert resp.json().get("linked")
    new_teacher_id = resp.json()["id"]
    admin = _find_admin(s)
    assert admin is not None
    assert admin.get("hasLinkedTeacher")

    # 5. Delete admin → teacher survives, unlinked
    s.post(url("/delete-admin"), json={"username": admin["username"]})
    assert _find_admin(s) is None
    assert _find_teacher(s) is not None

    _cleanup(s)
