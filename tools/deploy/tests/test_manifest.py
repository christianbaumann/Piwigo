"""The manifest: what was last uploaded where, and what that makes pending.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [DT] [ERR].
State transition not applicable here: the manifest holds no state of its own beyond the
file on disk — the deploy's run-to-run transitions are exercised in tests/test_upload.py.
"""

import hashlib
import json
from pathlib import Path

import pytest

from pwgdeploy import manifest

# Anti-vacuity: the round-trip fixture must actually carry entries, or an implementation
# that returns {} unconditionally would pass every load/save test here.
MIN_ROUND_TRIP_ENTRIES = 2

# sha256 of b"piwigo", computed independently of this tool.
KNOWN_DIGEST = "3e667f4f00751478895e5b8a6de8bb0fe86d9fc9010447b5cb69efebb070829c"


def write(path: Path, data: bytes) -> Path:
    path.write_bytes(data)
    return path


# --- manifest_path -----------------------------------------------------------------


def test_manifest_path_differs_per_target(tmp_path):
    """Two hosts, and one host with two remote roots, are three separate targets. [ECP]"""
    paths = {
        manifest.manifest_path(tmp_path, "a.example", "/www"),
        manifest.manifest_path(tmp_path, "b.example", "/www"),
        manifest.manifest_path(tmp_path, "a.example", "/www/staging"),
    }

    assert len(paths) == 3


def test_manifest_path_is_a_json_file_inside_the_state_dir(tmp_path):
    """[HAPPY]"""
    path = manifest.manifest_path(tmp_path, "a.example", "/www")

    assert path.parent == tmp_path
    assert path.suffix == ".json"


def test_manifest_path_holds_no_separators(tmp_path):
    """A remote root must not turn into nested directories nor escape the state dir. [BVA]"""
    path = manifest.manifest_path(tmp_path, "a.example", "/www/../../etc")

    assert "/" not in path.name
    assert ".." not in path.name


# --- load / save -------------------------------------------------------------------


def test_save_then_load_round_trips(tmp_path):
    """[HAPPY]"""
    entries = {"index.php": "a" * 64, "plugins/persons/main.inc.php": "b" * 64}
    assert len(entries) >= MIN_ROUND_TRIP_ENTRIES
    path = tmp_path / "target.json"

    manifest.save(path, entries)

    assert manifest.load(path) == entries


def test_save_creates_the_state_directory(tmp_path):
    """A fresh clone has no .state/ at all. [HAPPY]"""
    path = tmp_path / "state" / "target.json"

    manifest.save(path, {"index.php": "a" * 64})

    assert manifest.load(path) == {"index.php": "a" * 64}


def test_load_of_a_missing_file_is_empty(tmp_path):
    """[NEG]"""
    assert manifest.load(tmp_path / "absent.json") == {}


def test_load_of_a_future_version_is_empty(tmp_path):
    """A format this build cannot read is discarded, not guessed at. [NEG] [ECP]"""
    path = tmp_path / "target.json"
    path.write_text(
        json.dumps({"version": manifest.MANIFEST_VERSION + 998, "entries": {"a": "b"}})
    )

    assert manifest.load(path) == {}


def test_load_of_unreadable_json_is_empty(tmp_path):
    """A truncated write from an older build re-uploads; it never raises. [NEG]"""
    path = write(tmp_path / "target.json", b"{not json")

    assert manifest.load(path) == {}


def test_save_is_atomic(tmp_path):
    """A failed write leaves the previous manifest intact. [ERR]

    Oracle is the implementation's tmp-file-plus-os.replace strategy, not a requirement.
    """
    path = tmp_path / "target.json"
    manifest.save(path, {"index.php": "a" * 64})

    class Unserialisable:
        pass

    with pytest.raises(TypeError):
        manifest.save(path, {"index.php": Unserialisable()})

    assert manifest.load(path) == {"index.php": "a" * 64}
    assert list(tmp_path.iterdir()) == [path]


# --- file_hash ---------------------------------------------------------------------


