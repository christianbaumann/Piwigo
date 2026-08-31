# 0022 — `tools/` is not published to the web space

Date: 2026-08-31
Status: accepted
Resolves the open question left by Phase 6 of
[`docs/agents/plans/2026-08-31-ftp-deployment-and-remote-install.md`](../plans/2026-08-31-ftp-deployment-and-remote-install.md)

## Context

`tools/` holds 21 tracked files of upstream maintenance and translation tooling — Perl scripts,
`metadata.php`, `github_issues.php`, the release script — plus this fork's own
`install-hooks.sh` and `test-hooks.sh`, and `tools/deploy/` itself.

The first version of the exclusion list published all of it except `tools/deploy/` and the two
hook scripts. The reasoning was that a stock Piwigo install has these files: upstream's own
release script strips only `.git` (`tools/pwg_rel_create.sh`), and `tools/index.php` redirects
any request out of the directory. Matching a stock install is the conservative default, and
"upstream ships it" is a real argument.

It was questioned during the Phase 6 verification round, from the real `--list-files` output
(`docs/backlog.md`), and left unanswered rather than decided.

## Decision

**Exclude the whole directory.** `EXCLUDED_PREFIXES` in `tools/deploy/pwgdeploy/fileset.py`
carries `tools/`, replacing the three narrower entries it had.

The deciding fact is that **core loads nothing from `tools/` at runtime**. Its only two
mentions anywhere in core are comments (`include/config_default.inc.php:18,353`). So the
directory is dev and maintenance PHP reachable over HTTP, and it buys the deployed gallery
nothing at all. `tools/index.php`'s redirect is a convenience, not an access control — it
governs a request for the directory, not a request for a script inside it.

Weighed against that, "a stock install has these files" is an argument for *sameness*, not for
*need*. Nothing in this fork or in Piwigo behaves differently because the directory is absent,
and the smaller HTTP surface wins.

Cost, paid once: the next deploy prunes 21 files. `--dry-run` names them before it happens —
which it only does because the same verification round fixed a dry run that reported `0 removed`
unconditionally.

## Consequences

- The published file set went from 3328 to 3307 paths, measured 2026-08-31.
- `tools/deploy` cannot deploy itself, which was already true and is now true by the general
  rule rather than by a special case.
- Guarded by `test_excludes_the_whole_tools_directory` (which asserts the opposite of the
  superseded `test_excludes_the_fork_s_own_hook_scripts_but_keeps_upstream_tools`),
  `test_a_toolsish_path_outside_the_directory_is_kept` for the prefix boundary — `tools/` is a
  path prefix, so `plugins/persons/tools/` and a sibling named `toolstrap/` are untouched — and
  an assertion in the real-repository characterization test that no selected path starts with
  `tools/`, with the anti-vacuity guard that the directory is tracked at all.

## What would reverse this

Core, a theme, or a fork-local plugin coming to load something from `tools/` at runtime — a
`include(PHPWG_ROOT_PATH.'tools/…')` rather than a comment. Then the file it needs is a
runtime dependency and the exclusion is a broken deploy, which is the failure this list is
otherwise built to prevent. Nothing else: an upstream release continuing to ship the directory
is not a reason, since that is already the case today.
