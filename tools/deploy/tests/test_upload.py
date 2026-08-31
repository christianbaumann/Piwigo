"""One transfer: enumerate, hash, diff, create, upload, prune, chmod.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ST] [DT] [ERR].
Decision table not applicable here — the four-way new/changed/unchanged/removed table is
`manifest.diff`'s, and is exercised in tests/test_manifest.py rather than restated.

Everything runs against `FakeTransport`; what these tests cannot witness is FTPS itself,
which is what tests/test_smoke.py's procedure covers against a real server.
"""

import pytest

from pwgdeploy import manifest, upload
from pwgdeploy.config import load
from pwgdeploy.errors import TransportError
from pwgdeploy.fileset import REMOTE_DIRS_TO_CREATE, WRITABLE_REMOTE_PATHS
from tests.fakes import FakeTransport

# Anti-vacuity: a run over an empty file set would satisfy almost every assertion below,
# so each test that counts uploads asserts the fixture actually carries files first.
MIN_FIXTURE_FILES = 3

RAW = {
    "ftp": {"host": "ftp.example.test", "user": "w1", "password": "p", "remote_root": "/piwigo"},
    "mysql": {"host": "localhost", "user": "d1", "password": "p", "database": "d1"},
    "admin": {"username": "webmaster", "password": "p", "email": "you@example.net"},
    "site": {"base_url": "https://g.example.test"},
}

TRACKED = ("index.php", "include/common.inc.php", "themes/modus/theme.css")


@pytest.fixture
def config():
    return load(RAW)


@pytest.fixture
def repo(tmp_path):
    root = tmp_path / "repo"
    for rel in TRACKED:
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f"contents of {rel}\n", encoding="utf-8")
    return root


@pytest.fixture
def state(tmp_path):
    return tmp_path / "state"


def tracked_is(*paths):
    """The enumeration port, stubbed: these tests are about the run, not about git."""
    return lambda repo_root: list(paths)


def deploy(config, repo, state, transport, **kwargs):
    kwargs.setdefault("tracked", tracked_is(*TRACKED))
    return upload.run(config, repo, state, transport, **kwargs)


def stored_manifest(config, state):
    return manifest.load(
        manifest.manifest_path(state, config.ftp.host, config.ftp.remote_root)
    )


# --- what a first run does ----------------------------------------------------------


def test_first_run_uploads_every_file(config, repo, state):
    """[HAPPY]"""
    transport = FakeTransport()

    result = deploy(config, repo, state, transport)

    assert len(TRACKED) >= MIN_FIXTURE_FILES
    assert len(result.uploaded) == len(TRACKED)
    assert sorted(transport.paths("put")) == sorted(f"/piwigo/{rel}" for rel in TRACKED)
    assert transport.files["/piwigo/index.php"] == b"contents of index.php\n"


def test_the_manifest_records_what_was_uploaded(config, repo, state):
    """[HAPPY] [ST]"""
    deploy(config, repo, state, FakeTransport())

    entries = stored_manifest(config, state)

    assert len(entries) == len(TRACKED)
    assert entries["/piwigo/index.php"] == manifest.file_hash(repo / "index.php")


def test_the_session_is_opened_and_closed(config, repo, state):
    """[ST]"""
    transport = FakeTransport()

    deploy(config, repo, state, transport)

    assert transport.names()[0] == "connect"
    assert transport.names()[-1] == "close"


def test_the_session_is_closed_when_a_put_fails(config, repo, state):
    """The manifest is only useful if the connection is released too. [NEG]"""
    transport = FakeTransport(fail_on_put=1)

    with pytest.raises(TransportError):
        deploy(config, repo, state, transport)

    assert transport.closed


# --- second run ---------------------------------------------------------------------


def test_second_run_uploads_nothing(config, repo, state):
    """[ST]"""
    first = deploy(config, repo, state, FakeTransport())
    assert len(first.uploaded) >= MIN_FIXTURE_FILES

    second = deploy(config, repo, state, FakeTransport())

    assert second.uploaded == []
    assert second.unchanged_count == len(first.uploaded)


def test_changed_file_is_re_uploaded_alone(config, repo, state):
    """[ST]"""
    deploy(config, repo, state, FakeTransport())
    (repo / "index.php").write_text("edited\n", encoding="utf-8")

    result = deploy(config, repo, state, FakeTransport())

    assert result.uploaded == ["/piwigo/index.php"]
    assert result.unchanged_count == len(TRACKED) - 1


def test_a_new_file_is_uploaded_alone(config, repo, state):
    """[ST] [ECP] — the other half of the changed/new partition."""
    deploy(config, repo, state, FakeTransport())
    (repo / "extra.php").write_text("new\n", encoding="utf-8")

    result = deploy(
        config, repo, state, FakeTransport(), tracked=tracked_is(*TRACKED, "extra.php")
    )

    assert result.uploaded == ["/piwigo/extra.php"]


