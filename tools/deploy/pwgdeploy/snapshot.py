"""Snapshot store of the remote backup: diff against the last snapshot, build hardlinked folders.

A snapshot is `<root>/<name>/` holding `files/` and `snapshot.json`. It is built in
`<name>.partial/` and renamed only when complete, so a killed run leaves a folder the next run
resumes. A file unchanged since the last complete snapshot is hardlinked, not fetched again.

Folder names are defined here and nowhere else: `snapshot_name()` builds one, `parse_name()`
reads one. A daily snapshot is the bare timestamp; every other kind is a `-<kind>` suffix.
"""

from __future__ import annotations

import hashlib
import json
import os
import shutil
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Callable

NAME_TIME_FORMAT = "%Y-%m-%dT%H%M%S"
STAMP_LENGTH = len("2026-10-20T100338")
KINDS = ("daily", "manual", "predeploy", "prerestore")
DEFAULT_KIND = "daily"
PARTIAL_SUFFIX = ".partial"
FILES_DIR = "files"
SNAPSHOT_JSON = "snapshot.json"
HASH_CHUNK_BYTES = 1 << 20


class SnapshotError(Exception):
    """The backup root cannot hold a consistent snapshot."""


@dataclass(frozen=True)
class RemoteFile:
    path: str
    size: int
    mtime: int


@dataclass(frozen=True)
class Entry:
    path: str
    size: int
    mtime: int
    sha1: str


@dataclass(frozen=True)
class Plan:
    fetch: list[RemoteFile]
    link: list[Entry]
    gone: list[str]


def snapshot_name(now: datetime, kind: str = DEFAULT_KIND) -> str:
    if kind not in KINDS:
        raise ValueError(f"unknown snapshot kind: {kind!r}")
    stamp = now.strftime(NAME_TIME_FORMAT)
    return stamp if kind == DEFAULT_KIND else f"{stamp}-{kind}"


def parse_name(name: str) -> tuple[datetime, str] | None:
    """(time, kind) of a complete snapshot's folder name; None for anything else, `.partial` too."""
    stamp, separator, suffix = name[:STAMP_LENGTH], name[STAMP_LENGTH:STAMP_LENGTH + 1], name[STAMP_LENGTH + 1:]
    if len(stamp) != STAMP_LENGTH:
        return None
    if separator == "":
        kind = DEFAULT_KIND
    elif separator == "-" and suffix in KINDS and suffix != DEFAULT_KIND:
        kind = suffix
    else:
        return None
    try:
        return datetime.strptime(stamp, NAME_TIME_FORMAT), kind
    except ValueError:
        return None


def diff(previous: dict[str, Entry] | None, remote: list[RemoteFile]) -> Plan:
    previous = previous or {}
    fetch, link = [], []
    for item in remote:
        old = previous.get(item.path)
        if old is not None and old.size == item.size and old.mtime == item.mtime:
            link.append(old)
        else:
            fetch.append(item)
    present = {item.path for item in remote}
    gone = sorted(path for path in previous if path not in present)
    return Plan(fetch=fetch, link=link, gone=gone)


def _complete_snapshots(root: Path) -> list[tuple[datetime, Path]]:
    found = []
    for child in Path(root).iterdir() if Path(root).is_dir() else []:
        parsed = parse_name(child.name)
        if parsed and child.is_dir() and (child / SNAPSHOT_JSON).is_file():
            found.append((parsed[0], child))
    return sorted(found)


def latest(root: Path) -> tuple[Path, dict[str, Entry]] | None:
    """The newest complete snapshot and its files, or None. `.partial` folders never count."""
    snapshots = _complete_snapshots(root)
    if not snapshots:
        return None
    folder = snapshots[-1][1]
    document = json.loads((folder / SNAPSHOT_JSON).read_text(encoding="utf-8"))
    files = {path: Entry(path=path, **row) for path, row in document["files"].items()}
    return folder, files


def _sha1(path: Path) -> str:
    digest = hashlib.sha1()
    with open(path, "rb") as handle:
        while chunk := handle.read(HASH_CHUNK_BYTES):
            digest.update(chunk)
    return digest.hexdigest()


def _write_json(path: Path, document: dict) -> None:
    temporary = path.with_name(path.name + ".tmp")
    try:
        temporary.write_text(json.dumps(document, indent=1, sort_keys=True), encoding="utf-8")
    except BaseException:
        temporary.unlink(missing_ok=True)
        raise
    os.replace(temporary, path)


def _take_partial(root: Path, name: str) -> Path:
    """Continue the newest `.partial` folder, remove older ones; else start `<name>.partial`."""
    partials = sorted(
        (p for p in root.iterdir() if p.is_dir() and p.name.endswith(PARTIAL_SUFFIX)),
        key=lambda p: p.name,
    )
    for older in partials[:-1]:
        shutil.rmtree(older)
    if partials:
        return partials[-1]
    folder = root / (name + PARTIAL_SUFFIX)
    folder.mkdir()
    return folder


def _check_same_device(root: Path, previous_folder: Path | None) -> None:
    if previous_folder and os.stat(root).st_dev != os.stat(previous_folder).st_dev:
        raise SnapshotError(
            f"{root} is on another file system than {previous_folder.name}: hardlinks would fail"
        )


def build(
    root: Path,
    name: str,
    plan: Plan,
    fetch: Callable[[str, Path], None],
    meta: dict | None = None,
) -> Path:
    """Build snapshot `name` under `root` and return its folder.

    `plan.link` entries are hardlinked from the latest complete snapshot; `plan.fetch` files
    are fetched unless a resumed `.partial` folder already holds them at the right size.
    `meta` is merged into snapshot.json (kind, git_commit, counts, ...).
    """
    root = Path(root)
    root.mkdir(parents=True, exist_ok=True)
    previous = latest(root)
    previous_folder = previous[0] if previous else None
    _check_same_device(root, previous_folder)
    partial = _take_partial(root, name)
    files_dir = partial / FILES_DIR
    files_dir.mkdir(exist_ok=True)

    entries: dict[str, dict] = {}
    for entry in plan.link:
        target = files_dir / entry.path
        if not target.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            os.link(previous_folder / FILES_DIR / entry.path, target)
        entries[entry.path] = {"size": entry.size, "mtime": entry.mtime, "sha1": entry.sha1}

    for item in plan.fetch:
        target = files_dir / item.path
        if not (target.is_file() and target.stat().st_size == item.size):
            target.parent.mkdir(parents=True, exist_ok=True)
            temporary = target.with_name(target.name + ".tmp")
            try:
                fetch(item.path, temporary)
                os.replace(temporary, target)
            except BaseException:
                temporary.unlink(missing_ok=True)
                raise
            os.utime(target, (item.mtime, item.mtime))
        entries[item.path] = {"size": item.size, "mtime": item.mtime, "sha1": _sha1(target)}

    _write_json(partial / SNAPSHOT_JSON, {**(meta or {}), "files": entries})
    final = root / name
    os.rename(partial, final)
    return final
