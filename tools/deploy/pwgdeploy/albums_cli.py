"""`pwg-albums`: create a local folder tree as albums on the remote (albums only, no photos)."""

from __future__ import annotations

import argparse
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

from pwgdeploy import albums
from pwgdeploy.bootstrap import login
from pwgdeploy.cli import STATE_DIR, http_client
from pwgdeploy.config import load_file
from pwgdeploy.errors import DeployError, RemoteHttpError

PROGRAM = "pwg-albums"


def positive_int(text: str) -> int:
    value = int(text)
    if value < 1:
        raise argparse.ArgumentTypeError(f"{text} is not a positive integer")
    return value


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog=PROGRAM,
        description="Create a local folder and its sub-folders as albums on the remote. "
        "An album that exists is skipped with everything below it.",
    )
    parser.add_argument("--dry-run", action="store_true", help="list the plan in the report, create nothing")
    parser.add_argument("--parent", type=positive_int, help="id of the album to create the tree under")
    parser.add_argument("config_json", help="credential file, as for pwg-deploy")
    parser.add_argument("folder", help="local folder to create as an album tree")
    return parser


def finish(results, errors, out, state_dir, done="created") -> None:
    report = albums.write_report(state_dir, results, datetime.now())
    counts = Counter(r.status for r in results)
    print(f"{counts[done]} {done}, {counts['skipped (exists)']} skipped, {len(errors)} failed", file=out)
    for message in errors:
        print(message, file=out)
    print(f"report: {report}", file=out)


def run(args, out, client_factory=http_client, state_dir=STATE_DIR) -> list[str]:
    """Returns the failure messages, one per album the server refused."""
    folder = Path(args.folder)
    if not folder.is_dir():
        raise DeployError(f"{folder} is not a directory")
    config = load_file(Path(args.config_json))
    base_url = config.site.base_url
    client = client_factory(config)
    login(client, config)

    local = albums.walk(folder.resolve())
    remote = albums.read_remote(client, base_url)
    if args.parent is not None and args.parent not in {album.id for album in remote}:
        raise DeployError(f"--parent {args.parent}: no such album on the remote")
    actions = albums.plan(local, remote, args.parent)
    if args.dry_run:
        results = [
            albums.Result(a.path, "skipped (exists)", a.album_id)
            if a.kind == albums.SKIP
            else albums.Result(a.path, "would create")
            for a in actions
        ]
        finish(results, [], out, state_dir, done="would create")
        return []
    ids: dict[str, int] = {}
    results: list[albums.Result] = []
    parent_failed: set[str] = set()
    errors: list[str] = []
    for action in actions:
        if action.kind == albums.SKIP:
            results.append(albums.Result(action.path, "skipped (exists)", action.album_id))
            continue
        if action.parent in parent_failed:
            parent_failed.add(action.path)
            results.append(albums.Result(action.path, "skipped (parent failed)"))
            continue
        parent_id = args.parent if action.parent is None else ids[action.parent]
        try:
            ids[action.path] = albums.create_album(client, base_url, action.name, parent_id)
        except RemoteHttpError as error:
            parent_failed.add(action.path)
            errors.append(f"failed  {action.path}: {error}")
            results.append(albums.Result(action.path, "failed", None, str(error)))
            continue
        results.append(albums.Result(action.path, "created", ids[action.path]))
    finish(results, errors, out, state_dir)
    return errors


def main(argv: list[str] | None = None, *, stdout=None, stderr=None, client_factory=http_client, state_dir=STATE_DIR) -> int:
    args = build_parser().parse_args(sys.argv[1:] if argv is None else argv)
    out = sys.stdout if stdout is None else stdout
    err = sys.stderr if stderr is None else stderr
    try:
        errors = run(args, out, client_factory, state_dir)
    except DeployError as error:
        print(f"{type(error).__name__}: {error}", file=err)
        return error.exit_code
    return RemoteHttpError.exit_code if errors else 0