def test_a_manifest_of_another_target_is_not_reused(config, repo, state):
    """Two remote roots are two targets, so the second one starts empty. [ECP]"""
    deploy(config, repo, state, FakeTransport())
    other = load({**RAW, "ftp": {**RAW["ftp"], "remote_root": "/staging"}})

    result = deploy(other, repo, state, FakeTransport())

    assert len(result.uploaded) == len(TRACKED)


# --- dry run ------------------------------------------------------------------------


def test_dry_run_never_touches_the_transport(config, repo, state):
    """[NEG]"""
    transport = FakeTransport()

    result = deploy(config, repo, state, transport, dry_run=True)

    assert transport.calls == []
    assert result.uploaded == []
    assert len(result.diff.pending) == len(TRACKED)


def test_dry_run_writes_no_manifest(config, repo, state):
    """A dry run that recorded state would make the next real run skip everything. [NEG]"""
    deploy(config, repo, state, FakeTransport(), dry_run=True)

    assert stored_manifest(config, state) == {}


# --- crash safety -------------------------------------------------------------------


def test_manifest_persists_after_each_file(config, repo, state):
    """Armed to fail on put #3, the manifest holds exactly the first two paths. [ERR]

    Characterization of the crash-safety contract: the oracle is the design decision that
    the manifest records what was *uploaded*, not what was *intended*.
    """
    transport = FakeTransport(fail_on_put=3)

    with pytest.raises(TransportError):
        deploy(config, repo, state, transport)

    entries = stored_manifest(config, state)
    assert len(entries) == 2
    assert sorted(entries) == sorted(transport.paths("put"))


def test_resume_after_failure_uploads_only_the_remainder(config, repo, state):
    """[ST]"""
    with pytest.raises(TransportError):
        deploy(config, repo, state, FakeTransport(fail_on_put=3))

    result = deploy(config, repo, state, FakeTransport())

    assert len(result.uploaded) == len(TRACKED) - 2
    assert result.unchanged_count == 2


def test_ctrl_c_mid_run_resumes_rather_than_restarts(config, repo, state):
    """The Ctrl-C half of Phase 5's manual criterion, at the layer that can express it.

    `KeyboardInterrupt` is a `BaseException`, so it reaches the run's `finally` by a
    different route than an ordinary `TransportError` — a `except Exception` anywhere on
    that path would swallow it and report a successful deploy. [ST] [NEG]
    """
    with pytest.raises(KeyboardInterrupt):
        deploy(config, repo, state, FakeTransport(interrupt_on_put=3))

    assert len(stored_manifest(config, state)) == 2

    resumed = FakeTransport()
    result = deploy(config, repo, state, resumed)

    assert len(result.uploaded) == len(TRACKED) - 2
    assert result.unchanged_count == 2
    assert resumed.closed


def test_ctrl_c_still_closes_the_session(config, repo, state):
    """An interrupted run must not leave the FTPS control connection open. [NEG]"""
    transport = FakeTransport(interrupt_on_put=1)

    with pytest.raises(KeyboardInterrupt):
        deploy(config, repo, state, transport)

    assert transport.closed


def test_a_failed_put_leaves_the_previous_hash_in_place(config, repo, state):
    """So the next run still sees the edited file as pending. [ST] [NEG]"""
    deploy(config, repo, state, FakeTransport())
    before = stored_manifest(config, state)["/piwigo/index.php"]
    (repo / "index.php").write_text("edited\n", encoding="utf-8")

    with pytest.raises(TransportError):
        deploy(config, repo, state, FakeTransport(fail_on_put=1))

    assert stored_manifest(config, state)["/piwigo/index.php"] == before


# --- prune --------------------------------------------------------------------------


def test_prune_deletes_only_previously_recorded_paths(config, repo, state):
    """[NEG]"""
    transport = FakeTransport()
    deploy(config, repo, state, transport)
    stranger = "/piwigo/upload/2026/photo.jpg"
    transport.files[stranger] = b"uploaded by the gallery itself"

    result = deploy(config, repo, state, transport, tracked=tracked_is(*TRACKED[1:]))

    assert result.deleted == ["/piwigo/index.php"]
    assert stranger in transport.files
    assert "/piwigo/index.php" not in transport.files


def test_a_pruned_path_leaves_the_manifest(config, repo, state):
    """[ST]"""
    deploy(config, repo, state, FakeTransport())

    deploy(config, repo, state, FakeTransport(), tracked=tracked_is(*TRACKED[1:]))

    assert "/piwigo/index.php" not in stored_manifest(config, state)


