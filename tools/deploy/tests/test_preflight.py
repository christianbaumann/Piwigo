"""The guard that makes the manifest and the remote agree before anything is uploaded.

`check_state` is a two-condition decision table, so [DT] is the governing technique and
all four rows are covered, each with and without `--adopt-remote-state`. [BVA] on
`entry_count`, whose only interesting boundary is 0 against 1. [ECP] on `probe`, which has
two classes and nothing between them.

Every message assertion names a *value* the message has to carry — the manifest path, the
flag — never a phrase of the prose around it. The message is the entire point of an abort:
an operator who cannot tell which of the two sides to fix is no better off than before.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from pwgdeploy import preflight
from pwgdeploy.errors import RemoteHttpError, StateMismatchError, VersionError
from tests.fakes import FakeGallery

BASE_URL = "https://g.example.test"
MANIFEST = Path("/tmp/state/host_root.json")
FILE_COUNT = 3378
ENTRY_COUNT = 3309

# Two versions that differ, and differ in a way no ordering could resolve — which is the
# point: this tool refuses on any difference rather than deciding which one is newer.
LOCAL_VERSION = "17.1.0"
REMOTE_VERSION = "17.0.0beta1"


def check(*, entry_count, remote_installed, adopt=False):
    return preflight.check_state(
        entry_count=entry_count,
        remote_installed=remote_installed,
        manifest_path=MANIFEST,
        file_count=FILE_COUNT,
        adopt=adopt,
    )


# --- the four cells ---------------------------------------------------------------------


def test_an_empty_manifest_and_a_blank_remote_is_a_first_run():
    """[DT][HAPPY] Row 1: nothing uploaded yet, nothing installed yet."""
    assert check(entry_count=0, remote_installed=False) is None


def test_a_recorded_manifest_and_an_installed_remote_is_an_update_run():
    """[DT][HAPPY] Row 2: the ordinary second and later run."""
    assert check(entry_count=ENTRY_COUNT, remote_installed=True) is None


def test_a_recorded_manifest_and_a_blank_remote_is_refused():
    """[DT][NEG] Row 3, the state that actually happened 2026-08-31: the web space was
    emptied by hand, and the run reported `0 new, 0 changed` over a broken site."""
    with pytest.raises(StateMismatchError):
        check(entry_count=ENTRY_COUNT, remote_installed=False)


def test_an_empty_manifest_and_an_installed_remote_is_refused():
    """[DT][NEG] Row 4: a second machine, or a lost `.state/`. Uploading would leave
    every path this file set no longer carries as an orphan no prune can ever reach."""
    with pytest.raises(StateMismatchError):
        check(entry_count=0, remote_installed=True)


def test_one_recorded_entry_is_already_a_recorded_manifest():
    """[BVA] The boundary between rows 1 and 3 is 0 against 1, not against "many"."""
    with pytest.raises(StateMismatchError):
        check(entry_count=1, remote_installed=False)


# --- what the refusals say ----------------------------------------------------------------


def test_the_wiped_remote_message_names_the_manifest_file_to_delete():
    """[NEG] The operator's next action is `rm <that file>`; a message that describes the
    situation without naming the path leaves them guessing which target it meant."""
    with pytest.raises(StateMismatchError) as raised:
        check(entry_count=ENTRY_COUNT, remote_installed=False)

    assert str(MANIFEST) in str(raised.value)
    assert preflight.ADOPT_FLAG in str(raised.value)
    assert str(ENTRY_COUNT) in str(raised.value)


def test_the_missing_manifest_message_names_the_audit_flag():
    """[NEG] The other direction's next action is to look at the server before adopting
    it, and `--audit` is the way to do that."""
    with pytest.raises(StateMismatchError) as raised:
        check(entry_count=0, remote_installed=True)

    assert preflight.AUDIT_FLAG in str(raised.value)
    assert preflight.ADOPT_FLAG in str(raised.value)
    assert str(FILE_COUNT) in str(raised.value)


# --- the escape hatch -----------------------------------------------------------------------


@pytest.mark.parametrize(
    "entry_count,remote_installed",
    [(ENTRY_COUNT, False), (0, True)],
    ids=["wiped-remote", "missing-manifest"],
)
def test_adopt_turns_each_refusal_into_a_warning(entry_count, remote_installed):
    """[DT] Both refusing rows, overridden. The warning carries the same words the abort
    would have: the operator is told what they are overriding, not merely allowed to."""
    warning = check(
        entry_count=entry_count, remote_installed=remote_installed, adopt=True
    )

    assert warning
    assert preflight.ADOPT_FLAG in warning


@pytest.mark.parametrize(
    "entry_count,remote_installed",
    [(0, False), (ENTRY_COUNT, True)],
    ids=["first-run", "update-run"],
)
def test_adopt_says_nothing_when_the_two_already_agree(entry_count, remote_installed):
    """[NEG] A flag that is noisy on every run stops being read, and then the one run it
    mattered on reads like all the others."""
    assert (
        check(entry_count=entry_count, remote_installed=remote_installed, adopt=True)
        is None
    )


# --- the probe ------------------------------------------------------------------------------


class _Config:
    """The fields `probe` reads, without building a whole DeployConfig."""

    def __init__(self, base_url, admin=("webmaster", "p")):
        self.site = type("Site", (), {"base_url": base_url})()
        self.admin = type("Admin", (), {"username": admin[0], "password": admin[1]})()


def test_probe_reports_a_blank_remote_as_not_installed():
    """[ECP] install.php renders its form, so the marker is absent."""
    state = preflight.probe(FakeGallery(BASE_URL), _Config(BASE_URL))

    assert state.installed is False


def test_probe_reports_an_installed_remote_from_the_marker():
    """[ECP] The other class: install.php:162 dies with the marker instead."""
    state = preflight.probe(FakeGallery(BASE_URL, installed=True), _Config(BASE_URL))

    assert state.installed is True


def test_probe_reports_the_remote_version():
    """[ECP] An installed remote answers pwg.getVersion, and that answer is what the
    version guard compares. Successor to `test_probe_leaves_the_version_unread`, which
    recorded the Phase 3 placeholder this replaces."""
    gallery = FakeGallery(BASE_URL, installed=True, version=REMOTE_VERSION)

    state = preflight.probe(gallery, _Config(BASE_URL))

    assert state.version == REMOTE_VERSION


def test_a_blank_remote_has_no_version_to_read():
    """[BVA] Nothing is installed, so there is no gallery to ask and no session to take.
    `None` is the value `check_version` reads as "nothing to compare"."""
    state = preflight.probe(FakeGallery(BASE_URL), _Config(BASE_URL))

    assert state.version is None


def test_the_probe_logs_in_before_asking_for_the_version():
    """[ST] The fake refuses every ws method but the login to an anonymous session, the
    way an install with guest access disabled does — so the order is the check."""
    gallery = FakeGallery(BASE_URL, installed=True)

    preflight.probe(gallery, _Config(BASE_URL))

    methods = gallery.methods_called()
    assert methods.index("pwg.session.login") < methods.index("pwg.getVersion")


def test_a_non_string_version_result_fails_loudly():
    """[NEG] A number, or a null, compared with `!=` against a string would refuse every
    run with a message naming a version nobody wrote."""
    gallery = FakeGallery(BASE_URL, installed=True, version=17)

    with pytest.raises(RemoteHttpError):
        preflight.probe(gallery, _Config(BASE_URL))


def test_probe_only_reads():
    """[NEG] The guard runs before the upload on every real run, so a probe that could
    change the remote would be a side effect nobody asked for."""
    gallery = FakeGallery(BASE_URL)

    preflight.probe(gallery, _Config(BASE_URL))

    assert gallery.installed is False
    assert [call[0] for call in gallery.calls] == ["get"]


# --- the version guard ------------------------------------------------------------------


def test_matching_versions_pass():
    """[HAPPY][ECP] The ordinary run: this checkout is what is already on the server."""
    assert (
        preflight.check_version(REMOTE_VERSION, REMOTE_VERSION, allow_change=False) is None
    )


def test_a_blank_remote_has_no_version_to_compare():
    """[BVA] A first run: there is no gallery yet, so there is nothing to disagree with
    and the guard must not invent a disagreement out of `None`."""
    assert preflight.check_version(LOCAL_VERSION, None, allow_change=False) is None


def test_differing_versions_are_refused():
    """[NEG][ECP] Uploading newer core PHP over an un-migrated schema is the failure this
    guard exists for, and it is not one the operator would see until the site broke."""
    with pytest.raises(VersionError):
        preflight.check_version(LOCAL_VERSION, REMOTE_VERSION, allow_change=False)


def test_the_refusal_names_both_versions_and_upgrade_php():
    """[NEG] The operator's next action is running upgrade.php on the remote themselves;
    a message that says only "they differ" leaves them with neither figure to act on."""
    with pytest.raises(VersionError) as raised:
        preflight.check_version(LOCAL_VERSION, REMOTE_VERSION, allow_change=False)

    message = str(raised.value)
    assert LOCAL_VERSION in message
    assert REMOTE_VERSION in message
    assert preflight.UPGRADE_SCRIPT in message
    assert preflight.ALLOW_VERSION_FLAG in message


def test_allow_version_change_turns_the_refusal_into_a_warning():
    """[DT] The named escape hatch, carrying the same words the abort would have."""
    warning = preflight.check_version(LOCAL_VERSION, REMOTE_VERSION, allow_change=True)

    assert warning
    assert preflight.ALLOW_VERSION_FLAG in warning
    assert REMOTE_VERSION in warning


def test_allow_version_change_says_nothing_when_the_versions_match():
    """[NEG] A flag that is noisy on every run stops being read."""
    assert (
        preflight.check_version(REMOTE_VERSION, REMOTE_VERSION, allow_change=True) is None
    )
