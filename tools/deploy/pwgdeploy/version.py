"""Which core is in this checkout.

One define, read from the one file core keeps it in. It is a value the preflight compares
against the remote's own answer, so an unreadable local version is a refusal rather than a
default: comparing nothing against a running gallery would report agreement it never
established.
"""

from __future__ import annotations

import re
from pathlib import Path

from pwgdeploy.errors import VersionError

# include/constants.php:10 — `define('PHPWG_VERSION', '17.0.0beta1');`
VERSION_FILE = "include/constants.php"

# Single quotes only: that is how core has always written it, and a double-quoted one is
# either not this define or not core.
_DEFINE = re.compile(r"define\(\s*'PHPWG_VERSION'\s*,\s*'([^']*)'\s*\)")


def parse_version(text: str) -> str:
    """The PHPWG_VERSION literal. Pure. Raises VersionError when absent or empty."""
    match = _DEFINE.search(text)
    if match is None:
        raise VersionError(f"no PHPWG_VERSION define found in {VERSION_FILE}")
    found = match.group(1)
    if not found:
        raise VersionError(f"the PHPWG_VERSION define in {VERSION_FILE} is empty")
    return found


def local_version(repo_root: Path) -> str:
    """parse_version of include/constants.php."""
    path = Path(repo_root) / VERSION_FILE
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as error:
        raise VersionError(f"cannot read {VERSION_FILE}: {error}") from error
    return parse_version(text)
