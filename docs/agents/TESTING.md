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

Piwigo core has no test suite. `plugins/typetags` and `plugins/provenance` each carry all
three layers, with their own `phpunit.xml` and their own `playwright.config.js`.

| Layer | Needs | Budget | What it witnesses |
|---|---|---|---|
| Unit | nothing — no DB, no HTTP, no Piwigo bootstrap | < 1s | one function's behaviour |
| Integration | DDEV up; real MariaDB and real `ws.php` | seconds | two parts meeting across a real boundary |
| E2E | DDEV up + Chromium in the container | slowest | the shipped page as a user sees it |

The run commands and the fresh-clone setup are in CLAUDE.md's Testing section — one copy,
cited here rather than repeated.

**Measured 2026-08-29** (dates attached because counts rot): unit 56 tests / 33,009
assertions in 0.105s; integration 44 tests / 150 assertions in 3.5s; E2E 26 (25 specs + 1 auth
setup) in 11.0s. The integration and E2E figures are the first ever taken by a run this
repository could reproduce: both suites used to read a human's login, so no agent session could
execute them. `plugins/typetags/tests/Support/create-test-users.php` now creates
`typetags_webmaster` and `typetags_normal`, as the provenance script does. The unit suite's sub-second budget is what makes it eligible for a commit
gate; the other two are not.

**`plugins/provenance`, measured 2026-08-29** (all three layers, after the gallery was
resynchronised — see *The gallery loss, and what running the suites again exposed* below): unit
138 tests / 312 assertions in 0.012s; integration 128 tests / 692 assertions / 3 skipped in
49.2s; E2E 26 (25 specs + 1 auth setup) in 17.5s. All three skips are the deliberate ones recorded
in the non-coverage table, not failures.

### The gallery loss, and what running the suites again exposed

The install lost its gallery on 2026-08-29 (0 rows in `piwigo_images`;
[decision 0011](decisions/0011-provenance-suites-require-a-throwaway-install.md)). While it was
empty the provenance integration suite could not run at all — `125 tests, 103 errors`, every one
a fixture refusing to run over a state it merely hoped for. It was resynchronised the same day
from the recovered scans on disk (`admin.php?page=site_update&site=1&quick_sync=1`, 4 albums and
105 photos), and all three layers are green again.

**Two real defects were hiding behind the empty gallery**, both in the fixtures rather than the
plugin, and both of the same shape — a precondition *hoped for* instead of forced, which is
exactly what `.claude/rules/test-design.md` (*fixture provenance*) forbids:

- `FixtureBuilder::anyAlbumId()` returned `MIN(id) FROM piwigo_categories`. Every one of its four
  callers immediately asks for that album's photos, so "any album" has always meant "an album
  with photos" — `MIN(id)` only ever *happened* to satisfy that. The day an empty default album
  outlived the gallery, all four failed in `setUp` with "album 1 holds no photo", which reads
  like a broken plugin. It now selects the lowest-id album that actually holds a photo, and says
  so in its failure message.
- `SetAlbumInfoTest::setUp()` carried its **own hand-typed copy** of that same `MIN(id)` query —
  the duplication `backpressure.md` (*single source of truth*) exists to prevent, and one of the
  two copies was stale already. It reads through the fixture now. The test it broke asserts the
  apply button renders, and that button is behind `{if $PROVENANCE_ALBUM.PHOTO_COUNT > 0}`, so
  it genuinely needs an album with photos.

A third thing the loss left behind, in the data rather than the code: three rows in
`piwigo_image_category` pointing at album ids (263, 282, 301) that no longer existed. Harmless
while `piwigo_images` was empty, they re-attached themselves to recycled photo ids 13/15/17 the
moment the sync recreated rows, putting three photos in two albums each and breaking the 1:1
invariant the copy-down fixture asserts. Deleted; 105 photos, 105 links, no photo in more than
one album.

