"""The rendered agent.php against DDEV, over real HTTP.

Run with `cd tools/deploy && PWG_DDEV_WEBROOT=<main checkout> uv run pytest -m integration`.
Needs DDEV up and the git-ignored `deploy.ddev.json` (only its site.base_url is used).

DDEV serves the main checkout, not a git worktree, so the directory the agent is written into
is read from PWG_DDEV_WEBROOT (default: this repository's root). Every test works in a
random-named sandbox directory below it, with its own `local/config/database.inc.php`: one
sandbox reads the install's `db` (dump is read-only), another is pointed at a scratch database
that is created and dropped here, so `db` is never written. The sandbox is removed in a finally.
Not for production.
"""

import os
import re
import secrets
import shutil
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path

import pytest

from pwgdeploy import agent
from pwgdeploy.agent import AgentClient
from pwgdeploy.config import load_file
from pwgdeploy.errors import RemoteHttpError
from pwgdeploy.http import UrllibClient

pytestmark = pytest.mark.integration

CONFIG = Path(__file__).resolve().parents[2] / "deploy.ddev.json"
WEBROOT = Path(os.environ.get("PWG_DDEV_WEBROOT") or Path(__file__).resolve().parents[4])
SOURCE_DB = "db"
COUNTED = ("images", "categories", "tags", "users")
SYNC_POLL_SECONDS = 0.3
SYNC_TIMEOUT_SECONDS = 60
OPCACHE_WAIT_SECONDS = 3
DDEV_TIMEOUT_SECONDS = 120
FUTURE = 4_000_000_000
PAST = 1_000_000_000
DB_CONFIG = (
    "<?php\n$conf['db_base'] = '{db}';\n$conf['db_user'] = 'root';\n$conf['db_pw'] = 'root';\n"
    "$conf['db_host'] = 'db';\n$prefixeTable = 'piwigo_';\n"
)


def ddev(command: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["ddev", "exec", "bash", "-c", command],
        cwd=WEBROOT, capture_output=True, text=True, timeout=DDEV_TIMEOUT_SECONDS,
    )


def sql(statement: str, database: str = "") -> str:
    result = ddev(f"mysql -uroot -proot -hdb -N {database} -e {statement!r}")
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()


def counts(database: str) -> dict[str, int]:
    return {t: int(sql(f"SELECT COUNT(*) FROM piwigo_{t}", database)) for t in COUNTED}


class Sandbox:
    """A directory below the web root that behaves like a gallery root for the agent."""

    def __init__(self, database: str):
        self.name = f"agent-test-{secrets.token_hex(6)}"
        self.path = WEBROOT / self.name
        self.token = secrets.token_hex(16)
        self.script = f"{secrets.token_hex(8)}.php"
        self._waiting: list[Path] = []
        (self.path / "local/config").mkdir(parents=True)
        self.write("local/config/database.inc.php", DB_CONFIG.format(db=database))

    def write(self, relative: str, content: str | bytes) -> Path:
        target = self.path / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content.encode() if isinstance(content, str) else content)
        self._waiting.append(target)
        return target

    def install_agent(self, *, expiry: int = FUTURE, **kwargs) -> None:
        self.write(self.script, agent.render(self.token, expiry, "dump", **kwargs))

    def sync(self) -> None:
        """Wait until the container holds the bytes the host wrote, then outlast opcache."""
        deadline = time.monotonic() + SYNC_TIMEOUT_SECONDS
        for target in self._waiting:
            expected = subprocess.run(
                ["md5", "-q", str(target)], capture_output=True, text=True
            ).stdout.strip()
            relative = target.relative_to(WEBROOT)
            while ddev(f"md5sum {relative}").stdout.split(" ")[0] != expected:
                assert time.monotonic() < deadline, f"{relative} never reached the container"
                time.sleep(SYNC_POLL_SECONDS)
        self._waiting.clear()
        time.sleep(OPCACHE_WAIT_SECONDS)

    @property
    def url(self) -> str:
        return f"{load_file(CONFIG).site.base_url}/{self.name}/{self.script}"

    def client(self, token: str | None = None) -> AgentClient:
        return AgentClient(UrllibClient(), self.url, self.token if token is None else token)

    def in_container(self, command: str) -> subprocess.CompletedProcess:
        return ddev(f"cd {self.name} && {command}")

    def exists(self, relative: str) -> bool:
        return self.in_container(f"test -e {relative}").returncode == 0

    def remove(self) -> None:
        """From inside the container too: files the script made would sync back otherwise."""
        ddev(f"rm -rf {self.name}")
        shutil.rmtree(self.path, ignore_errors=True)


@pytest.fixture
def sandbox_factory():
    made: list[Sandbox] = []

    def make(database: str) -> Sandbox:
        sandbox = Sandbox(database)
        made.append(sandbox)
        return sandbox

    yield make
    for sandbox in made:
        sandbox.remove()


@pytest.fixture
def scratch_db():
    name = f"pwgtest_{secrets.token_hex(5)}"
    sql(f"CREATE DATABASE {name} CHARACTER SET utf8mb4")
    try:
        yield name
    finally:
        sql(f"DROP DATABASE IF EXISTS {name}")


def status_of(url: str) -> int:
    try:
        with urllib.request.urlopen(url, timeout=30) as handle:
            return handle.status
    except urllib.error.HTTPError as error:
        return error.code


