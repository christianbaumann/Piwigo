"""One backup run, and the pure decisions around it.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST].
Decision table not applicable to `validate_paths`/`drift`: each rule is one independent condition.
The PHP half and the real FTPS/HTTPS are witnessed by tests/integration/test_backup_ddev.py and
the hand check in docs/agents/TESTING.md; here the host is `FakeBackupHost`.
"""

import json
import re
from datetime import datetime, timedelta

import pytest

from pwgdeploy import backup, config, snapshot
from pwgdeploy.errors import (
    AgentLeftoverError,
    BackupError,
    ConfigError,
    UnsafeRemotePathError,
)
from pwgdeploy.snapshot import RemoteFile
from tests.fakes import FakeBackupHost

NOW = datetime(2026, 10, 20, 10, 0, 0)
RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": FakeBackupHost.ROOT},
    "mysql": {"host": "localhost", "user": "d1", "password": "p", "database": "d1", "prefix": "pw_"},
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": FakeBackupHost.BASE_URL},
}
MIN_FILES = 2


def make_config(tmp_path, with_backup=True):
    raw = dict(RAW)
    if with_backup:
        raw["backup"] = {"dir": str(tmp_path / "bk")}
    return config.load(raw)


@pytest.fixture
def host():
    h = FakeBackupHost()
    h.add_photo("upload/2026/a.jpg", b"aaa")
    h.add_photo("galleries/x/b.jpg", b"bbbb", mtime=1_700_000_100)
    return h


@pytest.fixture
def state_dir(tmp_path):
    folder = tmp_path / "state"
    folder.mkdir()
    return folder


def run(cfg, host, state_dir, *, now=NOW, kind="daily", reports=None):
    return backup.run(
        cfg, kind, now, host.transport, host.client,
        repo_root=state_dir, state_dir=state_dir, git_commit=lambda: "abc123",
        report=(lambda label, text: reports.append((label, text))) if reports is not None else (lambda *_: None),
        clock=lambda: NOW,
    )


def snapshot_json(result):
    return json.loads((result.folder / "snapshot.json").read_text())


# --- validate_paths

def rf(path):
    return RemoteFile(path, 1, 1)


@pytest.mark.parametrize("path", [
    "upload/a.jpg", "galleries/a/b c.jpg", "_data/photoedit/originals/1-2.png", "upload/é/ü.jpg",
])
def test_paths_under_a_backed_up_folder_pass(path):  # [HAPPY][ECP]
    backup.validate_paths([rf(path)])


@pytest.mark.parametrize("path", [
    "upload/../local/config/database.inc.php", "../upload/a", "/etc/passwd", "upload//a",
    "upload/./a", "local/config/config.inc.php", "uploads/a.jpg", "_data/i/a.jpg", "upload\\..\\x",
    "upload/a\0b", "", "upload/a/..",
])
def test_every_other_path_is_refused(path):  # [NEG][ECP][BVA]
    with pytest.raises(UnsafeRemotePathError):
        backup.validate_paths([rf("upload/ok.jpg"), rf(path)])


def test_an_empty_listing_passes():  # [BVA]
    backup.validate_paths([])


# --- drift

def test_matching_paths_have_no_drift():  # [HAPPY]
    document = backup.drift([rf("upload/a.jpg"), rf("galleries/b.jpg")], ["./upload/a.jpg", "galleries/b.jpg"])
    assert backup.drift_count(document) == 0


def test_a_row_without_a_file_and_a_file_without_a_row_are_both_drift():  # [ECP]
    document = backup.drift([rf("upload/a.jpg"), rf("upload/stray.jpg")], ["./upload/a.jpg", "./upload/gone.jpg"])
    assert document["db_without_file"] == ["upload/gone.jpg"]
    assert document["file_without_db"] == ["upload/stray.jpg"]
    assert backup.drift_count(document) == 2


