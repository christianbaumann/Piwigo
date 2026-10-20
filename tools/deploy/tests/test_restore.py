"""Restore of a snapshot: the pure plan, the order of calls, and every refusal.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST].
Decision table not applicable to `plan`: upload and delete are two independent rules.
The PHP half (`import` against a real database) is witnessed by tests/integration/test_restore_ddev.py;
here the host is `FakeBackupHost`. The real host and FTPS stay a hand check (docs/agents/TESTING.md).
"""

import io
import json
import re
from datetime import datetime, timedelta
from types import SimpleNamespace

import pytest

from pwgdeploy import backup, backup_cli, config, manifest, restore, snapshot
from pwgdeploy.errors import AgentLeftoverError, BackupError, RemoteHttpError, TransportError, UnsafeRemotePathError
from pwgdeploy.http import Response
from pwgdeploy.snapshot import Entry, RemoteFile
from tests.fakes import FakeBackupHost

NOW = datetime(2026, 10, 20, 10, 0, 0)
LATER = NOW + timedelta(hours=1)
RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": FakeBackupHost.ROOT},
    "mysql": {"host": "localhost", "user": "d1", "password": "p", "database": "d1"},
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": FakeBackupHost.BASE_URL},
}
SNAPSHOT_DUMP = b"-- yesterday --\nINSERT 1;\n"
CONFIG_PATH = "local/config/config.inc.php"
DAILY = "2026-10-20T100000"


def entry(path, size=1, mtime=1):
    return Entry(path, size, mtime, "sha")


def rf(path, size=1, mtime=1):
    return RemoteFile(path, size, mtime)


# --- plan (pure)

@pytest.mark.parametrize("remote, upload, delete, unchanged", [
    ([rf("upload/a")], [], [], 1),                                  # identical
    ([], ["upload/a"], [], 0),                                      # missing on the host
    ([rf("upload/a", size=2)], ["upload/a"], [], 0),                # another size
    ([rf("upload/a", mtime=2)], ["upload/a"], [], 0),               # another mtime
    ([rf("upload/a"), rf("upload/extra")], [], ["upload/extra"], 1),  # not in the snapshot
])
def test_the_plan_uploads_what_differs_and_deletes_what_the_snapshot_lacks(remote, upload, delete, unchanged):  # [ECP][BVA]
    result = restore.plan({"upload/a": entry("upload/a")}, remote)

    assert (result.upload, result.delete, result.unchanged) == (upload, delete, unchanged)


def test_an_empty_snapshot_deletes_everything_and_an_empty_host_receives_everything():  # [BVA]
    assert restore.plan({}, [rf("upload/a")]).delete == ["upload/a"]
    files = {"upload/a": entry("upload/a"), "galleries/b": entry("galleries/b")}
    assert restore.plan(files, []).upload == ["galleries/b", "upload/a"]


# --- the request

@pytest.mark.parametrize("name", [
    "2026-10-20T100000.partial", "nonsense", "../2026-10-20T100000", "2026-10-20T100000-weekly", "",
])
def test_a_partial_or_unknown_name_is_refused(name):  # [NEG][ECP]
    with pytest.raises(restore.RestoreError, match="not the name of a complete snapshot"):
        restore.check_request(name, True, False)


def test_without_yes_a_restore_is_refused_but_a_dry_run_is_not():  # [NEG][ECP]
    with pytest.raises(restore.RestoreError, match="--yes"):
        restore.check_request(DAILY, False, False)
    restore.check_request(DAILY, False, True)
    restore.check_request(DAILY, True, False)


def test_a_restore_error_shares_the_backup_exit_code():  # [HAPPY]
    assert restore.RestoreError.exit_code == BackupError.exit_code


def test_the_deny_all_file_matches_the_one_agent_php_writes():  # [HAPPY] structural guard
    source = backup.agent.TEMPLATE.read_text(encoding="utf-8")
    assert len(source) > 1000
    for line in restore.DENY_ALL_HTACCESS.splitlines():
        assert line in source


# --- the run

