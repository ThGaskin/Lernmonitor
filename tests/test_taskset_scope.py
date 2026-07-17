"""
Tests: taskset scope switching (grade ↔ class) and add/remove teacher roundtrip.

All tests that mutate state back up the DB first and restore afterwards.

Set env vars:
    TEST_ADMIN_USER=admin
    TEST_ADMIN_PASS=password
    APP_URL=http://localhost:8080
"""

import io
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


def current_task_scope(session: requests.Session) -> str:
    return session.get(url("/api/settings")).json().get("task_scope", "grade")


def set_task_scope(session: requests.Session, scope: str, force: bool = False) -> requests.Response:
    body = {"key": "task_scope", "value": scope}
    if force:
        body["force"] = True
    return session.post(url("/api/settings"), json=body)


def count_tasksets_for_class(session: requests.Session, class_id: int, subject_id: int) -> int:
    """Returns how many taskset rows exist for a specific class+subject combination."""
    resp = session.get(url("/api/admin-tasksets"))
    assert resp.status_code == 200
    entries = resp.json()
    count = 0
    for grade_entry in entries:
        for subj_entry in grade_entry["subjects"]:
            if subj_entry["subjectId"] != subject_id:
                continue
            for cls_entry in subj_entry["classes"]:
                if cls_entry["classId"] == class_id:
                    count += len(cls_entry["taskSets"])
    return count


def get_tasksets_for_class(session: requests.Session, class_id: int, subject_id: int) -> list:
    """Returns the list of taskset objects for a specific class+subject combination."""
    resp = session.get(url("/api/admin-tasksets"))
    assert resp.status_code == 200
    entries = resp.json()
    for grade_entry in entries:
        for subj_entry in grade_entry["subjects"]:
            if subj_entry["subjectId"] != subject_id:
                continue
            for cls_entry in subj_entry["classes"]:
                if cls_entry["classId"] == class_id:
                    return cls_entry["taskSets"]
    return []


# ---------------------------------------------------------------------------
# Helpers to set up a minimal fixture: one class, one subject, one teacher,
# one lerngruppe, one taskset. Returns IDs.
# ---------------------------------------------------------------------------

def create_fixture(s: requests.Session) -> dict:
    """
    Creates a minimal set of objects needed for scope tests.
    Returns {'classId', 'subjectId', 'teacherId', 'tasksetId'}.
    """
    cls_resp = s.post(url("/add-class"), json={"className": "SCOPE_TEST_5A", "grade": 55})
    assert cls_resp.status_code == 200
    class_id = cls_resp.json()["id"]

    subj_resp = s.post(url("/add-subject"), json={"name": "ScopeTestSubject99"})
    assert subj_resp.status_code == 200
    subjects = s.get(url("/subjects")).json()
    subject_id = next(x["id"] for x in subjects if x["name"] == "ScopeTestSubject99")

    teacher_resp = s.post(url("/add-teacher"), json={
        "firstName": "Scope",
        "lastName":  "TestTeacher99",
        "email":     "scope.testteacher99@test.de",
    })
    assert teacher_resp.status_code == 200
    teacher_id = teacher_resp.json()["id"]

    # Assign the subject to the class
    s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})

    # Assign the teacher to the lerngruppe
    lg_resp = s.post(url("/add-lerngruppe"), json={
        "teacherId": teacher_id,
        "classId":   class_id,
        "subjectId": subject_id,
    })
    assert lg_resp.status_code == 200

    return {"classId": class_id, "subjectId": subject_id, "teacherId": teacher_id}


# ---------------------------------------------------------------------------
# Access guards (no DB needed)
# ---------------------------------------------------------------------------

