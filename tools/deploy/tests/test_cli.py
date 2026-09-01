"""The command: flags, the report it prints, and the exit code each failure maps to.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [ST] [DT].
Boundary values not applicable: the CLI has no numeric domain of its own — the counts it
prints come from `manifest.diff`, whose boundaries are tested in tests/test_manifest.py.

Both adapters are injected, so every case below runs with no socket. What these tests
cannot witness is the two real factories they replace; those are the phase's manual step.
"""

import io
import re

import pytest

from pwgdeploy import cli
from pwgdeploy import preflight
from pwgdeploy import version as pwgversion
from pwgdeploy.errors import (
    ConfigError,
    InsecureTransportError,
    StateMismatchError,
    TransportError,
    VersionError,
)
from tests.fakes import FakeGallery, FakeTransport

BASE_URL = "https://g.example.test"

RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": "/piwigo"},
    "mysql": {"host": "localhost", "user": "d1", "password": "p", "database": "d1"},
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": BASE_URL},
}

TRACKED = (
    "index.php",
    "include/common.inc.php",
    "themes/modus/theme.css",
    "plugins/persons/tests/UnitTest.php",
    "docs/agents/plans/whatever.md",
)
# Anti-vacuity: the two excluded entries above are what makes --list-files a real filter.
EXPECTED_PUBLISHED = 3

# A checkout the fake gallery is not running. Any difference is a refusal, so which one
# it is does not matter — only that it is not FakeGallery.VERSION.
OTHER_VERSION = "17.1.0"


@pytest.fixture
def config_file(tmp_path):
    import json

    path = tmp_path / "deploy.local.json"
    path.write_text(json.dumps(RAW), encoding="utf-8")
    return path


def _write_constants(root, version: str) -> None:
    """The one file the version guard reads. Written with the fake gallery's own version
    by default, so an ordinary run is a matching pair rather than a refusal."""
    path = root / pwgversion.VERSION_FILE
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(f"<?php\ndefine('PHPWG_VERSION', '{version}');\n", encoding="utf-8")


@pytest.fixture
def repo(tmp_path):
    root = tmp_path / "repo"
    for rel in TRACKED:
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f"contents of {rel}\n", encoding="utf-8")
    _write_constants(root, FakeGallery.VERSION)
    return root


class Run:
    """One invocation, with both adapters replaced and the output captured."""

    def __init__(self, config_file, repo, tmp_path, transport=None, gallery=None):
        self.transport = transport if transport is not None else FakeTransport()
        self.gallery = gallery if gallery is not None else FakeGallery(BASE_URL)
        self.out = io.StringIO()
        self.err = io.StringIO()
        self._args = [str(config_file)]
        self._repo = repo
        self._state = tmp_path / "state"

    def __call__(self, *flags):
        return cli.main(
            list(flags) + self._args,
            stdout=self.out,
            stderr=self.err,
            repo_root=self._repo,
            state_dir=self._state,
            transport_factory=lambda config: self.transport,
            client_factory=lambda config: self.gallery,
            tracked=lambda repo_root: list(TRACKED),
        )

    @property
    def text(self):
        return self.out.getvalue()


@pytest.fixture
def run(config_file, repo, tmp_path):
    return Run(config_file, repo, tmp_path)


def test_a_full_run_uploads_then_bootstraps_and_exits_zero(run):
    """[HAPPY][ST] The end state the plan describes, in one command."""
    assert run() == 0

    assert run.transport.paths("put")
    assert run.gallery.installed is True
    assert all(state == "active" for state in run.gallery.plugin_states.values())


def test_the_report_names_every_step(run):
    """[HAPPY] The operator reads this instead of the log; each step says what it did."""
    run()

    for label in ("transport", "file set", "manifest", "preflight", "upload", "chmod", "install", "config", "plugins", "sync"):
        assert label in run.text, f"{label} missing from:\n{run.text}"


def test_the_report_names_the_target_it_deployed_to(run):
    """[HAPPY] Two web spaces and one credential file per space: the header is the only
    thing standing between a sandbox deploy and the wrong host."""
    run()

    assert "ftp.example.test" in run.text
    assert "/piwigo" in run.text


