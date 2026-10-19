"""A local folder tree as an album tree on the remote, over ws.php.

`walk()` reads the disk, `read_remote()` the gallery, `plan()` decides, `create_album()`
acts. Only `plan()` holds decisions, so it is the pure part the unit tests drive.
"""

from __future__ import annotations

import json
import unicodedata
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

from pwgdeploy.bootstrap import ws_call
from pwgdeploy.errors import RemoteHttpError
from pwgdeploy.seed import collated

CREATE = "create"
SKIP = "skip"


@dataclass(frozen=True)
class LocalFolder:
    name: str
    children: tuple["LocalFolder", ...] = ()


@dataclass(frozen=True)
class RemoteAlbum:
    id: int
    name: str
    parent_id: int | None


@dataclass(frozen=True)
class Action:
    path: str
    name: str
    kind: str
    parent: str | None
    """Path of the parent folder; None for the top folder, which goes to the remote root."""
    album_id: int | None = None
    """The existing album a `skip` found, if any."""


def walk(path: Path) -> LocalFolder:
    """Sub-folders only: hidden ones (`.*`) and symlinks are left out, names in NFC."""
    path = Path(path)
    children = sorted(
        (
            entry
            for entry in path.iterdir()
            if not entry.name.startswith(".") and not entry.is_symlink() and entry.is_dir()
        ),
        key=lambda entry: entry.name,
    )
    return LocalFolder(
        unicodedata.normalize("NFC", path.name), tuple(walk(child) for child in children)
    )


def read_remote(client, base_url: str) -> list[RemoteAlbum]:
    """Every album, from one call. `name` is HTML-escaped by galleryrules; `name_raw` is stored."""
    result = ws_call(
        client, base_url, "pwg.categories.getList", {"recursive": "true"}
    )
    listed = result.get("categories") if isinstance(result, dict) else None
    if not isinstance(listed, list):
        raise RemoteHttpError("pwg.categories.getList did not return a list")
    return [
        RemoteAlbum(
            int(row["id"]),
            row["name_raw"],
            None if row.get("id_uppercat") is None else int(row["id_uppercat"]),
        )
        for row in listed
    ]


def plan(
    local: LocalFolder, remote: list[RemoteAlbum], parent_id: int | None = None
) -> list[Action]:
    """One action per folder, top-down. An album that exists (same parent, same name as the
    server compares it) is skipped, and its sub-folders get no action at all. The top folder
    is matched against the children of `parent_id` (None: the root)."""
    known: dict[tuple, int | None] = {}
    for album in remote:
        known.setdefault((album.parent_id, collated(album.name)), album.id)
    actions: list[Action] = []

    def visit(folder: LocalFolder, parent_path: str | None, parent_key) -> None:
        path = folder.name if parent_path is None else f"{parent_path}/{folder.name}"
        key = (parent_key, collated(folder.name))
        if key in known:
            actions.append(Action(path, folder.name, SKIP, parent_path, known[key]))
            return
        known[key] = None
        actions.append(Action(path, folder.name, CREATE, parent_path))
        for child in folder.children:
            visit(child, path, path)

    visit(local, None, parent_id)
    return actions


def create_album(client, base_url: str, name: str, parent_id: int | None) -> int:
    fields = {"name": name}
    if parent_id is not None:
        fields["parent"] = str(parent_id)
    return int(ws_call(client, base_url, "pwg.categories.add", fields)["id"])


@dataclass(frozen=True)
class Result:
    path: str
    status: str
    album_id: int | None = None
    message: str | None = None
    """The server's message for a failure."""


def write_report(state_dir: Path, results: list[Result], now: datetime) -> Path:
    """One entry per album, in tree order, in `albums-<timestamp>.json`."""
    state_dir = Path(state_dir)
    state_dir.mkdir(parents=True, exist_ok=True)
    report = state_dir / f"albums-{now.strftime('%Y%m%d-%H%M%S')}.json"
    entries = [
        {"path": r.path, "album_id": r.album_id, "status": r.status, "message": r.message}
        for r in results
    ]
    report.write_text(json.dumps(entries, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return report
