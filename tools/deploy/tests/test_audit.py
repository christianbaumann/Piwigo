"""The read-only audit: walking the remote tree, and the three buckets it sorts it into.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ERR].

Both halves are pure — `walk` takes the listing operation as a callable, `compare` takes
three collections — so the whole of this file runs without a server. Decision table not
applicable: `compare` has one condition per bucket and no interaction between them.
"""

from __future__ import annotations

import pytest

from pwgdeploy import audit, fileset
from pwgdeploy.transport import RemoteEntry

ROOT = "/piwigo"

# Under the smallest tree any test below builds. A walk that comes back with fewer files
# than this has stopped reading the listing it claims to walk, and every count assertion
# after it would pass on nothing.
MIN_AUDIT_FILES = 1


class Listing:
    """A remote tree, addressed the way `walk` addresses it: one directory at a time.

    `tree` maps a directory path to `{name: is_dir}`. A directory absent from it lists
    empty, which is what a real server answers for a directory holding nothing.
    """

    def __init__(self, tree: dict[str, dict[str, bool]]):
        self.tree = tree
        self.listed: list[str] = []

    def __call__(self, remote_dir: str) -> list[RemoteEntry]:
        self.listed.append(remote_dir)
        return [
            RemoteEntry(name=name, is_dir=is_dir)
            for name, is_dir in sorted(self.tree.get(remote_dir, {}).items())
        ]


# --- walk ------------------------------------------------------------------------------


def test_walk_finds_a_file_at_the_root():
    """[HAPPY]"""
    listing = Listing({ROOT: {"index.php": False}})

    files, _directories = audit.walk(listing, ROOT)

    assert files == ["/piwigo/index.php"]
    assert len(files) >= MIN_AUDIT_FILES


def test_walk_descends_into_a_subdirectory():
    """[HAPPY] The whole point of a walk: one MLSD per directory, depth first."""
    listing = Listing(
        {
            ROOT: {"index.php": False, "themes": True},
            "/piwigo/themes": {"modus": True},
            "/piwigo/themes/modus": {"theme.css": False},
        }
    )

    files, _directories = audit.walk(listing, ROOT)

    assert files == ["/piwigo/index.php", "/piwigo/themes/modus/theme.css"]


def test_walk_of_an_empty_remote_finds_nothing():
    """[BVA] A blank web space is a real state, and it must not read as an error."""
    listing = Listing({})

    files, directories = audit.walk(listing, ROOT)

    assert files == []
    assert directories == 1, "the root itself was listed"


def test_walk_skips_the_server_authoritative_directories():
    """[ECP] `_data/` and `upload/` hold thousands of files this tool never wrote and the
    manifest never records. Listing them would report every one as an orphan.

    The names are read off `fileset`, never transcribed: they are the same two the deploy
    creates and chmods."""
    assert audit.AUDIT_SKIP == fileset.REMOTE_DIRS_TO_CREATE
    tree = {ROOT: {"index.php": False}}
    for name in fileset.REMOTE_DIRS_TO_CREATE:
        tree[ROOT][name] = True
        tree[f"{ROOT}/{name}"] = {"never_uploaded.jpg": False}
    listing = Listing(tree)

    files, _directories = audit.walk(listing, ROOT)

    assert files == ["/piwigo/index.php"]
    assert not any(name in " ".join(listing.listed) for name in fileset.REMOTE_DIRS_TO_CREATE)


def test_a_directory_named_like_a_skipped_one_deeper_in_is_still_walked():
    """[ERR] The skip is a path, not a name: `plugins/foo/upload/` is published content
    and hiding it would make the audit under-report exactly where it is needed."""
    listing = Listing(
        {
            ROOT: {"plugins": True},
            "/piwigo/plugins": {"upload": True},
            "/piwigo/plugins/upload": {"main.inc.php": False},
        }
    )

    files, _directories = audit.walk(listing, ROOT)

    assert files == ["/piwigo/plugins/upload/main.inc.php"]


def test_walk_stops_at_the_depth_limit():
    """[BVA] A symlink loop on the server lists itself forever. Without the bound this
    test would hang rather than fail, which is why the loop is the fixture."""

    def endless(remote_dir: str) -> list[RemoteEntry]:
        endless.listed.append(remote_dir)
        return [RemoteEntry(name="loop", is_dir=True)]

    endless.listed = []

    _files, directories = audit.walk(endless, ROOT, max_depth=3)

    assert directories == 4, "the root plus three levels below it"
    assert len(endless.listed) == directories


