"""The one question the manifest cannot answer, asked before anything is uploaded.

The manifest is the tool's only picture of the remote, and nothing ever checked that
picture against the server. Two states make it a lie, and each is silent:

- the web space was emptied by something other than this tool, so a manifest full of
  hashes describes a server holding none of those files. The run reports
  `0 new, 0 changed`, uploads nothing, and leaves the site broken. Observed 2026-08-31.
- the manifest is gone — a second machine, a wiped `.state/` — while the remote is a
  fully installed gallery. The run re-uploads everything, and every remote path this
  file set no longer carries becomes an orphan the prune can never reach again, because
  prune only considers what the *previous* manifest recorded.

`check_state` is the decision and takes only values, so all four cells of its table are
unit-tested. `probe` is the impure half: one GET, through the injected HTTP port.
decision 0027.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from pwgdeploy import bootstrap
from pwgdeploy.config import DeployConfig
from pwgdeploy.errors import RemoteHttpError, StateMismatchError, VersionError

ADOPT_FLAG = "--adopt-remote-state"
AUDIT_FLAG = "--audit"
ALLOW_VERSION_FLAG = "--allow-version-change"
# Core's own migration entry point. This tool never posts to it — it names it and stops.
UPGRADE_SCRIPT = "upgrade.php"

VERSION_METHOD = "pwg.getVersion"


@dataclass(frozen=True)
class RemoteState:
    installed: bool
    version: str | None
    """None while the remote is blank: there is no gallery to ask."""


def probe(client, config: DeployConfig) -> RemoteState:
    """One GET of install.php, and — when it is installed — its PHPWG_VERSION.

    Port-typed; opens no socket of its own. Asks the same marker the bootstrap asks
    (install.php:156-165 decides it from local/config/database.inc.php alone), so the two
    halves of the run cannot disagree about what "installed" means.

    ws.php:57-62 registers pwg.getVersion with no `admin_only`, so the session is not
    strictly needed — it is taken anyway, because an install with guest access disabled
    refuses every method but the login, and one code path that works against both beats
    an unauthenticated call with a login retry.
    """
    installed = bootstrap.is_installed(client, config.site.base_url)
    if not installed:
        return RemoteState(installed=False, version=None)

    bootstrap.login(client, config)
    reported = bootstrap.ws_call(client, config.site.base_url, VERSION_METHOD)
    if not isinstance(reported, str):
        raise RemoteHttpError(
            f"{VERSION_METHOD} returned {reported!r}, not a version string"
        )
    return RemoteState(installed=True, version=reported)


def check_state(
    *,
    entry_count: int,
    remote_installed: bool,
    manifest_path: Path,
    file_count: int,
    adopt: bool,
) -> str | None:
    """None when the two agree; a warning string when `adopt` overrides a disagreement.

    Raises StateMismatchError otherwise. Pure: every input is a value.

    | entry_count | remote_installed | verdict                                  |
    |-------------|------------------|------------------------------------------|
    | 0           | False            | agree — a first run                      |
    | > 0         | True             | agree — an update run                    |
    | > 0         | False            | refuse — the manifest is a lie           |
    | 0           | True             | refuse — orphans would become permanent  |
    """
    if entry_count > 0 and not remote_installed:
        return _refuse(_wiped_remote_message(entry_count, manifest_path), adopt)
    if entry_count == 0 and remote_installed:
        return _refuse(_missing_manifest_message(file_count), adopt)
    return None


def check_version(local: str, remote: str | None, *, allow_change: bool) -> str | None:
    """None when they match or there is nothing to compare (the remote is not installed).

    Exact string equality, not a semver parse: `17.0.0beta1` is not a semver, and "which
    of these two is newer" is a question this tool must not answer. Any difference is a
    refusal — uploading either way puts core PHP on a schema no run of this tool migrated.
    """
    if remote is None or local == remote:
        return None

    message = (
        f"local PHPWG_VERSION is {local}, the remote reports {remote}. Uploading would "
        f"put core PHP on a schema this tool did not migrate; it does not run "
        f"{UPGRADE_SCRIPT}. Run {UPGRADE_SCRIPT} on the remote yourself, or pass "
        f"{ALLOW_VERSION_FLAG}."
    )
    if allow_change:
        return f"{ALLOW_VERSION_FLAG} given, proceeding anyway. {message}"
    raise VersionError(message)


def _refuse(message: str, adopt: bool) -> str:
    """Abort, or — with the named escape hatch — carry the same words as a warning.

    Self-healing either case silently is what this guard exists to prevent: one would
    re-upload 128 MB without being asked, the other would adopt a server whose contents
    nobody has looked at.
    """
    if adopt:
        return f"{ADOPT_FLAG} given, proceeding anyway. {message}"
    raise StateMismatchError(message)


def _wiped_remote_message(entry_count: int, manifest_path: Path) -> str:
    return (
        f"the manifest records {entry_count} uploaded files, but install.php says the "
        f"gallery is not installed. The remote was emptied by something other than this "
        f"tool, so the manifest is a lie and this run would upload nothing. Delete "
        f"{manifest_path} and re-run, or pass {ADOPT_FLAG} to upload against the remote "
        f"as it is."
    )


def _missing_manifest_message(file_count: int) -> str:
    return (
        f"install.php says the gallery is already installed, but there is no manifest "
        f"for this target. This run would re-upload all {file_count} files, and anything "
        f"already on the server that this run does not send stays there permanently — no "
        f"later run can reach it. Pass {ADOPT_FLAG} to proceed, or run {AUDIT_FLAG} first "
        f"to see what is there."
    )