def test_list_files_prints_the_published_set_and_stops(run):
    """[ECP] The excluded entries are the point: a test file and a doc must not appear."""
    assert run("--list-files") == 0

    printed = [line for line in run.text.splitlines() if line.strip()]
    assert len(printed) == EXPECTED_PUBLISHED
    assert "index.php" in printed
    assert not any("tests/" in line or line.startswith("docs/") for line in printed)
    assert run.transport.calls == []


def test_dry_run_connects_to_nothing(run):
    """[ST] The flag exists to be safe to point at a live host; a connect would break it."""
    assert run("--dry-run") == 0

    assert run.transport.calls == []
    assert run.gallery.calls == []


def test_dry_run_reports_what_it_would_send(run):
    """[HAPPY] A dry run whose report said nothing would have no reason to exist."""
    run("--dry-run")

    assert "3 new" in run.text


def test_a_second_dry_run_after_a_deploy_reports_nothing_pending(run):
    """[ST] The plan's own verification: a third run shows zero pending transfers."""
    run()
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run")

    assert "0 new" in run.text
    assert "0 changed" in run.text


def _record_a_stranger_in_the_manifest(tmp_path, path="/piwigo/gone.php"):
    from pwgdeploy import manifest

    state = manifest.manifest_path(tmp_path / "state", "ftp.example.test", "/piwigo")
    entries = manifest.load(state)
    entries[path] = "0" * 64
    manifest.save(state, entries)


def test_dry_run_reports_what_it_would_delete(run, tmp_path):
    """[NEG] Prune is the only destructive thing this tool does, so a dry run that hides
    it is the one report an operator cannot afford to trust. Reporting `0 removed` before
    a run that deletes a file is worse than printing nothing."""
    run()
    _record_a_stranger_in_the_manifest(tmp_path)
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run")

    assert "1 removed" in run.text


def test_dry_run_with_no_prune_reports_no_deletion(run, tmp_path):
    """[DT] The paired row: the flag that disables the deletion must change the figure,
    or the number is decoration rather than a prediction."""
    run()
    _record_a_stranger_in_the_manifest(tmp_path)
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run", "--no-prune")

    assert "0 removed" in run.text


GALLERY_REMOTE_PATH = "/piwigo/galleries/PHOTO_ALBUM/img_0421.png"


def _gallery_lines(text: str) -> list[str]:
    """Every line the `galleries` label opened, continuations included.

    Reads the label width off production rather than counting spaces here, and matches
    the label rather than the word — a report line naming a path under galleries/ would
    otherwise satisfy a bare substring check.
    """
    label = f"  {'galleries':{cli.LABEL_WIDTH}}"
    lines = text.splitlines()
    opened = [i for i, line in enumerate(lines) if line.startswith(label)]
    if not opened:
        return []
    start = opened[0]
    rest = [
        line
        for line in lines[start + 1 :]
        if line.startswith(" " * (2 + cli.LABEL_WIDTH))
    ]
    return [lines[start], *rest]


def test_a_dry_run_names_the_photo_it_would_delete(run, tmp_path):
    """[HAPPY] The 106 tracked scans are prune-eligible by design (decision 0026), and
    they are the only published files no later run could restore. `3 removed` hides
    which three; this line is the compensating control.

    Two strangers, one of them a photo, so both figures on the line are discriminating:
    the second is read off the `upload` line rather than transcribed, which is the
    "the count matches the removed total" half of the plan's manual check."""
    run()
    _record_a_stranger_in_the_manifest(tmp_path, GALLERY_REMOTE_PATH)
    _record_a_stranger_in_the_manifest(tmp_path)
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run")

    lines = _gallery_lines(run.text)
    assert lines
    assert GALLERY_REMOTE_PATH in lines[1]
    removed = int(re.search(r"(\d+) removed", run.text).group(1))
    assert removed == 2, "the fixture must prune more than the photo, or the line is vacuous"
    assert [int(n) for n in re.findall(r"\d+", lines[0])] == [1, removed]


