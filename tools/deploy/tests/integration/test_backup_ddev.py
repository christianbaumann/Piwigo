"""`backup.run()` end to end against DDEV: the real agent.php over real HTTP, a local-filesystem transport.

Run with `cd tools/deploy && PWG_DDEV_WEBROOT=<main checkout> uv run pytest -m integration -k backup_ddev`.
Needs DDEV up and the git-ignored `deploy.ddev.json` (only its site.base_url is used).

The FTP space is a random-named sandbox directory below the web root (DDEV serves the main
checkout, not a worktree), its `local/config/database.inc.php` points at `db`, which is only
dumped, never written. `DdevTransport` maps FTP paths onto the sandbox and waits for Mutagen
and opcache, which a real FTP server does not need. Every sandbox is removed in a finally; the
backup folder is a pytest tmp dir. Not for production.

Covers (task 06 verification): a second run fetches only changed files and shares the inode of
an unchanged one; a run killed during the file fetch leaves `.partial` and the next run completes
it; `snapshot.json` holds commit, timestamps, dump method and counts; after each run the script's
URL answers 404 and neither the script nor a dump is left; the audit finds no leftover script (and
finds a planted one). The real FTPS and the real host stay a hand check (docs/agents/TESTING.md).
"""

import json
import secrets
import shutil
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path

import pytest

from pwgdeploy import audit, backup, config
from pwgdeploy.errors import TransportError
from pwgdeploy.http import UrllibClient
from pwgdeploy.transport import RemoteEntry
from tests.integration.test_agent_ddev import (
    COUNTED, OPCACHE_WAIT_SECONDS, SOURCE_DB, SYNC_POLL_SECONDS, SYNC_TIMEOUT_SECONDS,
    WEBROOT, Sandbox, counts, ddev,
)

pytestmark = pytest.mark.integration

CONFIG = Path(__file__).resolve().parents[2] / "deploy.ddev.json"
NOW = datetime(2026, 10, 20, 10, 0, 0)
PHOTO_MTIME = 1_700_000_000
CHANGED_MTIME = 1_700_000_500
ARTIFICIAL_COMMIT = "0123abcd"
MIN_PHOTOS = 3


class DdevTransport:
    """FTP paths ("/<sandbox>/upload/a.jpg") onto the sandbox, truth read from the container."""

    def __init__(self, sandbox: Sandbox, interrupt_on: str | None = None):
        self.sandbox = sandbox
        self.interrupt_on = interrupt_on
        self.gets: list[str] = []

    def connect(self): ...

    def close(self): ...

    def _relative(self, remote_path: str) -> str:
        return remote_path.lstrip("/").removeprefix(self.sandbox.name).lstrip("/")

    def put(self, local: Path, remote_path: str) -> None:
        self.sandbox.write(self._relative(remote_path), Path(local).read_bytes())
        self.sandbox.sync()

    def get(self, remote_path: str, local: Path) -> None:
        relative = self._relative(remote_path)
        if self.interrupt_on and relative.endswith(self.interrupt_on):
            raise KeyboardInterrupt()
        self.gets.append(relative)
        deadline = time.monotonic() + SYNC_TIMEOUT_SECONDS
        target = self.sandbox.path / relative
        while True:
            size = self.sandbox.in_container(f"stat -c %s '{relative}'").stdout.strip()
            if size and target.is_file() and target.stat().st_size == int(size):
                break
            if time.monotonic() > deadline:
                raise TransportError(f"{relative} never reached the host")
            time.sleep(SYNC_POLL_SECONDS)
        shutil.copyfile(target, local)

    def exists(self, remote_path: str) -> bool:
        return self.sandbox.exists(self._relative(remote_path))

    def delete(self, remote_path: str) -> None:
        self.sandbox.in_container(f"rm -f '{self._relative(remote_path)}'")

    def list_dir(self, remote_dir: str) -> list[RemoteEntry]:
        relative = self._relative(remote_dir) or "."
        names = self.sandbox.in_container(f"ls -1pA '{relative}'").stdout.splitlines()
        return [RemoteEntry(name.rstrip("/"), name.endswith("/")) for name in names if name]


@pytest.fixture
def sandbox():
    box = Sandbox(SOURCE_DB)
    try:
        box.write("local/config/config.inc.php", "<?php\n")
        box.sync()
        yield box
    finally:
        box.remove()


def make_config(tmp_path, sandbox):
    base = json.loads(CONFIG.read_text())["site"]["base_url"]
    return config.load({
        "ftp": {"host": "unused.invalid", "user": "u", "password": "p", "remote_root": f"/{sandbox.name}"},
        "mysql": {"host": "db", "user": "db", "password": "db", "database": "db"},
        "admin": {"username": "w", "password": "p", "email": "w@example.invalid"},
        "site": {"base_url": f"{base}/{sandbox.name}"},
        "backup": {"dir": str(tmp_path / "backups")},
    })