def test_walk_counts_every_directory_it_listed():
    """[HAPPY] The figure the report prints; without it `3411 files` says nothing about
    how much of the tree was actually reached."""
    listing = Listing(
        {
            ROOT: {"a": True, "b": True},
            "/piwigo/a": {"x.php": False},
            "/piwigo/b": {},
        }
    )

    _files, directories = audit.walk(listing, ROOT)

    assert directories == 3
    assert directories == len(listing.listed)


def test_walk_of_an_empty_remote_root_lists_the_login_directory():
    """[BVA] A web space whose document root *is* the FTP login directory: the remote
    root is `""`, and an absolute `/` would list the server's root instead."""
    listing = Listing({"": {"index.php": False}})

    files, _directories = audit.walk(listing, "")

    assert listing.listed == [""]
    assert files == ["index.php"]


# --- compare ---------------------------------------------------------------------------


def test_compare_puts_a_recorded_and_present_file_in_covered():
    """[ECP] The ordinary case: everything the last deploy sent is still there."""
    report = audit.compare(["/piwigo/index.php"], {"/piwigo/index.php": "h"}, set())

    assert report.covered == ["/piwigo/index.php"]
    assert report.orphans == [] and report.missing == []


def test_compare_puts_an_unrecorded_present_file_in_orphans():
    """[ECP] The reason the audit exists: prune only considers what the manifest records,
    so a path the manifest lost is one no future run can reach."""
    report = audit.compare(["/piwigo/old.php"], {}, set())

    assert report.orphans == ["/piwigo/old.php"]
    assert report.covered == []


def test_compare_puts_a_recorded_absent_file_in_missing():
    """[ECP] The other direction: the manifest claims a file the server does not hold, so
    the next run would report it unchanged and never send it."""
    report = audit.compare([], {"/piwigo/theme.css": "h"}, set())

    assert report.missing == ["/piwigo/theme.css"]
    assert report.orphans == []


def test_the_generated_config_is_covered_not_an_orphan():
    """[ERR] `local/config/config.inc.php` is written by the bootstrap, not enumerated by
    git — the same trap `upload.py:62-69` already had to fix once, where it looked
    `removed` on every run after the first. On the server it is deliberate, so it is
    never an orphan whether or not the manifest happens to record it."""
    generated = {"/piwigo/local/config/config.inc.php"}

    report = audit.compare(list(generated), {}, generated)

    assert report.covered == sorted(generated)
    assert report.orphans == []


def test_compare_of_an_empty_manifest_makes_everything_an_orphan():
    """[BVA] The state the missing-manifest guard refuses a deploy over; the audit is
    what an operator runs to see what they would be adopting."""
    present = ["/piwigo/a.php", "/piwigo/b.php"]

    report = audit.compare(present, {}, set())

    assert len(present) >= MIN_AUDIT_FILES
    assert report.orphans == present


def test_compare_of_an_empty_listing_makes_everything_missing():
    """[BVA] The wiped remote, seen from the audit rather than from the guard."""
    entries = {"/piwigo/a.php": "h", "/piwigo/b.php": "h"}

    report = audit.compare([], entries, set())

    assert len(entries) >= MIN_AUDIT_FILES
    assert report.missing == sorted(entries)


def test_compare_carries_the_directory_count_and_the_skipped_names():
    """[HAPPY] Anti-vacuity for the report: both figures reach the printer through the
    report object, so a walk that skipped everything cannot print a full-looking line."""
    report = audit.compare([], {}, set(), directories=402, skipped=("_data", "upload"))

    assert report.directories == 402
    assert report.skipped == ("_data", "upload")


@pytest.mark.parametrize("bucket", ["covered", "orphans", "missing"])
def test_every_bucket_is_sorted(bucket):
    """[ERR] The report names paths an operator will delete by hand over FTP; a listing
    order that depends on the server's own would make two runs incomparable."""
    report = audit.compare(
        ["/piwigo/b.php", "/piwigo/a.php"],
        {"/piwigo/a.php": "h", "/piwigo/z.php": "h", "/piwigo/y.php": "h"},
        set(),
    )

    paths = getattr(report, bucket)
    assert len(paths) >= MIN_AUDIT_FILES
    assert paths == sorted(paths)