**The suites do not touch a real scan, and this was measured rather than assumed.** All 105 files
under `galleries/` were sha256-summed before the sync and again after the integration suite and
after the E2E suite: byte-identical every time, with no `_original` sidecar anywhere and 105
image rows still present. The write-back fixtures copy a real PNG into `upload/provenance-test/`
or into a fixture-created album — *"a photo of this suite's own, so the write-back never touches
a real scan"* — and exercise the copy. The throwaway marker's warning is still the one to obey
(the suites do delete albums and photos through core), but the blast radius is the fixtures' own
content.

### The integration and E2E suites mutate the real database

Both write to `piwigo_image_tag`, `piwigo_tags`, `piwigo_typetags` and
`piwigo_user_cache`, and restore what they recorded. **Neither is safe against a
production install**, and neither ever will be — this is stated rather than assumed.
Fixtures force their precondition and assert it took effect; no assertion depends on
cleanup having run, because cleanup is skipped on failure so it cannot erase the evidence.

### Write-back throughput and disk cost — measured 2026-08-29

`PROVENANCE_WRITEBACK_MAX_CHUNK` is 10 because of the figures below, not by guess. They are
a **dated measurement, never an assertion** (`.claude/rules/test-design.md`, *assert the
causal fact, not a wall-clock figure*) — nothing in any suite compares against them.

Method: the 76 PNGs of `upload/2026/04/19/` copied to `/tmp/bench` inside the web container
(exiftool 13.25, DDEV, PHP 8.4) and written one invocation per file, exactly as
`provenance_exiftool_run()` does. Copies, never the collection itself.

| What | Wall clock | Per file |
|---|---|---|
| 10 files (one write-back chunk) | 2.83 s | 283 ms |
| 76 files (the whole album, 8 chunks) | 12.73 s | 167 ms |

A chunk therefore spends about 5% of the production 60 s request ceiling, so the constant
stays at 10. Disk: the same 76 files grew from 55 MB to 110 MB — one extra copy, the
`_original` sidecars, created once and never rewritten (`WriteBackTest`).

### The commit gate, and what it was watched doing

`.githooks/pre-commit` gates on the unit suite only — the one suite that runs without the
stack up. Design and installation are in CLAUDE.md; the rule it follows is
`.claude/rules/precommit-hooks.md`. What belongs here is the record of what was watched, and
which of it is now watched on every run instead of once.

