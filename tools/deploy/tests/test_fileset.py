"""The file set: what a deploy publishes and what it must never publish.

Techniques per .claude/rules/test-design.md: [HAPPY] [NEG] [ECP] [BVA] [ERR].
Boundary analysis has no arithmetic threshold to work on here, so [BVA] is applied to
the edges of the *matching rules* instead: a basename that merely contains an excluded
word, and a path under an excluded prefix that is nonetheless published.
Decision table not applicable: the rules are a disjunction, not a matrix — one hit
excludes, and no combination changes the outcome.
"""

import subprocess
from pathlib import Path

import pytest

from pwgdeploy import fileset
from pwgdeploy.errors import GitError

REPO_ROOT = Path(__file__).resolve().parents[3]

# Anti-vacuity floors for the characterization test below. This checkout tracked 3376
# paths, measured 2026-08-31; the floors sit far enough under that to survive ordinary
# growth and deletion while still failing loudly on an empty or truncated enumeration.
MIN_TRACKED_PATHS = 3000
MIN_SELECTED_PATHS = 2500

# plugins/typetags held 157 tracked files, measured 2026-08-31.
MIN_SUBMODULE_PATHS = 100

# 106 recovered scans under galleries/, measured 2026-09-01. The floor sits well under
# that so deleting a handful does not go red, while an empty result — a classifier that
# matches nothing the real file set holds — still fails loudly.
MIN_GALLERY_PHOTOS = 50

# The payload a deploy actually pushes: 128.4 MiB (134.7 MB decimal, which is the figure
# the plan's Phase 2 records) over 3307 selected paths, measured
# 2026-08-31 with the typetags submodule checked out. The band is wide on purpose — a
# figure this test pinned exactly would go red on the next photo added to galleries/ —
# but a file set that shrank to a third of it is the silent-partial-deploy failure the
# path-count floor above cannot see: dropping galleries/'s 105 PNGs costs a fraction of
# the paths and most of the bytes.
MIN_SELECTED_BYTES = 80 * 1024 * 1024
MAX_SELECTED_BYTES = 400 * 1024 * 1024


class FakeRun:
    """Stands in for subprocess.run: records the command, replays a canned result."""

    def __init__(self, stdout: bytes = b"", returncode: int = 0, stderr: bytes = b""):
        self.stdout = stdout
        self.returncode = returncode
        self.stderr = stderr
        self.commands: list[list[str]] = []

    def __call__(self, command, **kwargs):
        self.commands.append(list(command))
        return subprocess.CompletedProcess(
            command, self.returncode, self.stdout, self.stderr
        )


def nul(*paths: str) -> bytes:
    """git ls-files -z output: NUL-terminated, not NUL-separated."""
    return b"".join(p.encode("utf-8") + b"\0" for p in paths)


# --- the exclusion filter --------------------------------------------------


def test_keeps_an_ordinary_plugin_file():
    """[HAPPY] The common case: an application file is published untouched."""
    assert fileset.select(["plugins/persons/main.inc.php"]) == [
        "plugins/persons/main.inc.php"
    ]


def test_drops_a_tests_segment_anywhere_in_the_path():
    """[ECP] `tests` is excluded at any depth, not only directly under a plugin."""
    dropped = [
        "plugins/persons/tests/Support/FixtureBuilder.php",
        "tests/whatever.php",
        "plugins/typetags/tests/e2e/support/PicturePage.js",
    ]
    assert fileset.select(dropped) == []


def test_keeps_a_file_named_tests_js():
    """[BVA] `tests` matches a path *segment*; a basename that contains it survives."""
    kept = ["themes/modus/js/tests.js", "admin/themes/default/js/mytests/x.js"]
    assert fileset.select(kept) == kept


@pytest.mark.parametrize("basename", fileset.EXCLUDED_BASENAMES)
def test_drops_each_excluded_basename(basename):
    """[ECP] One class per dev-tooling basename, wherever in the tree it sits."""
    assert fileset.select([f"plugins/provenance/{basename}"]) == []


