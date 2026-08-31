"""The file-server port, and the one adapter that speaks to a real web space.

Six operations, no more — everything the upload needs and nothing it does not. The
adapter holds no decisions of its own beyond refusing a cleartext session (decision 2),
which is why it is verified by hand once and recorded in the ledger rather than by an
integration suite nobody could run without an FTPS server.
"""

from __future__ import annotations

import ftplib
from pathlib import Path
from typing import Protocol

from pwgdeploy.errors import InsecureTransportError

CONNECT_TIMEOUT_SECONDS = 30
TRANSFER_BLOCKSIZE = 1 << 16

# What FEAT must advertise before a password is sent. Plain FTP would put it on the wire
# in clear, and the host offers FTPS, so its absence is a bug or an impostor.
AUTH_TLS_FEATURE = "AUTH TLS"


class Transport(Protocol):
    """What a deploy needs from a file server."""

    def connect(self) -> None: ...

    def close(self) -> None: ...

    def makedirs(self, remote_dir: str) -> None:
        """mkdir -p. Idempotent: an existing directory is success, not an error."""

    def put(self, local: Path, remote_path: str) -> None: ...

    def delete(self, remote_path: str) -> None: ...

    def chmod(self, remote_path: str, mode: str) -> bool:
        """False when the server has no SITE CHMOD — a warning, not a failed deploy."""

    def exists(self, remote_path: str) -> bool: ...


class FtplibTransport:
    """`ftplib.FTP_TLS`, with a FEAT probe that refuses to log in over cleartext."""

    def __init__(
        self,
        host: str,
        user: str,
        password: str,
        port: int = 21,
        *,
        ftp_factory=ftplib.FTP_TLS,
    ):
        self._host = host
        self._user = user
        self._password = password
        self._port = port
        self._ftp_factory = ftp_factory
        self._ftp = None
        # Every directory this session has already issued an MKD for. FTP has no
        # mkdir -p, so without this a 3478-file deploy would re-create every parent.
        self._created: set[str] = set()

    def connect(self) -> None:
        ftp = self._ftp_factory(timeout=CONNECT_TIMEOUT_SECONDS)
        ftp.connect(self._host, self._port)
        features = ftp.sendcmd("FEAT")
        if AUTH_TLS_FEATURE not in features.upper():
            raise InsecureTransportError(
                f"{self._host}:{self._port} does not advertise {AUTH_TLS_FEATURE}, so "
                f"logging in would send the password in clear. FEAT replied:\n{features}"
            )
        ftp.login(self._user, self._password)
        ftp.prot_p()
        ftp.set_pasv(True)
        self._ftp = ftp

    def close(self) -> None:
        if self._ftp is None:
            return
        try:
            self._ftp.quit()
        except ftplib.all_errors:
            self._ftp.close()
        finally:
            self._ftp = None

    def makedirs(self, remote_dir: str) -> None:
        prefix = "/" if remote_dir.startswith("/") else ""
        segments = [segment for segment in remote_dir.split("/") if segment]
        walked = ""
        for segment in segments:
            walked = f"{walked}/{segment}" if walked else prefix + segment
            if walked in self._created:
                continue
            try:
                self._ftp.mkd(walked)
            except ftplib.error_perm:
                # 550 here means "already exists" on every server worth deploying to;
                # a genuinely unwritable path fails loudly on the STOR that follows.
                pass
            self._created.add(walked)

    def put(self, local: Path, remote_path: str) -> None:
        with open(local, "rb") as handle:
            self._ftp.storbinary(
                f"STOR {remote_path}", handle, blocksize=TRANSFER_BLOCKSIZE
            )

    def delete(self, remote_path: str) -> None:
        self._ftp.delete(remote_path)

    def chmod(self, remote_path: str, mode: str) -> bool:
        try:
            self._ftp.sendcmd(f"SITE CHMOD {mode} {remote_path}")
        except ftplib.error_perm:
            return False
        return True

    def exists(self, remote_path: str) -> bool:
        try:
            self._ftp.size(remote_path)
        except ftplib.error_perm:
            return False
        return True
