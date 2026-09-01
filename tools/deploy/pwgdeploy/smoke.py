"""The FTPS adapter's one hand check, executed by machine instead of remembered.

Phase 4 of the deploy plan asks for a check no unit test can make: against the *real* web
space, does the handshake succeed under `PROT P`, does a file uploaded over FTPS reappear
over HTTP, and does deleting it take it away again. That needs credentials and a server,
so it cannot run in the suite — but it does not have to be a human clicking through an
FTP client either. This module is the procedure; only the credentials are manual.

    python3 -m pwgdeploy.smoke deploy.local.json

The orchestration below is port-typed and unit-tested against `FakeTransport`; what the
run adds on top is the two real adapters underneath it.
"""

from __future__ import annotations

import secrets
import sys
import tempfile
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from pwgdeploy.config import DeployConfig, load_file
from pwgdeploy.errors import DeployError, RemoteHttpError
from pwgdeploy.transport import FtplibTransport
from pwgdeploy.urls import remote_path, site_url

# Long enough that no earlier run, cache or unrelated file can collide with it.
PROBE_TOKEN_BYTES = 8
PROBE_NAME_TEMPLATE = "pwg-deploy-smoke-{token}.txt"
PROBE_BODY_TEMPLATE = "pwg-deploy smoke probe {token}\n"
FETCH_TIMEOUT_SECONDS = 30


@dataclass(frozen=True)
class SmokeResult:
    remote: str
    url: str
    body_matched: bool
    gone_after_delete: bool


def run(config: DeployConfig, transport, fetch, *, token: str | None = None) -> SmokeResult:
    """Upload one probe file, read it back over HTTP, delete it, confirm it is gone.

    `fetch(url)` returns the response body, or None when the server has no such file.
    The body carries the token, so a stale file or a caching proxy cannot satisfy the
    check by accident.
    """
    token = token or secrets.token_hex(PROBE_TOKEN_BYTES)
    name = PROBE_NAME_TEMPLATE.format(token=token)
    body = PROBE_BODY_TEMPLATE.format(token=token).encode("utf-8")
    remote = remote_path(config.ftp.remote_root, name)
    url = site_url(config.site.base_url, name)

    transport.connect()
    try:
        with tempfile.TemporaryDirectory() as scratch:
            local = Path(scratch) / name
            local.write_bytes(body)
            transport.put(local, remote)

        fetched = fetch(url)
        if fetched != body:
            raise RemoteHttpError(
                f"{url} did not return the bytes just uploaded to {remote}. "
                f"Expected {body!r}, got {fetched!r} — the FTP root and the document "
                f"root may not be the same directory."
            )

        transport.delete(remote)
        gone = fetch(url) is None
    finally:
        transport.close()

    return SmokeResult(remote=remote, url=url, body_matched=True, gone_after_delete=gone)


def urllib_fetch(url: str) -> bytes | None:
    """The real HTTP side. None for 404, so "gone" is a value rather than an exception."""
    try:
        with urllib.request.urlopen(url, timeout=FETCH_TIMEOUT_SECONDS) as response:
            return response.read()
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return None
        raise RemoteHttpError(f"{url} returned HTTP {error.code}") from error


def main(argv: list[str] | None = None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    if len(argv) != 1:
        print(f"usage: python3 -m {__spec__.name} <credential file>", file=sys.stderr)
        return 2

    try:
        config = load_file(Path(argv[0]))
        transport = FtplibTransport(
            config.ftp.host, config.ftp.user, config.ftp.password, config.ftp.port
        )
        result = run(config, transport, urllib_fetch)
    except DeployError as error:
        print(f"{type(error).__name__}: {error}", file=sys.stderr)
        return error.exit_code

    print(f"FTPS handshake and PROT P: ok ({config.ftp.host}:{config.ftp.port})")
    print(f"uploaded and read back:    {result.remote} -> {result.url}")
    print(
        "deleted again:             "
        + ("ok" if result.gone_after_delete else "WARNING: still served after delete")
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