@pytest.mark.parametrize("basename", fileset.EXCLUDED_BASENAMES)
def test_an_excluded_basename_is_not_matched_as_a_substring(basename):
    """[BVA] Only the exact basename goes; a longer name that contains it stays."""
    kept = f"plugins/provenance/not-a-{basename}"
    assert fileset.select([kept]) == [kept]


@pytest.mark.parametrize("prefix", fileset.EXCLUDED_PREFIXES)
def test_drops_each_excluded_prefix(prefix):
    """[ECP] One class per excluded prefix; a bare filename rule is its own class."""
    path = prefix if not prefix.endswith("/") else f"{prefix}some/file.txt"
    assert fileset.select([path]) == []


def test_keeps_local_index_php_guards():
    """[BVA] local/config/ is excluded, yet its directory-listing guard is published:
    without it the remote serves an index of the directory holding the DB credentials."""
    assert fileset.select(["local/config/database.inc.php"]) == []
    assert fileset.select(["local/config/index.php"]) == ["local/config/index.php"]
    assert fileset.select(["local/index.php"]) == ["local/index.php"]


def test_excludes_the_deploy_tool_itself():
    """[NEG] The tool has no business on the web space."""
    assert fileset.select(["tools/deploy/pwgdeploy/config.py"]) == []


def test_excludes_the_local_dev_environment():
    """[NEG] .ddev/ describes a Docker stack that exists only on a developer's machine.
    Nothing serves it, and publishing it puts the local setup on a public host."""
    assert fileset.select([".ddev/config.yaml"]) == []
    assert fileset.select([".ddev/web-build/Dockerfile.playwright"]) == []


def test_excludes_the_whole_tools_directory():
    """[ECP] Successor to test_excludes_the_fork_s_own_hook_scripts_but_keeps_upstream_tools,
    which asserted the opposite for everything but the two hook scripts. Core loads
    nothing from tools/ at runtime — its only two mentions in core are comments
    (include/config_default.inc.php:18,353) — so publishing it puts dev and maintenance
    PHP on a public host for no benefit. See
    docs/agents/decisions/0022-the-tools-directory-is-not-published.md."""
    assert fileset.select(["tools/install-hooks.sh"]) == []
    assert fileset.select(["tools/test-hooks.sh"]) == []
    assert fileset.select(["tools/piwigo_remote.pl"]) == []
    assert fileset.select(["tools/index.php"]) == []
    assert fileset.select(["tools/pwg_rel_create.sh"]) == []


def test_a_toolsish_path_outside_the_directory_is_kept():
    """[BVA] tools/ is a path prefix, not a substring: the exclusion must not reach a
    sibling directory whose name merely starts the same way."""
    assert fileset.select(["toolstrap/main.php"]) == ["toolstrap/main.php"]
    assert fileset.select(["plugins/persons/tools/helper.php"]) == [
        "plugins/persons/tools/helper.php"
    ]


def test_the_handbook_ships_with_the_application():
    """[HAPPY] The pages, stylesheet and screenshots are ordinary application content."""
    kept = [
        "handbuch/index.html",
        "handbuch/01-alben.html",
        "handbuch/assets/handbuch.css",
        "handbuch/assets/screenshots/01-alben-verwaltung.png",
    ]
    assert fileset.select(kept) == kept


def test_the_handbook_toolchain_stays_off_the_web_space():
    """[NEG] The generator/checker scripts need DDEV, php exec and Node — dev-only,
    same posture as the top-level tools/ directory (decision 0022)."""
    assert fileset.select([
        "handbuch/tools/seed.php",
        "handbuch/tools/shoot.js",
        "handbuch/tools/check.php",
    ]) == []


def test_a_handbuch_toolsish_path_outside_the_tools_directory_is_kept():
    """[BVA] handbuch/tools/ is a path prefix, not a substring: a sibling file whose
    name merely starts the same way survives, mirroring
    test_a_toolsish_path_outside_the_directory_is_kept for the top-level tools/ rule."""
    assert fileset.select(["handbuch/toolshed.html"]) == ["handbuch/toolshed.html"]


