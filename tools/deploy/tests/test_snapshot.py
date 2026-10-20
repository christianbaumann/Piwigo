import os
from datetime import datetime

import pytest

from pwgdeploy import snapshot
from pwgdeploy.snapshot import Entry, RemoteFile, SnapshotError, build, diff, latest

NOW = datetime(2026, 10, 20, 10, 3, 38)


def entry(path, size=3, mtime=10, sha1="x"):
    return Entry(path, size, mtime, sha1)


def fetcher(content=b"new", calls=None):
    def fetch(path, local):
        if calls is not None:
            calls.append(path)
        local.write_bytes(content)
    return fetch


def make(root, name, files, content=b"abc"):
    plan = diff(latest(root)[1] if latest(root) else None,
                [RemoteFile(p, len(content), m) for p, m in files.items()])
    return build(root, name, plan, fetcher(content))


# --- names

def test_daily_name_is_the_bare_timestamp():  # [ECP]
    assert snapshot.snapshot_name(NOW) == "2026-10-20T100338"


@pytest.mark.parametrize("kind", ["manual", "predeploy", "prerestore"])
def test_other_kinds_are_a_suffix_and_round_trip(kind):  # [ECP]
    name = snapshot.snapshot_name(NOW, kind)
    assert name == f"2026-10-20T100338-{kind}"
    assert snapshot.parse_name(name) == (NOW, kind)


def test_daily_round_trips():  # [ECP]
    assert snapshot.parse_name("2026-10-20T100338") == (NOW, "daily")


@pytest.mark.parametrize("name", [
    "2026-10-20T100338.partial", "backup.log", "2026-10-20T100338-bogus",
    "2026-10-20T100338-daily", "2026-13-20T100338", "", "2026-10-20T10033",
])
def test_unparseable_names_are_none(name):  # [NEG][BVA]
    assert snapshot.parse_name(name) is None


def test_unknown_kind_is_refused_by_the_builder():  # [NEG]
    with pytest.raises(ValueError):
        snapshot.snapshot_name(NOW, "weekly")


# --- diff

def test_no_previous_fetches_everything():  # [ECP]
    plan = diff(None, [RemoteFile("a", 1, 1)])
    assert [f.path for f in plan.fetch] == ["a"] and plan.link == [] and plan.gone == []


def test_unchanged_file_is_linked():  # [ECP]
    plan = diff({"a": entry("a")}, [RemoteFile("a", 3, 10)])
    assert [e.path for e in plan.link] == ["a"] and plan.fetch == []


def test_changed_size_is_fetched():  # [BVA]
    plan = diff({"a": entry("a")}, [RemoteFile("a", 4, 10)])
    assert [f.path for f in plan.fetch] == ["a"] and plan.link == []


def test_changed_mtime_is_fetched():  # [BVA]
    plan = diff({"a": entry("a")}, [RemoteFile("a", 3, 11)])
    assert [f.path for f in plan.fetch] == ["a"]


def test_new_file_is_fetched_beside_a_linked_one():  # [ECP]
    plan = diff({"a": entry("a")}, [RemoteFile("a", 3, 10), RemoteFile("b", 1, 1)])
    assert [f.path for f in plan.fetch] == ["b"] and [e.path for e in plan.link] == ["a"]


def test_file_gone_from_the_remote_is_gone_and_not_planned():  # [ECP]
    plan = diff({"a": entry("a")}, [])
    assert plan.gone == ["a"] and plan.fetch == [] and plan.link == []


# --- build

def test_first_build_writes_files_and_json(tmp_path):  # [HAPPY]
    folder = make(tmp_path, "2026-10-19T030000", {"upload/a.jpg": 5})
    assert (folder / "files/upload/a.jpg").read_bytes() == b"abc"
    found = latest(tmp_path)
    assert found[0] == folder
    got = found[1]["upload/a.jpg"]
    assert (got.size, got.mtime) == (3, 5)
    assert len(got.sha1) == 40
    assert not list(tmp_path.glob("*.partial"))


def test_unchanged_file_shares_an_inode(tmp_path):  # [ST]
    first = make(tmp_path, "2026-10-19T030000", {"a": 5})
    second = make(tmp_path, "2026-10-20T030000", {"a": 5})
    one, two = (first / "files/a").stat(), (second / "files/a").stat()
    assert one.st_ino == two.st_ino and one.st_nlink == 2