def ready(sandbox_factory, database: str, **render_kwargs) -> Sandbox:
    sandbox = sandbox_factory(database)
    sandbox.install_agent(**render_kwargs)
    sandbox.sync()
    return sandbox


def import_into_scratch(source: Sandbox, scratch: Sandbox, dump_path: str) -> int:
    scratch.in_container("mkdir -p dump")
    copied = ddev(f"cp {source.name}/{dump_path} {scratch.name}/dump/dump.sql.gz")
    assert copied.returncode == 0, copied.stderr
    return scratch.client().import_dump("dump/dump.sql.gz")


ACTIONS = {
    "dump": lambda c: c.dump(),
    "list": lambda c: c.list_files(),
    "import": lambda c: c.import_dump("dump/dump.sql.gz"),
    "cleanup": lambda c: c.cleanup(),
}


@pytest.mark.parametrize("case", ["no-token", "wrong-token", "expired", "get"])
def test_every_refusal_answers_403_and_changes_nothing(sandbox_factory, case):
    expiry = PAST if case == "expired" else FUTURE
    sandbox = ready(sandbox_factory, SOURCE_DB, expiry=expiry)
    if case == "get":
        assert status_of(sandbox.url) == 403
    else:
        token = {"no-token": "", "wrong-token": "not-the-token"}.get(case)
        for call in ACTIONS.values():
            with pytest.raises(RemoteHttpError, match="HTTP 403"):
                call(sandbox.client(token))
    assert not sandbox.exists("dump")
    assert sandbox.exists(sandbox.script)


def test_dump_by_mysqldump_and_by_php_import_with_the_same_row_counts(sandbox_factory, scratch_db):
    source = ready(sandbox_factory, SOURCE_DB)
    target = ready(sandbox_factory, scratch_db)

    plain = source.client().dump()
    assert plain["method"] == "mysqldump"
    assert plain["size"] > 0
    assert set(plain["counts"]) == set(COUNTED)
    assert plain["counts"]["images"] > 0
    # While the dump exists under the web root, Apache must not serve it. Apache's
    # bind mount sees the file only once Mutagen has synced it back to the host.
    deadline = time.monotonic() + SYNC_TIMEOUT_SECONDS
    while not (source.path / plain["path"]).is_file() or not (source.path / "dump/.htaccess").is_file():
        assert time.monotonic() < deadline, "dump never reached the host"
        time.sleep(SYNC_POLL_SECONDS)
    served = ddev(
        f"curl -s -o /dev/null -w '%{{http_code}}' http://apache/{source.name}/{plain['path']}"
    )
    assert served.stdout == "403", served.stdout
    assert import_into_scratch(source, target, plain["path"]) > 0
    assert counts(scratch_db) == plain["counts"]
    sql(f"DROP DATABASE {scratch_db}")
    sql(f"CREATE DATABASE {scratch_db} CHARACTER SET utf8mb4")

    fallback = ready(sandbox_factory, SOURCE_DB, mysqldump="/nonexistent/mysqldump")
    php = fallback.client().dump()
    assert php["method"] == "php"
    assert import_into_scratch(fallback, target, php["path"]) > 0
    assert counts(scratch_db) == php["counts"]


def test_list_names_a_new_file_with_exact_size_and_mtime_and_the_db_paths(sandbox_factory, scratch_db):
    source = ready(sandbox_factory, SOURCE_DB)
    plain = source.client().dump()
    target = ready(sandbox_factory, scratch_db)
    import_into_scratch(source, target, plain["path"])

    marker = f"upload/{secrets.token_hex(4)}/photo-é.jpg"
    target.write(marker, b"x" * 1234)
    target.sync()
    stat = target.in_container(f"touch -d @1700000000 '{marker}' && stat -c '%s %Y' '{marker}'")
    size, mtime = map(int, stat.stdout.split())

    files, db_paths = target.client().list_files()
    by_path = {f.path: f for f in files}
    assert (by_path[marker].size, by_path[marker].mtime) == (size, mtime)
    assert (size, mtime) == (1234, 1700000000)
    assert all(re.match(r"(upload|galleries|_data/photoedit/originals)/", f.path) for f in files)
    expected = sql("SELECT path FROM piwigo_images", scratch_db).splitlines()
    assert sorted(db_paths) == sorted(expected)
    assert len(db_paths) > 0


@pytest.mark.parametrize("path", ["local/config/database.inc.php", "../x.sql.gz", "/etc/passwd", "dump/missing.sql.gz"])
def test_import_refuses_a_path_outside_the_dump_dir(sandbox_factory, scratch_db, path):
    target = ready(sandbox_factory, scratch_db)
    target.in_container("mkdir -p dump")
    with pytest.raises(RemoteHttpError, match="HTTP 403.*inside the dump directory"):
        target.client().import_dump(path)
    assert counts_missing(scratch_db)


def counts_missing(database: str) -> bool:
    return sql("SHOW TABLES", database) == ""


def test_cleanup_removes_the_dump_the_dir_and_the_script(sandbox_factory):
    sandbox = ready(sandbox_factory, SOURCE_DB)
    sandbox.client().dump()
    assert sandbox.exists("dump/dump.sql.gz") and sandbox.exists("dump/.htaccess")
    removed = sandbox.client().cleanup()
    assert sandbox.script in removed
    assert not sandbox.exists("dump")
    assert not sandbox.exists(sandbox.script)
    time.sleep(OPCACHE_WAIT_SECONDS)
    assert status_of(sandbox.url) == 404