@pytest.fixture
def host():
    h = FakeBackupHost()
    h.cleanup_removes_dir_files = True
    h.dump_bytes = SNAPSHOT_DUMP
    h.add_photo("upload/2026/a.jpg", b"aaa")
    h.add_photo("galleries/x/b.jpg", b"bbbb", mtime=1_700_000_100)
    return h


@pytest.fixture
def cfg(tmp_path):
    return config.load({**RAW, "backup": {"dir": str(tmp_path / "bk")}})


@pytest.fixture
def state_dir(tmp_path):
    folder = tmp_path / "state"
    folder.mkdir()
    return folder


def manifest_file(state_dir):
    return manifest.manifest_path(state_dir, "ftp.example.test", FakeBackupHost.ROOT)


@pytest.fixture
def yesterday(host, cfg, state_dir):
    """A snapshot of the host as it is now; the tests then change the host."""
    manifest.save(manifest_file(state_dir), {"index.php": "yesterday"})
    return backup.run(
        cfg, "daily", NOW, host.transport, host.client,
        repo_root=state_dir, state_dir=state_dir, git_commit=lambda: "abc123", clock=lambda: NOW,
    )


def spoil(host, state_dir):
    """The host after a bad day: a photo deleted, an album file added, the config and database changed."""
    root = FakeBackupHost.ROOT
    del host.transport.files[f"{root}/upload/2026/a.jpg"]
    del host.meta["upload/2026/a.jpg"]
    host.add_photo("galleries/new/c.jpg", b"ccc")
    host.transport.files[f"{root}/{CONFIG_PATH}"] = b"broken"
    host.dump_bytes = b"-- today --\n"
    manifest.save(manifest_file(state_dir), {"index.php": "today"})
    host.actions.clear()
    host.transport.calls.clear()


def do_restore(cfg, host, state_dir, name=DAILY, *, reports=None, **kwargs):
    return restore.run(
        cfg, name, LATER, host.transport, host.client,
        repo_root=state_dir, state_dir=state_dir, git_commit=lambda: "def456", clock=lambda: LATER,
        report=(lambda label, text: reports.append((label, text))) if reports is not None else (lambda *_: None),
        **{"yes": True, **kwargs},
    )


def test_a_restore_brings_back_the_photo_removes_the_album_file_and_resets_database_config_and_manifest(
    host, cfg, state_dir, yesterday
):  # [HAPPY]
    spoil(host, state_dir)
    root = FakeBackupHost.ROOT

    result = do_restore(cfg, host, state_dir)

    files = host.transport.files
    assert files[f"{root}/upload/2026/a.jpg"] == b"aaa"
    assert f"{root}/galleries/new/c.jpg" not in files
    assert files[f"{root}/{CONFIG_PATH}"] == CONFIG_PATH.encode()
    assert host.imported == [SNAPSHOT_DUMP]
    assert manifest.load(manifest_file(state_dir)) == {"index.php": "yesterday"}
    assert (result.uploaded, result.deleted, result.manifest_restored) == (1, 1, True)
    assert result.statements == 2


def test_the_prerestore_snapshot_holds_the_state_just_before_the_restore(host, cfg, state_dir, yesterday):  # [HAPPY]
    spoil(host, state_dir)

    result = do_restore(cfg, host, state_dir)

    before = cfg.backup.dir / result.prerestore
    assert result.prerestore == "2026-10-20T110000-prerestore"
    assert (before / "files/galleries/new/c.jpg").read_bytes() == b"ccc"
    assert not (before / "files/upload/2026/a.jpg").exists()
    assert (before / "db/dump.sql.gz").read_bytes() == b"-- today --\n"
    assert json.loads((before / "snapshot.json").read_text())["kind"] == "prerestore"
    assert (before / "deploy-manifest.json").is_file()
    assert (cfg.backup.dir / DAILY).is_dir(), "the snapshot restored from must survive the run"


def test_the_host_keeps_no_script_and_no_dump_directory(host, cfg, state_dir, yesterday):  # [HAPPY]
    spoil(host, state_dir)

    do_restore(cfg, host, state_dir)

    assert host.scripts() == []
    assert host.dump_files() == []


