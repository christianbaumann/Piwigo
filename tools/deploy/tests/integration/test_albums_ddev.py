"""pwg-albums against the DDEV install, through the real ws.php.

Run with `cd tools/deploy && uv run pytest -m integration`. Needs DDEV up and the
git-ignored `deploy.ddev.json` (a webmaster login). Creates a tree under an album with a
unique name and deletes it in teardown; never touches another album. Not for production.
"""

import io
import uuid
from pathlib import Path

import pytest

from pwgdeploy import albums
from pwgdeploy.albums_cli import main
from pwgdeploy.bootstrap import login, ws_call
from pwgdeploy.cli import http_client
from pwgdeploy.config import load_file
from pwgdeploy.errors import RemoteHttpError

CONFIG = Path(__file__).resolve().parents[2] / "deploy.ddev.json"
SUBFOLDERS = ("Urlaub/Italien", "Hochzeit", "Müller")
EXPECTED_ALBUMS = 5  # root + Urlaub + Italien + Hochzeit + Müller

pytestmark = pytest.mark.integration


@pytest.fixture(scope="module")
def session():
    assert CONFIG.is_file(), f"{CONFIG} is missing: copy deploy.example.json and fill in the DDEV webmaster login"
    config = load_file(CONFIG)
    client = http_client(config)
    token = login(client, config)
    yield config, client, token


@pytest.fixture
def folder(tmp_path):
    root = tmp_path / f"pwg-albums-test-{uuid.uuid4().hex[:12]}"
    for sub in SUBFOLDERS:
        (root / sub).mkdir(parents=True)
    return root


def run(folder):
    out, err = io.StringIO(), io.StringIO()
    code = main([str(CONFIG), str(folder)], stdout=out, stderr=err)
    return code, out.getvalue(), err.getvalue()


def test_a_tree_is_created_once_and_skipped_the_second_time(session, folder):
    config, client, token = session
    base_url = config.site.base_url
    try:
        code, out, err = run(folder)
        assert code == 0, err
        assert f"{EXPECTED_ALBUMS} created, 0 skipped" in out

        remote = albums.read_remote(client, base_url)
        by_id = {a.id: a for a in remote}
        top = next(a for a in remote if a.name == folder.name)
        assert top.parent_id is None
        children = {a.name: a for a in remote if a.parent_id == top.id}
        assert set(children) == {"Urlaub", "Hochzeit", "Müller"}
        assert [a.name for a in remote if a.parent_id == children["Urlaub"].id] == ["Italien"]
        assert by_id[children["Hochzeit"].id].parent_id == top.id

        count_before = len(remote)
        code, out, err = run(folder)
        assert code == 0, err
        assert "0 created, 1 skipped" in out
        assert len(albums.read_remote(client, base_url)) == count_before
    finally:
        for album in albums.read_remote(client, base_url):
            if album.name == folder.name:
                ws_call(
                    client,
                    base_url,
                    "pwg.categories.delete",
                    {"category_id": str(album.id), "pwg_token": token, "photo_deletion_mode": "no_delete"},
                )
    assert not [a for a in albums.read_remote(client, base_url) if a.name == folder.name]


def test_a_refused_album_fails_the_run_but_not_its_siblings(session, tmp_path):
    """[NEG] The server refuses a blank name; its child is never requested."""
    config, client, token = session
    base_url = config.site.base_url
    root = tmp_path / f"pwg-albums-test-{uuid.uuid4().hex[:12]}"
    (root / " " / "Child").mkdir(parents=True)
    (root / "Sibling").mkdir()
    try:
        code, out, err = run(root)
        assert code == RemoteHttpError.exit_code, err
        assert "skipped (parent failed)  " + f"{root.name}/ /Child" in out
        remote = albums.read_remote(client, base_url)
        top = next(a for a in remote if a.name == root.name)
        assert [a.name for a in remote if a.parent_id == top.id] == ["Sibling"]
        assert not [a for a in remote if a.name == "Child"]
    finally:
        for album in albums.read_remote(client, base_url):
            if album.name == root.name:
                ws_call(
                    client,
                    base_url,
                    "pwg.categories.delete",
                    {"category_id": str(album.id), "pwg_token": token, "photo_deletion_mode": "no_delete"},
                )
