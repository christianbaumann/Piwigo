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


MAIL = {
    "host": "smtp.example.test", "port": 465, "secure": "ssl", "user": "u", "password": "mailsecret",
    "sender_email": "noreply@example.test",
}


def invoke(tmp_path, host, *args, with_backup=True, mail=None, notify=None, send_mail=None):
    backup_section = {"dir": str(tmp_path / "bk"), **({"notify": notify} if notify else {})}
    raw = {**RAW, **({"backup": backup_section} if with_backup else {}), **({"mail": mail} if mail else {})}
    path = tmp_path / "deploy.json"
    path.write_text(json.dumps(raw))
    out, err = io.StringIO(), io.StringIO()
    code = backup_cli.main(
        [str(path), *args], stdout=out, stderr=err,
        transport_factory=lambda cfg: host.transport, client_factory=lambda cfg: host.client,
        state_dir=tmp_path, repo_root=tmp_path, clock=lambda: NOW, git_commit=lambda: "abc123",
        **({"send_mail": send_mail} if send_mail else {}),
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


class Outbox:
    def __init__(self, fail=None):
        self.sent, self.fail = [], fail

    def __call__(self, mail, message):
        if self.fail:
            raise self.fail
        self.sent.append((mail, message))


def failing_host():
    h = FakeBackupHost()
    h.listed_extra = [{"path": "../x", "size": 1, "mtime": 1}]
    return h


def test_a_failed_run_mails_the_admin_address_by_default(tmp_path):  # [HAPPY]
    outbox = Outbox()
    code, _, _ = invoke(tmp_path, failing_host(), mail=MAIL, send_mail=outbox)

    assert code == errors.UnsafeRemotePathError.exit_code
    ((_, message),) = outbox.sent
    assert message["To"] == "you@example.net"
    assert "UnsafeRemotePathError" in message["Subject"]
    assert "failed UnsafeRemotePathError" in message.get_content()


def test_backup_notify_overrides_the_recipient(tmp_path):  # [ECP]
    outbox = Outbox()
    invoke(tmp_path, failing_host(), mail=MAIL, notify="ops@example.net", send_mail=outbox)
    assert outbox.sent[0][1]["To"] == "ops@example.net"


def test_a_successful_run_sends_no_mail(tmp_path, host):  # [NEG]
    outbox = Outbox()
    assert invoke(tmp_path, host, mail=MAIL, send_mail=outbox)[0] == 0
    assert outbox.sent == []


def test_a_killed_run_sends_no_mail(tmp_path):  # [NEG]
    host = FakeBackupHost(interrupt_on_get="upload/a.jpg")
    host.add_photo("upload/a.jpg", b"aaa")
    outbox = Outbox()
    assert invoke(tmp_path, host, mail=MAIL, send_mail=outbox)[0] == 130
    assert outbox.sent == []


def test_a_failed_send_is_logged_and_keeps_the_exit_code(tmp_path):  # [ERR-path]
    outbox = Outbox(fail=OSError("refused"))
    code, _, err = invoke(tmp_path, failing_host(), mail=MAIL, send_mail=outbox)

    assert code == errors.UnsafeRemotePathError.exit_code
    assert "failure mail not sent" in err
    assert log_lines(tmp_path)[-1].endswith("mail-failed")


def test_a_config_without_a_mail_section_logs_that_no_mail_can_go(tmp_path):  # [NEG]
    outbox = Outbox()
    code, _, err = invoke(tmp_path, failing_host(), send_mail=outbox)

    assert code == errors.UnsafeRemotePathError.exit_code
    assert "no mail section" in err
    assert log_lines(tmp_path)[-1].endswith("no-mail-section")
    assert outbox.sent == []


def test_a_failing_check_mails_too(tmp_path, host, monkeypatch):  # [HAPPY]
    def failing_check(root, out, err):
        print("check failed: table images differs", file=err)
        return check.EXIT_CHECK_FAILED

    monkeypatch.setattr(check, "check_main", failing_check)
    outbox = Outbox()

    code, _, err = invoke(tmp_path, host, "--check", mail=MAIL, send_mail=outbox)

    assert code == check.EXIT_CHECK_FAILED
    assert "table images differs" in err
    assert "table images differs" in outbox.sent[0][1].get_content()
    assert "check" in outbox.sent[0][1]["Subject"]


def test_a_passing_check_sends_no_mail(tmp_path, host, monkeypatch):  # [NEG]
    monkeypatch.setattr(check, "check_main", lambda root, out, err: 0)
    outbox = Outbox()
    assert invoke(tmp_path, host, "--check", mail=MAIL, send_mail=outbox)[0] == 0
    assert outbox.sent == []
