# Testing

The project-facing testing **record**: what the suites are, what is deliberately not
tested, how strong the unit suite was measured to be, and what still has no oracle.

**The rules themselves live in `.claude/rules/`** — `testing.md` (layers and placement),
`test-design.md` (the technique legend, anti-vacuity, characterization tests, fixtures, the
hand-check ledger rule), `mutation-testing.md`, `e2e-tests.md`, `backpressure.md`,
`precommit-hooks.md`. Those are auto-loaded when an agent touches a matching path, and they
are the single owner of every rule. Nothing here restates them; this file is what they
produced.

Two project-specific rulings that the general rules cannot state, because they are facts
about this codebase:

- **Server-rendered source is not the DOM — and here that bites hard.** The injected script
  builds both `#Tags` and `#typetags-unassigned` as JavaScript string literals, so a
  raw-body scan finds the *script's* copy and reports an element the server never rendered.
  Two tests failed on their first run for exactly this. Every element-presence assertion in
  `PicturePageSourceTest` scans the page with `<script>` blocks stripped, guarded by
  `assertMarkupSurvivedStripping()`.
- **The prefilter's two search strings are named constants**, `TYPETAGS_TPL_TAG_ANCHOR` and
  `TYPETAGS_TPL_INJECT_POINT` in `events_public.inc.php`, because the structural guard would
  otherwise carry a second hand-typed copy of them. Likewise the E2E rendering specs read the
  expected palette out of `piwigo_typetags` instead of carrying one.

## Suites

Piwigo core has no test suite. `plugins/typetags` carries all three layers.

| Layer | Needs | Budget | What it witnesses |
|---|---|---|---|
| Unit | nothing — no DB, no HTTP, no Piwigo bootstrap | < 1s | one function's behaviour |
| Integration | DDEV up; real MariaDB and real `ws.php` | seconds | two parts meeting across a real boundary |
| E2E | DDEV up + Chromium in the container | slowest | the shipped page as a user sees it |

The run commands and the fresh-clone setup are in CLAUDE.md's Testing section — one copy,
cited here rather than repeated.

**Measured 2026-08-29** (dates attached because counts rot): unit 56 tests / 33,009
assertions in 0.105s; integration 44 tests / 150 assertions; E2E 26 (25 specs + 1 auth
setup) in 8.9s. The unit suite's sub-second budget is what makes it eligible for a commit
gate; the other two are not.

### The integration and E2E suites mutate the real database

Both write to `piwigo_image_tag`, `piwigo_tags`, `piwigo_typetags` and
`piwigo_user_cache`, and restore what they recorded. **Neither is safe against a
production install**, and neither ever will be — this is stated rather than assumed.
Fixtures force their precondition and assert it took effect; no assertion depends on
cleanup having run, because cleanup is skipped on failure so it cannot erase the evidence.

### The commit gate, and what it was watched doing

`.githooks/pre-commit` gates on the unit suite only — the one suite that runs without the
stack up. Design and installation are in CLAUDE.md; the rule it follows is
`.claude/rules/precommit-hooks.md`. What belongs here is the record of what was watched, and
which of it is now watched on every run instead of once.

`tools/test-hooks.sh` is the standing check — **10 cases, measured 2026-08-29**: three
probe preconditions, three direct-invocation cases, two installation checks, and two real
`git commit` cases. A clean hook run takes 1.0s wall clock (measured 2026-08-29, of which
the unit suite is 0.098s and the rest is `ddev exec` overhead). That figure is a dated
measurement, not an assertion — nothing in the suite gates on it.