def test_no_prune_flag_deletes_nothing(config, repo, state):
    """[NEG]"""
    transport = FakeTransport()
    deploy(config, repo, state, transport)

    result = deploy(
        config, repo, state, transport, prune=False, tracked=tracked_is(*TRACKED[1:])
    )

    assert result.deleted == []
    assert transport.paths("delete") == []
    assert "/piwigo/index.php" in transport.files


def test_no_prune_keeps_the_path_in_the_manifest(config, repo, state):
    """It is still on the server, so forgetting it would make it unprunable later. [ST]"""
    deploy(config, repo, state, FakeTransport())

    deploy(
        config, repo, state, FakeTransport(), prune=False, tracked=tracked_is(*TRACKED[1:])
    )

    assert "/piwigo/index.php" in stored_manifest(config, state)


# --- directories and permissions ----------------------------------------------------


def test_creates_the_data_and_upload_directories(config, repo, state):
    """[HAPPY]"""
    transport = FakeTransport()

    result = deploy(config, repo, state, transport)

    assert len(REMOTE_DIRS_TO_CREATE) > 0
    for name in REMOTE_DIRS_TO_CREATE:
        assert f"/piwigo/{name}" in result.dirs_created


def test_parent_directories_are_created_before_their_files(config, repo, state):
    """[ST]"""
    transport = FakeTransport()

    deploy(config, repo, state, transport)

    order = [call for call in transport.calls if call[0] in ("makedirs", "put")]
    made = [path for kind, path in order if kind == "makedirs"]
    assert "/piwigo/themes/modus" in made
    for index, (kind, path) in enumerate(order):
        if kind == "put" and "/" in path[len("/piwigo/") :]:
            assert path.rsplit("/", 1)[0] in [
                made_path for made_kind, made_path in order[:index] if made_kind == "makedirs"
            ]


def test_a_directory_is_created_once_however_many_files_it_holds(config, repo, state):
    """[ECP]"""
    (repo / "themes/modus/second.css").write_text("x\n", encoding="utf-8")
    transport = FakeTransport()

    deploy(
        config,
        repo,
        state,
        transport,
        tracked=tracked_is(*TRACKED, "themes/modus/second.css"),
    )

    assert transport.paths("makedirs").count("/piwigo/themes/modus") == 1


def test_chmod_is_asked_for_each_writable_path(config, repo, state):
    """[HAPPY]"""
    transport = FakeTransport()

    result = deploy(config, repo, state, transport)

    assert len(WRITABLE_REMOTE_PATHS) > 0
    assert transport.paths("chmod") == [f"/piwigo/{name}" for name in WRITABLE_REMOTE_PATHS]
    assert result.chmod_supported is True


def test_chmod_refusal_is_a_warning_not_a_failure(config, repo, state):
    """A server without SITE CHMOD still gets a complete deploy. [NEG]"""
    transport = FakeTransport(chmod_supported=False)

    result = deploy(config, repo, state, transport)

    assert result.chmod_supported is False
    assert len(result.uploaded) == len(TRACKED)
    # Every path is still asked for, so the warning can name all of them: a short-
    # circuiting `all()` would stop at the first refusal and under-report the damage.
    assert transport.paths("chmod") == [f"/piwigo/{name}" for name in WRITABLE_REMOTE_PATHS]


# --- the empty remote root ----------------------------------------------------------


def test_an_empty_remote_root_uploads_beside_the_login_directory(config, repo, state):
    """[ECP] [BVA]"""
    rooted_at_login = load({**RAW, "ftp": {**RAW["ftp"], "remote_root": ""}})
    transport = FakeTransport()

    deploy(rooted_at_login, repo, state, transport)

    assert "index.php" in transport.paths("put")
    assert "themes/modus" in transport.paths("makedirs")


# --- progress -----------------------------------------------------------------------


def test_progress_is_reported_once_per_uploaded_file(config, repo, state):
    """[HAPPY]"""
    seen = []

    deploy(config, repo, state, FakeTransport(), progress=lambda *call: seen.append(call))

    assert len(seen) == len(TRACKED)
    assert seen[-1] == ("/piwigo/themes/modus/theme.css", len(TRACKED), len(TRACKED))


# --- the file set is filtered, not taken raw ----------------------------------------


def test_excluded_paths_are_never_uploaded(config, repo, state):
    """`select()` runs inside the deploy, not only in the CLI. [NEG]"""
    (repo / "docs").mkdir()
    (repo / "docs/secret.md").write_text("not for the web\n", encoding="utf-8")
    transport = FakeTransport()

    deploy(config, repo, state, transport, tracked=tracked_is(*TRACKED, "docs/secret.md"))

    assert transport.paths("put") != []
    assert "/piwigo/docs/secret.md" not in transport.paths("put")