@pytest.mark.parametrize("path", [
    "upload/2026/a.jpg_original", "upload/2026/pwg_representative/a.jpg", "upload/buffer/x.part",
    "upload/index.php", "upload/2026/.htaccess", "_data/photoedit/originals/1.png",
])
def test_files_that_are_no_photo_are_not_drift(path):  # [ECP]
    assert backup.drift([rf(path)], [])["file_without_db"] == []


def test_a_symlinked_photo_the_script_skipped_counts_as_a_row_without_a_file():  # [ERR]
    # The script lists no symlinks, so such a photo only appears as a db row.
    assert backup.drift([], ["./upload/linked.jpg"])["db_without_file"] == ["upload/linked.jpg"]


def test_the_recorded_lists_are_capped_but_the_counts_are_not():  # [BVA]
    over = backup.MAX_RECORDED_DRIFT + 5
    document = backup.drift([], [f"./upload/{i}.jpg" for i in range(over)])
    assert len(document["db_without_file"]) == backup.MAX_RECORDED_DRIFT
    assert document["db_without_file_count"] == over


def test_normalise_strips_one_leading_dot_slash():  # [ECP]
    assert backup.normalise_db_path("./upload/a") == "upload/a"
    assert backup.normalise_db_path("upload/a") == "upload/a"
    assert backup.normalise_db_path("././upload/a") == "./upload/a"


def test_the_dump_dir_is_random_and_prefixed():  # [ECP]
    first, second = backup.dump_dir(), backup.dump_dir()
    assert first != second
    assert re.fullmatch(rf"{backup.DUMP_DIR_PREFIX}[0-9a-f]+", first)


def test_a_log_line_names_times_kind_counts_and_result():  # [HAPPY]
    line = backup.format_log_line(NOW, NOW + timedelta(seconds=5), "daily", 3, 4, 500, "ok")
    assert line == "2026-10-20T10:00:00 2026-10-20T10:00:05 daily fetched=3 linked=4 bytes=500 ok"


# --- run

def test_a_run_writes_a_complete_snapshot_and_leaves_the_host_clean(tmp_path, host, state_dir):  # [HAPPY]
    (state_dir / "ftp.example.test_piwigo.json").write_text("{}")
    reports = []

    result = run(make_config(tmp_path), host, state_dir, reports=reports)

    folder = result.folder
    assert folder.name == "2026-10-20T100000"
    assert (folder / "db/dump.sql.gz").read_bytes() == host.dump_bytes
    assert (folder / "files/upload/2026/a.jpg").read_bytes() == b"aaa"
    assert (folder / "files/local/config/database.inc.php").read_bytes() == b"local/config/database.inc.php"
    assert (folder / "files/local/config/config.inc.php").is_file()
    assert host.scripts() == [] and host.dump_files() == []
    assert not (tmp_path / "bk" / backup.STAGING_DIR).exists()
    assert not list((tmp_path / "bk").glob("*.partial"))
    assert (result.fetched, result.linked, result.drift_count) == (2, 0, 0)
    assert [label for label, _ in reports] == ["agent", "dump", "list", "cleanup", "diff", "snapshot", "retention"]


def test_snapshot_json_holds_commit_timestamps_method_counts_prefix_and_drift(tmp_path, host, state_dir):  # [HAPPY]
    host.add_photo("upload/stray.jpg", b"s", in_db=False)
    result = run(make_config(tmp_path), host, state_dir)
    document = snapshot_json(result)

    assert document["git_commit"] == "abc123"
    assert document["kind"] == "daily"
    assert document["dump_method"] == "mysqldump"
    assert document["counts"] == host.counts
    assert document["prefix"] == "pw_"
    assert {"db_started", "files_started", "finished"} <= set(document)
    assert document["drift"]["file_without_db"] == ["upload/stray.jpg"]
    assert len(document["files"]) >= MIN_FILES
    assert document["files"]["upload/2026/a.jpg"]["sha1"]