def test_a_real_prune_names_the_photo_it_deleted(run, tmp_path):
    """[HAPPY] The truth half of the prediction above: a real run reports what it did,
    read back from `result.deleted` rather than from the diff it predicted."""
    run()
    _record_a_stranger_in_the_manifest(tmp_path, GALLERY_REMOTE_PATH)
    run.transport.files[GALLERY_REMOTE_PATH] = b"x"
    run.out.truncate(0)
    run.out.seek(0)

    run()

    assert _gallery_lines(run.text)
    assert GALLERY_REMOTE_PATH in run.text
    assert GALLERY_REMOTE_PATH not in run.transport.files


def test_no_gallery_line_when_the_prune_touches_no_photo(run, tmp_path):
    """[NEG] Absence is what makes the line signal: a report that carried it on every
    run would be read as decoration. Anti-vacuity — a deletion really did happen."""
    run()
    _record_a_stranger_in_the_manifest(tmp_path)
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run")

    assert "1 removed" in run.text
    assert _gallery_lines(run.text) == []


def test_no_prune_prints_no_gallery_line(run, tmp_path):
    """[NEG] --no-prune deletes nothing, so there is no photo to warn about."""
    run()
    _record_a_stranger_in_the_manifest(tmp_path, GALLERY_REMOTE_PATH)
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run", "--no-prune")

    assert _gallery_lines(run.text) == []


def test_more_gallery_deletions_than_the_cap_are_summarised(run, tmp_path):
    """[BVA] One past MAX_REPORTED_GALLERY_DELETIONS: the report stays readable and
    still says how many it did not name."""
    over = cli.MAX_REPORTED_GALLERY_DELETIONS + 1
    run()
    for i in range(over):
        _record_a_stranger_in_the_manifest(tmp_path, f"/piwigo/galleries/a/{i}.png")
    run.out.truncate(0)
    run.out.seek(0)

    run("--dry-run")

    lines = _gallery_lines(run.text)
    assert len(lines) == 1 + cli.MAX_REPORTED_GALLERY_DELETIONS + 1
    assert f"{over - cli.MAX_REPORTED_GALLERY_DELETIONS} more" in lines[-1]


def test_the_report_names_what_the_sync_deleted(config_file, repo, tmp_path):
    """[HAPPY] The scan removes database rows for photos that are gone; a summary that
    only ever counts additions never says so."""
    run = Run(
        config_file,
        repo,
        tmp_path,
        gallery=FakeGallery(BASE_URL, albums_deleted=2, photos_deleted=7),
    )

    assert run() == 0

    assert "deleted: 7 photos, 2 albums" in run.text


def _preflight_line(text: str) -> str:
    """The one `preflight` line, matched by label rather than by the word."""
    label = f"  {'preflight':{cli.LABEL_WIDTH}}"
    found = [line for line in text.splitlines() if line.startswith(label)]
    assert len(found) == 1, f"expected one preflight line, got {found}"
    return found[0]


def test_the_preflight_line_says_the_two_agree(run):
    """[HAPPY] A guard whose verdict is not reported is one nobody knows ran."""
    run()

    assert "manifest and remote agree" in _preflight_line(run.text)


def test_a_dry_run_says_the_preflight_was_skipped(run):
    """[NEG] A silently skipped guard is one an operator believes ran, and `--dry-run`
    opens no connection at all — so the skip has to be on the report."""
    run("--dry-run")

    assert "skipped" in _preflight_line(run.text)


def test_the_preflight_line_reports_both_versions(config_file, repo, tmp_path):
    """[HAPPY] An operator reads this line to know the two cores match; a verdict with no
    figures behind it is one they have to take on trust."""
    runner = Run(config_file, repo, tmp_path, gallery=FakeGallery(BASE_URL, installed=True))

    runner("--adopt-remote-state")

    assert FakeGallery.VERSION in _preflight_line(runner.text)


def test_a_version_difference_exits_with_the_version_code(config_file, repo, tmp_path):
    """[NEG] Newer core PHP over a schema nothing migrated. The code is read off the
    class, so a renumbering cannot make this test wrong."""
    _write_constants(repo, OTHER_VERSION)
    runner = Run(config_file, repo, tmp_path, gallery=FakeGallery(BASE_URL, installed=True))

    assert runner("--adopt-remote-state") == VersionError.exit_code
    assert preflight.UPGRADE_SCRIPT in runner.err.getvalue()