def test_settings_requires_admin():
    resp = requests.post(url("/api/settings"),
                         json={"key": "task_scope", "value": "grade"},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_add_lerngruppe_requires_admin():
    resp = requests.post(url("/add-lerngruppe"),
                         json={"teacherId": 1, "classId": 1, "subjectId": 1},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)

def test_remove_lerngruppe_requires_admin():
    resp = requests.post(url("/remove-lerngruppe"),
                         json={"teacherId": 1, "classId": 1, "subjectId": 1},
                         allow_redirects=False)
    assert resp.status_code in (302, 401, 403)


# ---------------------------------------------------------------------------
# Bug regression: remove + re-add teacher must not duplicate tasksets
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_readd_teacher_does_not_duplicate_tasksets():
    """
    Core regression test for the duplication bug:
    removing and re-adding a teacher in class-scope must not add extra taskset rows.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        # Force DB into class-scope regardless of starting state.
        # grade→class never fails, so no skip needed here.
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        ids = create_fixture(s)
        class_id   = ids["classId"]
        subject_id = ids["subjectId"]
        teacher_id = ids["teacherId"]

        # Create a taskset for the class (class-scope requires a class+subject assignment
        # and an existing teacher lerngruppe for 'create-grade-taskset' to find classes)
        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     55,
            "name":      "DupTestTask",
            "maxPoints": 5,
            "isPassFail": False,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        count_after_create = count_tasksets_for_class(s, class_id, subject_id)
        assert count_after_create == 1, \
            f"Expected 1 taskset after create, got {count_after_create}"

        # Remove the teacher
        rm_resp = s.post(url("/remove-lerngruppe"), json={
            "teacherId": teacher_id,
            "classId":   class_id,
            "subjectId": subject_id,
        })
        assert rm_resp.status_code == 200

        # Re-add the teacher
        add_resp = s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id,
            "classId":   class_id,
            "subjectId": subject_id,
        })
        assert add_resp.status_code == 200

        count_after_readd = count_tasksets_for_class(s, class_id, subject_id)
        assert count_after_readd == count_after_create, (
            f"Taskset count changed after remove+re-add: "
            f"{count_after_create} → {count_after_readd} (duplication bug)"
        )

        # Do it a second time to catch the ×3 variant of the bug
        s.post(url("/remove-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })
        s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })
        count_after_second_readd = count_tasksets_for_class(s, class_id, subject_id)
        assert count_after_second_readd == count_after_create, (
            f"Taskset count changed after second remove+re-add: "
            f"{count_after_create} → {count_after_second_readd} (duplication bug)"
        )

    finally:
        restore(s, snap)


# ---------------------------------------------------------------------------
# Grade → class scope migration
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_grade_to_class_scope_copies_tasksets_to_each_class():
    """
    Switching grade→class must create one taskset row per class at that grade,
    and delete the original grade-level (class_id IS NULL) row.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        # Force DB into grade-scope regardless of starting state.
        if current_task_scope(s) != "grade":
            r = set_task_scope(s, "grade", force=True)
            if r.status_code != 200:
                pytest.skip(f"Cannot switch to grade-scope (hard conflict in existing data): {r.text}")

        # Create two classes at the same grade
        r1 = s.post(url("/add-class"), json={"className": "SCOPE_5X", "grade": 55})
        r2 = s.post(url("/add-class"), json={"className": "SCOPE_5Y", "grade": 55})
        assert r1.status_code == 200 and r2.status_code == 200
        class_id_x = r1.json()["id"]
        class_id_y = r2.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "ScopeMigSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "ScopeMigSubject99")

        teacher_resp = s.post(url("/add-teacher"), json={
            "firstName": "Mig",
            "lastName":  "Teacher99",
            "email":     "mig.teacher99@test.de",
        })
        assert teacher_resp.status_code == 200
        teacher_id = teacher_resp.json()["id"]

        # Assign teacher to both classes
        s.post(url("/assign-class-subject"), json={"classId": class_id_x, "subjectId": subject_id})
        s.post(url("/assign-class-subject"), json={"classId": class_id_y, "subjectId": subject_id})
        s.post(url("/add-lerngruppe"), json={"teacherId": teacher_id, "classId": class_id_x, "subjectId": subject_id})
        s.post(url("/add-lerngruppe"), json={"teacherId": teacher_id, "classId": class_id_y, "subjectId": subject_id})

        # Create a grade-level taskset
        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     55,
            "name":      "GradeMigTask",
            "maxPoints": 5,
            "isPassFail": False,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        # Switch to class-scope
        switch_resp = set_task_scope(s, "class")
        assert switch_resp.status_code == 200, f"scope switch failed: {switch_resp.text}"

        # Both classes must now have exactly 1 taskset named "GradeMigTask"
        count_x = count_tasksets_for_class(s, class_id_x, subject_id)
        count_y = count_tasksets_for_class(s, class_id_y, subject_id)
        assert count_x == 1, f"Class X: expected 1 taskset after migration, got {count_x}"
        assert count_y == 1, f"Class Y: expected 1 taskset after migration, got {count_y}"

    finally:
        restore(s, snap)


# ---------------------------------------------------------------------------
# Class → grade scope migration (merge)
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_class_to_grade_scope_merges_tasksets():
    """
    Switching class→grade must collapse per-class tasksets into a single
    grade-level row per (grade, subject, name), and remove all class-specific rows.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        # Force DB into class-scope regardless of starting state.
        # grade→class never fails, so no skip needed here.
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        ids = create_fixture(s)
        class_id   = ids["classId"]
        subject_id = ids["subjectId"]

        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     55,
            "name":      "MergeTestTask",
            "maxPoints": 5,
            "isPassFail": False,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        # Switch back to grade-scope (may require force if there are soft warnings)
        switch_resp = set_task_scope(s, "grade")
        if switch_resp.status_code == 409 and switch_resp.json().get("error") == "soft_warning":
            switch_resp = set_task_scope(s, "grade", force=True)
        assert switch_resp.status_code == 200, f"scope switch failed: {switch_resp.text}"

        # The taskset must now exist at grade-level (class_id IS NULL).
        # In grade-scope, /api/admin-tasksets shows tasksets per grade/subject without class filters.
        tasksets_resp = s.get(url("/api/admin-tasksets"))
        assert tasksets_resp.status_code == 200
        all_entries = tasksets_resp.json()
        grade55 = next((g for g in all_entries if g.get("grade") == 55), None)
        assert grade55 is not None, "Grade 55 missing from admin-tasksets after merge"

        all_names = [
            ts["name"]
            for subj_entry in grade55["subjects"]
            for cls_entry in subj_entry["classes"]
            for ts in cls_entry["taskSets"]
        ]
        assert "MergeTestTask" in all_names, (
            f"Taskset 'MergeTestTask' missing after class→grade merge. Found: {all_names}"
        )

        # Verify the setting was actually saved
        assert s.get(url("/api/settings")).json().get("task_scope") == "grade"

    finally:
        restore(s, snap)


# ---------------------------------------------------------------------------
# Full roundtrip: create → add teacher → class-scope → remove → re-add → grade-scope
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_full_scope_roundtrip():
    """
    Complete roundtrip as described in the bug report:
    1. Start in grade-scope, create a taskset
    2. Assign a teacher
    3. Switch to class-scope
    4. Remove the teacher
    5. Re-add the teacher (must not duplicate)
    6. Switch back to grade-scope (must not duplicate)

    At each step, taskset count must match expectations.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        # Force DB into grade-scope regardless of starting state.
        if current_task_scope(s) != "grade":
            r = set_task_scope(s, "grade", force=True)
            if r.status_code != 200:
                pytest.skip(f"Cannot switch to grade-scope (hard conflict in existing data): {r.text}")

        # --- Step 1: create objects and a grade-level taskset ---
        r = s.post(url("/add-class"), json={"className": "ROUNDTRIP_5Z", "grade": 55})
        assert r.status_code == 200
        class_id = r.json()["id"]

        subj_r = s.post(url("/add-subject"), json={"name": "RoundtripSubject99"})
        assert subj_r.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "RoundtripSubject99")

        teacher_r = s.post(url("/add-teacher"), json={
            "firstName": "Round",
            "lastName":  "TripTeacher99",
            "email":     "round.tripteacher99@test.de",
        })
        assert teacher_r.status_code == 200
        teacher_id = teacher_r.json()["id"]

        s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})

        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     55,
            "name":      "RoundtripTask",
            "maxPoints": 5,
            "isPassFail": False,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        # --- Step 2: assign teacher ---
        s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })

        # --- Step 3: switch to class-scope ---
        switch_to_class = set_task_scope(s, "class")
        assert switch_to_class.status_code == 200, f"grade→class failed: {switch_to_class.text}"

        count_after_class_switch = count_tasksets_for_class(s, class_id, subject_id)
        assert count_after_class_switch == 1, (
            f"Expected 1 taskset after grade→class switch, got {count_after_class_switch}"
        )

        # --- Step 4: remove teacher ---
        s.post(url("/remove-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })

        # --- Step 5: re-add teacher — must NOT duplicate ---
        s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })

        count_after_readd = count_tasksets_for_class(s, class_id, subject_id)
        assert count_after_readd == count_after_class_switch, (
            f"Taskset count changed after remove+re-add: "
            f"{count_after_class_switch} → {count_after_readd} (duplication bug)"
        )

        # --- Step 6: switch back to grade-scope ---
        switch_to_grade = set_task_scope(s, "grade")
        if switch_to_grade.status_code == 409 and switch_to_grade.json().get("error") == "soft_warning":
            switch_to_grade = set_task_scope(s, "grade", force=True)
        assert switch_to_grade.status_code == 200, f"class→grade failed: {switch_to_grade.text}"

        assert s.get(url("/api/settings")).json().get("task_scope") == "grade"

        # Grade-scope taskset must exist exactly once for this subject/grade
        tasksets_resp = s.get(url("/api/admin-tasksets"))
        all_entries = tasksets_resp.json()
        grade55 = next((g for g in all_entries if g.get("grade") == 55), None)
        assert grade55 is not None

        roundtrip_names = [
            ts["name"]
            for subj_entry in grade55["subjects"]
            for cls_entry in subj_entry["classes"]
            for ts in cls_entry["taskSets"]
            if ts["name"] == "RoundtripTask"
        ]
        assert len(roundtrip_names) == 1, (
            f"Expected exactly 1 'RoundtripTask' after class→grade merge, "
            f"got {len(roundtrip_names)} (duplication or loss)"
        )

    finally:
        restore(s, snap)


