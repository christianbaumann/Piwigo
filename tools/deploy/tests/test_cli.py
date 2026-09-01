"""The command: flags, the report it prints, and the exit code each failure maps to.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [ST] [DT].
Boundary values not applicable: the CLI has no numeric domain of its own — the counts it
prints come from `manifest.diff`, whose boundaries are tested in tests/test_manifest.py.

Both adapters are injected, so every case below runs with no socket. What these tests
cannot witness is the two real factories they replace; those are the phase's manual step.
"""

import io

import pytest

from pwgdeploy import cli
from pwgdeploy.errors import ConfigError, InsecureTransportError, TransportError
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


@pytest.fixture
def config_file(tmp_path):
    import json

    path = tmp_path / "deploy.local.json"
    path.write_text(json.dumps(RAW), encoding="utf-8")
    return path


@pytest.fixture
def repo(tmp_path):
    root = tmp_path / "repo"
    for rel in TRACKED:
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f"contents of {rel}\n", encoding="utf-8")
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

    for label in ("transport", "file set", "manifest", "upload", "chmod", "install", "config", "plugins", "sync"):
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


def test_no_bootstrap_uploads_but_leaves_the_gallery_alone(run):
    """[DT] Upload yes, install/plugins/sync no — the row for a file-only redeploy."""
    assert run("--no-bootstrap") == 0

    assert run.transport.paths("put")
    assert run.gallery.calls == []


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
    is missing files, and the install would look like it worked."""
    runner = Run(config_file, repo, tmp_path, transport=FakeTransport(fail_on_put=2))

    assert runner() == TransportError.exit_code
    assert runner.gallery.calls == []


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