def test_hash_of_a_known_byte_string(tmp_path):
    """[HAPPY]"""
    path = write(tmp_path / "f", b"piwigo")

    assert manifest.file_hash(path) == KNOWN_DIGEST


def test_hash_streams_across_the_chunk_boundary(tmp_path):
    """One byte past a chunk must hash as the whole file does. [BVA]"""
    data = b"x" * (manifest.HASH_CHUNK_BYTES + 1)
    path = write(tmp_path / "f", data)

    assert manifest.file_hash(path) == hashlib.sha256(data).hexdigest()


def test_hash_of_an_empty_file(tmp_path):
    """[BVA]"""
    path = write(tmp_path / "f", b"")

    assert manifest.file_hash(path) == hashlib.sha256(b"").hexdigest()


def test_a_changed_byte_changes_the_hash(tmp_path):
    """Anti-vacuity for the diff tests: the hash must depend on the bytes. [ECP]"""
    one = write(tmp_path / "one", b"piwigo")
    other = write(tmp_path / "other", b"piwigp")

    assert manifest.file_hash(one) != manifest.file_hash(other)


# --- diff --------------------------------------------------------------------------

CURRENT = {"new.php": "n", "same.php": "s", "edited.php": "after"}
PREVIOUS = {"same.php": "s", "edited.php": "before", "gone.php": "g"}


@pytest.mark.parametrize(
    "path, bucket",
    [
        ("new.php", "new"),
        ("edited.php", "changed"),
        ("same.php", "unchanged"),
        ("gone.php", "removed"),
    ],
)
def test_diff_decision_table(path, bucket):
    """The four cases of (in current?, in previous?, hash equal?). [DT]"""
    result = manifest.diff(CURRENT, PREVIOUS)

    assert getattr(result, bucket) == [path]


def test_diff_of_two_empty_manifests_is_empty():
    """[BVA]"""
    result = manifest.diff({}, {})

    assert result.new == []
    assert result.changed == []
    assert result.unchanged == []
    assert result.removed == []
    assert result.pending == []


def test_first_run_is_all_new():
    """[BVA]"""
    result = manifest.diff(CURRENT, {})

    assert sorted(result.new) == sorted(CURRENT)
    assert result.changed == []
    assert result.unchanged == []
    assert result.removed == []


def test_pending_is_new_and_changed():
    """[HAPPY]"""
    assert manifest.diff(CURRENT, PREVIOUS).pending == ["edited.php", "new.php"]


def test_pending_order_is_deterministic():
    """Two calls over differently ordered inputs yield the same list. [HAPPY]"""
    shuffled = dict(reversed(list(CURRENT.items())))

    assert manifest.diff(CURRENT, PREVIOUS).pending == (
        manifest.diff(shuffled, PREVIOUS).pending
    )


def test_removed_is_only_ever_previously_recorded_paths():
    """Remote-authored content is unreachable from the diff by construction. [NEG]"""
    result = manifest.diff({}, PREVIOUS)

    assert sorted(result.removed) == sorted(PREVIOUS)


# --- the three pieces together -----------------------------------------------------


def test_a_deleted_manifest_makes_every_file_new(tmp_path):
    """Throwing the state file away resets the target to a first run. [ST]

    manifest_path + load + diff composed over real files on disk. This is the whole of
    Phase 3's manual criterion except the CLI's `--dry-run` printing, which does not
    exist until Phase 5.
    """
    state_dir = tmp_path / "state"
    source = tmp_path / "src"
    source.mkdir()
    names = ["index.php", "picture.php", "ws.php"]
    for name in names:
        write(source / name, name.encode())
    current = {name: manifest.file_hash(source / name) for name in names}
    assert len(current) == len(names) > 0

    path = manifest.manifest_path(state_dir, "a.example", "/www")
    manifest.save(path, current)
    assert manifest.diff(current, manifest.load(path)).pending == []

    path.unlink()

    result = manifest.diff(current, manifest.load(path))
    assert sorted(result.new) == sorted(names)
    assert result.unchanged == []
    assert result.pending == sorted(names)
