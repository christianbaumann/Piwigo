"""`pwg-backup --restore`: put one snapshot's database, files and config back on the remote.

`plan()` is pure: the snapshot's file table against the host's listing gives what to upload and
what to delete. `run()` sequences it: a `prerestore` snapshot of the state being replaced first
(a failure stops everything), then a temporary agent to list the host, the file uploads and the
deletions, a second temporary agent for the dump import, the two config files and the deploy
manifest. Two agents rather than one because the uploads of a whole gallery can outlast one
agent's expiry (`backup.SCRIPT_LIFETIME_SECONDS`).

Uploading over FTP gives every file a new mtime on the host (`Transport` has no MFMT). The next
backup therefore sees every restored file as changed and downloads it again, and a second restore
of the same snapshot uploads them all again; that is accepted, not worked around.
"""

from __future__ import annotations

import json
import secrets
import tempfile
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Callable, Iterable, Mapping

from pwgdeploy import agent, backup, manifest, snapshot
from pwgdeploy.agent import AgentClient
from pwgdeploy.config import DeployConfig
from pwgdeploy.errors import BackupError, TransportError
from pwgdeploy.http import HttpClient
from pwgdeploy.snapshot import Entry, RemoteFile
from pwgdeploy.transport import Transport
from pwgdeploy.urls import remote_path, site_url

KIND = "restore"
MAX_LISTED_DELETIONS = 50
MAX_REPORTED_PROBLEMS = 5
# The deny-all file agent.php writes into a dump directory it creates; a directory made here
# needs it before the dump (password hashes) is uploaded into it.
DENY_ALL_HTACCESS = (
    "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
    "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
)
CREATED_MARKER = ".created-by-agent"

Report = Callable[[str, str], None]


class RestoreError(BackupError):
    """The restore was refused or cannot start; shares the backup exit code."""


@dataclass(frozen=True)
class RestorePlan:
    upload: list[str]
    delete: list[str]
    unchanged: int


@dataclass
class RestoreResult:
    name: str
    prerestore: str | None
    uploaded: int
    deleted: int
    unchanged: int
    bytes_uploaded: int
    statements: int
    manifest_restored: bool


@dataclass(frozen=True)
class LoadedSnapshot:
    name: str
    folder: Path
    files: dict[str, Entry]


def plan(files: Mapping[str, Entry], remote: Iterable[RemoteFile]) -> RestorePlan:
    """Upload what the host lacks or holds with another size or mtime; delete what the snapshot lacks."""
    listed = {item.path: item for item in remote}
    upload, unchanged = [], 0
    for path, entry in files.items():
        held = listed.get(path)
        if held is not None and held.size == entry.size and held.mtime == entry.mtime:
            unchanged += 1
        else:
            upload.append(path)
    delete = [path for path in listed if path not in files]
    return RestorePlan(upload=sorted(upload), delete=sorted(delete), unchanged=unchanged)


def check_request(name: str, yes: bool, dry_run: bool) -> None:
    if snapshot.parse_name(name) is None:
        raise RestoreError(f"{name!r} is not the name of a complete snapshot (a .partial folder cannot be restored)")
    if not (yes or dry_run):
        raise RestoreError("restoring replaces the remote's database and files; repeat with --yes")


def load_snapshot(backup_dir: Path, name: str) -> LoadedSnapshot:
    """The snapshot, with every file it names present here at the recorded size; nothing remote yet."""
    folder = Path(backup_dir) / name
    document_path = folder / snapshot.SNAPSHOT_JSON
    if not document_path.is_file():
        raise RestoreError(f"no snapshot {name} in {backup_dir}")
    document = json.loads(document_path.read_text(encoding="utf-8"))
    files = {path: Entry(path=path, **row) for path, row in document["files"].items()}
    if not files:
        raise RestoreError(f"snapshot {name} holds no files; restoring it would delete the remote's whole gallery")
    backup.validate_paths([RemoteFile(e.path, e.size, e.mtime) for e in files.values()])
    problems = [f"{backup.DUMP_PATH}: missing"] if not (folder / backup.DUMP_PATH).is_file() else []
    for relative in backup.CONFIG_FILES:
        if not (folder / snapshot.FILES_DIR / relative).is_file():
            problems.append(f"{relative}: missing")
    for path in sorted(files):
        target = folder / snapshot.FILES_DIR / path
        if not target.is_file() or target.stat().st_size != files[path].size:
            problems.append(f"{path}: missing or of another size")
    if problems:
        shown = problems[:MAX_REPORTED_PROBLEMS]
        extra = len(problems) - len(shown)
        raise RestoreError(
            f"snapshot {name} is damaged: " + "; ".join(shown) + (f"; ... and {extra} more" if extra else "")
        )
    return LoadedSnapshot(name=name, folder=folder, files=files)