`tools/test-hooks.sh` is the standing check — **15 cases, measured 2026-08-29** (10 before
`plugins/provenance` was added to the gate): three probe preconditions, five
direct-invocation cases, five installation checks, and two real `git commit` cases. The five
installation checks are what grew — the gate now asserts a `core.hooksPath` for the
superproject and for the `plugins/typetags` submodule, that `plugins/provenance` is a plain
directory the superproject's `hooksPath` already covers (it would need its own installation
if it were ever made a submodule — see
[decision 0014](decisions/0014-provenance-is-its-own-plugin.md)), and that each entry in
`UNIT_SUITES` names a runner that exists. A clean hook run takes 1.0s wall clock (measured
2026-08-29, of which the unit suite is 0.098s and the rest is `ddev exec` overhead). That
figure is a dated measurement, not an assertion — nothing in the suite gates on it.

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
| E2E specs for `plugins/provenance` Phases 1-2 | Neither phase renders anything a browser can observe; see [decision 0007](decisions/0007-no-e2e-tests-for-provenance-phases-1-and-2.md) | Schema sits at the integration layer, the composition functions at the unit layer; an E2E spec would restate one of them |
| E2E specs for `plugins/provenance` Phase 3 | The phase registers `ws_add_methods` and nothing else - no prefilter, no template, no screen; see [decision 0008](decisions/0008-no-e2e-tests-for-provenance-phase-3.md) | Row shaping is unit-level, the recorder's SQL and the admin gate are integration-level; a browser would restate the gate |
| A reinstall overwriting the row-visibility key an administrator switched off | `install()` is re-entered only through `update()`, which `admin/include/plugins.class.php:156-168` reaches only by extracting an extension archive; its `install` case (133-137) is skipped while a plugins-table row exists. A first version of the test was written against `performAction('activate')`, **survived** the mutant that deletes the guard, and was replaced by a skip | See [decision 0010](decisions/0010-provenance-row-visibility-key.md). `PluginActivationTest::testReinstallLeavesTheDisplayInfoKeyAsTheAdministratorSetIt` is skipped carrying the reason, not deleted |
| The `provenance` key surviving a save of Administration → Configuration → Display | Core re-serializes `picture_informations` from a hardcoded checkbox list (`admin/configuration.php:101-112,278-283`), dropping any key with no checkbox — it already loses its own `privacy_level` the same way | Accepted rather than fixed; see [decision 0010](decisions/0010-provenance-row-visibility-key.md). Both the integration fixture and `seed.php` force the key rather than depend on it |
| The provenance row and the Colored Tags injection coexisting on one picture page | Both prefilters prepend at neighbouring anchors; the rule that keeps them independent — that `PROVENANCE_TPL_INJECT_POINT` does not span `{if isset($metadata)}` — is asserted at the **unit** layer by `PicturePageAnchorTest::testAnchorDoesNotSpanTheColoredTagsInjectionPoint` | Verified once by hand, 2026-08-29: both injections present on one logged-in page. An integration test would restate the unit rule one layer up while carrying a hand-typed copy of another plugin's internals |
| Document-level horizontal overflow on the album properties screen | `document.documentElement.scrollWidth` is pinned at 979px by `#pwgMain` at every viewport below 1024, so it does not move when the injected block is given a 4000px `min-width` — measured against two mutants, 2026-08-29 | A check that cannot fail is not a check (`test-design.md`, anti-vacuity). The block's geometry is asserted directly instead, by `album-provenance.spec.js` → `does not disturb the footer at a narrow width` |
| Document-level and element-level horizontal overflow on the **public picture page** | The same trap, a second time and on a different page: with the rendered value widened to 400 characters at a 320px viewport, `document.documentElement.scrollWidth - clientWidth` stayed at 0 (the theme clips it) and the row's own `scrollWidth - clientWidth` stayed at 0 (the element grows instead of overflowing). `document.body.scrollWidth - clientWidth` moved 0 → 2959 — measured 2026-08-29 | `provenance.spec.js` → `the row stays inside its column on a narrow viewport` measures `document.body`, and `PicturePage.js` carries the measurement so the next person does not re-derive it |
| Provenance columns as keys in `use_iptc_mapping` / `use_exif_mapping` | Deliberately absent, and that absence is the mechanism: `get_sync_metadata_attributes()` (`admin/include/functions_metadata.php:125-150`) derives the columns a sync overwrites from `array_keys()` of those two arrays, so a column that is not a key is a column no sync writes. See [decision 0015](decisions/0015-provenance-columns-stay-out-of-the-metadata-mappings.md) | A test would assert the absence of a configuration entry. The positive half — that apply and inheritance are the only writers — is what the suites actually assert |
| File-vs-database divergence after a third-party metadata edit | Not built (decision 4a); `images.date_metadata_update` is the recorded candidate signal, carried in `docs/backlog.md` | No behaviour exists to test *yet*, so the gap is a skipped test rather than prose: `WriteBackTest::testAThirdPartyEditIsDetectedAsFileDatabaseDivergence` carries the reason and the body the backlog fix must make pass. Un-skipping it is that fix's first step |
| History retention, purge or a row cap | Not built; the table grows without bound by design, with the growth path recorded in [decision 0016](decisions/0016-no-history-retention-in-v1.md) | Same: nothing to assert. What *is* asserted is the property that keeps growth bounded in practice — an unchanged field writes no row (`ApplyTest`) |
| A per-album write-permission model for provenance | Admin-only for this slice (decision C5). The admin gate itself is covered at the integration layer on every WS method (guest → 401, non-admin refused, bad token → 403) | Per-album rights are a requirement that does not exist; asserting them would invent one |
| Batch Manager bulk actions for the four album-sourced columns | Album-level entry plus `pwg.provenance.applyToPhotos` *is* the bulk path (decision 2a). The Batch Manager carries only the move-mode prompt, which `BatchManagerPageTest` and `BatchTemplateAnchorTest` do cover | Outside the change's blast radius |
| `pwg.images.setInfo` accepting provenance fields | Its allow-list is hard-coded with no hook, exactly like `ws_categories_setInfo`'s. The plugin owns `pwg.provenance.setPhotoInfo` instead, covered by `SetPhotoInfoTest` | Would test core's refusal to be extended, not the plugin |
| Enforcement of the 1:1 photo↔album relationship | Core allows many-to-many via `piwigo_image_category` and this plan does not change that; it is a separate backlog item | The assumption is **asserted, not relied on** — the Phase 5 fixture asserts the photo is in exactly one album before the test body runs, so a violation fails loudly instead of producing a quietly wrong result. The undefined case itself is a skipped test, `ApplyTest::testAPhotoInTwoAlbumsIsRefusedRatherThanSilentlyReassigned` |
| The `album_delete` history source | Descoped from Phase 9 ([decision 0013](decisions/0013-no-album-delete-prompt-in-v1.md)). The value stays in `provenance_history_sources()` and in the schema ENUM, and **no code path writes it** | A test would need a third fork-local trigger (`begin_delete_categories`) that was agreed and never added. The characterization net for `delete_categories()` is committed regardless (`81f49176b`) |
| HEIC ingestion, and the ExifTool 12.76 HEIC rotation-corruption caveat | The collection is 100% PNG. The caveat is recorded, not designed around | No input in the domain reaches the branch. Writing a HEIC fixture would test exiftool, not the plugin |
| An exiftool `-stay_open` daemon | Not built — batched multi-file invocations only. The chunk size is set from the dated throughput measurement above, not guessed | A performance characteristic. Asserting one would mean a wall-clock assertion, which `test-design.md` forbids |
| E2E specs for `plugins/persons` Phases 1-4 | No phase before 5 registers a prefilter, a template or an admin screen, so there is no rendered surface to drive; see [decision 0017](decisions/0017-no-e2e-tests-for-persons-phases-1-to-4.md) | The coordinate math and the RegionInfo parser are unit-level, the exiftool read and the index rebuild integration-level. `npx playwright test` in `plugins/persons` exits 1 with `No tests found`, which is the correct output, not a broken harness |
| `MP:RegionInfo` (Microsoft/Picasa) and `.picasa.ini` regions | Deliberately not read or written — MWG plus `XMP-iptcExt:PersonInImage` only, per the plan's *What We're NOT Doing* | Would assert an import path that does not exist |
| `owner` as a reference to a people table | Free text in v1 (decision 3a); the reference-table item is in `docs/backlog.md` | Would assert a data model that does not exist |

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

