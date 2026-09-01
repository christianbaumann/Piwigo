"""What the server actually holds, against what the manifest says it holds.

The manifest is the tool's only picture of the remote, and the prune only ever considers
paths that picture records. A path dropped from the manifest while still on the server is
therefore an orphan no run can reach — invisible to every report the deploy prints. This
module is the one place that reads the server back, and it only reads: it lists, it
compares, and it names what it found. Deleting an orphan stays a hand operation over FTP.
decision 0030.

`walk` takes the listing operation as a callable and `compare` takes three collections, so
both are pure given their inputs and neither knows what FTP is.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Callable, Iterable, Mapping

from pwgdeploy import fileset
from pwgdeploy.transport import RemoteEntry
from pwgdeploy.urls import remote_path

# Server-authoritative by construction: the deploy creates them empty and never writes
# into them, so the manifest records nothing under either. Walking them would report
# every thumbnail and every uploaded original as an orphan that is not one.
AUDIT_SKIP = fileset.REMOTE_DIRS_TO_CREATE

# A symlink loop on the server lists itself forever. This is a correctness bound first
# and a performance bound second; the published tree is nowhere near it.
MAX_DEPTH = 20


@dataclass(frozen=True)
class AuditReport:
    covered: list[str]
    """On the server and accounted for — recorded by the manifest, or generated."""
    orphans: list[str]
    """On the server, absent from the manifest. No run can reach these."""
    missing: list[str]
    """In the manifest, absent from the server. The next run would call them unchanged."""
    directories: int = 0
    skipped: tuple[str, ...] = ()


def walk(
    list_dir: Callable[[str], list[RemoteEntry]],
    root: str,
    *,
    skip: Iterable[str] = AUDIT_SKIP,
    max_depth: int = MAX_DEPTH,
) -> tuple[list[str], int]:
    """Every file path under `root`, and how many directories were listed.

    Paths come back as remote paths, the same form the manifest keys use, so the two are
    directly comparable. Depth is bounded rather than trusted: a directory at `max_depth`
    is listed, one below it is not.
    """
    skipped = {name.strip("/") for name in skip}
    files: list[str] = []
    directories = 0

    pending = [("", 0)]
    while pending:
        relative, depth = pending.pop(0)
        directories += 1
        for entry in list_dir(_dir_path(root, relative)):
            below = f"{relative}/{entry.name}" if relative else entry.name
            if not entry.is_dir:
                files.append(remote_path(root, below))
                continue
            if below in skipped or depth >= max_depth:
                continue
            pending.append((below, depth + 1))

    return sorted(files), directories


def compare(
    remote_files: Iterable[str],
    entries: Mapping[str, str],
    generated: Iterable[str],
    *,
    directories: int = 0,
    skipped: tuple[str, ...] = AUDIT_SKIP,
) -> AuditReport:
    """Three buckets, each sorted so two runs of the audit are comparable.

    `generated` — `local/config/config.inc.php` — is published by the bootstrap rather
    than enumerated by git, so it is deliberate on the server whether or not the manifest
    happens to record it. Counting it as an orphan is the same mistake `upload.py:62-69`
    had to fix once, where it read as `removed` on every run after the first.
    """
    present = set(remote_files)
    recorded = set(entries)
    accounted = recorded | set(generated)
    return AuditReport(
        covered=sorted(present & accounted),
        orphans=sorted(present - accounted),
        missing=sorted(recorded - present),
        directories=directories,
        skipped=tuple(skipped),
    )


def _dir_path(root: str, relative: str) -> str:
    """The path to list. An empty root means the FTP login directory, not the server's
    root — `remote_path` answers `/` for that pair, which would list the wrong tree."""
    if relative:
        return remote_path(root, relative)
    return root.rstrip("/")