def _stand_in(backup_dir: Path, name: str) -> dict[str, Entry] | None:
    """The newest complete snapshot other than `name`, as the offline guess of the host's files."""
    candidates = [
        child for _, child in snapshot._complete_snapshots(backup_dir) if child.name != name
    ]
    if not candidates:
        return None
    document = json.loads((candidates[-1] / snapshot.SNAPSHOT_JSON).read_text(encoding="utf-8"))
    return {path: Entry(path=path, **row) for path, row in document["files"].items()}


def _report_plan(report: Report, restore_plan: RestorePlan) -> None:
    report(
        "plan",
        f"{len(restore_plan.upload)} to upload, {len(restore_plan.delete)} to delete, "
        f"{restore_plan.unchanged} unchanged",
    )
    for path in restore_plan.delete[:MAX_LISTED_DELETIONS]:
        report("delete", path)
    hidden = len(restore_plan.delete) - MAX_LISTED_DELETIONS
    if hidden > 0:
        report("delete", f"... and {hidden} more")


def _dry_run(config, loaded, report) -> RestoreResult:
    report("dry-run", "nothing is contacted, taken or changed")
    guess = _stand_in(config.backup.dir, loaded.name)
    if guess is None:
        report("plan", f"{len(loaded.files)} files in the snapshot; no other snapshot to compare with, "
                       "so uploads and deletions are known only after the host is listed")
    else:
        stand_in = [RemoteFile(e.path, e.size, e.mtime) for e in guess.values()]
        report("compare", "against the newest other snapshot, not the host; the real run lists the host")
        _report_plan(report, plan(loaded.files, stand_in))
    report("import", f"{backup.DUMP_PATH} would replace the remote database")
    return RestoreResult(loaded.name, None, 0, 0, 0, 0, 0, False)


@contextmanager
def _agent_session(config, transport: Transport, client: HttpClient, clock, report):
    """A temporary agent on the host: yields (client, dump directory on the host, its FTP path)."""
    root = config.ftp.remote_root
    token = secrets.token_hex(backup.SCRIPT_TOKEN_BYTES)
    script_name = f"{secrets.token_hex(backup.SCRIPT_NAME_BYTES)}.php"
    directory = backup.dump_dir()
    script = remote_path(root, script_name)
    dump_files = remote_path(root, directory)
    expiry = int(clock().timestamp()) + backup.SCRIPT_LIFETIME_SECONDS
    agent_client = None
    try:
        with tempfile.TemporaryDirectory() as scratch:
            source = Path(scratch) / script_name
            source.write_text(agent.render(token, expiry, directory), encoding="utf-8")
            transport.put(source, script)
        report("agent", f"uploaded {script_name}")
        agent_client = AgentClient(client, site_url(config.site.base_url, script_name), token)
        yield agent_client, directory, dump_files
    finally:
        backup._remove_agent(agent_client, transport, script, dump_files)
        report("cleanup", "temporary script and dump removed from the host")


def _put(transport: Transport, local: Path, remote: str) -> None:
    parent = remote.rpartition("/")[0]
    if parent:
        transport.makedirs(parent)
    transport.put(local, remote)


def _upload_dump(transport, folder: Path, dump_files: str) -> None:
    transport.makedirs(dump_files)
    with tempfile.TemporaryDirectory() as scratch:
        for name, content in ((".htaccess", DENY_ALL_HTACCESS), (CREATED_MARKER, "")):
            source = Path(scratch) / "small"
            source.write_text(content, encoding="utf-8")
            transport.put(source, f"{dump_files}/{name}")
    transport.put(folder / backup.DUMP_PATH, f"{dump_files}/{backup.DUMP_FILE_NAME}")


def _delete(transport: Transport, path: str) -> None:
    try:
        transport.delete(path)
    except TransportError:
        raise
    except Exception as error:
        raise TransportError(f"delete of {path} failed: {error}") from error


