"""Structural guard: the README's checkable claims against the code they describe.

`README.md` is the only instruction a first-time operator gets, and every fact in it is a
second copy of something production already states — a flag, an exit code, a field name, a
default, an exclusion rule. *Do not transcribe production data into a test* applies to
documentation for the same reason: the copy rots the day only one of the two is updated,
and nothing else in this repository would notice. A wrong README is worse than none — it
is followed.

What this file cannot check is whether the prose *reads* well to a human; that half stays
in the hand-check ledger.

Every parser here carries a lower-bound guard, because a README rewrite that merely
changed a table's shape would otherwise leave these tests scanning nothing and passing.

Techniques: [HAPPY] for each documented-equals-real claim, [NEG] for the reverse
direction (a flag or field that exists but is undocumented), anti-vacuity throughout.
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

from pwgdeploy import cli, config, errors, fileset

README = Path(__file__).resolve().parents[1] / "README.md"
REPO_ROOT = Path(__file__).resolve().parents[3]

# Lower bounds for the scans below, each one under what the README carried when this file
# was written, 2026-08-31: 5 flags, 7 exit codes, 13 distinct field names (the sets are
# compared by name, so the `host`/`user`/`password` that three sections share count once),
# 6 exclusion prefixes, 4 relative links. A scan that finds fewer has stopped reading the
# document it claims to check.
MIN_FLAGS = 4
MIN_EXIT_CODES = 6
MIN_FIELDS = 12
MIN_EXCLUDED_PREFIXES = 5
MIN_LINKS = 3

# argparse's own, never written by this tool and never documented in the flag table.
BUILTIN_FLAGS = {"-h", "--help"}


@pytest.fixture(scope="module")
def readme() -> str:
    text = README.read_text(encoding="utf-8")
    assert len(text) > 1000, "README.md is empty or truncated; every scan below is vacuous"
    return text


FENCE = re.compile(r"```.*?```", re.DOTALL)


def prose(text: str) -> str:
    """The document minus its fenced code blocks.

    A fence opens with three backticks, so scanning the raw text pairs them off by one
    and every inline span after the first fence is read inside-out — the failure that
    made the first run of this file report zero documented flags while the table plainly
    listed five. The lower-bound guards are what turned that into a red test rather than
    a green one.
    """
    return FENCE.sub("", text)


def backticked(text: str) -> list[str]:
    return re.findall(r"`([^`]+)`", prose(text))


def test_the_readme_is_where_the_tool_expects_it(readme):
    """Anti-vacuity for the whole file: a moved README would make every fixture below
    fail to load rather than silently check nothing."""
    assert README.is_file()
    assert "pwg-deploy" in readme


# --- the command and its flags ---------------------------------------------------------


def documented_flags(readme: str) -> set[str]:
    return {token for token in backticked(readme) if token.startswith("--")}


def real_flags() -> set[str]:
    return {
        option
        for action in cli.build_parser()._actions
        for option in action.option_strings
    } - BUILTIN_FLAGS


def test_every_documented_flag_is_accepted_by_the_parser(readme):
    """[HAPPY] A flag in the README that argparse rejects is an instruction that fails
    the moment it is followed."""
    documented = documented_flags(readme)
    assert len(documented) >= MIN_FLAGS, f"only {len(documented)} flags found in README.md"
    assert documented <= real_flags(), f"documented but unknown: {documented - real_flags()}"


def test_every_flag_the_tool_accepts_is_documented(readme):
    """[NEG] The reverse direction, which is the one that rots: a flag added later ships
    undocumented and nothing notices."""
    undocumented = real_flags() - documented_flags(readme)
    assert not undocumented, f"accepted but undocumented: {sorted(undocumented)}"


def test_the_documented_entry_point_is_the_one_pyproject_installs(readme):
    """[HAPPY] `uv run pwg-deploy` only works because of the console script."""
    assert "uv run pwg-deploy" in readme
    pyproject = (README.parent / "pyproject.toml").read_text(encoding="utf-8")
    assert f'{cli.PROGRAM} = "pwgdeploy.cli:main"' in pyproject


# --- exit codes -------------------------------------------------------------------------


def documented_exit_codes(readme: str) -> set[int]:
    section = readme[readme.index("Exit codes") :]
    section = section[: section.index("\n\n")]
    return {int(n) for n in re.findall(r"`(\d+)`", section)}


def real_exit_codes() -> set[int]:
    codes = {
        cls.exit_code
        for cls in vars(errors).values()
        if isinstance(cls, type) and issubclass(cls, errors.DeployError)
    }
    return codes | {cli.INTERRUPTED_EXIT_CODE}


def test_every_documented_exit_code_is_one_the_tool_can_return(readme):
    """[HAPPY] The codes exist so a caller can branch on them without parsing a message;
    a wrong one in the README makes that branch silently wrong."""
    documented = documented_exit_codes(readme)
    assert len(documented) >= MIN_EXIT_CODES, f"only {len(documented)} exit codes found"
    assert documented <= real_exit_codes(), f"documented but unreachable: {documented - real_exit_codes()}"


def test_every_exit_code_the_tool_can_return_is_documented(readme):
    """[NEG] A new error class with a new code is invisible to an operator until it fires.
    DeployError's own generic 1 is deliberately excluded: it is the base, never raised."""
    undocumented = real_exit_codes() - documented_exit_codes(readme) - {errors.DeployError.exit_code}
    assert not undocumented, f"reachable but undocumented: {sorted(undocumented)}"


