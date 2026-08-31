"""Structural guard: a real credential file must never become committable.

Nothing else in the tool would notice a .gitignore rewrite that dropped these rules —
the leak would only show up in a pushed commit. Automates the plan's Phase 1 criterion
`git check-ignore -v deploy.local.json reports the new rule` as a repeatable check.

Techniques: [NEG] for the paths that must be ignored, [BVA] for the committed example
that must not be, which is also this file's anti-vacuity guard: a .gitignore that
ignored everything would pass every other assertion here.
"""

import subprocess
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parents[3]

MUST_BE_IGNORED = (
    "deploy.local.json",
    "deploy.production.json",
    # The copy that lands next to the example, which is where README.md tells an
    # operator to put it and the only place they see the file being copied from.
    "tools/deploy/deploy.local.json",
    "tools/deploy/.state/ftp.example.net-piwigo.json",
    "tools/deploy/pwgdeploy/__pycache__/config.cpython-312.pyc",
    "tools/deploy/.pytest_cache/CACHEDIR.TAG",
    "tools/deploy/.venv/pyvenv.cfg",
)

MUST_NOT_BE_IGNORED = (
    "tools/deploy/deploy.example.json",
    "tools/deploy/pyproject.toml",
    "tools/deploy/pwgdeploy/config.py",
    "tools/deploy/tests/test_config.py",
)


def is_ignored(rel_path: str) -> bool:
    """--no-index, deliberately: without it git reports every *tracked* path as
    not-ignored regardless of the rules, and the tracked-file assertions below would
    pass on a .gitignore that excludes the whole tool."""
    result = subprocess.run(
        ["git", "check-ignore", "-q", "--no-index", "--", rel_path],
        cwd=REPO_ROOT,
        capture_output=True,
    )
    if result.returncode not in (0, 1):
        raise AssertionError(
            f"git check-ignore failed for {rel_path}: {result.stderr.decode().strip()}"
        )
    return result.returncode == 0


def test_the_checkout_is_the_repository_root():
    """Anti-vacuity: every other test here is meaningless if this is the wrong directory."""
    assert (REPO_ROOT / ".gitignore").is_file()
    assert (REPO_ROOT / "install.php").is_file()


@pytest.mark.parametrize("rel_path", MUST_BE_IGNORED)
def test_working_state_is_ignored(rel_path):
    """[NEG] A real credential file or a per-target manifest must not be committable."""
    assert is_ignored(rel_path), f"{rel_path} is NOT git-ignored"


@pytest.mark.parametrize("rel_path", MUST_NOT_BE_IGNORED)
def test_the_tool_itself_stays_tracked(rel_path):
    """[BVA] The committed example sits next to the ignored ones and must survive."""
    assert not is_ignored(rel_path), f"{rel_path} is git-ignored but must be tracked"