def test_the_order_is_snapshot_then_uploads_then_deletes_then_import_then_config(host, cfg, state_dir, yesterday):  # [ST]
    spoil(host, state_dir)
    root = FakeBackupHost.ROOT

    do_restore(cfg, host, state_dir)

    calls = host.transport.calls
    index = lambda *needle: next(i for i, c in enumerate(calls) if c[:2] == needle)
    first_get = next(i for i, c in enumerate(calls) if c[0] == "get")
    photo_put = index("put", f"{root}/upload/2026/a.jpg")
    deletion = index("delete", f"{root}/galleries/new/c.jpg")
    dump_put = next(i for i, c in enumerate(calls) if c[0] == "put" and c[1].endswith("/dump.sql.gz"))
    config_put = index("put", f"{root}/{CONFIG_PATH}")
    assert first_get < photo_put < deletion < dump_put < config_put
    assert host.actions == ["dump", "list", "cleanup", "list", "cleanup", "import", "cleanup"]
    assert calls[dump_put - 2][1].endswith("/.htaccess"), "the dump lands in a directory that is already protected"


def test_a_failed_prerestore_snapshot_stops_before_anything_changes(host, cfg, state_dir, yesterday):  # [NEG]
    spoil(host, state_dir)
    host.fail_action = "dump"

    with pytest.raises(RemoteHttpError):
        do_restore(cfg, host, state_dir)

    assert host.imported == []
    assert [c for c in host.transport.calls if c[0] == "delete" and "galleries/new" in c[1]] == []
    assert f"{FakeBackupHost.ROOT}/galleries/new/c.jpg" in host.transport.files
    assert host.scripts() == []


def test_an_unsafe_path_in_the_hosts_listing_deletes_nothing(host, cfg, state_dir, yesterday, monkeypatch):  # [NEG]
    spoil(host, state_dir)
    monkeypatch.setattr(restore.backup, "run", lambda *a, **k: SimpleNamespace(name="x-prerestore"))
    host.listed_extra = [{"path": "local/config/database.inc.php", "size": 1, "mtime": 1}]

    with pytest.raises(UnsafeRemotePathError):
        do_restore(cfg, host, state_dir)

    assert [c for c in host.transport.calls if c[0] == "delete" and "/local/" in c[1]] == []
    assert host.imported == []
    assert host.scripts() == []


def test_a_failing_import_reports_the_prerestore_snapshot_and_still_cleans_up(host, cfg, state_dir, yesterday):  # [NEG]
    spoil(host, state_dir)
    host.fail_action = None
    reports = []
    original = host.client.post

    def failing(url, fields, *, timeout=None):
        if fields["action"] == "import":
            host.fail_action = "import"
        return original(url, fields, timeout=timeout)

    host.client.post = failing

    with pytest.raises(RemoteHttpError):
        do_restore(cfg, host, state_dir, reports=reports)

    assert ("failed", "the remote may be partly restored; the state before is snapshot "
            "2026-10-20T110000-prerestore") in reports
    assert host.scripts() == [] and host.dump_files() == []
    assert host.transport.files[f"{FakeBackupHost.ROOT}/{CONFIG_PATH}"] == b"broken"
    assert manifest.load(manifest_file(state_dir)) == {"index.php": "today"}


def test_a_script_that_cannot_be_removed_is_reported_as_a_leftover(host, cfg, state_dir, yesterday):  # [NEG]
    spoil(host, state_dir)
    host.fail_action = None
    host.fail_cleanup_over_ftp = True
    original = host.client.post
    host.client.post = lambda url, fields, *, timeout=None: (
        original(url, fields, timeout=timeout) if fields["action"] != "cleanup"
        else Response(url, 500, '{"error": "no"}')
    )

    with pytest.raises(AgentLeftoverError):
        do_restore(cfg, host, state_dir)


def test_a_failed_delete_names_the_file(host, cfg, state_dir, yesterday, monkeypatch):  # [NEG]
    spoil(host, state_dir)
    real = host.transport.delete

    def delete(path):
        if "galleries/new" in path:
            raise OSError("550 denied")
        real(path)

    monkeypatch.setattr(host.transport, "delete", delete)

    with pytest.raises(TransportError, match=r"galleries/new/c\.jpg"):
        do_restore(cfg, host, state_dir)

    assert host.imported == []