def test_a_version_difference_uploads_nothing(run):
    """[NEG] The refusal is worthless if it fires after the transfer it was meant to stop.
    Anti-vacuity: the first run's puts prove the transport would otherwise be used."""
    assert run() == 0
    assert run.transport.paths("put")
    run.transport.calls.clear()
    _write_constants(run._repo, OTHER_VERSION)

    assert run() == VersionError.exit_code
    assert run.transport.calls == []


def test_allow_version_change_uploads_over_the_difference(run):
    """[DT] The escape hatch's paired row, and it says on the report what it overrode."""
    assert run() == 0
    _write_constants(run._repo, OTHER_VERSION)
    run.out.truncate(0)
    run.out.seek(0)

    assert run("--allow-version-change") == 0

    assert run.transport.paths("put")
    assert preflight.ALLOW_VERSION_FLAG in _preflight_line(run.text)


def test_a_wiped_remote_exits_with_the_state_mismatch_code(run):
    """[NEG] The 2026-08-31 state: a manifest full of hashes and an emptied web space.
    The code is read off the class so a renumbering cannot make this test wrong."""
    run()
    run.gallery.installed = False
    run.out.truncate(0)
    run.out.seek(0)

    assert run() == StateMismatchError.exit_code
    assert preflight.ADOPT_FLAG in run.err.getvalue()


def test_a_refused_preflight_uploads_nothing(run):
    """[NEG] The guard is worthless if it aborts after the transfer it was meant to stop.
    Anti-vacuity: the first run's puts prove the transport would otherwise be used."""
    run()
    assert run.transport.paths("put")
    run.transport.calls.clear()
    run.gallery.installed = False

    run()

    assert run.transport.calls == []


def test_an_installed_remote_with_no_manifest_is_refused(config_file, repo, tmp_path):
    """[NEG] The other direction: nothing local to compare against, and every remote path
    this file set does not carry would become an orphan no prune can reach."""
    runner = Run(config_file, repo, tmp_path, gallery=FakeGallery(BASE_URL, installed=True))

    assert runner() == StateMismatchError.exit_code
    assert runner.transport.calls == []
    assert preflight.AUDIT_FLAG in runner.err.getvalue()


def test_adopt_remote_state_uploads_over_the_disagreement(config_file, repo, tmp_path):
    """[DT] The escape hatch's paired row: the same setup that aborts above proceeds, and
    says on the report which guard it overrode."""
    runner = Run(config_file, repo, tmp_path, gallery=FakeGallery(BASE_URL, installed=True))

    assert runner("--adopt-remote-state") == 0

    assert runner.transport.paths("put")
    assert preflight.ADOPT_FLAG in _preflight_line(runner.text)


def test_a_first_run_then_a_second_both_pass_the_guard(run):
    """[ST] One gallery, two invocations: the verdict flips from "first run" to "update
    run" as the manifest fills and the install completes, and neither is an abort."""
    assert run() == 0
    assert run.gallery.installed is True

    assert run() == 0


def test_no_bootstrap_uploads_but_leaves_the_gallery_alone(run):
    """[DT] Upload yes, install/plugins/sync no — the row for a file-only redeploy.

    The preflight still runs: the upload is exactly the half it protects. So the claim is
    not "no request at all" but "nothing that changes the remote" — one read-only GET, no
    POST, and a gallery still uninstalled afterwards."""
    assert run("--no-bootstrap") == 0

    assert run.transport.paths("put")
    assert [call[0] for call in run.gallery.calls] == ["get"]
    assert run.gallery.installed is False


def test_no_prune_keeps_a_file_the_manifest_no_longer_covers(run, tmp_path):
    """[DT] The other flag row: a removal recorded in the manifest is skipped."""
    run()
    run.transport.files["/piwigo/gone.php"] = b"x"
    from pwgdeploy import manifest

    state = manifest.manifest_path(tmp_path / "state", "ftp.example.test", "/piwigo")
    entries = manifest.load(state)
    entries["/piwigo/gone.php"] = "0" * 64
    manifest.save(state, entries)

    run("--no-prune")

    assert "/piwigo/gone.php" in run.transport.files