def test_the_generated_config_is_not_part_of_the_tracked_file_set():
    """[ST] It is produced by the bootstrap, never enumerated by git — which is exactly
    why prune has to be told about it; see tests/test_upload.py."""
    assert fileset.GENERATED_CONFIG_PATH not in fileset.select(
        [fileset.GENERATED_CONFIG_PATH]
    )


def test_select_preserves_input_order_and_drops_nothing_else():
    """[HAPPY] Anti-vacuity for every parametrised case above: a filter that returned
    [] for everything would satisfy each `== []` assertion on its own."""
    paths = ["index.php", "plugins/persons/tests/x.php", "admin/site_update.php"]
    assert fileset.select(paths) == ["index.php", "admin/site_update.php"]


# --- the git adapter -------------------------------------------------------


def test_git_tracked_paths_runs_recurse_submodules():
    """[HAPPY] decision 7: a plain ls-files reports one gitlink, not the submodule's files."""
    run = FakeRun(stdout=nul("index.php", "plugins/typetags/main.inc.php"))
    assert fileset.git_tracked_paths(REPO_ROOT, run=run) == [
        "index.php",
        "plugins/typetags/main.inc.php",
    ]
    assert run.commands == [["git", "ls-files", "-z", "--recurse-submodules"]]


def test_empty_git_output_raises_git_error():
    """[NEG] An empty file set must fail loudly; deploying nothing is not an outcome."""
    with pytest.raises(GitError) as exc:
        fileset.git_tracked_paths(REPO_ROOT, run=FakeRun(stdout=b""))
    assert "ls-files" in str(exc.value)


def test_git_failure_names_the_command():
    """[NEG] The message must be actionable without re-running anything by hand."""
    run = FakeRun(returncode=128, stderr=b"fatal: not a git repository")
    with pytest.raises(GitError) as exc:
        fileset.git_tracked_paths(REPO_ROOT, run=run)
    message = str(exc.value)
    assert "git ls-files -z --recurse-submodules" in message
    assert "not a git repository" in message


# --- the completeness guard ------------------------------------------------


def enough_paths(count: int = fileset.MIN_EXPECTED_PATHS) -> list[str]:
    return [f"core/file{i}.php" for i in range(count)]


def test_check_complete_accepts_a_whole_enumeration():
    """[HAPPY] Anti-vacuity for every rejection case below: a guard that raised
    unconditionally would satisfy all of them."""
    paths = enough_paths() + ["plugins/typetags/main.inc.php"]
    fileset.check_complete(paths, ["plugins/typetags"])


def test_check_complete_accepts_a_repository_with_no_submodules():
    """[ECP] No submodule declared is not an error."""
    fileset.check_complete(enough_paths(), [])


@pytest.mark.parametrize(
    "count, rejected",
    [
        (fileset.MIN_EXPECTED_PATHS - 1, True),
        (fileset.MIN_EXPECTED_PATHS, False),
        (fileset.MIN_EXPECTED_PATHS + 1, False),
    ],
)
def test_check_complete_path_floor(count, rejected):
    """[BVA] The floor is inclusive: exactly MIN_EXPECTED_PATHS is whole enough."""
    if rejected:
        with pytest.raises(GitError) as exc:
            fileset.check_complete(enough_paths(count), [])
        assert str(count) in str(exc.value)
        assert str(fileset.MIN_EXPECTED_PATHS) in str(exc.value)
    else:
        fileset.check_complete(enough_paths(count), [])


def test_check_complete_rejects_an_uninitialised_submodule():
    """[NEG] The failure this guard exists for: a plausible total, a missing plugin.
    The message must name the submodule and the command that fixes it, because the
    enumeration itself gives no hint that anything is missing."""
    paths = enough_paths()
    with pytest.raises(GitError) as exc:
        fileset.check_complete(paths, ["plugins/typetags"])
    message = str(exc.value)
    assert "plugins/typetags" in message
    assert fileset.SUBMODULE_INIT_HINT in message


