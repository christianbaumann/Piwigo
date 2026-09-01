"""What a deploy publishes.

Enumeration is a thin `git` adapter; every decision about what to keep lives in the pure
`select()` below, so each exclusion rule is testable without a repository.
"""

from __future__ import annotations

import subprocess
from pathlib import Path
from typing import Iterable

from pwgdeploy.errors import GitError

# decision 7: plugins/typetags is a submodule, so a plain ls-files reports one gitlink
# instead of the plugin's files.
GIT_LS_FILES = ("git", "ls-files", "-z", "--recurse-submodules")

# Which submodules *should* have contributed, read from .gitmodules rather than hardcoded.
GIT_SUBMODULE_PATHS = (
    "git",
    "config",
    "--file",
    ".gitmodules",
    "--get-regexp",
    r"^submodule\..*\.path$",
)
# `git config --get-regexp` exits 1 when nothing matches; a repository with no
# submodules is not an error.
GIT_CONFIG_NO_MATCH = 1

# An uninitialised submodule is dropped from `--recurse-submodules` output *silently* —
# neither its files nor its gitlink appear, and the total stays plausible. Without this
# floor and the per-submodule check below, a deploy would publish a gallery whose plugin
# has no code and report success. Measured 2026-08-31: 3376 tracked paths with the
# typetags submodule absent, 3533 with it checked out.
MIN_EXPECTED_PATHS = 3000
SUBMODULE_INIT_HINT = "git submodule update --init --recursive"

# decision 11's deploy exclusion list.
EXCLUDED_DIR_NAMES = ("tests",)
EXCLUDED_BASENAMES = (
    "phpunit.xml",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "playwright.config.js",
    "create-test-users.php",
)
EXCLUDED_PREFIXES = (
    "docs/",
    ".claude/",
    ".githooks/",
    ".ddev/",
    "local/config/",
    # decision 0022: core loads nothing from tools/ at runtime — its only two mentions
    # in core are comments (include/config_default.inc.php:18,353) — so the whole
    # directory stays off the web space, upstream's release script notwithstanding.
    "tools/",
    # decision 0025: the handbook's dev-only generator/checker tooling stays off the
    # web space, same reasoning as the bare tools/ entry above — just scoped to the
    # one subtree that needs it, since handbuch/ itself now ships.
    "handbuch/tools/",
    ".gitignore",
    ".gitmodules",
)

# Published by the deploy itself rather than enumerated by git: `bootstrap` generates it
# after the install (decision 8). It is therefore absent from the file set by
# construction, which makes it look "removed" to `manifest.diff` on every run after the
# first — so the prune has to be told about it by name. Found by the second real deploy,
# 2026-08-31: the full command hid it (prune deleted the file, the bootstrap that followed
# put it back seconds later), but `--no-bootstrap` would have left the gallery with no
# config at all.
GENERATED_CONFIG_PATH = "local/config/config.inc.php"
GENERATED_REMOTE_PATHS = (GENERATED_CONFIG_PATH,)

# Published despite sitting under an excluded prefix: without them the remote serves a
# directory listing of the directory that holds the database credentials.
LOCAL_GUARD_PREFIX = "local/"
LOCAL_GUARD_BASENAME = "index.php"

# tools/pwg_rel_create.sh:123-127
REMOTE_DIRS_TO_CREATE = ("_data", "upload")
# tools/pwg_rel_create.sh:133-140
WRITABLE_REMOTE_PATHS = ("local", "_data", "upload", "plugins", "themes")


def git_tracked_paths(repo_root: Path, run=subprocess.run) -> list[str]:
    """Every path git tracks, submodule contents included."""
    result = run(
        list(GIT_LS_FILES),
        cwd=str(repo_root),
        capture_output=True,
    )
    command = " ".join(GIT_LS_FILES)
    if result.returncode != 0:
        stderr = (result.stderr or b"").decode("utf-8", "replace").strip()
        raise GitError(f"`{command}` failed in {repo_root}: {stderr}")

    paths = [p for p in (result.stdout or b"").decode("utf-8").split("\0") if p]
    if not paths:
        raise GitError(f"`{command}` reported no files in {repo_root}")
    return paths


def declared_submodule_paths(repo_root: Path, run=subprocess.run) -> list[str]:
    """The submodule paths .gitmodules declares, whether or not they are checked out."""
    result = run(
        list(GIT_SUBMODULE_PATHS),
        cwd=str(repo_root),
        capture_output=True,
    )
    if result.returncode == GIT_CONFIG_NO_MATCH:
        return []
    if result.returncode != 0:
        stderr = (result.stderr or b"").decode("utf-8", "replace").strip()
        raise GitError(
            f"`{' '.join(GIT_SUBMODULE_PATHS)}` failed in {repo_root}: {stderr}"
        )

    paths = []
    for line in (result.stdout or b"").decode("utf-8").splitlines():
        _key, _, value = line.partition(" ")
        if value.strip():
            paths.append(value.strip().rstrip("/"))
    return paths


def check_complete(
    paths: list[str],
    submodule_paths: Iterable[str],
    min_expected: int = MIN_EXPECTED_PATHS,
) -> None:
    """Raise when the enumeration is obviously partial. Pure: no I/O.

    A partial file set is worse than a failed one — it deploys, and the missing code is
    only noticed by whoever visits the broken page.
    """
    if len(paths) < min_expected:
        raise GitError(
            f"only {len(paths)} tracked paths, expected at least {min_expected} — "
            f"the enumeration looks partial; try `{SUBMODULE_INIT_HINT}`"
        )
    for submodule in submodule_paths:
        prefix = submodule.rstrip("/") + "/"
        if not any(path.startswith(prefix) for path in paths):
            raise GitError(
                f"submodule {submodule} contributed no files — it is declared in "
                f".gitmodules but not checked out, and `git ls-files "
                f"--recurse-submodules` omits it silently. Run `{SUBMODULE_INIT_HINT}`"
            )


def verified_tracked_paths(repo_root: Path, run=subprocess.run) -> list[str]:
    """Enumerate, then prove the enumeration is whole. The entry point a deploy uses."""
    paths = git_tracked_paths(repo_root, run=run)
    check_complete(paths, declared_submodule_paths(repo_root, run=run))
    return paths


def total_bytes(repo_root: Path, paths: Iterable[str]) -> int:
    """What a deploy of `paths` weighs — the figure the run reports and a human checks."""
    return sum((Path(repo_root) / path).stat().st_size for path in paths)


def select(tracked: Iterable[str]) -> list[str]:
    """Keep only what the remote install needs, in the order given."""
    return [path for path in tracked if _is_published(path)]


def _is_published(path: str) -> bool:
    if _is_local_guard(path):
        return True
    segments = path.split("/")
    if any(segment in EXCLUDED_DIR_NAMES for segment in segments[:-1]):
        return False
    if segments[-1] in EXCLUDED_BASENAMES:
        return False
    return not path.startswith(EXCLUDED_PREFIXES)


def _is_local_guard(path: str) -> bool:
    return path.startswith(LOCAL_GUARD_PREFIX) and path.endswith(
        "/" + LOCAL_GUARD_BASENAME
    )