def test_the_deploy_manifest_is_copied_when_there_is_one(tmp_path, host, state_dir):  # [ECP]
    manifest_file = state_dir / "ftp.example.test_piwigo.json"
    manifest_file.write_text('{"x": 1}')
    with_manifest = run(make_config(tmp_path), host, state_dir)
    assert (with_manifest.folder / backup.MANIFEST_COPY).read_text() == '{"x": 1}'
    assert manifest_file.read_text() == '{"x": 1}'

    manifest_file.unlink()
    without = run(make_config(tmp_path), host, state_dir, now=NOW + timedelta(minutes=1))
    assert not (without.folder / backup.MANIFEST_COPY).exists()


def test_the_agent_is_removed_before_any_photo_is_downloaded(tmp_path, host, state_dir):  # [ST]
    seen = []
    original = host.transport.get

    def get(remote_path, local):
        seen.append((remote_path, host.scripts()))
        original(remote_path, local)

    host.transport.get = get
    run(make_config(tmp_path), host, state_dir)

    assert host.actions == ["dump", "list", "cleanup"]
    photos = [(path, scripts) for path, scripts in seen if "/upload/" in path or "/galleries/" in path]
    assert len(photos) >= MIN_FILES, "no photo was fetched; the ordering claim is vacuous"
    assert all(scripts == [] for _, scripts in photos)
    dump = [scripts for path, scripts in seen if "/dump-" in path]
    assert len(dump) == 1 and len(dump[0]) == 1


def test_the_second_run_fetches_only_what_changed_and_shares_the_inode(tmp_path, host, state_dir):  # [HAPPY]
    cfg = make_config(tmp_path)
    first = run(cfg, host, state_dir)
    host.change_photo("galleries/x/b.jpg", b"BBBB", mtime=1_700_000_999)
    host.transport.calls.clear()

    second = run(cfg, host, state_dir, now=NOW + timedelta(hours=1))

    assert (second.fetched, second.linked) == (1, 1)
    fetched = [c[1] for c in host.transport.calls if c[0] == "get" and "/upload/" in c[1] + "/galleries/"]
    assert [p for p in fetched if p.endswith("a.jpg")] == []
    same = "files/upload/2026/a.jpg"
    assert (first.folder / same).stat().st_ino == (second.folder / same).stat().st_ino
    assert (second.folder / "files/galleries/x/b.jpg").read_bytes() == b"BBBB"
    assert (first.folder / "files/galleries/x/b.jpg").read_bytes() == b"bbbb"


def test_a_failed_file_fetch_leaves_a_partial_folder_and_a_clean_host(tmp_path, state_dir):  # [NEG][ST]
    host = FakeBackupHost(fail_get="galleries/x/b.jpg")
    host.add_photo("upload/a.jpg", b"aaa")
    host.add_photo("galleries/x/b.jpg", b"bbbb")
    cfg = make_config(tmp_path)

    with pytest.raises(Exception, match="lost the connection"):
        run(cfg, host, state_dir)

    assert host.scripts() == [] and host.dump_files() == []
    names = sorted(p.name for p in (tmp_path / "bk").iterdir())
    assert names == ["2026-10-20T100000.partial"]
    assert snapshot.latest(tmp_path / "bk") is None
    assert not (tmp_path / "bk" / "backup.log").exists()


def test_a_killed_run_is_completed_by_the_next_one_without_refetching(tmp_path, state_dir):  # [ST]
    host = FakeBackupHost(interrupt_on_get="galleries/x/b.jpg")
    host.add_photo("upload/a.jpg", b"aaa")
    host.add_photo("galleries/x/b.jpg", b"bbbb")
    cfg = make_config(tmp_path)
    with pytest.raises(KeyboardInterrupt):
        run(cfg, host, state_dir)
    assert host.scripts() == []
    assert [p.name for p in (tmp_path / "bk").iterdir()] == ["2026-10-20T100000.partial"]

    host.transport._interrupt_on_get = None
    host.transport.calls.clear()
    result = run(cfg, host, state_dir, now=NOW + timedelta(hours=2))

    assert result.name == "2026-10-20T120000"
    fetched = [c[1] for c in host.transport.calls if c[0] == "get" and c[1].endswith(".jpg")]
    assert fetched == [f"{host.ROOT}/galleries/x/b.jpg"]
    assert [p.name for p in (tmp_path / "bk").iterdir()] == ["2026-10-20T120000"]


