"""One `pwg-backup` run: temporary script up, dump and file list, snapshot built, script gone.

The decisions are pure functions (`validate_paths`, `drift`, `normalise_db_path`,
`format_log_line`, `dump_dir`); `run()` only sequences them around the two ports. The temporary
script lives for the dump and the list and is removed before the file download starts, so a
long first run never outlasts its expiry and the host is clean while the photos transfer.
"""

from __future__ import annotations

import secrets
import shutil
import subprocess
import tempfile
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Callable

from pwgdeploy import agent, manifest, retention, snapshot
from pwgdeploy.agent import AgentClient
from pwgdeploy.config import DeployConfig
from pwgdeploy.errors import (
    AgentLeftoverError,
    BackupError,
    ConfigError,
    GitError,
    UnsafeRemotePathError,
)
from pwgdeploy.http import HttpClient
from pwgdeploy.snapshot import RemoteFile
from pwgdeploy.transport import Transport
from pwgdeploy.urls import remote_path, site_url

BACKED_UP_PREFIXES = ("upload/", "galleries/", "_data/photoedit/originals/")
CONFIG_FILES = ("local/config/config.inc.php", "local/config/database.inc.php")
DUMP_PATH = "db/dump.sql.gz"
DUMP_FILE_NAME = "dump.sql.gz"
MANIFEST_COPY = "deploy-manifest.json"
STAGING_DIR = ".incoming"
LOG_FILE = "backup.log"

SCRIPT_TOKEN_BYTES = 16
SCRIPT_NAME_BYTES = 8
# Covers a slow dump and the list; the script is removed before the downloads start.
SCRIPT_LIFETIME_SECONDS = 3600

# The probe of task 01 decides this: with a PHP-writable directory outside the web root the
# function returns an absolute path instead. Until then the dump goes below the web root, in a
# random-named directory that agent.php protects with a deny-all .htaccess.
DUMP_DIR_PREFIX = "dump-"
DUMP_DIR_BYTES = 8
# What agent.php leaves in the dump directory; the FTP fallback removes these, `.htaccess` last.
DUMP_DIR_LEFTOVERS = ("dump.sql.gz", "dump.sql", "client.cnf", ".created-by-agent", ".htaccess")

# Left out of the drift report: files that belong to no `images.path` row.
NON_PHOTO_SEGMENTS = ("pwg_representative", "buffer")
NON_PHOTO_NAMES = ("index.php", ".htaccess")
SIDECAR_SUFFIX = "_original"
DRIFT_PREFIXES = ("upload/", "galleries/")
MAX_RECORDED_DRIFT = 100

Report = Callable[[str, str], None]


@dataclass
class BackupResult:
    name: str
    folder: Path
    kind: str
    fetched: int
    linked: int
    gone: int
    bytes_fetched: int
    dump_method: str
    drift_count: int
    pruned: list[str] = field(default_factory=list)
    started: datetime | None = None
    finished: datetime | None = None


def dump_dir(base: str | None = None) -> str:
    """Where the temporary script writes the dump, relative to the web root.

    Without `base` (`backup.dump_dir` in the credential file) a random-named directory under
    the web root; with it, a random-named subdirectory of `base`.
    """
    name = f"{DUMP_DIR_PREFIX}{secrets.token_hex(DUMP_DIR_BYTES)}"
    return f"{base}/{name}" if base else name


def validate_paths(files: list[RemoteFile]) -> None:
    """Every listed path must be relative, under a backed-up folder, with no `..` or empty segment."""
    for item in files:
        path = item.path
        segments = path.split("/")
        unsafe = (
            not path.startswith(BACKED_UP_PREFIXES)
            or any(segment in ("", ".", "..") for segment in segments)
            or "\\" in path
            or "\0" in path
        )
        if unsafe:
            raise UnsafeRemotePathError(f"the temporary script listed an unsafe path: {path!r}")


def validate_dump_path(answered: object, directory: str) -> None:
    """The script names the dump it wrote; only the file in the directory this run chose is fetched."""
    if answered != f"{directory}/{DUMP_FILE_NAME}":
        raise UnsafeRemotePathError(f"the temporary script named an unexpected dump path: {answered!r}")


def normalise_db_path(path: str) -> str:
    return path[2:] if path.startswith("./") else path


