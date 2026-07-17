"""
pytest configuration: spins up a dedicated test database and PHP server.

On every test session:
  1. Drops and recreates `student_database_test`
  2. Applies sql/schema.sql  (--force to skip the end-of-file ALTER TABLE lines
     that fail on a fresh DB because the columns are already in CREATE TABLE)
  3. Seeds sql/test_seed.sql
  4. Starts a PHP dev server on TEST_SERVER_PORT (default 8081) pointed at
     the test DB
  5. Tears everything down after all tests finish

Override with APP_URL to test against an already-running server instead:
    APP_URL=http://localhost:8080 pytest
In that case, the managed server and DB setup are skipped entirely.
"""

import os
import subprocess
import time
import requests
import pytest

# ---------------------------------------------------------------------------
# DB / server config — all overridable via env vars
# ---------------------------------------------------------------------------

_DB_HOST = os.environ.get("TEST_DB_HOST", "127.0.0.1")
_DB_PORT = os.environ.get("TEST_DB_PORT", "3306")
_DB_USER = os.environ.get("TEST_DB_USER", "root")
_DB_PASS = os.environ.get("TEST_DB_PASS", "")
_DB_NAME = os.environ.get("TEST_DB_NAME", "student_database_test")
_SERVER_PORT = os.environ.get("TEST_SERVER_PORT", "8081")

_MANAGED = "APP_URL" not in os.environ  # False → user provided their own server

BASE_URL = os.environ.get("APP_URL", f"http://localhost:{_SERVER_PORT}").rstrip("/")

# ---------------------------------------------------------------------------
# Test credentials — match the values in sql/test_seed.sql
# ---------------------------------------------------------------------------

ADMIN_USER     = "admin@test.de"
ADMIN_PASS     = "testAdminPass123"
TEACHER1_EMAIL = "teacher1@test.de"
TEACHER1_PASS  = "testTeacher1Pass"
TEACHER2_EMAIL = "teacher2@test.de"
TEACHER2_PASS  = "testTeacher2Pass"
STUDENT1_EMAIL = "student1@test.de"
STUDENT1_PASS  = "testStudent1Pass"
STUDENT2_EMAIL = "student2@test.de"
STUDENT2_PASS  = "testStudent2Pass"

# Inject into env so existing test files that read os.environ.get(...) just work
os.environ.setdefault("TEST_ADMIN_USER",    ADMIN_USER)
os.environ.setdefault("TEST_ADMIN_PASS",    ADMIN_PASS)
os.environ.setdefault("TEST_TEACHER_EMAIL", TEACHER1_EMAIL)
os.environ.setdefault("TEST_TEACHER_PASS",  TEACHER1_PASS)
os.environ.setdefault("TEST_STUDENT_EMAIL", STUDENT1_EMAIL)
os.environ.setdefault("TEST_STUDENT_PASS",  STUDENT1_PASS)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_SCHEMA_FILE  = os.path.join(_PROJECT_ROOT, "sql", "schema.sql")
_SEED_FILE    = os.path.join(_PROJECT_ROOT, "sql", "test_seed.sql")


def _mysql_cmd(db: str | None = None) -> list[str]:
    cmd = [
        "mysql",
        f"--host={_DB_HOST}",
        f"--port={_DB_PORT}",
        f"--user={_DB_USER}",
    ]
    if _DB_PASS:
        cmd.append(f"--password={_DB_PASS}")
    if db:
        cmd.append(db)
    return cmd


def _run_sql(sql: str, db: str | None = None) -> None:
    proc = subprocess.run(
        _mysql_cmd(db),
        input=sql.encode(),
        capture_output=True,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"MySQL error:\n{proc.stderr.decode()}")


def _run_sql_file(path: str, db: str | None = None, force: bool = False) -> None:
    cmd = _mysql_cmd(db)
    if force:
        cmd.append("--force")
    with open(path, "rb") as f:
        proc = subprocess.run(cmd, stdin=f, capture_output=True)
    if not force and proc.returncode != 0:
        raise RuntimeError(f"MySQL error on {path}:\n{proc.stderr.decode()}")


# ---------------------------------------------------------------------------
# Session fixture: build DB + start server
# ---------------------------------------------------------------------------

@pytest.fixture(scope="session", autouse=True)
def _test_server():
    if not _MANAGED:
        yield
        return

    # 1. Drop and recreate the test database
    _run_sql(f"DROP DATABASE IF EXISTS `{_DB_NAME}`; "
             f"CREATE DATABASE `{_DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")

    # 2. Apply schema (--force because the end-of-file ALTER TABLE lines fail
    #    on a fresh DB where the columns are already in CREATE TABLE)
    _run_sql_file(_SCHEMA_FILE, db=_DB_NAME, force=True)

    # 3. Seed fixture data
    _run_sql_file(_SEED_FILE, db=_DB_NAME)

    # 4. Start PHP server pointed at the test DB
    env = {
        **os.environ,
        "DB_HOST": _DB_HOST,
        "DB_PORT": _DB_PORT,
        "DB_NAME": _DB_NAME,
        "DB_USER": _DB_USER,
        "DB_PASS": _DB_PASS,
    }
    proc = subprocess.Popen(
        ["php", "-S", f"localhost:{_SERVER_PORT}", "-t", "public", "router.php"],
        cwd=_PROJECT_ROOT,
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    # Wait up to 5 s for the server to become ready
    deadline = time.time() + 5
    while time.time() < deadline:
        try:
            requests.get(f"http://localhost:{_SERVER_PORT}/", timeout=0.5)
            break
        except Exception:
            time.sleep(0.1)

    yield  # tests run here

    proc.terminate()
    proc.wait()


# ---------------------------------------------------------------------------
# Per-test helpers (unchanged API so all existing test files still work)
# ---------------------------------------------------------------------------

def url(path: str) -> str:
    return BASE_URL + path


@pytest.fixture
def base_url() -> str:
    return BASE_URL


@pytest.fixture
def client() -> requests.Session:
    """A requests.Session that persists cookies across calls."""
    session = requests.Session()
    session.max_redirects = 0
    return session


# db_required: kept for backward compatibility but is now always a no-op —
# the test server is always available when running under the managed setup.
db_required = pytest.mark.skipif(False, reason="DB always available in managed test server")