## Mutant table — `plugins/provenance` unit suite

Run 2026-08-29 against the provenance unit suite (138 tests), by hand, one mutant at a time.
Scope and method per [decision 0001](decisions/0001-mutation-testing-unit-only.md) and
`.claude/rules/mutation-testing.md`: unit layer only, prose not script, end of the plan rather
than per-commit. Every mutant targets `plugins/provenance/include/functions.inc.php` — the
Phase 2 composition layer, which is the only substantial provenance logic that loads with no
database and no Piwigo bootstrap.

**Method, and why it is stated.** DDEV runs Mutagen, so a host edit reaches the container a
moment later; a mutant applied and immediately tested is read from the *pre-mutation* file and
every result in the table shifts by one. So after each apply and each revert the container's
`md5sum` was polled until it matched the host's before any suite ran, and `git diff` was read to
confirm the edit landed at all (a `sed`/`perl` address that silently matches nothing produces
the same false "survived"). Both failure modes were hit building the Phase 1 table; neither
recurred here. The file was restored from a pristine copy after every mutant and confirmed
byte-identical to HEAD (`615f7990…`) before the next.

| Mutant | Expected killer | Result |
|---|---|---|
| `strlen($text) <= PROVENANCE_IPTC_MAX_BYTES` → `<` | the 1999/2000/2001-byte BVA trio | killed: `testExactlyAtTheCapIsUnchanged`, `testMultiByteTextUnderTheByteCapIsUnchanged` |
| `strlen` → `mb_strlen` in `provenance_truncate_for_iptc()` (both calls) | the multi-byte truncation tests | killed: `testCharacterStraddlingTheBoundaryIsNotSplit`, `testAllMultiByteTextStaysValidUtf8`, `testJustOverTheCapIsTruncated`, `testTruncationMarkIsAppendedWithinTheBudget`, `BuildArgfileTest::testOnlyTheIptcSlotCarriesTheTruncatedCaption` |
| `PROVENANCE_CAPTION_SEPARATOR` emptied | the composition-order test | killed — but see the finding below |
| `if ($part !== '')` → `!== null` in `provenance_compose_caption()` (emptiness check weakened to a presence check) | the whitespace-only-part test | killed: `testWhitespaceOnlyPartIsOmitted`, `testEmptyPartIsOmitted`, `testAllPartsEmptyReturnsEmptyString` |
| `provenance_field_order()` wrapped in `array_reverse()` | the deterministic-caption test | killed: 9 cases across `CaptionPartsTest` and `ComposeCaptionTest`, including `testOrderComesFromTheFieldOrderNotTheInputOrder` |
| `array_merge(array('-charset', 'iptc=UTF8'), $lines)` → `$lines` | the argfile line-order test | killed: `testFullLineSequence`, `testCharsetDeclarationComesFirst` |

