"""What was last uploaded where, and what that makes pending.

The manifest is a local, git-ignored, per-target JSON file mapping remote path → sha256
of the bytes last successfully uploaded there. It is the only record of remote state the
tool keeps; nothing is ever read back from the server to decide what to send.

sha256 over file bytes rather than git's blob SHA-1, so one code path covers tracked
files, submodule files and the generated config.inc.php alike.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping

MANIFEST_VERSION = 1
HASH_CHUNK_BYTES = 1 << 20

# Everything outside this set becomes "_" in a manifest file name, so a remote root can
# neither nest the file in directories nor escape the state directory. A dot is kept —
# hosts are full of them — but a run of dots is collapsed, so no ".." survives either.
_UNSAFE_IN_FILENAME = re.compile(r"[^A-Za-z0-9._-]+")
_DOT_RUN = re.compile(r"\.{2,}")


def manifest_path(state_dir: Path, host: str, remote_root: str) -> Path:
    """One manifest per target, so two web spaces never share state."""
    slug = _slugify(host) + "_" + _slugify(remote_root)
    return Path(state_dir) / f"{slug}.json"


def _slugify(value: str) -> str:
    safe = _DOT_RUN.sub(".", _UNSAFE_IN_FILENAME.sub("_", value))
    return safe.strip("._") or "root"


def load(path: Path) -> dict[str, str]:
    """The recorded entries, or {} when there is nothing this build can read.

    A version mismatch or an unreadable file discards the manifest rather than guessing:
    the next run re-uploads everything, which is correct-but-slow instead of fast-but-wrong.
    """
    try:
        document = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}
    if not isinstance(document, dict) or document.get("version") != MANIFEST_VERSION:
        return {}
    entries = document.get("entries")
    return entries if isinstance(entries, dict) else {}


def save(path: Path, entries: Mapping[str, str]) -> None:
    """Write atomically, so an interrupted run never truncates the previous manifest."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    document = {"version": MANIFEST_VERSION, "entries": dict(entries)}
    temporary = path.with_name(path.name + ".tmp")
    try:
        temporary.write_text(json.dumps(document, indent=1, sort_keys=True), encoding="utf-8")
    except BaseException:
        temporary.unlink(missing_ok=True)
        raise
    os.replace(temporary, path)


def file_hash(path: Path) -> str:
    """sha256 of the file's bytes, streamed so a large photo costs no memory."""
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        while chunk := handle.read(HASH_CHUNK_BYTES):
            digest.update(chunk)
    return digest.hexdigest()


@dataclass(frozen=True)
class Diff:
    new: list[str]
    changed: list[str]
    unchanged: list[str]
    removed: list[str]

    @property
    def pending(self) -> list[str]:
        """What this run has to upload, in a deterministic order."""
        return sorted(self.new + self.changed)


def diff(current: Mapping[str, str], previous: Mapping[str, str]) -> Diff:
    """Compare the file set's hashes against what the last run recorded.

    `removed` is previous - current, so only paths the previous manifest recorded are
    ever eligible for deletion: remote-authored content under upload/ and _data/ is
    unreachable from here by construction.
    """
    new, changed, unchanged = [], [], []
    for path, digest in current.items():
        if path not in previous:
            new.append(path)
        elif previous[path] != digest:
            changed.append(path)
        else:
            unchanged.append(path)
    removed = [path for path in previous if path not in current]
    return Diff(
        new=sorted(new),
        changed=sorted(changed),
        unchanged=sorted(unchanged),
        removed=sorted(removed),
    )
