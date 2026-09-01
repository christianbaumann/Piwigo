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
from pwgdeploy.errors import StateMismatchError

ADOPT_FLAG = "--adopt-remote-state"
AUDIT_FLAG = "--audit"


@dataclass(frozen=True)
class RemoteState:
    installed: bool
    version: str | None
    """None while the remote is blank — and, for now, always: Phase 4 fills this in."""


def probe(client, config: DeployConfig) -> RemoteState:
    """One GET of install.php. Port-typed; opens no socket of its own.

    Asks the same marker the bootstrap asks (install.php:156-165 decides it from
    local/config/database.inc.php alone), so the two halves of the run cannot disagree
    about what "installed" means.
    """
    return RemoteState(
        installed=bootstrap.is_installed(client, config.site.base_url), version=None
    )


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