| Watched | How | Now automated as |
|---|---|---|
| Blocks a real commit on a syntax error | `git commit` in `plugins/typetags` with a broken `.php` staged; rejected, commit not created | `git rejects a real commit` — a throwaway repo wired via `core.hooksPath`, asserting the commit count did not move |
| Lets a clean commit through on the real path | — | `git accepts a clean commit`, asserting the count moved by exactly one |
| Both repos are actually wired to `.githooks` | `git config --get core.hooksPath` read by hand in each | `superproject` / `plugins/typetags core.hooksPath resolves to .githooks` |
| Blocks a newly added `\|\| true` in a test file | real `git commit` of `tests/HookRatchetProbe.php`; rejected, naming the offending added line | `vacuous assertion blocks` (direct invocation) |
| Grandfathers a pre-existing one | committed the probe with `--no-verify`, then committed an unrelated edit to the *same file* cleanly | not automated — it needs two commits and a file already carrying the pattern in `HEAD`; the ratchet's mechanism (`git diff --cached -U0`, added lines only) is one line of the hook |
| `--no-verify` bypasses | used to create the grandfathering fixture above | not automated — it is git's behaviour, not the hook's |
| Degrades rather than blocking when DDEV is down | stub `ddev` on `PATH` returning non-zero: `WARNING: DDEV is not running - unit suite skipped`, exit 0 | not automated — it needs `PATH` manipulation around the runner the self-test itself depends on |

Proven able to fail, each killing exactly its own case:

| Mutant | Killed | Nothing else moved |
|---|---|---|
| hook's vacuity grep neutered to `hits=""` | `vacuous assertion blocks` | yes |
| `probe_vacuous.php` written as a byte-copy of the clean baseline | the "probe changed nothing" guard, plus the case it protects | yes |
| `git -C plugins/typetags config --unset core.hooksPath` | `plugins/typetags core.hooksPath resolves to .githooks` | yes — the real-commit cases wire their own repo, so they stayed green |

The hook was restored byte-identical after each mutant, `plugins/typetags` reset to
`3eeee007d`, and the installer re-run.

### Clear the compiled template after editing a prefilter

`Template::set_prefilter()` hashes only the callback's *name* into Smarty's `compile_id`
(`include/template.class.php:1060-1070`), not its source. Editing a prefilter leaves the
previously compiled `picture.tpl` in place and the page keeps showing the old injection
with no error anywhere. `rm -rf _data/templates_c/*` after any prefilter edit — this cost
a false-red debugging cycle in Phase 3 and a second one during Phase 4's mutation runs.

## Tests NOT required (with justification)

So a later reader can tell a considered omission from an oversight.

| Component | Why no test | Technique rationale |
|---|---|---|
| `check_color()`'s DB branch in `get_typetag_id()` | Covered at the integration layer by `typetags.type.add` | Duplicating it at the unit layer adds no equivalence class |
| `typetags_escape_prefilter()` | One `str_replace`, no branching | No partition and no boundary to sit on |
| `typetags_tags()` letters/cloud/cumulus modes | Not touched by this feature | Outside the change's blast radius |
| `removeTag` image validation | A `DELETE` on a nonexistent image is already a no-op | No reachable defect; the asymmetry with `addTag` is deliberate and commented |
| Concurrent add/remove from two tabs | The `PRIMARY KEY (image_id, tag_id)` makes both operations idempotent | Testing it would assert the database's behaviour, not the plugin's |
| Scoping of the `nb_available_tags` invalidation | Over-invalidation is safe; see [decision 0004](decisions/0004-unscoped-tag-cache-invalidation-accepted.md) | A performance characteristic, not a correctness one — nothing to assert |
| Per-image visibility on `addTag` | "All logged-in users" is the recorded permission model; see [decision 0005](decisions/0005-tag-assignment-permission-model.md) | Would assert a requirement that does not exist |
| `post_only` on the two image methods | Deliberately absent; see [decision 0003](decisions/0003-no-post-only-on-ws-methods.md) | Characterized instead, by `AddTagTest::testMethodAlsoAnswersToGet` `[ERR]` |

## Mutant table — unit suite

Run 2026-08-29 against the unit suite, by hand, one mutant at a time, with
`include/functions.inc.php` confirmed byte-identical to HEAD after each. Scope and method
per [decision 0001](decisions/0001-mutation-testing-unit-only.md) and
`.claude/rules/mutation-testing.md`: unit layer only, prose not script.

