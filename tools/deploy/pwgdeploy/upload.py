"""One transfer, start to finish.

Every decision here is made against the local file set and the local manifest; nothing is
ever read back from the server to decide what to send. The manifest is written after each
successful file, so an interrupted run resumes where it stopped instead of restarting —
which is why it records what was *uploaded*, never what was *intended*.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Callable

from pwgdeploy import fileset, manifest
from pwgdeploy.config import DeployConfig
from pwgdeploy.manifest import Diff
from pwgdeploy.urls import remote_path

# tools/pwg_rel_create.sh:133-140 makes these world-writable in the release zip; over FTP
# the same thing is a SITE CHMOD, which not every server offers.
WRITABLE_MODE = "0777"


@dataclass(frozen=True)
class UploadResult:
    diff: Diff
    uploaded: list[str]
    deleted: list[str]
    dirs_created: list[str]
    chmod_supported: bool

    @property
    def unchanged_count(self) -> int:
        return len(self.diff.unchanged)


def run(
    config: DeployConfig,
    repo_root: Path,
    state_dir: Path,
    transport,
    *,
    dry_run: bool = False,
    prune: bool = True,
    progress: Callable[[str, int, int], None] | None = None,
    tracked=fileset.verified_tracked_paths,
) -> UploadResult:
    """Publish the file set to `transport`, uploading only what changed."""
    repo_root = Path(repo_root)
    root = config.ftp.remote_root

    local_of = {
        remote_path(root, rel): repo_root / rel
        for rel in fileset.select(tracked(repo_root))
    }
    current = {remote: manifest.file_hash(local) for remote, local in local_of.items()}

    state_path = manifest.manifest_path(state_dir, config.ftp.host, root)
    entries = manifest.load(state_path)
    difference = manifest.diff(current, entries)

    if dry_run:
        return UploadResult(
            diff=difference,
            uploaded=[],
            deleted=[],
            dirs_created=[],
            chmod_supported=True,
        )

    uploaded: list[str] = []
    deleted: list[str] = []
    dirs_created: list[str] = []

    transport.connect()
    try:
        for directory in _directories(root, difference.pending):
            transport.makedirs(directory)
            dirs_created.append(directory)

        total = len(difference.pending)
        for remote in difference.pending:
            transport.put(local_of[remote], remote)
            entries[remote] = current[remote]
            manifest.save(state_path, entries)
            uploaded.append(remote)
            if progress is not None:
                progress(remote, len(uploaded), total)

        if prune:
            for remote in difference.removed:
                transport.delete(remote)
                entries.pop(remote, None)
                manifest.save(state_path, entries)
                deleted.append(remote)

        chmod_supported = all(
            [
                transport.chmod(remote_path(root, name), WRITABLE_MODE)
                for name in fileset.WRITABLE_REMOTE_PATHS
            ]
        )
    finally:
        transport.close()

    return UploadResult(
        diff=difference,
        uploaded=uploaded,
        deleted=deleted,
        dirs_created=dirs_created,
        chmod_supported=chmod_supported,
    )


def _directories(root: str, pending: list[str]) -> list[str]:
    """Every directory the pending files need, parents first, each named once.

    The two data directories are always in the list: they hold no tracked file, but the
    gallery cannot write a thumbnail or accept an upload without them.
    """
    needed = {remote_path(root, name) for name in fileset.REMOTE_DIRS_TO_CREATE}
    for remote in pending:
        parent = remote.rsplit("/", 1)[0] if "/" in remote else ""
        if parent and parent != root:
            needed.add(parent)
    return sorted(needed)
