"""`pwg-backup`: flags, report, log file and the exit code of each failure.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP].
Both adapters are injected (FakeBackupHost); `--check` is a one-line delegation to check.check_main.
"""

import io
import json
from datetime import datetime

import pytest

from pwgdeploy import backup, backup_cli, check, errors
from tests.fakes import FakeBackupHost

NOW = datetime(2026, 10, 20, 10, 0, 0)
RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": FakeBackupHost.ROOT},
    "mysql": {"host": "localhost", "user": "d1", "password": "p", "database": "d1"},
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": FakeBackupHost.BASE_URL},
}


@pytest.fixture
def host():
    h = FakeBackupHost()
    h.add_photo("upload/a.jpg", b"aaa")
    return h


def invoke(tmp_path, host, *args, with_backup=True):
    raw = {**RAW, **({"backup": {"dir": str(tmp_path / "bk")}} if with_backup else {})}
    path = tmp_path / "deploy.json"
    path.write_text(json.dumps(raw))
    out, err = io.StringIO(), io.StringIO()
    code = backup_cli.main(
        [str(path), *args], stdout=out, stderr=err,
        transport_factory=lambda cfg: host.transport, client_factory=lambda cfg: host.client,
        state_dir=tmp_path, repo_root=tmp_path, clock=lambda: NOW, git_commit=lambda: "abc123",
    )
    return code, out.getvalue(), err.getvalue()


def log_lines(tmp_path):
    return (tmp_path / "bk" / backup.LOG_FILE).read_text().splitlines()


def test_a_run_prints_one_line_per_step_and_logs_it(tmp_path, host):  # [HAPPY]
    code, out, _ = invoke(tmp_path, host, "--kind", "manual")

    assert code == 0
    for label in ("agent", "dump", "list", "cleanup", "diff", "snapshot", "retention", "done"):
        assert f"  {label}" in out
    lines = log_lines(tmp_path)
    assert len(lines) == 1
    assert lines[0] == "2026-10-20T10:00:00 2026-10-20T10:00:00 manual fetched=1 linked=0 bytes=13 ok"
    assert host.transport.closed


def test_the_default_kind_is_manual_and_the_snapshot_name_carries_it(tmp_path, host):  # [ECP]
    invoke(tmp_path, host)
    assert [p.name for p in (tmp_path / "bk").iterdir() if p.is_dir()] == ["2026-10-20T100000-manual"]


def test_an_unknown_kind_is_a_usage_error(tmp_path, host):  # [NEG]
    with pytest.raises(SystemExit):
        invoke(tmp_path, host, "--kind", "weekly")


def test_a_failed_run_exits_with_its_own_code_and_logs_the_failure(tmp_path, host):  # [NEG]
    host.listed_extra = [{"path": "../x", "size": 1, "mtime": 1}]

    code, _, err = invoke(tmp_path, host)

    assert code == errors.UnsafeRemotePathError.exit_code
    assert "UnsafeRemotePathError" in err
    assert log_lines(tmp_path)[0].endswith("manual fetched=- linked=- bytes=- failed UnsafeRemotePathError")


def test_a_killed_run_exits_130_and_says_it_resumes(tmp_path):  # [ST]
    host = FakeBackupHost(interrupt_on_get="upload/a.jpg")
    host.add_photo("upload/a.jpg", b"aaa")

    code, _, err = invoke(tmp_path, host)

    assert code == 130
    assert "resumes" in err
    assert log_lines(tmp_path)[0].endswith("interrupted")


def test_a_credential_file_without_backup_exits_3_and_writes_no_log(tmp_path, host):  # [NEG]
    code, _, err = invoke(tmp_path, host, with_backup=False)

    assert code == errors.ConfigError.exit_code
    assert "backup" in err
    assert host.transport.calls == []


def test_check_runs_the_restore_check_on_the_backup_dir_and_nothing_else(tmp_path, host, monkeypatch):  # [HAPPY]
    seen = []
    monkeypatch.setattr(check, "check_main", lambda root, out, err: seen.append(root) or 0)

    code, _, _ = invoke(tmp_path, host, "--check")

    assert code == 0
    assert seen == [(tmp_path / "bk").resolve()]
    assert host.transport.calls == []


def test_a_failing_check_returns_its_exit_code(tmp_path, host, monkeypatch):  # [NEG]
    monkeypatch.setattr(check, "check_main", lambda root, out, err: check.EXIT_CHECK_FAILED)
    assert invoke(tmp_path, host, "--check")[0] == check.EXIT_CHECK_FAILED
