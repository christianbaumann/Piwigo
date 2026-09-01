# 0025 — The German handbook moves into the application tree at top-level `handbuch/`

Date: 2026-09-01
Status: accepted
Supersedes Decision 1 of [`docs/agents/decisions/0024-german-handbook-location-and-demo-content.md`](0024-german-handbook-location-and-demo-content.md).

## Context

Decision 1 of 0024 put the handbook at `docs/handbuch/`, correctly rejecting
`language/de_DE/help/` (upstream's directory, rewritten on every merge, with no place for
fork-local plugin coverage). But `tools/deploy`'s fileset excludes `docs/` in full — deliberately,
since the rest of `docs/` (agent research, plans, decisions, `TESTING.md`) must never ship — so
the handbook has never been reachable on a real install, only via `file://` locally. That
"not reachable from the gallery" consequence, accepted in 0024 as the cost of avoiding
`language/de_DE/help/`, is a real gap: a document written for the gallery's own users was never
actually deployable to them.

Two ways to close it: narrow the `docs/` exclusion to carve out `docs/handbuch/`, or move the
handbook out of `docs/` entirely. Narrowing was rejected — nesting a "ships" exception inside a
prefix whose entire reason to exist is "never ships" turns one clean deny-list entry into a rule
with a silent carve-out, and every future addition under `docs/` would need to be checked against
it. Moving the shippable content to a sibling of `plugins/`, `themes/`, `admin/` keeps `docs/`
unqualified and keeps the new location's own exclusion (its dev-only `tools/` subdirectory)
readable as its own rule, the same shape as the existing bare `tools/` entry for the deploy tool.

## Decision

**The handbook moves from `docs/handbuch/` to top-level `handbuch/`.** It is git-tracked
application content now, published by `tools/deploy` like any other top-level directory, with one
exception: `handbuch/tools/` (the generator/checker scripts — `seed.php`, `shoot.js`,
`check.php`) needs DDEV, `php exec`, Node and Playwright, and refuses to run without the install's
`persons_throwaway_install` marker. That is dev tooling, not application content, so it gets its
own `EXCLUDED_PREFIXES` entry in `tools/deploy/pwgdeploy/fileset.py`, scoped to the one subtree
that needs it rather than reusing the directory-name-based `EXCLUDED_DIR_NAMES` mechanism, which
would also catch `plugins/persons/tools/helper.php` (`tools/deploy/tests/test_fileset.py`'s
`test_a_toolsish_path_outside_the_directory_is_kept` boundary case, decision 0022).

Every internal reference inside the handbook's pages is already relative, so the move is a pure
relocation: no HTML content changed, only the path-depth constants the tooling and the E2E suite
use to find the repository root.

**What does not change:**
- Decision 1's rejection of `language/de_DE/help/` — still correct, still the reason the handbook
  is not nested under upstream's help tree.
- Decisions 2 and 3 of 0024 (generated demo content, translate-before-photograph) — untouched.
- The `file://`-with-no-server property `plugins/provenance/tests/e2e/handbuch-pages.spec.js`
  proves — it still holds at the new location, verified by the same suite passing unchanged.
- The scope of this change: **URL-only, no in-app link.** Confirmed with the user 2026-09-01. The
  handbook is now reachable at `/handbuch/` on a real install, but nothing in the gallery links to
  it — no footer, no help menu, no admin dashboard entry. That stays a separate, later change if
  wanted.

## Consequences

- `docs/` regains its clean, unqualified "nothing under here ever ships" property — the property
  that made narrowing it the wrong fix in the first place.
- A deploy now publishes `handbuch/index.html`, the five topic pages, `handbuch/assets/**` (the
  stylesheet and 20 screenshots), and excludes `handbuch/tools/**`. Verified by
  `tools/deploy/tests/test_fileset.py::test_the_handbook_ships_with_the_application`,
  `test_the_handbook_toolchain_stays_off_the_web_space`,
  `test_a_handbuch_toolsish_path_outside_the_tools_directory_is_kept`, the auto-generated
  `test_drops_each_excluded_prefix[handbuch/tools/]`, and two new assertions in
  `test_real_repository_file_set`.
- `handbuch/tools/check.php`, `seed.php` and `shoot.js` each dropped one directory level in their
  hardcoded root constants (repo root is now two levels up from `handbuch/tools/`, not three).
  `plugins/provenance/tests/e2e/handbuch-pages.spec.js`'s `HANDBUCH_DIR` constant dropped the
  `docs` path segment the same way.
- Every rule file, decision, and `docs/agents/TESTING.md` line that named `docs/handbuch/...`
  in prose now names `handbuch/...`.
- HTTP reachability is proven by a new `test.describe` block in `handbuch-pages.spec.js`, run
  against the real DDEV nginx-fpm stack (not a double). The remote host's own reachability has no
  local test double, per `.claude/rules/deployment.md`, and stays a recorded, dated hand check.

## What would reverse this

- Upstream shipping a German help system that covers the fork's plugins would still make
  Decision 1 of 0024 worth revisiting, exactly as before — unrelated to this decision.
- A future need to exclude other, unrelated top-level content the same way `docs/` is excluded
  would be a reason to reconsider whether `handbuch/` should instead sit under some other
  already-excluded prefix — nothing currently points that way.
- Adding an in-app link is explicitly out of scope here, not reversed by it: it is a strict
  addition on top of this decision, not a change to it.