# --- the credential file ------------------------------------------------------------------


def documented_credential_fields(readme: str) -> set[str]:
    """The second column of the credential table, which is where field names live."""
    fields: set[str] = set()
    for line in readme.splitlines():
        if not line.startswith("| "):
            continue
        columns = [c.strip() for c in line.strip("|").split("|")]
        if len(columns) >= 3 and columns[0] in ("", *config.SECTIONS, *(f"`{s}`" for s in config.SECTIONS)):
            fields.update(backticked(columns[1]))
    return fields


def real_credential_fields() -> set[str]:
    example = (README.parent / "deploy.example.json").read_text(encoding="utf-8")
    return set(re.findall(r'"(\w+)":', example)) - set(config.SECTIONS)


def test_every_documented_credential_field_is_in_the_example(readme):
    """[HAPPY] A field the README explains and the example lacks is one an operator has
    to invent, in a file whose every key is rejected if unknown."""
    documented = documented_credential_fields(readme)
    assert len(documented) >= MIN_FIELDS, f"only {len(documented)} fields found in the table"
    assert documented <= real_credential_fields(), (
        f"documented but absent from deploy.example.json: "
        f"{sorted(documented - real_credential_fields())}"
    )


def test_every_field_of_the_example_is_documented(readme):
    """[NEG] The direction that rots: a field added to the example with no explanation."""
    undocumented = real_credential_fields() - documented_credential_fields(readme)
    assert not undocumented, f"in the example but undocumented: {sorted(undocumented)}"


def test_every_section_of_the_example_is_documented(readme):
    """[HAPPY] Anti-vacuity for the two tests above: the table is read by section, so a
    section missing from it would silently take its fields with it."""
    for section in config.SECTIONS:
        assert f"| `{section}` |" in readme, f"section {section} has no row in the table"


def test_the_documented_defaults_are_the_real_defaults(readme):
    """[HAPPY] Three values an operator will omit on the strength of the README saying so."""
    documented = set(re.findall(r"default `([^`]+)`", prose(readme)))
    assert len(documented) >= 3, f"only {len(documented)} defaults documented"
    assert documented == {
        str(config.DEFAULT_PORT),
        config.DEFAULT_PREFIX,
        config.DEFAULT_LANGUAGE,
    }


# --- what is published --------------------------------------------------------------------


