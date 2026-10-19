"""Folder tree to album plan: walk() reads the disk, plan() decides create or skip.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA].
Decision table not applicable: one condition per folder (does a same-named album exist
under the same parent), two outcomes.
"""

import io
import json
import os
import unicodedata
from datetime import datetime
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


def test_a_new_top_folder_is_created_under_the_parent():
    """[HAPPY]"""
    remote = [RemoteAlbum(5, "Archiv", None)]
    actions = albums.plan(tree("Familie", tree("Urlaub")), remote, parent_id=5)
    assert [(a.path, a.kind) for a in actions] == [("Familie", "create"), ("Familie/Urlaub", "create")]


def test_an_existing_top_folder_under_the_parent_is_skipped():
    """[ECP]"""
    remote = [RemoteAlbum(5, "Archiv", None), RemoteAlbum(8, "Familie", 5)]
    actions = albums.plan(tree("Familie", tree("Urlaub")), remote, parent_id=5)
    assert [(a.path, a.kind, a.album_id) for a in actions] == [("Familie", "skip", 8)]


def test_the_same_name_at_the_root_is_not_an_existing_album_under_the_parent():
    """[NEG]"""
    remote = [RemoteAlbum(5, "Archiv", None), RemoteAlbum(8, "Familie", None)]
    actions = albums.plan(tree("Familie"), remote, parent_id=5)
    assert [a.kind for a in actions] == ["create"]


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


# --- write_report() ----------------------------------------------------------------------


def test_the_report_lists_every_result_with_its_status_id_and_message(tmp_path):
    """[HAPPY] [ECP] one entry per result in order; a result carries any status as given."""
    results = [
        albums.Result("A", "created", 7),
        albums.Result("A/B", "failed", None, "Invalid name"),
        albums.Result("A/B/C", "skipped (parent failed)"),
        albums.Result("D/Müller", "skipped (exists)", 3),
    ]
    report = albums.write_report(tmp_path / "state", results, datetime(2026, 10, 19, 14, 5, 9))
    assert report.name == "albums-20261019-140509.json"
    entries = json.loads(report.read_text(encoding="utf-8"))
    assert entries == [
        {"path": "A", "album_id": 7, "status": "created", "message": None},
        {"path": "A/B", "album_id": None, "status": "failed", "message": "Invalid name"},
        {"path": "A/B/C", "album_id": None, "status": "skipped (parent failed)", "message": None},
        {"path": "D/Müller", "album_id": 3, "status": "skipped (exists)", "message": None},
    ]
    assert "Müller" in report.read_text(encoding="utf-8")


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


# --- the create loop: a refused album does not stop the run ---------------------------------


class RefusingGallery:
    """Answers ws.php like a gallery that refuses one album name."""

    def __init__(self, refuse):
        self.refuse = refuse
        self.requested = []

    def post(self, url, fields, *, timeout=None):
        import json
        from types import SimpleNamespace

        method = fields["method"]
        if method == "pwg.session.getStatus":
            result = {"pwg_token": "t"}
        elif method == "pwg.categories.getList":
            result = {"categories": []}
        elif method == "pwg.categories.add":
            self.requested.append(fields["name"])
            if fields["name"] == self.refuse:
                body = {"stat": "fail", "err": 1002, "message": "bad name"}
                return SimpleNamespace(body=json.dumps(body))
            result = {"id": 100 + len(self.requested)}
        else:
            result = True
        return SimpleNamespace(body=json.dumps({"stat": "ok", "result": result}))


def run_main(monkeypatch, tmp_path, gallery, *tree_paths):
    import io
    from types import SimpleNamespace

    from pwgdeploy import albums_cli

    for rel in tree_paths:
        (tmp_path / "Top" / rel).mkdir(parents=True)
    config = SimpleNamespace(
        site=SimpleNamespace(base_url="http://x"),
        admin=SimpleNamespace(username="a", password="p"),
    )
    monkeypatch.setattr(albums_cli, "load_file", lambda path: config)
    out, err = io.StringIO(), io.StringIO()
    code = albums_cli.main(
        ["c.json", str(tmp_path / "Top")],
        stdout=out,
        stderr=err,
        client_factory=lambda cfg: gallery,
        state_dir=tmp_path / "state",
    )
    return code, out.getvalue()


def test_a_refused_album_is_reported_its_children_are_not_requested_and_siblings_are_created(
    monkeypatch, tmp_path
):
    """[NEG] Bad has a child; Gut is its sibling."""
    gallery = RefusingGallery(refuse="Bad")
    code, out = run_main(monkeypatch, tmp_path, gallery, "Bad/Child", "Gut")
    assert code != 0
    assert gallery.requested == ["Top", "Bad", "Gut"]
    assert "created           Top/Gut" in out
    assert "skipped (parent failed)  Top/Bad/Child" in out
    summary = out.index("2 created, 0 skipped, 1 failed")
    assert out.index("failed  Top/Bad: ", summary) > summary
    assert "bad name" in out[summary:]


def test_a_run_without_a_refusal_exits_zero(monkeypatch, tmp_path):
    """[HAPPY] The counterpart: nothing failed, exit 0."""
    code, out = run_main(monkeypatch, tmp_path, RefusingGallery(refuse=None), "A")
    assert code == 0 and "2 created, 0 skipped, 0 failed" in out