def _restore_manifest(loaded: LoadedSnapshot, config, state_dir: Path, report: Report) -> bool:
    copy = loaded.folder / backup.MANIFEST_COPY
    entries = manifest.load(copy) if copy.is_file() else {}
    if not entries:
        report("manifest", "the snapshot holds no readable deploy manifest; the current one is kept")
        return False
    target = manifest.manifest_path(state_dir, config.ftp.host, config.ftp.remote_root)
    manifest.save(target, entries)
    report("manifest", f"{len(entries)} entries written to {target.name}")
    return True


def run(
    config: DeployConfig,
    name: str,
    now: datetime,
    transport: Transport | None,
    client: HttpClient | None,
    *,
    repo_root: Path,
    state_dir: Path,
    yes: bool = False,
    dry_run: bool = False,
    report: Report = lambda label, text: None,
    clock: Callable[[], datetime] = datetime.now,
    git_commit: Callable[[], str] | None = None,
) -> RestoreResult:
    check_request(name, yes, dry_run)
    loaded = load_snapshot(config.backup.dir, name)
    report("snapshot", f"{name}: {len(loaded.files)} files")
    if dry_run:
        return _dry_run(config, loaded, report)

    root = config.ftp.remote_root
    before = backup.run(
        config, "prerestore", now, transport, client,
        repo_root=repo_root, state_dir=state_dir, report=report, clock=clock, git_commit=git_commit,
    )
    report("prerestore", f"{before.name} holds the state being replaced")
    loaded = load_snapshot(config.backup.dir, name)

    try:
        with _agent_session(config, transport, client, clock, report) as (agent_client, _, _):
            remote_files, _ = agent_client.list_files()
        backup.validate_paths(remote_files)
        restore_plan = plan(loaded.files, remote_files)
        _report_plan(report, restore_plan)

        for path in restore_plan.upload:
            _put(transport, loaded.folder / snapshot.FILES_DIR / path, remote_path(root, path))
        report("upload", f"{len(restore_plan.upload)} files")
        for path in restore_plan.delete:
            _delete(transport, remote_path(root, path))
        report("delete", f"{len(restore_plan.delete)} files removed")

        with _agent_session(config, transport, client, clock, report) as (agent_client, directory, dump_files):
            _upload_dump(transport, loaded.folder, dump_files)
            statements = agent_client.import_dump(f"{directory}/{backup.DUMP_FILE_NAME}")
        report("import", f"{statements} statements")

        for relative in backup.CONFIG_FILES:
            _put(transport, loaded.folder / snapshot.FILES_DIR / relative, remote_path(root, relative))
        report("config", ", ".join(backup.CONFIG_FILES))
        restored = _restore_manifest(loaded, config, state_dir, report)
    except BaseException:
        report("failed", f"the remote may be partly restored; the state before is snapshot {before.name}")
        raise
    return RestoreResult(
        name=name, prerestore=before.name, uploaded=len(restore_plan.upload),
        deleted=len(restore_plan.delete), unchanged=restore_plan.unchanged,
        bytes_uploaded=sum(loaded.files[p].size for p in restore_plan.upload),
        statements=statements, manifest_restored=restored,
    )


def restore_main(
    config: DeployConfig,
    name: str,
    *,
    yes: bool,
    dry_run: bool,
    out,
    transport_factory,
    client_factory,
    state_dir: Path,
    repo_root: Path,
    clock: Callable[[], datetime],
    git_commit: Callable[[], str] | None,
    label_width: int,
) -> int:
    """What `pwg-backup --restore` calls. A dry run opens no connection at all."""

    def report(label: str, text: str) -> None:
        print(f"  {label:<{label_width}}{text}", file=out)

    started = clock()
    check_request(name, yes, dry_run)
    print(f"Piwigo restore {name} -> {config.ftp.host}" + (" (dry run)" if dry_run else ""), file=out)
    transport = None if dry_run else transport_factory(config)
    if transport is not None:
        transport.connect()
    try:
        result = run(
            config, name, started, transport, None if dry_run else client_factory(config),
            repo_root=repo_root, state_dir=state_dir, yes=yes, dry_run=dry_run,
            report=report, clock=clock, git_commit=git_commit,
        )
    finally:
        if transport is not None:
            transport.close()
    if not dry_run:
        backup.append_log(
            config.backup.dir,
            backup.format_log_line(
                started, clock(), KIND, result.uploaded, result.unchanged, result.bytes_uploaded, "ok"
            ),
        )
    report("done", f"{result.name}: {result.uploaded} uploaded, {result.deleted} deleted, "
                   f"{result.statements} statements")
    return 0