def test_the_deletions_are_counted_and_listed_before_anything_is_uploaded(host, cfg, state_dir, yesterday):  # [HAPPY]
    spoil(host, state_dir)
    reports = []

    do_restore(cfg, host, state_dir, reports=reports)

    labels = [label for label, _ in reports]
    plan_at = labels.index("plan")
    assert reports[plan_at][1] == "1 to upload, 1 to delete, 1 unchanged"
    assert reports[plan_at + 1] == ("delete", "galleries/new/c.jpg")
    assert plan_at < labels.index("upload")


def test_a_long_deletion_list_is_capped_in_the_report():  # [BVA]
    lines = []
    over = restore.MAX_LISTED_DELETIONS + 3
    restore._report_plan(lambda label, text: lines.append((label, text)),
                         restore.RestorePlan([], [f"upload/{i}" for i in range(over)], 0))

    assert len(lines) == 1 + restore.MAX_LISTED_DELETIONS + 1
    assert lines[-1] == ("delete", "... and 3 more")


def test_a_snapshot_without_a_manifest_keeps_the_current_one(host, cfg, state_dir, yesterday):  # [ECP]
    spoil(host, state_dir)
    (cfg.backup.dir / DAILY / backup.MANIFEST_COPY).unlink()
    reports = []

    result = do_restore(cfg, host, state_dir, reports=reports)

    assert result.manifest_restored is False
    assert manifest.load(manifest_file(state_dir)) == {"index.php": "today"}
    assert any(label == "manifest" and "kept" in text for label, text in reports)


@pytest.mark.parametrize("damage", ["photo", "dump", "config"])
def test_a_damaged_snapshot_is_refused_before_the_host_is_contacted(host, cfg, state_dir, yesterday, damage):  # [NEG][ECP]
    folder = cfg.backup.dir / DAILY
    target = {
        "photo": folder / "files/upload/2026/a.jpg",
        "dump": folder / "db/dump.sql.gz",
        "config": folder / "files" / CONFIG_PATH,
    }[damage]
    target.unlink()
    spoil(host, state_dir)

    with pytest.raises(restore.RestoreError, match="damaged"):
        do_restore(cfg, host, state_dir)

    assert host.transport.calls == [] and host.actions == []


def test_a_snapshot_without_files_is_refused_so_it_cannot_empty_the_remote(host, cfg, state_dir, yesterday):  # [NEG][BVA]
    document = cfg.backup.dir / DAILY / snapshot.SNAPSHOT_JSON
    data = json.loads(document.read_text())
    data["files"] = {}
    document.write_text(json.dumps(data))
    spoil(host, state_dir)

    with pytest.raises(restore.RestoreError, match="holds no files"):
        do_restore(cfg, host, state_dir)

    assert host.transport.calls == [] and host.actions == []


def test_a_snapshot_that_does_not_exist_is_refused(host, cfg, state_dir, yesterday):  # [NEG]
    with pytest.raises(restore.RestoreError, match="no snapshot"):
        do_restore(cfg, host, state_dir, name="2020-01-01T000000")


# --- dry run

def test_a_dry_run_contacts_nothing_and_lists_the_snapshot_import(host, cfg, state_dir, yesterday):  # [HAPPY]
    folders = sorted(p.name for p in cfg.backup.dir.iterdir())
    reports = []

    result = restore.run(
        cfg, DAILY, LATER, None, None, repo_root=state_dir, state_dir=state_dir,
        dry_run=True, report=lambda label, text: reports.append((label, text)),
    )

    assert result.uploaded == result.deleted == 0
    assert any(label == "import" for label, _ in reports)
    assert sorted(p.name for p in cfg.backup.dir.iterdir()) == folders


def test_a_dry_run_compares_with_the_newest_other_snapshot_and_says_so(host, cfg, state_dir, yesterday):  # [HAPPY]
    spoil(host, state_dir)
    backup.run(
        cfg, "manual", LATER, host.transport, host.client,
        repo_root=state_dir, state_dir=state_dir, git_commit=lambda: "x", clock=lambda: LATER,
    )
    reports = []

    restore.run(
        cfg, DAILY, LATER, None, None, repo_root=state_dir, state_dir=state_dir,
        dry_run=True, report=lambda label, text: reports.append((label, text)),
    )

    assert ("plan", "1 to upload, 1 to delete, 1 unchanged") in reports
    assert any(label == "compare" and "not the host" in text for label, text in reports)