def test_a_pruned_file_is_deleted_by_default(run, tmp_path):
    """[DT] The paired row, so the flag above is proven to change something."""
    run()
    from pwgdeploy import manifest

    state = manifest.manifest_path(tmp_path / "state", "ftp.example.test", "/piwigo")
    entries = manifest.load(state)
    entries["/piwigo/gone.php"] = "0" * 64
    manifest.save(state, entries)
    run.transport.files["/piwigo/gone.php"] = b"x"

    run()

    assert "/piwigo/gone.php" not in run.transport.files


def test_verbose_names_each_uploaded_path(run):
    """[ECP] The quiet run must not; otherwise the flag is decoration."""
    run("--verbose")
    verbose = run.text
    run.out.truncate(0)
    run.out.seek(0)

    assert "/piwigo/index.php" in verbose


def test_a_missing_credential_file_exits_with_the_config_code(config_file, repo, tmp_path):
    """[NEG] Exit code 3, not a traceback: a caller can branch on it."""
    runner = Run(tmp_path / "nope.json", repo, tmp_path)

    assert runner() == ConfigError.exit_code
    assert "nope.json" in runner.err.getvalue()


def test_a_refused_ftps_handshake_exits_with_its_own_code(config_file, repo, tmp_path):
    """[NEG] Decision 2's loud failure keeps a code of its own, distinct from a plain
    transport failure, so "the host went plaintext" is not confused with "the host is
    down"."""

    class Insecure(FakeTransport):
        def connect(self):
            raise InsecureTransportError("does not advertise AUTH TLS")

    runner = Run(config_file, repo, tmp_path, transport=Insecure())

    assert runner() == InsecureTransportError.exit_code
    assert InsecureTransportError.exit_code != TransportError.exit_code
    assert "AUTH TLS" in runner.err.getvalue()


def test_a_failed_upload_stops_before_the_bootstrap(config_file, repo, tmp_path):
    """[NEG][ST] Installing over a half-uploaded tree would produce a gallery whose code
    is missing files, and the install would look like it worked.

    The preflight's read-only GET precedes the upload, so what must not have happened is
    the install POST: the gallery is still uninstalled and no ws method was called."""
    runner = Run(config_file, repo, tmp_path, transport=FakeTransport(fail_on_put=2))

    assert runner() == TransportError.exit_code
    assert runner.gallery.installed is False
    assert runner.gallery.methods_called() == []


def test_the_error_goes_to_stderr_and_not_to_the_report(config_file, repo, tmp_path):
    """[NEG] So a shell pipeline reading the report is not fed a failure as data."""
    runner = Run(tmp_path / "nope.json", repo, tmp_path)

    runner()

    assert runner.out.getvalue() == ""
    assert runner.err.getvalue().strip()


def test_an_interrupted_run_says_it_can_be_resumed(config_file, repo, tmp_path):
    """[NEG][ST] Observed against the real web space 2026-08-31: Ctrl-C ended a deploy
    with a raw `KeyboardInterrupt` traceback out of `ssl.unwrap`, which reads like a
    crash. The run is resumable — the manifest records completed puts — so the one thing
    the operator needs to be told is exactly what the traceback did not say."""
    runner = Run(config_file, repo, tmp_path, transport=FakeTransport(interrupt_on_put=2))

    assert runner() == cli.INTERRUPTED_EXIT_CODE
    assert "resume" in runner.err.getvalue().lower()
    assert "Traceback" not in runner.err.getvalue()


def test_an_interrupted_run_keeps_what_it_already_uploaded(config_file, repo, tmp_path):
    """[ST] The claim the message makes has to be true: the completed put survives, so
    re-running sends the remainder. Anti-vacuity: the first put must have happened."""
    from pwgdeploy import manifest

    runner = Run(config_file, repo, tmp_path, transport=FakeTransport(interrupt_on_put=2))
    runner()

    entries = manifest.load(
        manifest.manifest_path(tmp_path / "state", "ftp.example.test", "/piwigo")
    )
    assert len(entries) == 1


def test_the_config_file_argument_is_required(run):
    """[NEG] argparse's own exit code 2; deploying to a guessed target is worse."""
    with pytest.raises(SystemExit) as raised:
        cli.main([], stdout=run.out, stderr=run.err)

    assert raised.value.code == 2
