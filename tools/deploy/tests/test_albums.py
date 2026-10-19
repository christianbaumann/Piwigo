"""Folder tree to album plan: walk() reads the disk, plan() decides create or skip.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA].
Decision table not applicable: one condition per folder (does a same-named album exist
under the same parent), two outcomes.
"""

import io
import json
import os
import unicodedata
from types import SimpleNamespace

from pwgdeploy import albums
from pwgdeploy.albums import Action, LocalFolder, RemoteAlbum
from pwgdeploy.albums_cli import main


def tree(name, *children):
    return LocalFolder(name, tuple(children))


def paths(actions, kind):
    return [a.path for a in actions if a.kind == kind]


# --- plan() ------------------------------------------------------------------------------


def test_a_new_tree_is_created_top_down_under_the_root():
    """[HAPPY]"""
    local = tree("Familie", tree("Urlaub", tree("Italien")), tree("Hochzeit"))
    actions = albums.plan(local, [])
    assert [(a.path, a.kind) for a in actions] == [
        ("Familie", "create"),
        ("Familie/Urlaub", "create"),
        ("Familie/Urlaub/Italien", "create"),
        ("Familie/Hochzeit", "create"),
    ]
    assert actions[0].parent is None and actions[0].name == "Familie"
    assert actions[2].parent == "Familie/Urlaub"


def test_an_existing_top_album_is_skipped_with_everything_below_it():
    """[ECP] Only the top album gets an action: its sub-folders are not looked at."""
    local = tree("Familie", tree("Urlaub"), tree("Hochzeit"))
    actions = albums.plan(local, [RemoteAlbum(7, "Familie", None)])
    assert actions == [Action("Familie", "Familie", "skip", None, 7)]


def test_an_existing_album_with_children_still_skips_the_whole_subtree():
    """[ECP] The remote already holds Urlaub under it; nothing new is created either."""
    remote = [RemoteAlbum(7, "Familie", None), RemoteAlbum(8, "Urlaub", 7)]
    actions = albums.plan(tree("Familie", tree("Urlaub"), tree("Neu")), remote)
    assert [a.kind for a in actions] == ["skip"]


def test_a_sibling_equal_to_an_earlier_one_is_skipped():
    """[ECP] Two folders that differ only in case: the second finds the first and is skipped."""
    actions = albums.plan(tree("Alben", tree("Urlaub"), tree("urlaub")), [])
    assert [(a.path, a.kind) for a in actions] == [
        ("Alben", "create"),
        ("Alben/Urlaub", "create"),
        ("Alben/urlaub", "skip"),
    ]


def test_the_same_name_under_another_parent_is_not_an_existing_album():
    """[NEG]"""
    remote = [RemoteAlbum(7, "Urlaub", 3)]
    actions = albums.plan(tree("Urlaub"), remote)
    assert [a.kind for a in actions] == ["create"]


def test_names_equal_in_case_and_accents_count_as_existing():
    """[ECP] seed.collated(): the server refuses the second name it calls equal."""
    actions = albums.plan(tree("MULLER"), [RemoteAlbum(1, "Müller", None)])
    assert [a.kind for a in actions] == ["skip"]


def test_the_first_match_counts_when_the_remote_holds_two():
    """[BVA]"""
    remote = [RemoteAlbum(5, "Urlaub", None), RemoteAlbum(6, "urlaub", None)]
    assert albums.plan(tree("Urlaub"), remote)[0].album_id == 5


# --- walk() ------------------------------------------------------------------------------


def test_walk_reads_nested_folders_and_ignores_files(tmp_path):
    """[HAPPY]"""
    (tmp_path / "Reise" / "Italien").mkdir(parents=True)
    (tmp_path / "Reise" / "foto.jpg").write_text("x")
    root = albums.walk(tmp_path / "Reise")
    assert root == tree("Reise", tree("Italien"))