**Nothing else moved** in any row: each mutant killed exactly the tests that watch it, and the
remaining tests of the 138 stayed green every time. No mutant survived, so there is no
unreachable-boundary finding here of the kind the typetags table records for `$l >= 0.45`.

### The finding: two tests died of a `ValueError`, not of an assertion

The emptied-separator mutant was killed, but two of its four casualties were killed for the
wrong reason. `PROVENANCE_CAPTION_SEPARATOR` is read *by the tests* as well as by production,
so with it set to `''`:

- `ComposeCaptionTest::testMissingKeyIsOmitted` raised
  `ValueError: substr_count(): Argument #2 ($needle) must not be empty`;
- `ComposeCaptionTest::testSinglePartCarriesNoSeparator` failed on
  `assertStringNotContainsString('', …)`, which is a check that cannot fail on a real separator
  and cannot pass on an empty one — it was never testing the behaviour it named.

A test that dies of a PHP error tells you the constant changed; it does not tell you the caption
came out wrong. The behavioural kill was real and came from elsewhere
(`CaptionPartsTest::testTheResultComposesInFieldOrder` compared the whole composed string), so
the mutant is genuinely dead — but two of the watchmen were asleep.

Fixed the way `.claude/rules/test-design.md` (*anti-vacuity*) prescribes, by giving each of the
two an explicit lower-bound guard ahead of the assertion that depends on it:

```php
$this->assertNotSame('', PROVENANCE_CAPTION_SEPARATOR, 'an empty separator makes the count below meaningless');
```

Both guards were then **watched failing** — the mutant re-applied, and both tests re-run to
confirm they now report the guard's message instead of a `ValueError` — before the mutant was
reverted. Suite after the fix: 138 tests / 312 assertions (was 310), green.