def test_check_complete_is_not_fooled_by_a_prefix_match():
    """[BVA] A sibling path that merely starts with the submodule's name does not count
    as the submodule having contributed."""
    paths = enough_paths() + ["plugins/typetags-backup/main.inc.php"]
    with pytest.raises(GitError):
        fileset.check_complete(paths, ["plugins/typetags"])


def test_declared_submodule_paths_parses_git_config():
    """[HAPPY] .gitmodules is the source of truth; the path is never hardcoded here."""
    run = FakeRun(stdout=b"submodule.plugins/typetags.path plugins/typetags\n")
    assert fileset.declared_submodule_paths(REPO_ROOT, run=run) == ["plugins/typetags"]
    assert run.commands == [list(fileset.GIT_SUBMODULE_PATHS)]


def test_declared_submodule_paths_is_empty_without_gitmodules():
    """[NEG] git config exits 1 when nothing matches; that is not a failure."""
    run = FakeRun(returncode=fileset.GIT_CONFIG_NO_MATCH)
    assert fileset.declared_submodule_paths(REPO_ROOT, run=run) == []


class ScriptedRun:
    """Replays a different canned result per git subcommand (`ls-files`, `config`)."""

    def __init__(self, results: dict):
        self.results = results
        self.commands: list[list[str]] = []

    def __call__(self, command, **kwargs):
        self.commands.append(list(command))
        stdout, returncode = self.results[command[1]]
        return subprocess.CompletedProcess(command, returncode, stdout, b"")


def test_verified_tracked_paths_refuses_an_incomplete_working_copy():
    """[NEG] The guard must be *wired into* the deploy entry point, not merely exist.
    Independent of this working copy, so it holds whether or not the submodule is
    checked out here — which is exactly what the skipped characterization cannot do."""
    run = ScriptedRun(
        {
            "ls-files": (nul(*enough_paths()), 0),
            "config": (b"submodule.plugins/typetags.path plugins/typetags\n", 0),
        }
    )
    with pytest.raises(GitError) as exc:
        fileset.verified_tracked_paths(REPO_ROOT, run=run)
    assert "plugins/typetags" in str(exc.value)


def test_verified_tracked_paths_returns_a_whole_enumeration():
    """[HAPPY] Anti-vacuity for the case above: the same call succeeds once the
    submodule has contributed, so the refusal is discriminating."""
    paths = enough_paths() + ["plugins/typetags/main.inc.php"]
    run = ScriptedRun(
        {
            "ls-files": (nul(*paths), 0),
            "config": (b"submodule.plugins/typetags.path plugins/typetags\n", 0),
        }
    )
    assert fileset.verified_tracked_paths(REPO_ROOT, run=run) == paths


def test_declared_submodule_paths_raises_on_a_real_git_failure():
    """[NEG] Any other non-zero exit is a genuine error and must not read as 'none'."""
    run = FakeRun(returncode=128, stderr=b"fatal: bad config")
    with pytest.raises(GitError) as exc:
        fileset.declared_submodule_paths(REPO_ROOT, run=run)
    assert "bad config" in str(exc.value)


# --- the photos a prune would reach ----------------------------------------


def test_gallery_paths_finds_a_tracked_photo():
    """[HAPPY] A scan under galleries/ exists nowhere but this working copy and the
    server, so a prune that reaches one is worth naming rather than counting."""
    assert fileset.gallery_paths("", ["galleries/PHOTO_ALBUM/img_0421.png"]) == [
        "galleries/PHOTO_ALBUM/img_0421.png"
    ]


def test_gallery_paths_ignores_a_core_file():
    """[NEG] Everything else the prune deletes is replaceable from this checkout."""
    assert fileset.gallery_paths("", ["index.php", "themes/modus/theme.css"]) == []