| Mutant | Expected killer | Result |
|---|---|---|
| `$l > 0.45` → `$l >= 0.45` | the BVA pair | **survived** — see below |
| `$l > 0.45` → `$l > 0.5` | the palette / BVA tests | killed: `testThresholdJustAboveGetsBlackText`, `testFourCharThresholdBoundary` |
| `strlen($color) == 7` → `>= 7` | the malformed-length test | **survived on first run**, killed after the test was strengthened — see below |
| the `empty($rgb)` guard's `return '#000'` → `'#fff'` | the malformed-length test | killed: `testMalformedLengthReturnsSafeDefault`, alone |
| `in_array($tag['id'], $assigned_ids)` → `!in_array(...)` | the partition tests | killed: 9 of the 11 `PartitionTagsTest` cases |
| `assertSame(1, substr_count(...))` → `assertGreaterThanOrEqual(1, ...)` in the template guard | nothing | **survived, and trivially so** — see below |

**Nothing else moved** in any of the killed rows: each mutant killed exactly the tests
that watch it, and every other test in the 52 stayed green.

### The three findings

**`$l >= 0.45` is unkillable because the boundary is unreachable, not because the test is
weak.** `$l == 0.45` exactly requires `min + max = 229.5` on 8-bit channels, which no
`#RRGGBB` value can produce. The nearest attainable pair is `#00E500` (`l = 0.449020`) and
`#00E600` (`l = 0.450980`), and both sides of the mutation agree on them. This is the
reading the plan anticipated, and `testThresholdIsUnreachableOnEightBitChannels` already
asserts it directly.

**`strlen($color) >= 7` survived for the opposite reason — a genuinely weak test.** None
of the four inputs in `testMalformedLengthReturnsSafeDefault` discriminated it:
`str_repeat('a', 1000)` *does* take the mutant's branch, but parses as a light colour and
returns `'#000'` — the same answer the guard gives. The test was strengthened with
`'#000000_overlong'`, which reads as `'#000000'` under the mutant and returns `'#fff'`
there against `'#000'` here. The mutant now dies, killing that one test and nothing else.

**The template-guard mutant as specified is degenerate.** Weakening the guard's own
assertion is not a mutation of production, so "killed by nothing" is trivially true — no
test tests the test, and per *build no apparatus that proves another apparatus*, none
should. The meaningful mutant is on the production side: appending a second copy of
`TYPETAGS_TPL_TAG_ANCHOR` to `themes/default/template/picture.tpl`. The shipped `=== 1`
guard kills it; the same mutant survives when the guard is weakened to `>= 1`. So the
exact-count assertion earns its strictness, which is a stronger result than the plan
predicted.

### Second run — `CleanCheckoutTest` (2026-08-29)

Added with Phase 7, so it got its own pass rather than riding on the one above. Four
mutants, one at a time, `main.inc.php` and `.gitignore` confirmed byte-identical to HEAD
and the git index confirmed clean after each.

| Mutant | Expected killer | Result |
|---|---|---|
| a runtime include target added that exists on disk but was never `git add`ed | the include-graph test | killed: `testEveryRuntimeIncludeTargetIsCommitted`, alone |
| a runtime filename appended to `.gitignore` | the ignore-rules test | **survived on first run**, killed after the test was fixed — see below |
| `git rm --cached maintain.class.php` (a loader entry point never committed) | the entry-point test | killed: `testLoaderEntryPointsAreCommitted`, alone |
| two includes refactored off the literal `TYPETAGS_PATH . '<path>'` idiom, shrinking what the scan can discover | the anti-vacuity floor | killed: `testGuardFixtureIsNotVacuous`, alone (3 targets against a floor of 4) |

**Nothing else moved** in any killed row: the other 55 tests stayed green each time.

