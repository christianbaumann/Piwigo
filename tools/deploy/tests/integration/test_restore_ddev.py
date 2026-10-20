"""`restore.run()` end to end against DDEV: the real agent.php, a real MariaDB import, a local-filesystem transport.

Run with `cd tools/deploy && PWG_DDEV_WEBROOT=<main checkout> uv run pytest -m integration -k restore_ddev`.
Needs DDEV up and the git-ignored `deploy.ddev.json` (only its site.base_url is used).

Two sandboxes below the web root. Sandbox A reads the install's `db` (read-only dump) and yields
the snapshot that is restored. Sandbox B is the "remote": its `database.inc.php` names a scratch
database that this test creates, fills from the snapshot's dump, damages and drops, so the import
never writes `db`. Restoring uploads A's `database.inc.php` into B last, which only changes a
file in B's sandbox. Sandboxes are removed in finally. Not for production.

Covers (task 09 verification): a photo deleted and a file added on the host come back / go away; the
scratch database holds the snapshot's row counts again; the `prerestore` snapshot holds the damaged
state; the manifest is written back; afterwards the agent's URL answers 404 and neither the script
nor a dump directory is left. Real FTPS and the real host stay a hand check (docs/agents/TESTING.md).
"""

import gzip
import json
import subprocess
import time
from datetime import datetime, timedelta

import pytest

from pwgdeploy import backup, manifest, restore
from pwgdeploy.http import UrllibClient
from tests.integration.test_agent_ddev import (
    COUNTED, OPCACHE_WAIT_SECONDS, SOURCE_DB, WEBROOT, Sandbox, counts, ddev, scratch_db, sql,
)
from tests.integration.test_backup_ddev import (
    ARTIFICIAL_COMMIT, PHOTO_MTIME, DdevTransport, assert_host_clean, make_config, seed_photo, status_of,
)

pytestmark = pytest.mark.integration

NOW = datetime(2026, 10, 20, 10, 0, 0)
IMPORT_TIMEOUT_SECONDS = 300
FIXTURE_MANIFEST = {"index.php": "from-the-snapshot"}


class RestoringTransport(DdevTransport):
    """DdevTransport plus the two things restore needs: directories (writes make them) and a put log."""

    def __init__(self, sandbox):
        super().__init__(sandbox)
        self.puts: list[str] = []

    def makedirs(self, remote_dir: str) -> None: ...

    def put(self, local, remote_path):
        self.puts.append(self._relative(remote_path))
        super().put(local, remote_path)


@pytest.fixture
def boxes(scratch_db):
    made = []
    try:
        for database in (SOURCE_DB, scratch_db):
            box = Sandbox(database)
            made.append(box)
            box.write("local/config/config.inc.php", "<?php\n")
            box.sync()
        yield made
    finally:
        for box in made:
            box.remove()


def import_dump_into(database: str, dump) -> None:
    done = subprocess.run(
        ["ddev", "exec", "mysql", "-uroot", "-proot", "-hdb", database],
        cwd=WEBROOT, input=gzip.decompress(dump.read_bytes()), capture_output=True,
        timeout=IMPORT_TIMEOUT_SECONDS,
    )
    assert done.returncode == 0, done.stderr.decode()


def test_a_snapshot_comes_back_over_a_damaged_host(tmp_path, boxes, scratch_db):
    source, target = boxes
    assert scratch_db in (target.path / "local/config/database.inc.php").read_text(), "B must not point at db"
    state_dir = tmp_path / "state"
    cfg_source, cfg_target = make_config(tmp_path, source), make_config(tmp_path, target)

    # the snapshot: sandbox A as it is on a good day
    seed_photo(source, "upload/2026/a.jpg", b"first photo", PHOTO_MTIME)
    seed_photo(source, "galleries/x/b.jpg", b"second photo", PHOTO_MTIME)
    manifest.save(manifest.manifest_path(state_dir, cfg_source.ftp.host, cfg_source.ftp.remote_root), FIXTURE_MANIFEST)
    good = backup.run(
        cfg_source, "daily", NOW, DdevTransport(source), UrllibClient(),
        repo_root=tmp_path, state_dir=state_dir, git_commit=lambda: ARTIFICIAL_COMMIT,
    )
    wanted = json.loads((good.folder / "snapshot.json").read_text())["counts"]
    assert set(wanted) == set(COUNTED) and wanted["tags"] > 0, "a snapshot with no tags proves nothing"

    # the remote on a bad day: the database as it was, then damaged; photo a gone, a stray file added
    import_dump_into(scratch_db, good.folder / backup.DUMP_PATH)
    assert counts(scratch_db) == wanted, "the scratch copy must start equal to the snapshot"
    sql("DELETE FROM piwigo_image_tag", scratch_db)
    sql("DELETE FROM piwigo_tags", scratch_db)
    assert counts(scratch_db)["tags"] == 0
    seed_photo(target, "galleries/x/b.jpg", b"second photo", PHOTO_MTIME)
    seed_photo(target, "galleries/stray/c.jpg", b"stray photo", PHOTO_MTIME)
    target.sync()

    transport = RestoringTransport(target)
    result = restore.run(
        cfg_target, good.name, NOW + timedelta(hours=1), transport, UrllibClient(),
        repo_root=tmp_path, state_dir=state_dir, yes=True, git_commit=lambda: ARTIFICIAL_COMMIT,
        clock=lambda: NOW + timedelta(hours=1),
    )

    time.sleep(OPCACHE_WAIT_SECONDS)
    assert counts(scratch_db) == wanted
    assert target.in_container("cat upload/2026/a.jpg").stdout == "first photo"
    assert not target.exists("galleries/stray/c.jpg")
    assert target.exists("galleries/x/b.jpg")
    assert "galleries/x/b.jpg" not in transport.puts, "an unchanged file was uploaded again"
    assert "upload/2026/a.jpg" in transport.puts
    assert (result.uploaded, result.deleted) == (1, 1)

    before = json.loads((cfg_target.backup.dir / result.prerestore / "snapshot.json").read_text())
    assert before["counts"]["tags"] == 0
    assert "galleries/stray/c.jpg" in before["files"]
    assert before["kind"] == "prerestore"

    restored = manifest.manifest_path(state_dir, cfg_target.ftp.host, cfg_target.ftp.remote_root)
    assert manifest.load(restored) == FIXTURE_MANIFEST
    assert (target.path / "local/config/database.inc.php").read_bytes() == (
        good.folder / "files/local/config/database.inc.php"
    ).read_bytes()

    script = next(p for p in transport.puts if p.endswith(".php") and "/" not in p)
    assert status_of(f"{cfg_target.site.base_url}/{script}") == 404
    assert_host_clean(target, cfg_target)
