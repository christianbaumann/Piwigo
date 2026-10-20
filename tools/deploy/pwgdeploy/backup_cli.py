"""`pwg-backup`: take an incremental snapshot of the remote's database, photos and config."""

from __future__ import annotations

import argparse
import sys
from datetime import datetime
from pathlib import Path

from pwgdeploy import backup, check, snapshot
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
        "--kind", choices=snapshot.KINDS, default=DEFAULT_KIND, help=f"snapshot kind (default {DEFAULT_KIND})"
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="restore check: import the newest snapshot's dump into DDEV's scratch database and verify its files",
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
) -> int:
    args = build_parser().parse_args(sys.argv[1:] if argv is None else argv)
    out = sys.stdout if stdout is None else stdout
    err = sys.stderr if stderr is None else stderr
    started = clock()
    backup_dir = None
    try:
        config = load_file(Path(args.config_json))
        if config.backup is None:
            raise ConfigError("the credential file has no 'backup' section")
        backup_dir = config.backup.dir
        if args.check:
            return check.check_main(backup_dir, out, err)

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
        _log_failure(backup_dir, args.kind, started, clock, "interrupted")
        print("interrupted; the next run resumes the unfinished snapshot", file=err)
        return INTERRUPTED_EXIT_CODE
    except DeployError as error:
        _log_failure(backup_dir, args.kind, started, clock, f"failed {type(error).__name__}")
        print(f"{type(error).__name__}: {error}", file=err)
        return error.exit_code


def _log_failure(backup_dir, kind, started, clock, result) -> None:
    if backup_dir is not None:
        backup.append_log(
            backup_dir, backup.format_log_line(started, clock(), kind, "-", "-", "-", result)
        )