# ---------------------------------------------------------------------------
# pass/fail: migration preserves the flag; mismatch blocks class→grade merge
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_grade_to_class_migration_preserves_is_pass_fail():
    """
    Switching grade→class must copy is_pass_fail to every class-specific row.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "grade":
            r = set_task_scope(s, "grade", force=True)
            if r.status_code != 200:
                pytest.skip(f"Cannot switch to grade-scope: {r.text}")

        r1 = s.post(url("/add-class"), json={"className": "PF_5X", "grade": 155})
        r2 = s.post(url("/add-class"), json={"className": "PF_5Y", "grade": 155})
        assert r1.status_code == 200 and r2.status_code == 200
        class_id_x = r1.json()["id"]
        class_id_y = r2.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "PFMigSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "PFMigSubject99")

        teacher_resp = s.post(url("/add-teacher"), json={
            "firstName": "PF", "lastName": "Teacher99", "email": "pf.teacher99@test.de",
        })
        assert teacher_resp.status_code == 200
        teacher_id = teacher_resp.json()["id"]

        for cid in (class_id_x, class_id_y):
            s.post(url("/assign-class-subject"), json={"classId": cid, "subjectId": subject_id})
            s.post(url("/add-lerngruppe"), json={"teacherId": teacher_id, "classId": cid, "subjectId": subject_id})

        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     155,
            "name":      "PassFailTask",
            "maxPoints": 10,
            "isPassFail": True,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        switch_resp = set_task_scope(s, "class")
        assert switch_resp.status_code == 200, f"grade→class failed: {switch_resp.text}"

        for cid in (class_id_x, class_id_y):
            tasksets = get_tasksets_for_class(s, cid, subject_id)
            assert len(tasksets) == 1, f"Expected 1 taskset for class {cid}, got {len(tasksets)}"
            assert tasksets[0]["isPassFail"] is True, (
                f"is_pass_fail not preserved for class {cid}: got {tasksets[0]['isPassFail']}"
            )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_pass_fail_mismatch_blocks_class_to_grade_merge():
    """
    Two classes at the same grade with the same task name+maxPoints but different
    is_pass_fail must produce a hard conflict when switching class→grade.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        r1 = s.post(url("/add-class"), json={"className": "PF_CONFLICT_A", "grade": 156})
        r2 = s.post(url("/add-class"), json={"className": "PF_CONFLICT_B", "grade": 156})
        assert r1.status_code == 200 and r2.status_code == 200
        class_id_a = r1.json()["id"]
        class_id_b = r2.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "PFConflictSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "PFConflictSubject99")

        teacher_resp = s.post(url("/add-teacher"), json={
            "firstName": "PFC", "lastName": "Teacher99", "email": "pfc.teacher99@test.de",
        })
        assert teacher_resp.status_code == 200
        teacher_id = teacher_resp.json()["id"]

        for cid in (class_id_a, class_id_b):
            s.post(url("/assign-class-subject"), json={"classId": cid, "subjectId": subject_id})
            s.post(url("/add-lerngruppe"), json={"teacherId": teacher_id, "classId": cid, "subjectId": subject_id})

        # Create task with pf=False for both classes, then flip only class A to pf=True.
        # (Two separate grade-level creates would give both classes both variants, not a mismatch.)
        ts = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     156,
            "name":      "ConflictTask",
            "maxPoints": 5,
            "isPassFail": False,
        })
        assert ts.status_code == 200, f"create taskset failed: {ts.text}"

        # Update only class A's row to pf=True — class B keeps pf=False.
        tasksets_a = get_tasksets_for_class(s, class_id_a, subject_id)
        assert len(tasksets_a) == 1, f"Expected 1 taskset for class A, got {len(tasksets_a)}"
        flip = s.post(url("/update-lg-taskset"), json={
            "tasksetId":  tasksets_a[0]["id"],
            "name":       tasksets_a[0]["name"],
            "maxPoints":  tasksets_a[0]["maxPoints"],
            "isPassFail": True,
        })
        assert flip.status_code == 200, f"update-lg-taskset failed: {flip.text}"

        # class→grade merge must be blocked by a hard conflict
        merge_resp = set_task_scope(s, "grade")
        assert merge_resp.status_code == 409, (
            f"Expected 409 hard conflict due to is_pass_fail mismatch, got {merge_resp.status_code}: {merge_resp.text}"
        )
        body = merge_resp.json()
        assert body.get("error") != "soft_warning", (
            "is_pass_fail mismatch must be a hard conflict, not a soft warning"
        )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_hidden_class_pf_mismatch_does_not_block_merge():
    """
    A class whose subject assignment has been removed is hidden from the admin panel.
    Its stale is_pass_fail value must NOT block a class→grade migration when all
    visible (subject-assigned) classes are consistent.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        r1 = s.post(url("/add-class"), json={"className": "HM_A", "grade": 158})
        r2 = s.post(url("/add-class"), json={"className": "HM_B", "grade": 158})
        r3 = s.post(url("/add-class"), json={"className": "HM_C", "grade": 158})
        assert r1.status_code == 200 and r2.status_code == 200 and r3.status_code == 200
        class_id_a = r1.json()["id"]
        class_id_b = r2.json()["id"]
        class_id_c = r3.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "HMSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "HMSubject99")

        teacher_resp = s.post(url("/add-teacher"), json={
            "firstName": "HM", "lastName": "Teacher99", "email": "hm.teacher99@test.de",
        })
        assert teacher_resp.status_code == 200
        teacher_id = teacher_resp.json()["id"]

        for cid in (class_id_a, class_id_b, class_id_c):
            s.post(url("/assign-class-subject"), json={"classId": cid, "subjectId": subject_id})
            s.post(url("/add-lerngruppe"), json={"teacherId": teacher_id, "classId": cid, "subjectId": subject_id})

        ts = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     158,
            "name":      "HiddenTask",
            "maxPoints": 7,
            "isPassFail": True,
        })
        assert ts.status_code == 200, f"create taskset failed: {ts.text}"

        # Set class C's task to pf=False while it is still visible.
        tasksets_c = get_tasksets_for_class(s, class_id_c, subject_id)
        assert len(tasksets_c) == 1, f"Expected 1 taskset for class C, got {len(tasksets_c)}"
        flip = s.post(url("/update-lg-taskset"), json={
            "tasksetId":  tasksets_c[0]["id"],
            "name":       tasksets_c[0]["name"],
            "maxPoints":  tasksets_c[0]["maxPoints"],
            "isPassFail": False,
        })
        assert flip.status_code == 200, f"update-lg-taskset failed: {flip.text}"

        # Remove C's subject assignment so it becomes hidden from the admin panel.
        rm = s.post(url("/remove-class-subject"), json={"classId": class_id_c, "subjectId": subject_id})
        assert rm.status_code == 200, f"remove-class-subject failed: {rm.text}"

        # With the fix, the stale pf=False row for the hidden class C must not
        # block migration — only the two visible classes (A and B, both pf=True) matter.
        merge_resp = set_task_scope(s, "grade")
        assert merge_resp.status_code == 200, (
            f"Expected migration to succeed when mismatch is only from a hidden class, "
            f"got {merge_resp.status_code}: {merge_resp.text}"
        )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_class_to_grade_migration_preserves_is_pass_fail():
    """
    Switching class→grade must carry is_pass_fail through to the merged grade-level row.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        r = s.post(url("/add-class"), json={"className": "PF_MERGE_A", "grade": 157})
        assert r.status_code == 200
        class_id = r.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "PFMergeSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "PFMergeSubject99")

        teacher_resp = s.post(url("/add-teacher"), json={
            "firstName": "PFM", "lastName": "Teacher99", "email": "pfm.teacher99@test.de",
        })
        assert teacher_resp.status_code == 200
        teacher_id = teacher_resp.json()["id"]

        s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})
        s.post(url("/add-lerngruppe"), json={"teacherId": teacher_id, "classId": class_id, "subjectId": subject_id})

        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     157,
            "name":      "MergePassFailTask",
            "maxPoints": 8,
            "isPassFail": True,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        switch_resp = set_task_scope(s, "grade")
        if switch_resp.status_code == 409 and switch_resp.json().get("error") == "soft_warning":
            switch_resp = set_task_scope(s, "grade", force=True)
        assert switch_resp.status_code == 200, f"class→grade failed: {switch_resp.text}"

        all_entries = s.get(url("/api/admin-tasksets")).json()
        grade157 = next((g for g in all_entries if g.get("grade") == 157), None)
        assert grade157 is not None, "Grade 157 missing after merge"

        merged_tasksets = [
            ts
            for subj_entry in grade157["subjects"]
            for cls_entry in subj_entry["classes"]
            for ts in cls_entry["taskSets"]
            if ts["name"] == "MergePassFailTask"
        ]
        assert len(merged_tasksets) == 1, f"Expected 1 merged taskset, got {len(merged_tasksets)}"
        assert merged_tasksets[0]["isPassFail"] is True, (
            f"is_pass_fail not preserved through class→grade merge: got {merged_tasksets[0]['isPassFail']}"
        )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_copy_to_new_lerngruppe_preserves_is_pass_fail():
    """
    When a second teacher is assigned to a class that already has tasksets,
    copyTaskSetsToNewLerngruppe must carry is_pass_fail to the copied rows.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        r = s.post(url("/add-class"), json={"className": "PF_COPY_A", "grade": 158})
        assert r.status_code == 200
        class_id = r.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "PFCopySubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "PFCopySubject99")

        t1_resp = s.post(url("/add-teacher"), json={
            "firstName": "PFCopy1", "lastName": "Teacher99", "email": "pfcopy1.teacher99@test.de",
        })
        t2_resp = s.post(url("/add-teacher"), json={
            "firstName": "PFCopy2", "lastName": "Teacher99", "email": "pfcopy2.teacher99@test.de",
        })
        assert t1_resp.status_code == 200 and t2_resp.status_code == 200
        teacher1_id = t1_resp.json()["id"]
        teacher2_id = t2_resp.json()["id"]

        s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})
        s.post(url("/add-lerngruppe"), json={"teacherId": teacher1_id, "classId": class_id, "subjectId": subject_id})

        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id,
            "grade":     158,
            "name":      "CopyPassFailTask",
            "maxPoints": 6,
            "isPassFail": True,
        })
        assert ts_resp.status_code == 200, f"create-grade-taskset failed: {ts_resp.text}"

        # Assign a second teacher — triggers copyTaskSetsToNewLerngruppe internally
        add_resp = s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher2_id, "classId": class_id, "subjectId": subject_id,
        })
        assert add_resp.status_code == 200, f"add second lerngruppe failed: {add_resp.text}"

        tasksets = get_tasksets_for_class(s, class_id, subject_id)
        pf_task = next((ts for ts in tasksets if ts["name"] == "CopyPassFailTask"), None)
        assert pf_task is not None, "CopyPassFailTask not found after second teacher added"
        assert pf_task["isPassFail"] is True, (
            f"is_pass_fail not preserved when copying to new lerngruppe: got {pf_task['isPassFail']}"
        )

    finally:
        restore(s, snap)


# ---------------------------------------------------------------------------
# Subject-assignment guard: add-lerngruppe, admin panel filter, CSV import
# ---------------------------------------------------------------------------

@db_required
@_needs_admin
def test_add_lerngruppe_blocked_when_subject_not_assigned_to_class():
    """
    /add-lerngruppe must return 400 when the class has not been assigned the
    subject via class_subjects, with an error message directing admin to the
    Klassen panel.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        r = s.post(url("/add-class"), json={"className": "GUARD_6A", "grade": 160})
        assert r.status_code == 200
        class_id = r.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "GuardSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "GuardSubject99")

        t_resp = s.post(url("/add-teacher"), json={
            "firstName": "Guard", "lastName": "Teacher99", "email": "guard.teacher99@test.de",
        })
        assert t_resp.status_code == 200
        teacher_id = t_resp.json()["id"]

        # Do NOT assign the subject to the class — attempt should be blocked
        resp = s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })
        assert resp.status_code == 400, (
            f"Expected 400 when subject not assigned to class, got {resp.status_code}: {resp.text}"
        )
        body = resp.json()
        assert "error" in body, "Response must include an error message"

        # After assigning the subject it must succeed
        s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})
        resp2 = s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })
        assert resp2.status_code == 200, (
            f"Expected 200 after subject assigned, got {resp2.status_code}: {resp2.text}"
        )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_admin_tasksets_hides_class_without_subject_assigned():
    """
    A class+subject lerngruppe (with tasks) must not appear in /api/admin-tasksets
    when the class has not been assigned that subject in class_subjects.
    Once the subject is assigned it must appear.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        r = s.post(url("/add-class"), json={"className": "FILTER_6B", "grade": 161})
        assert r.status_code == 200
        class_id = r.json()["id"]

        subj_resp = s.post(url("/add-subject"), json={"name": "FilterSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_id = next(x["id"] for x in subjects if x["name"] == "FilterSubject99")

        t_resp = s.post(url("/add-teacher"), json={
            "firstName": "Filter", "lastName": "Teacher99", "email": "filter.teacher99@test.de",
        })
        assert t_resp.status_code == 200
        teacher_id = t_resp.json()["id"]

        # Assign subject first so we can add the lerngruppe and create a taskset
        s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})
        s.post(url("/add-lerngruppe"), json={
            "teacherId": teacher_id, "classId": class_id, "subjectId": subject_id,
        })
        ts_resp = s.post(url("/admin/create-grade-taskset"), json={
            "subjectId": subject_id, "grade": 161,
            "name": "FilterTask", "maxPoints": 3, "isPassFail": False,
        })
        assert ts_resp.status_code == 200

        # Remove the subject assignment — the class should now be hidden
        s.post(url("/remove-class-subject"), json={"classId": class_id, "subjectId": subject_id})

        count_hidden = count_tasksets_for_class(s, class_id, subject_id)
        assert count_hidden == 0, (
            f"Class should be hidden from admin panel when subject unassigned, "
            f"but found {count_hidden} tasksets"
        )

        # Re-assign — it must reappear
        s.post(url("/assign-class-subject"), json={"classId": class_id, "subjectId": subject_id})
        count_visible = count_tasksets_for_class(s, class_id, subject_id)
        assert count_visible == 1, (
            f"Class should reappear after subject re-assigned, got {count_visible} tasksets"
        )

    finally:
        restore(s, snap)


@db_required
@_needs_admin
def test_aufgabenset_csv_import_blocked_when_subject_not_assigned():
    """
    Importing an aufgabensets CSV row for a class-scope taskset must be marked
    invalid in the preview when the class has not been assigned that subject.
    """
    s = requests.Session()
    login_admin(s)
    snap = backup(s)

    try:
        if current_task_scope(s) != "class":
            set_task_scope(s, "class")

        r = s.post(url("/add-class"), json={"className": "CSV_6C", "grade": 162})
        assert r.status_code == 200

        subj_resp = s.post(url("/add-subject"), json={"name": "CsvGuardSubject99"})
        assert subj_resp.status_code == 200
        subjects = s.get(url("/subjects")).json()
        subject_name = next(x["name"] for x in subjects if x["name"] == "CsvGuardSubject99")

        # CSV row: id, school_year, subject, class, grade, name, maxPoints, active, isPassFail
        csv_row = f",2024-2025,{subject_name},CSV_6C,162,TestTask,5,1,0\n"

        preview = s.post(url("/preview-import"), json={"type": "aufgabensets", "csv": csv_row}).json()
        assert preview["rows"], "Preview must return at least one row"
        row = preview["rows"][0]
        assert row["status"] == "invalid", (
            f"Row must be invalid when class has no subject assigned, got status={row['status']}"
        )
        assert any("zugewiesen" in e for e in row.get("errors", [])), (
            f"Error must mention subject not assigned, got: {row.get('errors')}"
        )

        # After assigning the subject the row must be valid
        s.post(url("/assign-class-subject"), json={
            "classId": r.json()["id"],
            "subjectId": next(x["id"] for x in subjects if x["name"] == "CsvGuardSubject99"),
        })
        preview2 = s.post(url("/preview-import"), json={"type": "aufgabensets", "csv": csv_row}).json()
        row2 = preview2["rows"][0]
        assert row2["status"] != "invalid", (
            f"Row must be valid after subject assigned, got status={row2['status']}, errors={row2.get('errors')}"
        )

    finally:
        restore(s, snap)