This is the same shape as the two typetags findings above: a mutant that dies while the test
watching it is weaker than it looks. It is the reason the pass is worth running once even when
every mutant is killed.

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
| 2026-08-29 | Phase 4 of the provenance plan opened two manual boxes: the album provenance modal opens, saves and survives a reload; and the injected block does not disturb the Properties layout at narrow widths. | **Replaced 2026-08-29** by `plugins/provenance/tests/e2e/album-provenance.spec.js` (4 specs), each watched failing against a mutant: a wrong web-service method name in the JS, a suppressed `value=` in the template, a 4000px `min-width` on the button, and a 5px `width` on the modal fields. |
| 2026-08-29 | Phase 6 of the provenance plan opened two manual boxes: a written file's caption is visible where a normal photo tool shows it; and a full 76-photo write-back costs roughly one extra copy on disk. | **Replaced 2026-08-29** by `plugins/provenance/tests/Integration/WriteBackTest.php` → `testAnIndependentReaderFindsTheCaption` (ImageMagick's `identify`, a separate implementation from the writer, finds the caption in EXIF, IPTC-IIM 2:120 and XMP `photoshop:Headline`) and `testAWriteCostsOneExtraCopyOnDisk` (per-file ratio against the pristine size). Watched red against a dropped `PROVENANCE_IPTC_CAPTION_TAG` and an added `-overwrite_original` respectively. Only *legibility in a GUI viewer* survives — see the open table below. |
| 2026-08-29 | Phase 8 of the provenance plan opened two manual boxes: the public row reads correctly in German, the install's own locale; and it does not break the info panel on a narrow viewport. | **Replaced 2026-08-29** by `plugins/provenance/tests/e2e/provenance.spec.js` → `the label is rendered in the language the account browses in` (the expected label is resolved by `seed.php` out of the browsing account's own language file, so an untranslated key fails) and `the row stays inside its column on a narrow viewport` (320px, `document.body` overflow — the two obvious probes were measured flat first; see the non-coverage table). |
| 2026-08-29 | Phase 8: the provenance row and the Colored Tags injection both land on one logged-in picture page, and a logged-out visitor gets the row at all. | **Partly replaced 2026-08-29.** The guest half is now `PicturePageSourceTest::testGuestGetsTheRow` (watched red against an `is_a_guest()` early return) and `provenance.spec.js` → `the row is visible without logging in`. The coexistence half stays a hand check — the rule behind it is asserted at the unit layer instead; see the non-coverage table. |
| 2026-08-29 | The write-back button's browser path, which Phase 6's plan asked for no spec for. | **Replaced 2026-08-29** by `plugins/provenance/tests/e2e/writeback-provenance.spec.js` (4 specs), each watched failing against a mutant: a wrong web-service method in the client, a summary that drops the per-photo failure count, and a `fail()` that no longer sets `.provenance-error` (which killed both failure specs). |
| 2026-08-29 | Phase 7 of the provenance plan opened one manual box: upload a photo into a provenance-carrying album through the normal admin UI and confirm it arrives with the album's values. | **Replaced 2026-08-29** by `InheritTest::testAnUploadedPhotoInheritsTheAlbumsProvenance` and `testWithTheLoungeOnTheValuesArriveWhenTheLoungeIsEmptied`, which drive the exact web-service sequence the upload screen issues — `pwg.images.upload` with a real file, then `pwg.images.uploadCompleted` — so nothing about the path is assumed. What a browser would add on top is plupload's chunking, which is core's code on a screen the plugin does not touch; per the placement rule, a spec there would restate integration coverage one layer up. |
| 2026-08-29 | Phase 9 of the provenance plan opened two manual boxes. One was descoped with the album-delete prompt ([decision 0013](decisions/0013-no-album-delete-prompt-in-v1.md)) and has nothing left to check. The other — the Batch Manager move prompt appears and its three choices behave as labelled — **was never performed by anyone**. | **Replaced 2026-08-29** by `plugins/provenance/tests/e2e/move-provenance.spec.js` (3 specs), written instead of ever being performed by hand: the entry was blocked on a seedable install, the gallery was resynchronised the same day, and automating it was cheaper than doing it once. Watched failing against the mutant that renders the injected block `display:none` — page source is unchanged by it, so `BatchManagerPageTest` stays green while all three specs go red, which is what puts these specs at the E2E layer rather than the integration one. `keep` gets no move of its own: it is also what a lost parameter produces, so a browser assertion on it could not fail (`InheritTest` distinguishes them by posting it explicitly). |
| 2026-08-29 | Phase 10 close-out: the six-mutant pass over the provenance unit suite (see the mutant table above). Each mutant was applied and reverted by hand, the container confirmed to have picked up the bytes before each run, and the file confirmed byte-identical to HEAD after each. | Not replaceable, and deliberately so — `.claude/rules/mutation-testing.md` records mutants as prose precisely because a script that patches and reverts source is a second thing to keep correct that fails silently when the patched line moves. The one durable product of the pass *is* automated: the two anti-vacuity guards added to `ComposeCaptionTest`. |
| 2026-08-30 | Phase 2 of the persons plan opened one manual box: point the rescan at a photo tagged in digiKam and confirm the names land in `piwigo_persons`. | **Replaced 2026-08-30** by `plugins/persons/tests/Integration/ReindexTest.php` → `testARescanIndexesAFileTaggedByAThirdPartyToolWithNoAppliedToDimensions`, which seeds the file with a plain `exiftool -json=` call in digiKam's shape — no `AppliedToDimensions` at all (KDE bug 429219) — and drives `persons_rescan_images()`. Watched red twice: against a parser default of `0` for unknown dimensions (killed at the precondition) and against an `(int)` cast in the indexer (killed at the `applied_w` assertion). A third mutant, `persons_positive_int_or_null()` returning `0`, **survived** — nothing reached that branch; the gap it exposed is now `ParseRegionInfoTest::testAnAppliedDimensionThatIsZeroOrNotANumberIsUnknown`, and the production rule it drove out is that applied dimensions are known only as a pair. Running an actual digiKam stays unautomated — see the open table below. |