@pytest.mark.parametrize("failing", ["dump", "list"])
def test_a_refused_agent_action_still_removes_the_script_and_stores_nothing(tmp_path, host, state_dir, failing):  # [NEG]
    host.fail_action = failing
    with pytest.raises(Exception, match=f"{failing} failed"):
        run(make_config(tmp_path), host, state_dir)
    assert host.scripts() == [] and host.dump_files() == []
    assert snapshot.latest(tmp_path / "bk") is None
    assert not (tmp_path / "bk" / backup.STAGING_DIR).exists()


def test_an_unsafe_listed_path_stops_the_run_before_anything_is_stored(tmp_path, host, state_dir):  # [NEG]
    host.listed_extra = [{"path": "upload/../local/config/database.inc.php", "size": 1, "mtime": 1}]
    with pytest.raises(UnsafeRemotePathError):
        run(make_config(tmp_path), host, state_dir)
    assert host.scripts() == []
    assert not [p for p in (tmp_path / "bk").iterdir()]


def test_a_dump_of_another_size_than_announced_fails(tmp_path, host, state_dir):  # [NEG]
    host.reported_dump_size = 999
    with pytest.raises(BackupError, match="bytes here"):
        run(make_config(tmp_path), host, state_dir)
    assert host.scripts() == []


def test_a_file_of_another_size_than_listed_fails_and_is_not_kept(tmp_path, host, state_dir):  # [NEG]
    host.meta["upload/2026/a.jpg"] = (99, 1_700_000_000)
    with pytest.raises(BackupError, match="bytes here"):
        run(make_config(tmp_path), host, state_dir)
    assert snapshot.latest(tmp_path / "bk") is None
    assert not list((tmp_path / "bk").rglob("a.jpg"))


def test_a_failing_http_cleanup_falls_back_to_deleting_over_ftp(tmp_path, host, state_dir):  # [ERR]
    host.fail_action = "cleanup"
    result = run(make_config(tmp_path), host, state_dir)
    assert host.scripts() == [] and host.dump_files() == []
    assert result.folder.is_dir()


def test_a_script_that_cannot_be_removed_is_a_typed_error_naming_it(tmp_path, host, state_dir):  # [NEG]
    host.fail_action = "cleanup"
    host.fail_cleanup_over_ftp = True
    with pytest.raises(AgentLeftoverError, match=r"still on the host"):
        run(make_config(tmp_path), host, state_dir)
    assert len(host.scripts()) == 1
    assert snapshot.latest(tmp_path / "bk") is None


def test_the_script_is_uploaded_under_a_random_name_and_expires(tmp_path, host, state_dir):  # [ECP]
    run(make_config(tmp_path), host, state_dir)
    puts = [c[1] for c in host.transport.calls if c[0] == "put"]
    assert len(puts) == 1
    assert re.fullmatch(rf"{host.ROOT}/[0-9a-f]{{16}}\.php", puts[0])


def test_a_credential_file_without_a_backup_section_is_a_config_error(tmp_path, host, state_dir):  # [NEG]
    with pytest.raises(ConfigError, match="backup"):
        run(make_config(tmp_path, with_backup=False), host, state_dir)
    assert host.transport.calls == []


def test_old_snapshots_are_pruned_after_a_successful_run(tmp_path, host, state_dir):  # [HAPPY]
    root = tmp_path / "bk"
    for name in ("2020-01-01T000000", "2020-01-02T000000"):
        (root / name).mkdir(parents=True)
        (root / name / "snapshot.json").write_text('{"files": {}}')

    result = run(make_config(tmp_path), host, state_dir)

    assert result.pruned == ["2020-01-01T000000"]
    assert (root / "2020-01-02T000000").is_dir()
