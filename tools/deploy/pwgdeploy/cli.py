"""The command: parse the flags, run the two halves, print one report.

Both adapters arrive through a factory so the whole command can be exercised without a
socket. Everything the report says is read back from what the run returned — no step
prints "ok" from the fact that it was reached.
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

from pwgdeploy import audit, bootstrap, fileset, manifest, preflight, upload, version
from pwgdeploy.config import DeployConfig, load_file
from pwgdeploy.errors import DeployError
from pwgdeploy.http import UrllibClient
from pwgdeploy.transport import FtplibTransport
from pwgdeploy.urls import remote_path

PROGRAM = "pwg-deploy"
DESCRIPTION = (
    "Deploy this Piwigo fork to an FTPS web space and complete its first-run install. "
    "The target is a sandbox instance; this tool is never safe to point at production."
)

LABEL_WIDTH = 12
# The shell's own convention for a process ended by SIGINT (128 + 2).
INTERRUPTED_EXIT_CODE = 130
BYTES_PER_MB = 1024 * 1024
# Enough to act on without turning the report into the deletion list itself; the tail
# says how many were not named.
MAX_REPORTED_GALLERY_DELETIONS = 10
# Same idea for the audit, which can legitimately find hundreds. Larger because its whole
# output is the list, where the deploy's is one line of a longer report.
MAX_REPORTED_ORPHANS = 20
# The audit's closing claim, and the one thing an operator has to be able to trust about
# it. Stated once here so the test that asserts it reads it rather than retyping it.
AUDIT_READ_ONLY_NOTICE = "This is a read-only report. Nothing was deleted."

# tools/deploy/ relative to this file: pwgdeploy/ -> deploy/ -> tools/ -> repository root.
REPO_ROOT = Path(__file__).resolve().parents[3]
STATE_DIR = Path(__file__).resolve().parents[1] / ".state"


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog=PROGRAM, description=DESCRIPTION)
    parser.add_argument("config_json", metavar="CONFIG_JSON", help="the credential file")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="enumerate, hash and diff; connect to nothing",
    )
    parser.add_argument(
        "--list-files", action="store_true", help="print the published file set and exit"
    )
    parser.add_argument(
        "--audit",
        action="store_true",
        help="list the remote and report what the manifest does not cover; delete nothing",
    )
    parser.add_argument(
        "--no-bootstrap",
        action="store_true",
        help="upload only; skip install, config, plugins and sync",
    )
    parser.add_argument(
        "--no-prune",
        action="store_true",
        help="never delete, even a path the previous manifest recorded",
    )
    parser.add_argument(
        "--adopt-remote-state",
        action="store_true",
        help="upload even when the manifest and the remote disagree about the install",
    )
    parser.add_argument(
        "--allow-version-change",
        action="store_true",
        help="upload even when the remote runs a different core version",
    )
    parser.add_argument("--verbose", action="store_true", help="name each uploaded path")
    return parser


def ftps_transport(config: DeployConfig) -> FtplibTransport:
    return FtplibTransport(
        config.ftp.host, config.ftp.user, config.ftp.password, config.ftp.port
    )


def http_client(config: DeployConfig) -> UrllibClient:
    return UrllibClient()


def main(
    argv: list[str] | None = None,
    *,
    stdout=None,
    stderr=None,
    repo_root: Path | None = None,
    state_dir: Path | None = None,
    transport_factory=ftps_transport,
    client_factory=http_client,
    tracked=fileset.verified_tracked_paths,
    clock=time.monotonic,
) -> int:
    args = build_parser().parse_args(sys.argv[1:] if argv is None else argv)
    out = sys.stdout if stdout is None else stdout
    err = sys.stderr if stderr is None else stderr
    repo_root = Path(repo_root or REPO_ROOT)
    state_dir = Path(state_dir or STATE_DIR)

    started = clock()
    try:
        config = load_file(Path(args.config_json))

        if args.audit:
            _report_target(out, config)
            _audit(out, config, state_dir, transport_factory)
            return 0

        tracked_paths = tracked(repo_root)

        if args.list_files:
            for path in fileset.select(tracked_paths):
                print(path, file=out)
            return 0

        published = fileset.select(tracked_paths)
        state_path = manifest.manifest_path(
            state_dir, config.ftp.host, config.ftp.remote_root
        )

        _report_target(out, config)
        _report_file_set(out, repo_root, tracked_paths, published)
        _report_manifest(out, state_path)
        _preflight(
            out, args, config, state_path, len(published), client_factory, repo_root
        )
        _upload(
            out, args, config, repo_root, state_dir, transport_factory, tracked_paths
        )

        if not args.dry_run and not args.no_bootstrap:
            _bootstrap(out, config, state_dir, transport_factory, client_factory)
        elif args.no_bootstrap:
            _line(out, "bootstrap", "skipped (--no-bootstrap)")
    except DeployError as error:
        print(f"{type(error).__name__}: {error}", file=err)
        return error.exit_code
    except KeyboardInterrupt:
        # The manifest records completed puts only, so the work already done is kept and
        # re-running sends the remainder. Saying so is the whole point: the bare
        # traceback this replaces surfaced from inside ssl.unwrap and read like a crash.
        print(
            f"interrupted. Nothing is lost — re-run the same command to resume from "
            f"where it stopped ({args.config_json}).",
            file=err,
        )
        return INTERRUPTED_EXIT_CODE

    print(f"done in {clock() - started:.1f}s", file=out)
    return 0


def _report_file_set(out, repo_root, tracked_paths, published) -> None:
    megabytes = fileset.total_bytes(repo_root, published) / BYTES_PER_MB
    excluded = len(tracked_paths) - len(published)
    _line(
        out,
        "file set",
        f"{len(published)} files, {megabytes:.1f} MB "
        f"(excluded: {excluded} dev/test files)",
    )


def _report_manifest(out, state_path: Path) -> None:
    known = "existing" if state_path.exists() else "new"
    _line(out, "manifest", f"{state_path} ({known})")


def _preflight(
    out, args, config, state_path, file_count, client_factory, repo_root
) -> None:
    """Refuse before uploading when the remote contradicts the manifest or the checkout.

    Two guards, in that order: the manifest against the install marker, then this
    checkout's PHPWG_VERSION against the one the remote reports. The state guard runs
    first because a remote that is not installed has no version to compare.

    Skipped on a dry run, and it says so: `--dry-run` opens no connection at all, and a
    guard that was silently not run is one an operator believes ran. It still runs under
    `--no-bootstrap` — the upload is exactly the half the guards protect.
    """
    if args.dry_run:
        _line(out, "preflight", "skipped (--dry-run opens no connection)")
        return

    state = preflight.probe(client_factory(config), config)
    warnings = [
        preflight.check_state(
            entry_count=len(manifest.load(state_path)),
            remote_installed=state.installed,
            manifest_path=state_path,
            file_count=file_count,
            adopt=args.adopt_remote_state,
        ),
        preflight.check_version(
            version.local_version(repo_root),
            state.version,
            allow_change=args.allow_version_change,
        ),
    ]
    verdict = f"installed, {state.version}" if state.installed else "not installed"
    said = " ".join(w for w in warnings if w) or "manifest and remote agree"
    _line(out, "preflight", f"{verdict} — {said}")


def _upload(out, args, config, repo_root, state_dir, transport_factory, tracked_paths):
    transport = transport_factory(config)
    if not args.dry_run:
        _line(out, "transport", f"FTPS to {config.ftp.host}:{config.ftp.port}")

    result = upload.run(
        config,
        repo_root,
        state_dir,
        transport,
        dry_run=args.dry_run,
        prune=not args.no_prune,
        progress=_progress(out) if args.verbose else None,
        tracked=lambda _root: tracked_paths,
    )

    # A dry run deletes nothing, so `deleted` is empty by construction — but prune is the
    # only destructive thing this tool does, and a preview that hides it is the report an
    # operator most needs to see. Predict it instead of printing a truthful zero.
    would_prune = 0 if args.no_prune else len(result.diff.removed)
    removed = would_prune if args.dry_run else len(result.deleted)
    verb = "would send" if args.dry_run else "sent"
    _line(
        out,
        "upload",
        f"{len(result.diff.new)} new, {len(result.diff.changed)} changed, "
        f"{result.unchanged_count} unchanged, {removed} removed ({verb})",
    )
    _report_gallery_deletions(out, args, config, result)
    if result.dirs_created:
        _line(out, "dirs", f"{len(result.dirs_created)} created")
    if not args.dry_run:
        _line(
            out,
            "chmod",
            " ".join(fileset.WRITABLE_REMOTE_PATHS)
            + ("  ok" if result.chmod_supported else "  no SITE CHMOD (warning)"),
        )
    return result


def _report_gallery_deletions(out, args, config, result) -> None:
    """Name the tracked scans a prune reached, on a dry run and on a real one alike.

    Those files are published on purpose — deleting one from the working copy is meant to
    propagate — but they are the only published files no later run could put back, so the
    count alone is not enough to act on. decision 0026.
    """
    if args.no_prune:
        return
    pruned = result.diff.removed if args.dry_run else result.deleted
    photos = fileset.gallery_paths(config.ftp.remote_root, pruned)
    if not photos:
        return

    verb = "would delete" if args.dry_run else "deleted"
    _line(
        out,
        "galleries",
        f"{len(photos)} of the {len(pruned)} {verb} a tracked photo:",
    )
    for path in photos[:MAX_REPORTED_GALLERY_DELETIONS]:
        _continuation(out, path)
    unnamed = len(photos) - MAX_REPORTED_GALLERY_DELETIONS
    if unnamed > 0:
        _continuation(out, f"… and {unnamed} more")


def _audit(out, config, state_dir, transport_factory) -> None:
    """List the remote and say what the manifest does not account for. Read-only.

    A standalone mode, like `--list-files`: no preflight, no upload, no bootstrap. It is
    the only thing in this tool that reads the server back, and the only way to see the
    orphans the prune can never reach — but it removes nothing, because an over-broad
    delete against a listing nobody has read is exactly the failure it exists to expose.
    decision 0030.
    """
    root = config.ftp.remote_root
    state_path = manifest.manifest_path(state_dir, config.ftp.host, root)
    entries = manifest.load(state_path)
    known = "existing" if state_path.exists() else "new"
    _line(out, "manifest", f"{state_path} ({known}, {len(entries)} entries)")

    transport = transport_factory(config)
    _line(out, "transport", f"FTPS to {config.ftp.host}:{config.ftp.port}")
    transport.connect()
    try:
        files, directories = audit.walk(transport.list_dir, root)
    finally:
        transport.close()

    generated = {remote_path(root, name) for name in fileset.GENERATED_REMOTE_PATHS}
    report = audit.compare(
        files, entries, generated, directories=directories, skipped=audit.AUDIT_SKIP
    )

    skipped = " ".join(f"{name}/" for name in report.skipped)
    _line(
        out,
        "listed",
        f"{_plural(len(files), 'file')} in "
        f"{_plural(report.directories, 'directory', 'directories')} "
        f"(skipped: {skipped})",
    )
    _line(
        out,
        "covered",
        f"{_plural(len(report.covered), 'file')} the manifest records and the server holds",
    )
    _report_paths(
        out, "orphans", report.orphans, "on the server the manifest does not cover"
    )
    _report_paths(
        out, "missing", report.missing, "the manifest records and the server does not hold"
    )
    print(f"  {AUDIT_READ_ONLY_NOTICE}", file=out)


def _plural(count: int, singular: str, plural: str | None = None) -> str:
    """`1 file`, `3 files`. One is the count the real host reports for its one orphan."""
    return f"{count} {singular}" if count == 1 else f"{count} {plural or singular + 's'}"


def _report_paths(out, label: str, paths: list[str], what: str) -> None:
    """One labelled count, then the paths themselves, capped. Silent when empty."""
    if not paths:
        return
    _line(out, label, f"{_plural(len(paths), 'file')} {what}:")
    for path in paths[:MAX_REPORTED_ORPHANS]:
        _continuation(out, path)
    unnamed = len(paths) - MAX_REPORTED_ORPHANS
    if unnamed > 0:
        _continuation(out, f"… and {unnamed} more")


def _bootstrap(out, config, state_dir, transport_factory, client_factory):
    client = client_factory(config)
    result = bootstrap.run(config, state_dir, transport_factory(config), client)

    _line(out, "install", "installed" if result.installed else "already installed - skipped")
    _line(
        out,
        "config",
        f"{bootstrap.CONFIG_RELATIVE_PATH} "
        + ("uploaded" if result.config_uploaded else "unchanged"),
    )
    _line(
        out,
        "plugins",
        ", ".join(f"{name} {state}" for name, state in result.plugins.items()),
    )
    _line(
        out,
        "sync",
        f"{result.sync.photos_added} photos, {result.sync.albums_added} albums, "
        f"{result.sync.errors} errors (deleted: {result.sync.photos_deleted} photos, "
        f"{result.sync.albums_deleted} albums)",
    )


def _progress(out):
    def report(remote: str, done: int, total: int) -> None:
        print(f"{'':{LABEL_WIDTH}}[{done}/{total}] {remote}", file=out)

    return report


def _report_target(out, config: DeployConfig) -> None:
    root = config.ftp.remote_root or "/"
    print(f"Piwigo deploy -> {config.ftp.host}:{root}", file=out)


def _line(out, label: str, value: str) -> None:
    print(f"  {label:{LABEL_WIDTH}}{value}", file=out)


def _continuation(out, value: str) -> None:
    """A further line under the label above it, aligned with that line's value."""
    print(f"  {'':{LABEL_WIDTH}}{value}", file=out)


if __name__ == "__main__":
    raise SystemExit(main())