### Open — no oracle, so no test

| Item | Why it cannot be automated |
|---|---|
| A first-time `PluginMaintain::install()` against an **empty schema** | Needs a throwaway Piwigo instance with its own database; this repository has one install and no way to provision another. Running it against the live install would mean `uninstall()` first, which drops `piwigo_typetags` and `piwigo_tags.id_typetags` — destroying real tag-colour data to test a method that is already idempotent by construction (`CREATE TABLE IF NOT EXISTS`, an `ALTER` guarded by `SHOW COLUMNS`, a `conf_update_param` guarded by `empty()`). The risk is not worth the coverage. What *is* automated: `CleanCheckoutTest` (4 tests) asserts every runtime file is committed and unignored — the half of "installs from a clean checkout" that this repository can actually get wrong — and `PluginActivationTest` asserts install()'s effects are present on the live install. |
| Badge contrast is *legible* for all 8 configured colours against the modus background | Subjective judgment. `get_color_text()` picks black or white by a lightness threshold and `rendering.spec.js` asserts the choice is applied, but whether the result reads comfortably is not a fact a machine can settle. |
| The hover opacity transition *feels* right | Subjective. The opacity values themselves are asserted; the perception is not. |
| A written file's caption *reads* correctly in a GUI viewer (macOS Preview, a phone gallery) | Subjective, and the falsifiable half is already automated: `WriteBackTest::testAnIndependentReaderFindsTheCaption` proves an independent library finds the caption in three standard slots. Whether a given viewer chooses to *show* that slot, and whether it looks right, is a judgment no assertion settles. Recorded while automating it: ImageMagick's EXIF reader replaces every non-ASCII byte with a dot while its IPTC and XMP readers do not — the file's bytes are correct, the reader is not. |
| A file produced by a **real** digiKam (or Picasa, or Apple Photos) rather than by exiftool in that tool's shape | No such binary is installed in the DDEV web image and none of these tools is scriptable from a container. The falsifiable half — that a file carrying MWG regions this plugin did not write is indexed, names and all, including the no-`AppliedToDimensions` shape digiKam produces — is automated (see the ledger). What stays is whether a given release of a given tool writes the shape recorded here; a fixture cannot settle that, only a file from that release can. |
| Committing does not *feel* slow with the pre-commit hook installed | Subjective, and a wall-clock assertion would violate *assert the causal fact, not a wall-clock figure*. |