def seed_photo(sandbox, relative, content, mtime):
    sandbox.write(relative, content)
    sandbox.sync()
    result = sandbox.in_container(f"touch -d @{mtime} '{relative}'")
    assert result.returncode == 0, result.stderr


def run(cfg, transport, now, tmp_path):
    return backup.run(
        cfg, "manual", now, transport, UrllibClient(),
        repo_root=tmp_path, state_dir=tmp_path, git_commit=lambda: ARTIFICIAL_COMMIT,
    )


def status_of(url):
    try:
        with urllib.request.urlopen(url, timeout=30) as handle:
            return handle.status
    except urllib.error.HTTPError as error:
        return error.code


def assert_host_clean(sandbox, cfg):
    names = sandbox.in_container("ls -1A").stdout.split()
    assert [n for n in names if n.endswith(".php")] == []
    assert [n for n in names if n.startswith(backup.DUMP_DIR_PREFIX)] == []
    root = f"/{sandbox.name}"
    transport = DdevTransport(sandbox)
    files, _ = audit.walk(transport.list_dir, root)
    report = audit.compare(files, {}, [], root=root)
    assert report.leftover_scripts == []
    assert len(files) >= MIN_PHOTOS, "the audit listed nothing; the clean claim is vacuous"


def test_two_runs_a_kill_and_a_clean_host(tmp_path, sandbox):
    cfg = make_config(tmp_path, sandbox)
    seed_photo(sandbox, "upload/2026/a.jpg", b"first photo", PHOTO_MTIME)
    seed_photo(sandbox, "galleries/x/b.jpg", b"second photo", PHOTO_MTIME)
    transport = DdevTransport(sandbox)
    puts = []
    original_put = transport.put
    transport.put = lambda local, remote: (puts.append(remote), original_put(local, remote))[1]

    # run 1: everything is fetched, the host is clean afterwards
    first = run(cfg, transport, NOW, tmp_path)
    assert first.dump_method in ("mysqldump", "php")
    assert (first.fetched, first.linked) >= (2, 0)
    script_url = f"{cfg.site.base_url}/{puts[0].rsplit('/', 1)[1]}"
    assert status_of(script_url) == 404
    time.sleep(OPCACHE_WAIT_SECONDS)
    assert_host_clean(sandbox, cfg)

    document = json.loads((first.folder / "snapshot.json").read_text())
    assert document["git_commit"] == ARTIFICIAL_COMMIT
    assert document["dump_method"] == first.dump_method
    assert {"db_started", "files_started", "finished"} <= set(document)
    assert document["counts"] == counts(SOURCE_DB)
    assert set(document["counts"]) == set(COUNTED)
    assert document["counts"]["images"] > 0
    assert document["prefix"] == "piwigo_"
    assert (first.folder / backup.DUMP_PATH).stat().st_size > 0
    assert (first.folder / "files/upload/2026/a.jpg").read_bytes() == b"first photo"

    # a planted script is what the audit exists to find
    sandbox.write("zz-planted.php", b"<?php")
    sandbox.sync()
    files, _ = audit.walk(DdevTransport(sandbox).list_dir, f"/{sandbox.name}")
    planted = audit.compare(files, {}, [], root=f"/{sandbox.name}")
    assert planted.leftover_scripts == [f"/{sandbox.name}/zz-planted.php"]
    sandbox.in_container("rm -f zz-planted.php")

    # run 2: one photo changed, one new file killed mid-fetch
    seed_photo(sandbox, "galleries/x/b.jpg", b"second photo, edited", CHANGED_MTIME)
    seed_photo(sandbox, "upload/2026/c.jpg", b"third photo", PHOTO_MTIME)
    killer = DdevTransport(sandbox, interrupt_on="upload/2026/c.jpg")
    with pytest.raises(KeyboardInterrupt):
        run(cfg, killer, NOW + timedelta(hours=1), tmp_path)
    partials = [p.name for p in (tmp_path / "backups").glob("*.partial")]
    assert len(partials) == 1
    time.sleep(OPCACHE_WAIT_SECONDS)
    assert_host_clean(sandbox, cfg)

    # run 3 resumes the partial and fetches only what is missing from it
    resumer = DdevTransport(sandbox)
    second = run(cfg, resumer, NOW + timedelta(hours=2), tmp_path)
    assert not list((tmp_path / "backups").glob("*.partial"))
    assert "upload/2026/a.jpg" not in resumer.gets, "an unchanged file was downloaded again"
    assert "upload/2026/c.jpg" in resumer.gets, "the file the killed run never fetched was not fetched"
    assert (second.folder / "files/upload/2026/c.jpg").read_bytes() == b"third photo"
    assert (second.folder / "files/galleries/x/b.jpg").read_bytes() == b"second photo, edited"
    assert (first.folder / "files/galleries/x/b.jpg").read_bytes() == b"second photo"
    same = "files/upload/2026/a.jpg"
    assert (first.folder / same).stat().st_ino == (second.folder / same).stat().st_ino
    time.sleep(OPCACHE_WAIT_SECONDS)
    assert_host_clean(sandbox, cfg)
