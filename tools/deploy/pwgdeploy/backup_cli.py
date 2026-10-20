"""`pwg-backup`: take an incremental snapshot of the remote's database, photos and config."""

from __future__ import annotations

import argparse
import io
import smtplib
import sys
from datetime import datetime
from pathlib import Path

from pwgdeploy import backup, check, notify, restore, snapshot
from pwgdeploy.cli import INTERRUPTED_EXIT_CODE, REPO_ROOT, STATE_DIR, ftps_transport, http_client
from pwgdeploy.config import load_file
from pwgdeploy.errors import ConfigError, DeployError

PROGRAM = "pwg-backup"
DEFAULT_KIND = "manual"
LABEL_WIDTH = 10
OK = "ok"


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog=PROGRAM,
        description="Pull an incremental snapshot of the remote's database, photos and config into "
        "the backup folder of the credential file. The folder is not encrypted.",
    )
    parser.add_argument("config_json", help="credential file, as for pwg-deploy; needs a 'backup' section")
    parser.add_argument(
        "--kind", choices=snapshot.KINDS, default=None, help=f"snapshot kind (default {DEFAULT_KIND})"
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="restore check: import the newest snapshot's dump into DDEV's scratch database and verify its files",
    )
    parser.add_argument("--restore", metavar="SNAPSHOT", help="put this snapshot (a folder name) back on the remote")
    parser.add_argument("--yes", action="store_true", help="confirm --restore, which replaces the remote's database and files")
    parser.add_argument(
        "--dry-run", action="store_true", help="with --restore: list what would be uploaded, deleted and imported; contacts nothing"
    )
    return parser


def main(
    argv: list[str] | None = None,
    *,
    stdout=None,
    stderr=None,
    transport_factory=ftps_transport,
    client_factory=http_client,
    state_dir: Path = STATE_DIR,
    repo_root: Path = REPO_ROOT,
    clock=datetime.now,
    git_commit=None,
    send_mail=notify.send,
) -> int:
    parser = build_parser()
    args = parser.parse_args(sys.argv[1:] if argv is None else argv)
    if (args.yes or args.dry_run) and not args.restore:
        parser.error("--yes and --dry-run belong to --restore")
    if args.restore and args.check:
        parser.error("--restore and --check are separate runs")
    if args.restore and args.kind is not None:
        parser.error("--kind belongs to a backup; a restore takes its own prerestore snapshot")
    if args.kind is None:
        args.kind = DEFAULT_KIND
    out = sys.stdout if stdout is None else stdout
    err = sys.stderr if stderr is None else stderr
    started = clock()
    backup_dir = None
    config = None
    try:
        loaded = load_file(Path(args.config_json))
        if loaded.backup is None:
            raise ConfigError("the credential file has no 'backup' section")
        config = loaded
        backup_dir = config.backup.dir
        if args.check:
            problems = io.StringIO()
            code = check.check_main(backup_dir, out, problems)
            err.write(problems.getvalue())
            if code:
                _mail_failure(config, args.kind, "check", problems.getvalue(), err, send_mail, clock)
            return code

        if args.restore:
            return restore.restore_main(
                config, args.restore, yes=args.yes, dry_run=args.dry_run, out=out,
                transport_factory=transport_factory, client_factory=client_factory,
                state_dir=state_dir, repo_root=repo_root, clock=clock, git_commit=git_commit,
                label_width=LABEL_WIDTH,
            )

        def report(label: str, text: str) -> None:
            print(f"  {label:<{LABEL_WIDTH}}{text}", file=out)

        print(f"Piwigo backup -> {backup_dir} ({config.ftp.host})", file=out)
        transport = transport_factory(config)
        transport.connect()
        try:
            result = backup.run(
                config, args.kind, started, transport, client_factory(config),
                repo_root=repo_root, state_dir=state_dir, report=report, clock=clock,
                git_commit=git_commit,
            )
        finally:
            transport.close()
        backup.append_log(
            backup_dir,
            backup.format_log_line(
                result.started, result.finished, args.kind, result.fetched, result.linked,
                result.bytes_fetched, OK,
            ),
        )
        report("done", f"{result.name}, {result.drift_count} drift")
        return 0
    except KeyboardInterrupt:
        _log_failure(backup_dir, _logged_kind(args), started, clock, "interrupted")
        print("interrupted; the next run resumes the unfinished snapshot", file=err)
        return INTERRUPTED_EXIT_CODE
    except DeployError as error:
        _log_failure(backup_dir, _logged_kind(args), started, clock, f"failed {type(error).__name__}")
        print(f"{type(error).__name__}: {error}", file=err)
        if config is not None:
            _mail_failure(config, _logged_kind(args), type(error).__name__, str(error), err, send_mail, clock)
        return error.exit_code


def _logged_kind(args) -> str:
    return restore.KIND if args.restore else args.kind


def _log_failure(backup_dir, kind, started, clock, result) -> None:
    if backup_dir is not None:
        backup.append_log(
            backup_dir, backup.format_log_line(started, clock(), kind, "-", "-", "-", result)
        )


def _mail_failure(config, kind, step, error_text, err, send_mail, clock) -> None:
    """Mail the failure; a mail that cannot go out is logged and never changes the exit code."""
    if config.mail is None:
        _log_mail_problem(config, kind, clock, "no-mail-section", err, "no mail section in the credential file; no failure mail sent")
        return
    try:
        tail = (config.backup.dir / backup.LOG_FILE).read_text(encoding="utf-8").splitlines()
    except OSError:
        tail = []
    message = notify.failure_mail(
        config.mail, notify.recipient_of(config), config.site.base_url, kind, step, error_text,
        tail, notify.secrets_of(config),
    )
    try:
        send_mail(config.mail, message)
    except (OSError, smtplib.SMTPException) as mail_error:
        _log_mail_problem(config, kind, clock, "mail-failed", err, f"failure mail not sent: {type(mail_error).__name__}")


def _log_mail_problem(config, kind, clock, result, err, text) -> None:
    print(text, file=err)
    now = clock()
    backup.append_log(config.backup.dir, backup.format_log_line(now, now, kind, "-", "-", "-", result))
