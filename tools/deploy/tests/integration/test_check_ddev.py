"""pwg-backup --check against DDEV's MariaDB.

Run with `cd tools/deploy && PWG_DDEV_DIR=<checkout with .ddev> uv run pytest -m integration -k check`.
Needs DDEV up. Reads `db` (a mysqldump of four tables, read-only); writes only the scratch database
`backup_check`, which the check drops again. Never touches `db`'s data. Not for production.
"""

import gzip
import hashlib
import json
import os
import subprocess
from pathlib import Path

import pytest

from pwgdeploy import check, snapshot

pytestmark = pytest.mark.integration

PROJECT = Path(os.environ.get("PWG_DDEV_DIR", check.PROJECT_ROOT))
SOURCE_DATABASE = "db"


def ddev(*args, stdin=None):
    done = subprocess.run(["ddev", *args], cwd=PROJECT, capture_output=True, text=True, input=stdin, timeout=120)
    assert done.returncode == 0, done.stderr
    return done.stdout


def databases():
    return set(ddev("mysql", "-N", "-B", "-e", "SHOW DATABASES").split())


def source_counts():
    statement = " UNION ALL ".join(
        f"SELECT '{t}', COUNT(*) FROM {SOURCE_DATABASE}.piwigo_{t}" for t in check.COUNTED_TABLES
    )
    return {k: int(v) for k, v in (line.split("\t") for line in ddev("mysql", "-N", "-B", "-e", statement).split("\n") if line)}


@pytest.fixture(scope="module")
def dump_bytes():
    tables = [f"piwigo_{t}" for t in check.COUNTED_TABLES]
    sql = ddev("exec", "mysqldump", "--single-transaction", SOURCE_DATABASE, *tables)
    return gzip.compress(sql.encode())


def make(root, dump, counts, extra=None):
    folder = root / "2026-10-20T100000"
    entries = {}
    (folder / check.DUMP_PATH).parent.mkdir(parents=True, exist_ok=True)
    (folder / check.DUMP_PATH).write_bytes(dump)
    for path, data in (extra or {}).items():
        target = folder / snapshot.FILES_DIR / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        entries[path] = {"size": len(data), "mtime": 1, "sha1": hashlib.sha1(data).hexdigest()}
    (folder / snapshot.SNAPSHOT_JSON).write_text(json.dumps({"counts": counts, "files": entries}))
    return folder


def run(root):
    return check.run_check(root, cwd=PROJECT)


def test_the_check_passes_cleans_up_and_leaves_db_alone(tmp_path, dump_bytes):
    counts = source_counts()
    assert counts["images"] > 0
    before = databases()
    make(tmp_path, dump_bytes, counts, {"upload/a.jpg": b"abc"})
    assert run(tmp_path) == []
    assert databases() == before
    assert check.CHECK_DATABASE not in databases()
    assert source_counts() == counts


def test_a_truncated_dump_fails_naming_the_import_error(tmp_path, dump_bytes):
    counts = source_counts()
    make(tmp_path, dump_bytes[: len(dump_bytes) // 2], counts)
    problems = run(tmp_path)
    assert problems and "import" in problems[0] and "cannot be read to its end" in problems[0]
    assert check.CHECK_DATABASE not in databases()


def test_a_changed_photo_fails_naming_the_file(tmp_path, dump_bytes):
    folder = make(tmp_path, dump_bytes, source_counts(), {"upload/a.jpg": b"abc"})
    (folder / "files/upload/a.jpg").write_bytes(b"xyz")
    assert run(tmp_path) == ["upload/a.jpg: sha1 differs from snapshot.json"]
    assert check.CHECK_DATABASE not in databases()
