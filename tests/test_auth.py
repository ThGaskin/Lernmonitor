"""
Chunk 2 tests: authentication — login, logout, session, access guards.

Tests are split into two groups:
  - Basic tests  : no DB required (wrong credentials, page rendering, redirects)
  - DB tests     : require a running MySQL instance with at least one user

For DB tests, set these env vars before running:
    TEST_ADMIN_USER=myAdmin TEST_ADMIN_PASS=myPassword pytest test_auth.py -v

Run all basic tests (no DB needed):
    APP_URL=http://localhost:8080 pytest test_auth.py -v -m "not db"
"""

import os
import pytest
import requests
from conftest import url, db_required

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def login(session: requests.Session, username: str, password: str) -> requests.Response:
    return session.post(url("/login"), json={"username": username, "password": password},
                        allow_redirects=False)

def logout(session: requests.Session) -> requests.Response:
    return session.post(url("/logout"), allow_redirects=False)

# ---------------------------------------------------------------------------
# GET /login
# ---------------------------------------------------------------------------

def test_login_page_returns_200():
    resp = requests.get(url("/login"))
    assert resp.status_code == 200

def test_login_page_returns_html():
    resp = requests.get(url("/login"))
    assert "text/html" in resp.headers.get("Content-Type", "")

def test_login_page_contains_form():
    resp = requests.get(url("/login"))
    assert "<form" in resp.text.lower()
    assert 'id="username"' in resp.text or 'name="username"' in resp.text

# ---------------------------------------------------------------------------
# POST /login — wrong credentials (no DB data needed; invalid always fails)
# ---------------------------------------------------------------------------

@db_required
def test_login_wrong_credentials_returns_401():
    resp = requests.post(url("/login"),
                         json={"username": "nobody@example.com", "password": "wrongpass"},
                         allow_redirects=False)
    assert resp.status_code == 401

def test_login_empty_username_returns_400():
    resp = requests.post(url("/login"),
                         data={"username": "", "password": "whatever"},
                         allow_redirects=False)
    assert resp.status_code == 400

def test_login_empty_password_returns_400():
    resp = requests.post(url("/login"),
                         data={"username": "someone", "password": ""},
                         allow_redirects=False)
    assert resp.status_code == 400

# ---------------------------------------------------------------------------
# POST /logout
# ---------------------------------------------------------------------------

def test_logout_without_session_returns_200():
    """Logout should be idempotent — works even when not logged in."""
    resp = requests.post(url("/logout"), allow_redirects=False)
    assert resp.status_code == 200

# ---------------------------------------------------------------------------
# GET /dashboard — access guard
# ---------------------------------------------------------------------------

def test_dashboard_unauthenticated_redirects_to_login():
    """Unauthenticated request to /dashboard must redirect to /login."""
    resp = requests.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 302
    assert resp.headers.get("Location", "").endswith("/login")

def test_dashboard_unauthenticated_follows_to_login():
    """Following the redirect lands on the login page (200)."""
    resp = requests.get(url("/dashboard"), allow_redirects=True)
    assert resp.status_code == 200
    assert "<form" in resp.text.lower()

# ---------------------------------------------------------------------------
# GET /login — already logged-in redirect (requires DB credentials)
# ---------------------------------------------------------------------------

_admin_user = os.environ.get("TEST_ADMIN_USER")
_admin_pass = os.environ.get("TEST_ADMIN_PASS")
_has_creds = bool(_admin_user and _admin_pass)
_needs_creds = pytest.mark.skipif(not _has_creds, reason="Set TEST_ADMIN_USER and TEST_ADMIN_PASS")

@db_required
@_needs_creds
def test_login_valid_admin_credentials_returns_200():
    session = requests.Session()
    resp = login(session, _admin_user, _admin_pass)
    assert resp.status_code == 200, f"Login failed: {resp.text}"

@db_required
@_needs_creds
def test_login_sets_session_cookie():
    session = requests.Session()
    resp = login(session, _admin_user, _admin_pass)
    assert resp.status_code == 200
    assert "PHPSESSID" in session.cookies

@db_required
@_needs_creds
def test_dashboard_accessible_after_login():
    session = requests.Session()
    login(session, _admin_user, _admin_pass)
    resp = session.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 200

@db_required
@_needs_creds
def test_login_page_redirects_to_dashboard_when_already_logged_in():
    session = requests.Session()
    login(session, _admin_user, _admin_pass)
    resp = session.get(url("/login"), allow_redirects=False)
    assert resp.status_code == 302
    assert resp.headers.get("Location", "").endswith("/dashboard")

@db_required
@_needs_creds
def test_logout_then_dashboard_redirects_to_login():
    session = requests.Session()
    login(session, _admin_user, _admin_pass)
    logout(session)
    resp = session.get(url("/dashboard"), allow_redirects=False)
    assert resp.status_code == 302
    assert resp.headers.get("Location", "").endswith("/login")