def _is_photo_candidate(path: str) -> bool:
    segments = path.split("/")
    return (
        path.startswith(DRIFT_PREFIXES)
        and not path.endswith(SIDECAR_SUFFIX)
        and segments[-1] not in NON_PHOTO_NAMES
        and not any(segment in NON_PHOTO_SEGMENTS for segment in segments[:-1])
    )


def drift(files: list[RemoteFile], db_paths: list[str]) -> dict:
    """Rows without a file and files without a row; lists are capped, counts are not."""
    in_db = {normalise_db_path(path) for path in db_paths}
    listed = {item.path for item in files}
    no_file = sorted(in_db - listed)
    no_row = sorted(path for path in listed - in_db if _is_photo_candidate(path))
    return {
        "db_without_file": no_file[:MAX_RECORDED_DRIFT],
        "file_without_db": no_row[:MAX_RECORDED_DRIFT],
        "db_without_file_count": len(no_file),
        "file_without_db_count": len(no_row),
    }


def drift_count(document: dict) -> int:
    return document["db_without_file_count"] + document["file_without_db_count"]


def format_log_line(
    started: datetime, finished: datetime, kind: str, fetched: int | str, linked: int | str,
    bytes_fetched: int | str, result: str,
) -> str:
    stamp = "%Y-%m-%dT%H:%M:%S"
    return (
        f"{started.strftime(stamp)} {finished.strftime(stamp)} {kind} "
        f"fetched={fetched} linked={linked} bytes={bytes_fetched} {result}"
    )


def append_log(backup_dir: Path, line: str) -> None:
    backup_dir.mkdir(parents=True, exist_ok=True)
    with open(backup_dir / LOG_FILE, "a", encoding="utf-8") as handle:
        handle.write(line + "\n")


def git_head(repo_root: Path) -> str:
    done = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=repo_root, capture_output=True, text=True
    )
    if done.returncode != 0 or not done.stdout.strip():
        raise GitError(f"git rev-parse HEAD failed: {done.stderr.strip()}")
    return done.stdout.strip()


def _try_delete(transport: Transport, path: str) -> None:
    # The adapter raises whatever its protocol library does; a file that is already gone is the goal.
    try:
        transport.delete(path)
    except Exception:
        pass


def _remove_agent(
    agent_client: AgentClient | None, transport: Transport, script: str, dump_files: str
) -> None:
    """Ask the script to remove itself; if that fails, delete over FTP. Raises when it is still there."""
    if agent_client is not None:
        try:
            agent_client.cleanup()
        except Exception:
            pass
    if _script_present(transport, script):
        for name in DUMP_DIR_LEFTOVERS:
            _try_delete(transport, f"{dump_files}/{name}")
        _try_delete(transport, script)
    if _script_present(transport, script):
        raise AgentLeftoverError(
            f"the temporary script {script} is still on the host; delete it over FTP now"
        )


def _script_present(transport: Transport, script: str) -> bool:
    try:
        return transport.exists(script)
    except Exception:
        return True


