"""
Chunk 1 tests: verify the app boots, the router works, and basic HTTP behaviour
is correct. These tests require a running PHP server (see conftest.py).

Run with:
    cd tests && APP_URL=http://localhost:8080 pytest test_infrastructure.py -v
"""

import pytest
import requests
from conftest import url


def test_app_is_running():
    """GET / should return 200."""
    resp = requests.get(url("/"))
    assert resp.status_code == 200, f"Expected 200, got {resp.status_code}"


def test_root_returns_json():
    """GET / should return valid JSON with status=ok."""
    resp = requests.get(url("/"))
    assert resp.headers.get("Content-Type", "").startswith("application/json")
    data = resp.json()
    assert data["status"] == "ok"
    assert "app" in data


def test_unknown_route_returns_404():
    """An unregistered path should return 404."""
    resp = requests.get(url("/this-route-does-not-exist-xyz"))
    assert resp.status_code == 404


def test_unknown_route_json_accept_returns_json_404():
    """If the client sends Accept: application/json, 404 should be JSON."""
    resp = requests.get(
        url("/nonexistent"),
        headers={"Accept": "application/json"},
    )
    assert resp.status_code == 404
    data = resp.json()
    assert "error" in data


def test_post_to_get_only_route_returns_404():
    """POST to a GET-only route should 404 (no route registered)."""
    resp = requests.post(url("/"), allow_redirects=False)
    assert resp.status_code == 404