**The finding: `testNoRuntimeFileIsGitIgnored` was vacuous as first written.** `git
check-ignore` ignores its own rules for **tracked** files unless `--no-index` is passed, so
the only path that could have failed it was one both ignored *and* untracked — which
`testEveryRuntimeIncludeTargetIsCommitted` already catches. The test duplicated coverage
while appearing to add some. Fixed by adding `--no-index`, which makes it query the rules
themselves; the mutant then died, killing that one test and nothing else. This is the same
shape as the `strlen >= 7` finding above: a mutant surviving because the test was genuinely
weak, not because the target was unreachable.

Why the invariant is worth a test even though a tracked-but-ignored file still reaches
clones: the pattern is a trap for the *next* runtime file added under it, which would be
silently skipped by `git add` and never noticed until someone cloned.

## Hand-check ledger

For behaviour no automated layer reaches. Each entry records the date, what was checked,
and — once something automates it — which test replaced it, so the ledger shrinks rather
than accumulating. Nothing is marked done on prose alone.

| Date | Checked by hand | Replaced by |
|---|---|---|
| 2026-08-28 | Picture page renders identically after the Phase 1 partition extraction (headed browser, logged in, `picture.php?/1/category/1`: "Personen ×" assigned badge, 7 correctly-coloured unassigned badges, 0 console errors). Confirmed by the user. | Not replaceable as-is — a before/after comparison whose "before" no longer exists. Ongoing rendering is covered by `MalformedColorRenderingTest` and `rendering.spec.js`. |
| 2026-08-28 | A server-side rejection (HTTP 200 + `stat:"fail"`) leaves the badge clickable and logs a warning. Mocked via `route.fulfill()` in a headed browser; red before the fix, green after. | **Replaced 2026-08-29** by `edge-cases.spec.js` → `a server rejection leaves the badge clickable`, itself watched failing against the reverted fix |
| 2026-08-29 | The pre-commit gate watched failing at the terminal before being trusted: two real `git commit` invocations in `plugins/typetags` (a PHP syntax error, then a newly added `\|\| true` in `tests/`), both rejected, neither creating a commit. Submodule reset to `3eeee007d` afterwards. | **Replaced 2026-08-29** by `tools/test-hooks.sh` → `git rejects a real commit` / `git accepts a clean commit`, which make real commits in a throwaway repo and assert the commit count. Three items from that session stay unautomated for stated reasons — see the commit-gate table above. |
| 2026-08-29 | Modus rendering compared against the 2026-04-27 reference screenshots. Structure and palette match; the only difference is the older capture's dark colour scheme. | **Replaced 2026-08-29** by `rendering.spec.js` (4 specs), which assert computed colour and geometry on every run. Not kept as a screenshot baseline — pixel-diffing a photo gallery is flaky for reasons unrelated to this feature. |

### Open — no oracle, so no test

| Item | Why it cannot be automated |
|---|---|
| A first-time `PluginMaintain::install()` against an **empty schema** | Needs a throwaway Piwigo instance with its own database; this repository has one install and no way to provision another. Running it against the live install would mean `uninstall()` first, which drops `piwigo_typetags` and `piwigo_tags.id_typetags` — destroying real tag-colour data to test a method that is already idempotent by construction (`CREATE TABLE IF NOT EXISTS`, an `ALTER` guarded by `SHOW COLUMNS`, a `conf_update_param` guarded by `empty()`). The risk is not worth the coverage. What *is* automated: `CleanCheckoutTest` (4 tests) asserts every runtime file is committed and unignored — the half of "installs from a clean checkout" that this repository can actually get wrong — and `PluginActivationTest` asserts install()'s effects are present on the live install. |
| Badge contrast is *legible* for all 8 configured colours against the modus background | Subjective judgment. `get_color_text()` picks black or white by a lightness threshold and `rendering.spec.js` asserts the choice is applied, but whether the result reads comfortably is not a fact a machine can settle. |
| The hover opacity transition *feels* right | Subjective. The opacity values themselves are asserted; the perception is not. |
| Committing does not *feel* slow with the pre-commit hook installed | Subjective, and a wall-clock assertion would violate *assert the causal fact, not a wall-clock figure*. |