def test_every_exclusion_the_readme_names_is_a_real_rule(readme):
    """[HAPPY] The exclusion list is what an operator checks before trusting that a
    secret-bearing directory stays off a public host."""
    section = readme[readme.index("The file set is `git ls-files") :]
    section = section[: section.index("Two deliberate exceptions")]
    named = {token for token in backticked(section) if token.endswith("/")}
    assert len(named) >= MIN_EXCLUDED_PREFIXES, f"only {len(named)} prefixes named"

    unreal = {
        prefix
        for prefix in named
        if not prefix.startswith(fileset.EXCLUDED_PREFIXES)
        and prefix.strip("/") not in fileset.EXCLUDED_DIR_NAMES
    }
    assert not unreal, f"named in README but not excluded by fileset: {sorted(unreal)}"


def test_the_two_documented_exceptions_really_survive(readme):
    """[BVA] Both are the boundary a plausible tightening of the rules would cross: the
    guard that hides the DB credentials, and the icon font a bare `vendor/` would eat."""
    assert "local/**/index.php" in readme or "`index.php`" in readme
    assert "themes/default/vendor/fontello/" in readme
    assert fileset.select(["local/config/index.php"]) == ["local/config/index.php"]
    assert fileset.select(["themes/default/vendor/fontello/css/fontello.css"]) == [
        "themes/default/vendor/fontello/css/fontello.css"
    ]


# Core's own file, written by install.php on the server and named in the README beside the
# generated one. It is not a constant of this tool — nothing here reads or writes it — so it
# is spelled out once, here, as the second member of the allowed set below.
CORE_DATABASE_CONFIG = "local/config/database.inc.php"


def test_every_config_file_the_readme_names_is_one_that_really_exists(readme):
    """[HAPPY] Not `GENERATED_CONFIG_PATH in readme`: that form stays green while a
    *second*, stale mention of the same file sits three paragraphs down under a wrong
    name — which is exactly what a mutant of the first occurrence proved on 2026-08-31.
    Every `local/config/*.inc.php` the document names has to be a file that exists."""
    named = {
        token
        for token in backticked(readme)
        if re.fullmatch(r"local/config/[^/]+\.inc\.php", token)
    }
    allowed = {fileset.GENERATED_CONFIG_PATH, CORE_DATABASE_CONFIG}
    assert named, "the README names no config file at all; this scan is vacuous"
    assert named == allowed, f"README names config files that do not exist: {sorted(named - allowed)}"
    assert fileset.GENERATED_CONFIG_PATH in fileset.GENERATED_REMOTE_PATHS


def test_the_server_authoritative_directories_are_the_ones_the_tool_creates(readme):
    """[HAPPY] Read the sentence that makes the promise, not the whole document, for the
    reason given above: a stale name elsewhere must not be covered by a true one here."""
    end = readme.index("are created empty")
    named = {token.rstrip("/") for token in backticked(readme[end - 200 : end]) if token.endswith("/")}
    assert named == set(fileset.REMOTE_DIRS_TO_CREATE), (
        f"README says {sorted(named)} are created empty and server-authoritative; "
        f"the tool creates {sorted(fileset.REMOTE_DIRS_TO_CREATE)}"
    )


# --- links ---------------------------------------------------------------------------------


def test_every_relative_link_in_the_readme_resolves(readme):
    """[NEG] Three of them point at decision files whose numbering already shifted once
    (0020 was taken before this phase ran), which is exactly how a link dies."""
    targets = [
        target
        for _label, target in re.findall(r"\[([^\]]+)\]\(([^)]+)\)", readme)
        if not target.startswith("http")
    ]
    assert len(targets) >= MIN_LINKS, f"only {len(targets)} relative links found"
    broken = [t for t in targets if not (README.parent / t.split("#")[0]).resolve().exists()]
    assert not broken, f"broken relative links: {broken}"


def test_the_rules_file_is_reachable_from_claude_md():
    """[NEG] `.claude/rules/backpressure.md`: a rules file with no read-trigger in
    CLAUDE.md is content deleted with extra steps — nothing would ever open it."""
    claude_md = (REPO_ROOT / "CLAUDE.md").read_text(encoding="utf-8")
    assert "(.claude/rules/deployment.md)" in claude_md
    assert (REPO_ROOT / ".claude/rules/deployment.md").is_file()
