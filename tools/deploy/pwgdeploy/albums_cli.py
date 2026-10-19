"""`pwg-albums`: create a local folder tree as albums on the remote (albums only, no photos)."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from pwgdeploy import albums
from pwgdeploy.bootstrap import login
from pwgdeploy.cli import http_client
from pwgdeploy.config import load_file
from pwgdeploy.errors import DeployError

PROGRAM = "pwg-albums"


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog=PROGRAM,
        description="Create a local folder and its sub-folders as albums on the remote. "
        "An album that exists is skipped with everything below it.",
    )
    parser.add_argument("--dry-run", action="store_true", help="print the plan, create nothing")
    parser.add_argument("config_json", help="credential file, as for pwg-deploy")
    parser.add_argument("folder", help="local folder to create as an album tree")
    return parser


def print_dry_run(actions, out) -> None:
    would_create = skipped = 0
    for action in actions:
        if action.kind == albums.SKIP:
            skipped += 1
            print(f"skipped (exists)  {action.path}", file=out)
        else:
            would_create += 1
            print(f"would create      {action.path}", file=out)
    print(f"{would_create} would create, {skipped} skipped (dry run)", file=out)


def run(args, out, client_factory=http_client) -> None:
    folder = Path(args.folder)
    if not folder.is_dir():
        raise DeployError(f"{folder} is not a directory")
    config = load_file(Path(args.config_json))
    base_url = config.site.base_url
    client = client_factory(config)
    login(client, config)

    local = albums.walk(folder.resolve())
    actions = albums.plan(local, albums.read_remote(client, base_url))
    if args.dry_run:
        print_dry_run(actions, out)
        return
    ids: dict[str, int] = {}
    created = skipped = 0
    for action in actions:
        if action.kind == albums.SKIP:
            skipped += 1
            print(f"skipped (exists)  {action.path}", file=out)
            continue
        parent_id = None if action.parent is None else ids[action.parent]
        ids[action.path] = albums.create_album(client, base_url, action.name, parent_id)
        created += 1
        print(f"created           {action.path}", file=out)
    print(f"{created} created, {skipped} skipped", file=out)


def main(argv: list[str] | None = None, *, stdout=None, stderr=None, client_factory=http_client) -> int:
    args = build_parser().parse_args(sys.argv[1:] if argv is None else argv)
    out = sys.stdout if stdout is None else stdout
    err = sys.stderr if stderr is None else stderr
    try:
        run(args, out, client_factory)
    except DeployError as error:
        print(f"{type(error).__name__}: {error}", file=err)
        return error.exit_code
    return 0
