"""Reading `PHPWG_VERSION` out of the checkout, which is one regex over one line.

[HAPPY] the literal core actually writes; [NEG] the two ways it can be unreadable;
[BVA] an empty literal, which is the boundary between "found" and "found nothing";
[ERR] the two shapes that are *not* the define this tool means — a double-quoted one
(core has never written one) and a second define after the first.

The checkout's own `include/constants.php` is read, never transcribed: a test carrying
`17.0.0beta1` as a string would go on passing after core moved on, which is the exact
failure the version guard exists to notice.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from pwgdeploy import version
from pwgdeploy.errors import VersionError

REPO_ROOT = Path(__file__).resolve().parents[3]
CONSTANTS = REPO_ROOT / version.VERSION_FILE

# constants.php carried 4.5 kB when this file was written, 2026-09-01. A read that comes
# back shorter than this is a truncated or moved file, and every scan below is vacuous.
MIN_CONSTANTS_BYTES = 500


def test_parse_version_reads_the_literal():
    """[HAPPY] The define as core writes it, banner and all."""
    assert version.parse_version("define('PHPWG_VERSION', '17.0.0beta1');") == "17.0.0beta1"


def test_parse_version_tolerates_the_whitespace_core_may_use():
    """[HAPPY] `define (  'PHPWG_VERSION' ,  '2.0' )` is the same statement to PHP, so a
    reformat of core must not read as "no version at all"."""
    assert version.parse_version("define(  'PHPWG_VERSION' ,  '2.0'  );") == "2.0"


def test_parse_version_without_the_define_raises():
    """[NEG] Refusing beats guessing: an unreadable local version means the guard cannot
    compare, and proceeding would be comparing nothing against the remote."""
    with pytest.raises(VersionError):
        version.parse_version("define('PHPWG_DEFAULT_LANGUAGE', 'en_UK');")


def test_parse_version_of_an_empty_literal_raises():
    """[BVA] `''` matches the pattern and carries no version — the boundary a bare
    `if not match` check passes straight through."""
    with pytest.raises(VersionError):
        version.parse_version("define('PHPWG_VERSION', '');")


def test_parse_version_ignores_a_double_quoted_define():
    """[ERR] Core has only ever single-quoted it. A double-quoted one is either not this
    define or not core, and reading it would put a version this tool cannot vouch for
    into a refusal message."""
    with pytest.raises(VersionError):
        version.parse_version('define("PHPWG_VERSION", "17.0.0beta1");')


def test_parse_version_takes_the_first_define_when_there_are_two():
    """[ERR] PHP's own rule: the first define wins and the second is a notice. Records
    that behaviour rather than requiring it — no requirement says core may not do this."""
    text = "define('PHPWG_VERSION', '17.0.0beta1');\ndefine('PHPWG_VERSION', '99');\n"

    assert version.parse_version(text) == "17.0.0beta1"


def test_local_version_reads_this_checkout():
    """[HAPPY] The whole point: what the tool would send, read off the file it sends."""
    text = CONSTANTS.read_text(encoding="utf-8")
    assert len(text) > MIN_CONSTANTS_BYTES, f"{CONSTANTS} is truncated; this test is vacuous"

    assert version.local_version(REPO_ROOT) == version.parse_version(text)


def test_local_version_of_a_tree_without_core_raises(tmp_path):
    """[NEG] A repo root pointed somewhere wrong fails naming the file it wanted, rather
    than reporting a version difference against a version it never found."""
    with pytest.raises(VersionError) as raised:
        version.local_version(tmp_path)

    assert version.VERSION_FILE in str(raised.value)