def test_changed_file_gets_new_inode_and_old_bytes_stay(tmp_path):  # [ST]
    first = make(tmp_path, "2026-10-19T030000", {"a": 5}, b"old")
    second = make(tmp_path, "2026-10-20T030000", {"a": 6}, b"new")
    assert (first / "files/a").stat().st_ino != (second / "files/a").stat().st_ino
    assert (first / "files/a").read_bytes() == b"old"
    assert (second / "files/a").read_bytes() == b"new"


def test_gone_file_is_not_in_the_new_snapshot(tmp_path):  # [ECP]
    make(tmp_path, "2026-10-19T030000", {"a": 5, "b": 5})
    second = make(tmp_path, "2026-10-20T030000", {"a": 5})
    assert not (second / "files/b").exists()
    assert set(latest(tmp_path)[1]) == {"a"}


def test_deleting_the_older_snapshot_leaves_the_newer_readable(tmp_path):  # [ST]
    import shutil
    first = make(tmp_path, "2026-10-19T030000", {"a": 5})
    second = make(tmp_path, "2026-10-20T030000", {"a": 5})
    shutil.rmtree(first)
    assert (second / "files/a").read_bytes() == b"abc"
    assert latest(tmp_path)[0] == second


def test_meta_lands_in_snapshot_json(tmp_path):  # [HAPPY]
    import json
    folder = build(tmp_path, "2026-10-19T030000", diff(None, []), fetcher(), meta={"kind": "daily"})
    assert json.loads((folder / "snapshot.json").read_text())["kind"] == "daily"


# --- resume

def test_killed_build_resumes_and_fetches_only_the_missing(tmp_path):  # [ST]
    remote = [RemoteFile("a", 3, 1), RemoteFile("b", 3, 1)]
    calls = []

    def dying(path, local):
        calls.append(path)
        if path == "b":
            raise RuntimeError("connection lost")
        local.write_bytes(b"abc")

    with pytest.raises(RuntimeError):
        build(tmp_path, "2026-10-20T030000", diff(None, remote), dying)
    assert [p.name for p in tmp_path.iterdir()] == ["2026-10-20T030000.partial"]
    assert latest(tmp_path) is None
    assert not list(tmp_path.rglob("*.tmp"))

    again = []
    folder = build(tmp_path, "2026-10-20T040000", diff(None, remote), fetcher(b"abc", again))
    assert again == ["b"]
    assert folder.name == "2026-10-20T040000"
    assert set(latest(tmp_path)[1]) == {"a", "b"}


def test_resume_refetches_a_file_of_the_wrong_size(tmp_path):  # [BVA]
    partial = tmp_path / "2026-10-20T030000.partial" / "files"
    partial.mkdir(parents=True)
    (partial / "a").write_bytes(b"ab")
    calls = []
    build(tmp_path, "2026-10-20T030000", diff(None, [RemoteFile("a", 3, 1)]), fetcher(b"abc", calls))
    assert calls == ["a"]


def test_older_partials_are_removed_newest_continued(tmp_path):  # [ECP]
    for stamp in ("2026-10-18T030000", "2026-10-19T030000"):
        (tmp_path / f"{stamp}.partial" / "files").mkdir(parents=True)
    (tmp_path / "2026-10-19T030000.partial/files/a").write_bytes(b"abc")
    calls = []
    build(tmp_path, "2026-10-20T030000", diff(None, [RemoteFile("a", 3, 1)]), fetcher(b"abc", calls))
    assert calls == []
    assert [p.name for p in tmp_path.iterdir()] == ["2026-10-20T030000"]


def test_latest_ignores_partial_and_empty_root(tmp_path):  # [NEG]
    assert latest(tmp_path / "missing") is None
    (tmp_path / "2026-10-20T030000.partial").mkdir()
    (tmp_path / "2026-10-21T030000").mkdir()  # no snapshot.json: not complete
    assert latest(tmp_path) is None
    done = make(tmp_path, "2026-10-19T030000", {"a": 1})
    assert latest(tmp_path)[0] == done


def test_other_filesystem_is_refused(tmp_path, monkeypatch):  # [NEG]
    make(tmp_path, "2026-10-19T030000", {"a": 1})
    real = os.stat

    def fake(path, *a, **k):
        result = real(path, *a, **k)
        if str(path).endswith("2026-10-19T030000"):
            return os.stat_result((*result[:2], result.st_dev + 1, *result[3:]))
        return result

    monkeypatch.setattr(snapshot.os, "stat", fake)
    with pytest.raises(SnapshotError):
        build(tmp_path, "2026-10-20T030000", diff(None, []), fetcher())
    assert not list(tmp_path.glob("*.partial"))