def test_walk_skips_hidden_folders(tmp_path):
    """[NEG]"""
    (tmp_path / "A" / ".thumbs").mkdir(parents=True)
    (tmp_path / "A" / "Sichtbar").mkdir()
    assert albums.walk(tmp_path / "A") == tree("A", tree("Sichtbar"))


def test_walk_does_not_follow_symlinks(tmp_path):
    """[NEG] A link to a folder (even a loop) is neither descended into nor an album."""
    (tmp_path / "A" / "Echt").mkdir(parents=True)
    os.symlink(tmp_path / "A", tmp_path / "A" / "Schleife")
    os.symlink(tmp_path / "A" / "Echt", tmp_path / "A" / "Link")
    assert albums.walk(tmp_path / "A") == tree("A", tree("Echt"))


def test_walk_returns_names_in_nfc(tmp_path):
    """[ECP] macOS can hand out NFD names."""
    nfd = unicodedata.normalize("NFD", "Müller")
    assert nfd != "Müller"
    (tmp_path / "A" / nfd).mkdir(parents=True)
    child = albums.walk(tmp_path / "A").children[0]
    assert child.name == "Müller"


def test_walk_sorts_siblings_by_name(tmp_path):
    """[BVA] The order of creation, and so of the report, does not depend on the disk."""
    for name in ("b", "c", "a"):
        (tmp_path / "A" / name).mkdir(parents=True)
    assert [c.name for c in albums.walk(tmp_path / "A").children] == ["a", "b", "c"]


# --- --dry-run ---------------------------------------------------------------------------


class RecordingClient:
    """Answers login and an empty getList; records every ws method called."""

    def __init__(self, remote=()):
        self.methods = []
        self.remote = list(remote)

    def post(self, url, fields, *, timeout=None):
        method = fields["method"]
        self.methods.append(method)
        result = {"pwg_token": "t"} if method == "pwg.session.getStatus" else {}
        if method == "pwg.categories.getList":
            result = {"categories": self.remote}
        if method == "pwg.categories.add":
            result = {"id": 99}
        return SimpleNamespace(body=json.dumps({"stat": "ok", "result": result}))


def run_cli(tmp_path, client, *flags):
    config = tmp_path / "deploy.json"
    config.write_text(
        json.dumps(
            {
                "site": {"base_url": "https://g.example.test"},
                "ftp": {"host": "h", "user": "u", "password": "p", "remote_root": "/r"},
                "mysql": {"host": "h", "user": "u", "password": "p", "database": "d", "prefix": "piwigo_"},
                "admin": {"username": "a", "password": "secret", "email": "a@example.test"},
            }
        )
    )
    folder = tmp_path / "Familie"
    (folder / "Urlaub").mkdir(parents=True)
    out, err = io.StringIO(), io.StringIO()
    code = main([*flags, str(config), str(folder)], stdout=out, stderr=err, client_factory=lambda c: client)
    return code, out.getvalue(), err.getvalue()


def test_dry_run_prints_the_plan_and_creates_nothing(tmp_path):
    """[HAPPY] Reads the remote, never calls pwg.categories.add."""
    client = RecordingClient()
    code, out, err = run_cli(tmp_path, client, "--dry-run")
    assert code == 0, err
    assert "pwg.categories.getList" in client.methods
    assert "pwg.categories.add" not in client.methods
    assert "would create      Familie" in out
    assert "would create      Familie/Urlaub" in out
    assert "2 would create, 0 skipped" in out


def test_dry_run_reports_an_existing_album_as_skipped(tmp_path):
    """[ECP]"""
    client = RecordingClient([{"id": 7, "name_raw": "Familie", "id_uppercat": None}])
    code, out, err = run_cli(tmp_path, client, "--dry-run")
    assert code == 0, err
    assert "skipped (exists)  Familie" in out
    assert "would create" not in out.replace("0 would create", "")
    assert "pwg.categories.add" not in client.methods