def test_gallery_paths_respects_a_nested_remote_root():
    """[BVA] These are remote paths, so the root is part of them: a photo under a
    different root on the same server was not published by this target."""
    assert fileset.gallery_paths("/piwigo", ["/piwigo/galleries/a/x.png"]) == [
        "/piwigo/galleries/a/x.png"
    ]
    assert fileset.gallery_paths("/piwigo", ["/other/galleries/a/x.png"]) == []


def test_gallery_paths_of_an_empty_list_is_empty():
    """[BVA] A prune that removed nothing has nothing to name."""
    assert fileset.gallery_paths("", []) == []


def test_a_path_merely_containing_galleries_is_not_a_photo():
    """[ERR] galleries/ is a path prefix, not a substring — the same boundary
    test_a_toolsish_path_outside_the_directory_is_kept guards for tools/."""
    assert fileset.gallery_paths("", ["plugins/galleriesx/a.php"]) == []
    assert fileset.gallery_paths("", ["galleriesx/a.png"]) == []


def test_gallery_paths_keeps_the_order_it_was_given():
    """[HAPPY] Anti-vacuity for every `== []` above: a classifier that returned nothing
    at all would satisfy each of them."""
    paths = ["index.php", "galleries/b/2.png", "galleries/a/1.png"]
    assert fileset.gallery_paths("", paths) == [
        "galleries/b/2.png",
        "galleries/a/1.png",
    ]


# --- always-created directories --------------------------------------------


def test_the_created_directories_are_the_two_the_release_script_creates():
    """[HAPPY] Structural guard against tools/pwg_rel_create.sh:123-127 drifting apart."""
    assert fileset.REMOTE_DIRS_TO_CREATE == ("_data", "upload")
    assert set(fileset.REMOTE_DIRS_TO_CREATE) <= set(fileset.WRITABLE_REMOTE_PATHS)


# --- characterization against the real checkout ----------------------------


def test_real_repository_file_set():
    """[ERR] Characterization: its oracle is this checkout's contents, not a requirement.
    It records that the filter, run over the real tracked list, keeps the application
    and drops the dev tooling."""
    tracked = fileset.git_tracked_paths(REPO_ROOT)
    assert len(tracked) > MIN_TRACKED_PATHS, (
        f"only {len(tracked)} tracked paths — the enumeration is broken, "
        "and every assertion below would pass vacuously"
    )

    selected = fileset.select(tracked)
    assert len(selected) > MIN_SELECTED_PATHS, f"only {len(selected)} paths survived"
    assert len(selected) < len(tracked), "the filter excluded nothing at all"

    assert "plugins/persons/main.inc.php" in selected
    assert "plugins/persons/tests/Support/create-test-users.php" in tracked
    assert "plugins/persons/tests/Support/create-test-users.php" not in selected

    # Not a bare "vendor/" match: themes/default/vendor/fontello is a *tracked core
    # asset* — the gallery's icon font — and must ship. The dev-tooling vendor
    # directories are each plugin's own composer/npm output, which that plugin's
    # .gitignore already keeps out of the tracked list.
    for unwanted in (
        "plugins/persons/vendor/",
        "plugins/provenance/vendor/",
        "plugins/typetags/vendor/",
        "node_modules/",
        "/tests/",
        ".playwright-browsers/",
    ):
        offenders = [p for p in selected if unwanted in p]
        assert not offenders, f"{unwanted} reached the file set: {offenders[:5]}"

    # Anti-vacuity for the loop above: the core asset that a bare "vendor/" rule would
    # have wrongly excluded is present, so the loop is discriminating, not empty.
    assert any(p.startswith("themes/default/vendor/fontello/") for p in selected)

    # A prefix match, not a substring one: plugins/*/tools/ would survive a rule this
    # loop cannot express (decision 0022).
    assert any(p.startswith("tools/") for p in tracked), "tools/ is tracked here"
    assert not [p for p in selected if p.startswith("tools/")]

    assert "handbuch/index.html" in selected
    assert "handbuch/tools/seed.php" in tracked
    assert "handbuch/tools/seed.php" not in selected