# --- the command line

def invoke(tmp_path, host, *args):
    path = tmp_path / "deploy.json"
    path.write_text(json.dumps({**RAW, "backup": {"dir": str(tmp_path / "bk")}}))
    out, err = io.StringIO(), io.StringIO()
    code = backup_cli.main(
        [str(path), *args], stdout=out, stderr=err,
        transport_factory=lambda c: host.transport, client_factory=lambda c: host.client,
        state_dir=tmp_path / "state", repo_root=tmp_path, clock=lambda: LATER, git_commit=lambda: "abc123",
    )
    return code, out.getvalue(), err.getvalue()


def test_the_command_restores_with_yes_and_logs_one_line(tmp_path, host, cfg, state_dir, yesterday):  # [HAPPY]
    spoil(host, state_dir)

    code, out, _ = invoke(tmp_path, host, "--restore", DAILY, "--yes")

    assert code == 0
    assert "  prerestore" in out and "  import" in out and "  done" in out
    last = (tmp_path / "bk" / backup.LOG_FILE).read_text().splitlines()[-1]
    assert re.fullmatch(r"\S+ \S+ restore fetched=1 linked=1 bytes=3 ok", last)
    assert host.transport.closed


def test_the_command_refuses_without_yes_and_touches_nothing(tmp_path, host, cfg, state_dir, yesterday):  # [NEG]
    spoil(host, state_dir)

    code, _, err = invoke(tmp_path, host, "--restore", DAILY)

    assert code == restore.RestoreError.exit_code
    assert "--yes" in err
    assert host.transport.calls == [] and host.imported == []


def test_the_command_refuses_a_partial_folder(tmp_path, host):  # [NEG]
    code, _, err = invoke(tmp_path, host, "--restore", "2026-10-20T100000.partial", "--yes")

    assert code == restore.RestoreError.exit_code
    assert "complete snapshot" in err
    assert host.transport.calls == []


def test_the_command_dry_run_opens_no_connection(tmp_path, host, cfg, state_dir, yesterday):  # [HAPPY]
    spoil(host, state_dir)

    code, out, _ = invoke(tmp_path, host, "--restore", DAILY, "--dry-run")

    assert code == 0
    assert "(dry run)" in out
    assert host.transport.calls == [] and host.actions == []


@pytest.mark.parametrize("flags", [["--yes"], ["--dry-run"], ["--restore", DAILY, "--check"]])
def test_flags_without_their_partner_are_usage_errors(tmp_path, host, flags):  # [NEG]
    with pytest.raises(SystemExit):
        invoke(tmp_path, host, *flags)


def test_kind_is_a_usage_error_with_restore(tmp_path, host):  # [NEG]
    with pytest.raises(SystemExit):
        invoke(tmp_path, host, "--restore", DAILY, "--yes", "--kind", "daily")


def test_a_failed_restore_mails_and_names_the_restore_kind(tmp_path, host, cfg, state_dir, yesterday):  # [HAPPY]
    sent = []
    path = tmp_path / "deploy.json"
    mail = {"host": "smtp.example.test", "port": 465, "secure": "ssl", "user": "u", "password": "mailsecret",
            "sender_email": "noreply@example.test"}
    path.write_text(json.dumps({**RAW, "backup": {"dir": str(tmp_path / "bk")}, "mail": mail}))

    code = backup_cli.main(
        [str(path), "--restore", DAILY], stdout=io.StringIO(), stderr=io.StringIO(),
        transport_factory=lambda c: host.transport, client_factory=lambda c: host.client,
        state_dir=tmp_path / "state", repo_root=tmp_path, clock=lambda: LATER, git_commit=lambda: "abc123",
        send_mail=lambda m, message: sent.append(message),
    )

    assert code == restore.RestoreError.exit_code
    assert "pwg-backup (restore)" in sent[0].get_content()
