"""In-memory stand-ins for the two ports, so every decision is tested without a network.

`FakeTransport` is what the whole of the upload suite runs against. It keeps the files it
was given, and a single ordered call log — the order is the point in several tests
(a directory must exist before the file that lands in it).
"""

from __future__ import annotations

from pathlib import Path

from pwgdeploy.errors import TransportError


class FakeTransport:
    """Records every operation in order; can be armed to fail on the Nth put."""

    def __init__(self, *, fail_on_put: int | None = None, chmod_supported: bool = True):
        self.fail_on_put = fail_on_put
        self.chmod_supported = chmod_supported
        # remote path -> bytes, including anything seeded before the run to stand for
        # content this deploy never wrote.
        self.files: dict[str, bytes] = {}
        self.dirs: list[str] = []
        self.calls: list[tuple] = []
        self.connected = False
        self.closed = False
        self._puts = 0

    # --- the port ------------------------------------------------------------------

    def connect(self) -> None:
        self.calls.append(("connect",))
        self.connected = True

    def close(self) -> None:
        self.calls.append(("close",))
        self.closed = True

    def makedirs(self, remote_dir: str) -> None:
        self.calls.append(("makedirs", remote_dir))
        if remote_dir not in self.dirs:
            self.dirs.append(remote_dir)

    def put(self, local: Path, remote_path: str) -> None:
        self._puts += 1
        if self._puts == self.fail_on_put:
            self.calls.append(("put-failed", remote_path))
            raise TransportError(f"fake transport refused put #{self._puts}: {remote_path}")
        self.calls.append(("put", remote_path))
        self.files[remote_path] = Path(local).read_bytes()

    def delete(self, remote_path: str) -> None:
        self.calls.append(("delete", remote_path))
        self.files.pop(remote_path, None)

    def chmod(self, remote_path: str, mode: str) -> bool:
        self.calls.append(("chmod", remote_path, mode))
        return self.chmod_supported

    def exists(self, remote_path: str) -> bool:
        self.calls.append(("exists", remote_path))
        return remote_path in self.files

    # --- what tests ask it -----------------------------------------------------------

    def names(self) -> list[str]:
        return [call[0] for call in self.calls]

    def paths(self, operation: str) -> list[str]:
        return [call[1] for call in self.calls if call[0] == operation]