def test_the_selected_file_set_weighs_what_a_deploy_expects():
    """[ERR] Characterization of this checkout's payload, and the automated half of the
    plan's "the byte total matches ~138 MB" manual criterion. The other half — that a
    real deploy of those bytes completes — needs credentials and stays manual."""
    selected = fileset.select(fileset.git_tracked_paths(REPO_ROOT))
    assert len(selected) > MIN_SELECTED_PATHS, "nothing to weigh"

    total = fileset.total_bytes(REPO_ROOT, selected)

    assert MIN_SELECTED_BYTES < total < MAX_SELECTED_BYTES, (
        f"the file set weighs {total / 1024 / 1024:.1f} MB, outside the band measured "
        "2026-08-31 — either the exclusion rules moved or the working copy is partial"
    )


def test_the_real_file_set_publishes_tracked_gallery_photos():
    """[ERR] Characterization, and the automated half of the plan's Phase 2 manual step:
    `git rm --cached` a scan, dry-run, see it named. Every other gallery_paths test runs
    over synthetic paths, so none of them would notice the classifier and the real file
    set drifting apart — a `.gitignore` rewrite dropping the four `!` re-includes, or a
    rename of the galleries/ prefix, would leave them all green while the report line
    silently stopped firing against this checkout."""
    selected = fileset.select(fileset.git_tracked_paths(REPO_ROOT))
    assert len(selected) > MIN_SELECTED_PATHS, "nothing to classify"

    photos = fileset.gallery_paths("", selected)

    assert len(photos) > MIN_GALLERY_PHOTOS, (
        f"only {len(photos)} published paths are tracked photos — either the scans left "
        "the file set or GALLERY_PREFIX no longer matches them, and the prune's one "
        "irreplaceable-deletion warning would never fire"
    )
    assert len(photos) < len(selected), "the classifier matched the whole file set"


def test_total_bytes_of_nothing_is_zero():
    """[BVA] The empty file set weighs nothing rather than raising."""
    assert fileset.total_bytes(REPO_ROOT, []) == 0


def test_total_bytes_sums_the_files_it_is_given(tmp_path):
    """[HAPPY] Two files of known size, so the sum has an oracle outside this checkout."""
    (tmp_path / "a.txt").write_bytes(b"x" * 10)
    (tmp_path / "sub").mkdir()
    (tmp_path / "sub/b.txt").write_bytes(b"y" * 25)

    assert fileset.total_bytes(tmp_path, ["a.txt", "sub/b.txt"]) == 35


def test_every_declared_submodule_contributes_its_files():
    """[ERR] Characterization against this checkout: `--recurse-submodules` really does
    reach into each submodule. Skips rather than fails where the submodule is not
    checked out — that state is a working-copy fact, and `check_complete` is what turns
    it into a loud failure at deploy time."""
    submodules = fileset.declared_submodule_paths(REPO_ROOT)
    assert submodules, ".gitmodules declares no submodule — decision 7 no longer holds"

    tracked = fileset.git_tracked_paths(REPO_ROOT)
    for submodule in submodules:
        prefix = submodule + "/"
        contributed = [p for p in tracked if p.startswith(prefix)]
        if not contributed:
            pytest.skip(
                f"submodule {submodule} is not checked out in this working copy — run "
                f"`{fileset.SUBMODULE_INIT_HINT}`. The deploy-time guard for exactly "
                "this state is covered by test_check_complete_rejects_an_uninitialised_submodule"
            )
        assert len(contributed) > MIN_SUBMODULE_PATHS, (
            f"{submodule} contributed only {len(contributed)} paths"
        )
        assert fileset.select(contributed), f"{submodule} was excluded in full"


def test_verified_tracked_paths_agrees_with_the_raw_enumeration():
    """[ERR] The deploy entry point returns the same list when nothing is missing."""
    try:
        verified = fileset.verified_tracked_paths(REPO_ROOT)
    except GitError as exc:
        if fileset.SUBMODULE_INIT_HINT not in str(exc):
            raise
        pytest.skip(f"working copy is incomplete: {exc}")
    assert verified == fileset.git_tracked_paths(REPO_ROOT)