def run(
    config: DeployConfig,
    kind: str,
    now: datetime,
    transport: Transport,
    client: HttpClient,
    *,
    repo_root: Path,
    state_dir: Path,
    report: Report = lambda label, text: None,
    clock: Callable[[], datetime] = datetime.now,
    git_commit: Callable[[], str] | None = None,
) -> BackupResult:
    if config.backup is None:
        raise ConfigError("the credential file has no 'backup' section")
    backup_dir = config.backup.dir
    root = config.ftp.remote_root
    started = clock()
    name = snapshot.snapshot_name(now, kind)
    commit = (git_commit or (lambda: git_head(repo_root)))()

    token = secrets.token_hex(SCRIPT_TOKEN_BYTES)
    script_name = f"{secrets.token_hex(SCRIPT_NAME_BYTES)}.php"
    directory = dump_dir(config.backup.dump_dir)
    script = remote_path(root, script_name)
    dump_files = remote_path(root, directory)
    expiry = int(started.timestamp()) + SCRIPT_LIFETIME_SECONDS

    backup_dir.mkdir(parents=True, exist_ok=True)
    staging = backup_dir / STAGING_DIR
    shutil.rmtree(staging, ignore_errors=True)
    staging.mkdir()
    try:
        agent_client, removed = None, False
        try:
            with tempfile.TemporaryDirectory() as scratch:
                source = Path(scratch) / script_name
                source.write_text(agent.render(token, expiry, directory), encoding="utf-8")
                transport.put(source, script)
            report("agent", f"uploaded {script_name} (expires in {SCRIPT_LIFETIME_SECONDS // 60} min)")
            agent_client = AgentClient(client, site_url(config.site.base_url, script_name), token)

            db_started = clock()
            dumped = agent_client.dump()
            validate_dump_path(dumped["path"], directory)
            dump_local = staging / "dump.sql.gz"
            transport.get(remote_path(root, dumped["path"]), dump_local)
            if dump_local.stat().st_size != dumped["size"]:
                raise BackupError(
                    f"the dump is {dump_local.stat().st_size} bytes here, the host wrote {dumped['size']}"
                )
            report("dump", f"{dumped['method']}, {dumped['size']} bytes, {dumped['counts']['images']} photos")

            files_started = clock()
            remote_files, db_paths = agent_client.list_files()
            validate_paths(remote_files)
            report("list", f"{len(remote_files)} files, {len(db_paths)} photo rows")

            _remove_agent(agent_client, transport, script, dump_files)
            removed = True
            report("cleanup", "temporary script and dump removed from the host")
        finally:
            if not removed:
                _remove_agent(agent_client, transport, script, dump_files)
        return _store(
            config, kind, now, transport, state_dir, report, clock, commit, name, staging,
            dumped, remote_files, db_paths, db_started, files_started, started,
        )
    finally:
        shutil.rmtree(staging, ignore_errors=True)


def _store(
    config, kind, now, transport, state_dir, report, clock, commit, name, staging,
    dumped, remote_files, db_paths, db_started, files_started, started,
) -> BackupResult:
    backup_dir, root = config.backup.dir, config.ftp.remote_root
    for relative in CONFIG_FILES:
        target = staging / "files" / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        transport.get(remote_path(root, relative), target)
    manifest_file = manifest.manifest_path(state_dir, config.ftp.host, root)
    extras = {DUMP_PATH: staging / "dump.sql.gz"}
    extras.update(
        {f"{snapshot.FILES_DIR}/{relative}": staging / "files" / relative for relative in CONFIG_FILES}
    )
    if manifest_file.is_file():
        shutil.copyfile(manifest_file, staging / MANIFEST_COPY)
        extras[MANIFEST_COPY] = staging / MANIFEST_COPY

    previous = snapshot.latest(backup_dir)
    plan = snapshot.diff(previous[1] if previous else None, remote_files)
    sizes = {item.path: item.size for item in plan.fetch}
    differences = drift(remote_files, db_paths)
    report(
        "diff",
        f"{len(plan.fetch)} to fetch, {len(plan.link)} unchanged, {len(plan.gone)} gone, "
        f"{drift_count(differences)} drift",
    )

    def fetch(path: str, local: Path) -> None:
        transport.get(remote_path(root, path), local)
        if local.stat().st_size != sizes[path]:
            raise BackupError(f"{path} is {local.stat().st_size} bytes here, the host listed {sizes[path]}")

    def meta() -> dict:
        return {
            "kind": kind,
            "git_commit": commit,
            "db_started": db_started.isoformat(timespec="seconds"),
            "files_started": files_started.isoformat(timespec="seconds"),
            "finished": clock().isoformat(timespec="seconds"),
            "dump_method": dumped["method"],
            "dump_size": dumped["size"],
            "counts": dumped["counts"],
            "prefix": config.mysql.prefix,
            "drift": differences,
        }

    folder = snapshot.build(backup_dir, name, plan, fetch, meta=meta, extras=extras)
    pruned = retention.prune(backup_dir, now)
    report("snapshot", f"{folder.name} ({len(plan.fetch)} fetched, {len(plan.link)} linked)")
    report("retention", f"{len(pruned)} old snapshots removed")
    return BackupResult(
        name=folder.name, folder=folder, kind=kind, fetched=len(plan.fetch), linked=len(plan.link),
        gone=len(plan.gone), bytes_fetched=sum(sizes.values()) + dumped["size"],
        dump_method=dumped["method"], drift_count=drift_count(differences), pruned=pruned,
        started=started, finished=clock(),
    )
