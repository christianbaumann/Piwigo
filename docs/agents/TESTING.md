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

Piwigo core has no test suite. `plugins/typetags`, `plugins/provenance` and `plugins/persons`
each carry all three layers, with their own `phpunit.xml` and their own `playwright.config.js`.
`plugins/persons` got its E2E layer with the public overlay in Phase 5 — see
[decision 0017](decisions/0017-no-e2e-tests-for-persons-phases-1-to-4.md) for why the four phases
before it had none.

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

**`plugins/provenance` integration, measured 2026-08-31** after the four core characterization
files landed: 181 tests / 952 assertions / 3 skipped in 68.7s. Unit re-measured 2026-08-31 at
the close of the handbook plan: 183 tests / 516 assertions in 0.018s, `GermanOverrideKeyTest`
included. The same run leaves the install at 5 albums, 105 photos, 8 tags and 8
colours — the values it started from.

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
| E2E specs for `plugins/persons` Phases 1-4 | No phase before 5 registers a prefilter, a template or an admin screen, so there is no rendered surface to drive; see [decision 0017](decisions/0017-no-e2e-tests-for-persons-phases-1-to-4.md) | The coordinate math and the RegionInfo parser are unit-level, the exiftool read and the index rebuild integration-level. The suite arrived with the overlay in Phase 5 and grew the editor's specs in Phase 6; the decision still holds for what came before it |
| An E2E spec for *a guest sees no person boxes* | The whole fact is that the server emits no stage, no box and no names row for a guest, which `PicturePageSourceTest::testAGuestSeesNoOverlay` asserts against the real page source | Restating it in a browser would put a rule one layer above the layer that owns it, against the placement rule in `testing.md`. There is no client-side half to witness: nothing is built in JavaScript |
| A `[NEG]` E2E spec for the overlay when JavaScript is disabled | The boxes are rendered and laid out in percent by the server; `overlay.js` only sizes `#persons-overlay`, so with no JavaScript the overlay is a zero-sized element and the boxes are simply invisible — no error, no broken layout | There is no failure mode to assert. The design choice that produces it (no region math in the browser) is what `PicturePageSourceTest` covers by finding the geometry in the page source |
| `MP:RegionInfo` (Microsoft/Picasa) and `.picasa.ini` regions | Deliberately not read or written — MWG plus `XMP-iptcExt:PersonInImage` only, per the plan's *What We're NOT Doing* | Would assert an import path that does not exist |
| `owner` as a reference to a people table | Free text in v1 (decision 3a); the reference-table item is in `docs/backlog.md` | Would assert a data model that does not exist |
| Touch and pen input on the region editor | `editor.js` binds `mousedown`/`mousemove`/`mouseup` only — measured 2026-08-31, there is no `touch*` or `pointer*` listener in the plugin — so there is no touch behaviour to assert. Carried in `docs/backlog.md` | A test would have to assert the *absence* of a feature. Playwright's touch emulation would drive events nothing listens for and fail for a reason that is not a defect |
| Stored regions after an administrator changes `images.coi` | `coi` is a display hint; it changes neither the file's bytes nor its `AppliedToDimensions`, so no stored region moves. Carried in `docs/backlog.md` against the day a crop is driven from it | Nothing changes, so any assertion would be a tautology. What *would* move regions — a physical rotation — is asserted instead, by `RotationTest` and `persons_rotation_delta()`'s unit cases |
| An external tool editing a file's regions behind Piwigo's back | The index is derived and nothing detects the drift; see [decision 0020](decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md). The candidate signal, `images.date_metadata_update`, is in `docs/backlog.md` | No behaviour exists to test. The repair that does exist is asserted: `IndexRebuildTest::testDroppingTheTablesAndRescanningRebuildsTheIndex` rebuilds the whole index from the files |
| An E2E spec per workflow the German handbook documents | Each workflow's outcome is already witnessed at the layer that can express it - `CoreAlbumCharacterizationTest`, `CorePhotoTextCharacterizationTest`, `CoreTagCrudCharacterizationTest` and `CoreUploadCharacterizationTest` at integration, the plugin suites at all three. What the browser adds is the *controls* the handbook names, and those got their own specs (see *The handbook's own claims* below). Browser coverage of the uploader was already refused with a stated reason earlier in this file | Restating an integration rule at the browser layer violates the placement rule in `.claude/rules/testing.md`: break the low-level behaviour and its own test must go red first |
| Pixel-diffing the handbook screenshots against a stored baseline | Rejected for the same reason screenshot comparison was rejected for the gallery itself: a photo gallery re-renders differently on a font, derivative or theme change that broke nothing, so the check fails on changes nobody made and is then disabled | Not a test of behaviour. `shoot.js`'s `assertOutput()` asserts the falsifiable part - every declared shot exists and no undeclared file sits beside them - and whether a shot still shows the screen its text describes is in the ledger |
| A test over the handbook's prose - reading a page and checking what it says | `check.php` covers what a machine can decide: every `src` and `href` resolves, every screenshot is referenced and every reference resolves, each page is well-formed XML, every quoted `admin.php?page=` route is one `admin.php:129-176` really resolves, and no em-dash or emoji. Beyond that there is no oracle but a reader | *Build no apparatus that proves another apparatus* (`.claude/rules/test-design.md`). A scan that reads a document looking for a word is the example the rule names |

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

## Mutant table — `plugins/persons` unit suite

Run 2026-08-31 against the persons unit suite (112 tests, 354 assertions at the time of the run;
399 after the finding below was fixed), by hand, one mutant at
a time, at the end of the plan rather than per-commit. Scope and method per
[decision 0001](decisions/0001-mutation-testing-unit-only.md) and
`.claude/rules/mutation-testing.md`. Every mutant targets
`plugins/persons/include/functions.inc.php`, the coordinate and merge math — the only substantial
persons logic that loads with no database and no Piwigo bootstrap, and the place where a silent
regression would be worst: a wrong box is still a box, and nobody notices until the faces are in
the wrong places in every file written since.

**Method.** Same as the provenance table above, for the same reason: after each apply and each
revert the container's `md5sum` was polled until it matched the host's before any suite ran, and
each edit was asserted to have changed the file at all (a replacement matching nothing produces
the same false "survived"). The file was restored with `git checkout` after every mutant and the
suite confirmed green again before the next.

| Mutant | Expected killer | Result |
|---|---|---|
| `persons_center_to_corner()`: `$x - $w / 2` → `$x + $w / 2` | the centre↔corner round-trip test | killed: `testCenterToCornerConvertsACenteredBox`, `testCornerToCenterIsTheInverseOfCenterToCorner`, `testABoxOverrunningTheLeftEdgeIsClippedNotDropped`, `testABoxOverrunningTwoEdgesIsClippedOnBoth`, `testAWidthOfExactlyOneCoversTheWholeImage`, `testAFloatArtefactIsRoundedAway`, `MergeRegionsTest::testAnAddThatOverrunsAnEdgeIsClipped` |
| `persons_clip_region()`: `$x > 1` → `$x >= 1` on the centre bound | the boundary pair at exactly 0 and 1 | killed: `testACenterAtExactlyOneIsKept` — and nothing else, which is the point: the mutant moves exactly one boundary and exactly one test watches it |
| `persons_rotate_region()`: `% 4` → `% 3` | the rotation-code equivalence classes | killed: `testRotationCodeThreeSwapsTheAxesTheOtherWay`, `testRotationCodeFourIsTreatedAsZero` |
| `persons_region_is_stale()`: `> PERSONS_STALE_RATIO_TOLERANCE` → `<` | the just-inside / just-outside boundary pair | killed: `testIdenticalDimensionsAreNotStale`, `testAProportionalResizeIsNotStale`, `testACropIsStale`, `testARatioDifferenceJustInsideToleranceIsNotStale`, `testARatioDifferenceJustOutsideToleranceIsStale` |
| `persons_merge_regions()`: the `$kept[] = $region;` that carries existing regions forward, removed — a merge turned into a replace | the "foreign region survives" test | killed: `testAddingASecondPersonKeepsTheFirst`, `testAForeignRegionSurvivesAnAdd`, `testRemovingOneRegionLeavesTheRest`, `testAMatcherWithoutABoxRemovesEveryRegionOfThatPerson`, `testRemovingSomethingThatIsNotThereIsANoOp`, `testAnAddWhoseCentreIsOutsideTheFrameIsDropped`, `testRemovingOneOfTwoBoxesForTheSamePersonKeepsTheOther` |
| `persons_minimum_box_ok()`: `and` → `or` | the one-axis-too-small case | killed: `testABoxTooSmallOnOneAxisOnlyIsRejected`, `testABoxOneEpsilonBelowTheMinimumIsRejected` |
| `persons_rotation_delta()`: the transpose check `$file_w === $applied_h` → `!==` | the display-only vs. physical pair | killed: `testAPhysicalRotationYieldsTheDelta`, `testAPhysicalRotationTheOtherWayYieldsTheOppositeDelta` |

**No mutant survived, and nothing else moved.** Each killed the tests that watch it and left the
rest of the 112 green. Two notes worth keeping rather than a finding:

- The plan's table named the merge mutant `array_merge` → replace. There is no `array_merge` in
  `persons_merge_regions()` — the function builds `$kept` in a loop — so the mutant was applied
  where the behaviour actually lives: the line that carries an existing region forward. Recorded
  rather than quietly swapped, per `.claude/rules/mutation-testing.md`.
- That mutant is the only one of the seven that killed by *error* as well as by assertion, and
  that is the pass's one finding — see below.

### The finding: one test died of a `TypeError`, not of an assertion

Under the merge mutant, `MergeRegionsTest::testRemovingOneOfTwoBoxesForTheSamePersonKeepsTheOther`
did not fail — it **errored**: `assertCount(): Argument #2 ($haystack) must be of type
Countable|Traversable|array, null given`. A merge that loses every region returns the *delete the
tag* shape, where `RegionInfo` carries no `RegionList` at all, so `['RegionList']` was `null` and
the count never ran. The kill was real and the behavioural kills came from the six siblings in the
same row, but this watchman was asleep: it reports "the shape changed", not "the wrong region
survived", and a subtler change to the same line would have found it silent.

Fixed the way the provenance table's finding was, and the way `.claude/rules/test-design.md`
(*anti-vacuity*) prescribes — every count assertion gets a guard proving the thing counted exists.
The ten `$merged['regioninfo']['RegionList']` accesses in the file now go through one helper:

```php
private function regionListOf(array $merged): array
{
    $this->assertIsArray($merged['regioninfo'], 'the merge returned no RegionInfo at all');
    $this->assertArrayHasKey('RegionList', $merged['regioninfo'],
        'the merge returned the "delete the tag" shape, not a region list');
    $this->assertIsArray($merged['regioninfo']['RegionList']);

    return $merged['regioninfo']['RegionList'];
}
```

The guard was then **watched reporting**: the mutant re-applied, the run came back with 7 failures
and 0 errors where it had been 6 failures and 1 error, then reverted. Suite after the fix:
112 tests / 399 assertions (was 354), green.

## The two tooltips only a browser can witness (2026-08-31)

`GermanScreenTest` asserts the picture page's `+` and `×` tooltips read German, but it reads
page source, and both titles sit there inside a `<script>` rather than on an element: the `×`
control has no server-rendered form at all, and the `+` badge is rebuilt by the same script
after a removal. A source assertion therefore stays green if the script stops carrying its
declaration onto the element — the regression a reader of the handbook would actually see.

`plugins/typetags/tests/e2e/german-tooltips.spec.js` closes it with two cases. Neither restates
the wording, which belongs one layer down: each compares the rendered `title` against the
declaration in the script's own text, read by `PicturePage.declaredTooltip()`, so no translated
string is typed into a spec.

| Mutant | Killed |
|---|---|
| the `title=` dropped from the `×` control's declaration | the remove case only |
| the `title=` dropped from the JS-built `+` badge's declaration (`events_public.inc.php:329`, not the server-rendered `:184`) | the rebuilt-badge case only |

Both mutants first came back green until `_data/templates_c/` was cleared — the same stale
compile that hid the `Créer` mutant in Phase 2. The plugin's markup is embedded into the
compiled core template by a prefilter, and Smarty's `compile_id` hashes only the callback name.

typetags E2E is now 28 (27 specs + 1 auth setup), measured 2026-08-31.

## Core characterization tests — watched go red (2026-08-31)

The four `Core*CharacterizationTest` files in `plugins/provenance/tests/Integration/` cover the
workflows the German handbook documents that had no test at any layer: core album creation and
description, the core photo-properties screen, core tag CRUD, and the core upload. Every case is
`[ERR]` — core carries no requirements document, so the oracle is the current implementation.

They pass on their first run, which is normally the tell that a test recorded code rather than
drove it. Per *proving a check can actually fail* in `.claude/rules/test-design.md`, each was
therefore watched go red: one core mutation per behaviour claim, applied on the host, checksum-
polled against `ddev exec md5sum` until the container saw the new bytes, the named tests run,
then reverted with `git checkout --` and polled back. **36 of 36 went red.**

This is not the mutation testing of `.claude/rules/mutation-testing.md` — that is a unit-layer
audit of test strength, and these are integration tests. It is the weaker, mandatory claim that
each characterization test can fail at all.

| Mutant | Killed |
|---|---|
| `create_virtual_category()` writes a fixed `uppercats` | `testANewTopLevelAlbumTakesItsOwnIdAsUppercats`, `testAddingWithAParentNestsTheAlbum` |
| `create_virtual_category()` stores a mangled name | `testAddingAnAlbumReturnsAnIdAndCreatesTheRow`, `testAUnicodeNameSurvivesTheRoundTrip` |
| the blank-name guard never fires | `testAnEmptyNameIsRefused` |
| a new album gets `''` instead of NULL as its description | `testANewAlbumHasAnEmptyDescription` |
| `pwg.categories.setInfo` drops `comment` from its update columns | `testSetInfoStoresTheDescription`, `testALongDescriptionIsStoredWhole` |
| `pwg.categories.setInfo` strips markup even with a valid token | `testSetInfoWithNoTokenStripsMarkup` |
| `pwg.categories.add` loses `admin_only` | `testAGuestCannotAddAnAlbum`, `testANormalUserCannotAddAnAlbum` |
| `picture_modify.php` stops writing the title | `testTitleAuthorDateAndDescriptionAreStored`, `testEachFieldCanBeClearedIndependently` |
| `picture_modify.php` writes a posted `file` field | `testTheFilenameIsNotWritable` |
| the properties form loses `check_pwg_token()` | `testAPostWithoutAValidTokenIsRefused` |
| `admin.php` **and** `picture_modify.php` both lose `check_status(ACCESS_ADMINISTRATOR)` | `testANormalUserIsRefused` |
| the `date_creation` pattern accepts anything | `testAnInvalidCreationDateIsRejectedOrNormalised` |
| the description is stripped whatever `allow_html_descriptions` says | `testUnicodeAndMarkupInTheDescription` |
| Linked albums associates instead of moving | `testLinkedAlbumsUnlinksAlbumsLeftOutOfTheSelection` |
| `move_images_to_categories()` stops sparing the storage album | `testTheStorageAlbumCannotBeUnlinked` |
| `create_tag()` derives no `url_name` | `testAddingATagCreatesTheRowAndAUrlName`, `testATagWithAUmlautGetsAUsableUrlName` |
| `create_tag()` never finds the tag it would duplicate | `testAddingADuplicateNameIsRefused` |
| `pwg.tags.rename` stops refusing a name that is taken | `testRenamingToAnExistingNameIsRefused` |
| `pwg.tags.rename` keeps the old name | `testRenamingChangesTheNameAndTheUrlName` |
| `delete_tags()` leaves the photo links behind | `testDeletingRemovesTheTagAndItsImageLinks` |
| `delete_tags()` cascades into `piwigo_typetags` | `testDeletingAColoredTagLeavesNoOrphanTypetagsRow` |
| `pwg.tags.merge` keeps the tags it merged away | `testMergingMovesEveryImageLinkAndRemovesTheSource` |
| merging a tag into itself becomes a silent success | `testMergingATagIntoItselfIsRefusedOrIsANoOp` |
| the properties screen appends tags instead of replacing | `testAssignmentReplacesRatherThanAppends`, `testAssigningAnEmptyListRemovesEveryTag` |
| `pwg.tags.add` loses `admin_only` | `testAGuestCannotCreateRenameOrDeleteATag`, `testANormalUserCannotCreateRenameOrDeleteATag` |
| `empty_lounge()` associates nothing | `testUploadFollowedByUploadCompletedLinksThePhotoToTheAlbum`, `testTheLinkMaterialisesOnlyAfterTheLoungeIsEmptied` |
| the uploader accepts every file type | `testAnUnsupportedExtensionIsRefused` |

### Two mutants that first looked survived, and were not

Both were re-run and both then killed. Neither was a weak test; each was an artefact of the
apparatus, which is exactly what the checksum discipline exists to catch.

- **`pwg.tags.add` loses `admin_only`** reported the guest case green and the normal-user case
  red under the same applied mutant. A direct `curl` against the mutated install proved a guest
  *did* get through, so the test could not have been green for a real reason. Re-running the
  mutant turned both red. The first run had read the pre-mutation file: the checksum poll answers
  when the bytes land, not when the running request picked them up.
- **`delete_tags()` cascades into `piwigo_typetags`** was first written to delete the colour rows
  just before `trigger_notify("delete_tags", …)`, which runs *after* the tag rows are already
  gone — so its subquery matched nothing and the mutant was a no-op, not a survivor. Moved ahead
  of the `TAGS_TABLE` delete, it killed the test.

The colour row the test uses is one it creates and removes itself rather than the install's own,
so that mutant cannot delete a real colour that reverting the source would not bring back.

### The one thing the pass changed in the tests

Under the mutants, PHP warnings printed by the mutated core land in front of the JSON body, so
`json_decode()` returns null and the fixture never adopts the album it just created. Six albums
were left behind across the run and removed by hand afterwards; the install was verified back at
5 albums, 105 photos, 8 tags and 8 colours. This is a property of the mutants, not of teardown:
a real regression produces no warning output, and the adopt-then-destroy path runs normally.

## Mutant table — `GermanOverrideKeyTest` (2026-08-31)

Run against the provenance unit suite at 183 tests / 516 assertions, by hand, one mutant at a
time, per `.claude/rules/mutation-testing.md`: unit layer only, prose not script, end of the plan
rather than per commit. Every mutant was applied, the container confirmed to hold the new bytes
(host `md5` compared against `ddev exec md5sum` in a poll loop) and only then run; the file was
restored and the suite re-run green afterwards. A `sed` that matched nothing was made a loud
failure rather than a silent "survived".

The guard is structural: its production side is not a function but the **template files that emit
the English literal** and the **language file that translates it**. Mutants are therefore listed
in pairs — one weakening the test, one damaging the thing the test watches — because a mutant that
only weakens an assertion can never turn a green run red, and reporting it as "survived" without
its pair would be an honest-looking lie.

| Mutant | Expected killer | Result |
|---|---|---|
| M1 `assertSame($times, ...)` replaced by a tautology | every case in the guard | **survived — invalid mutant.** A weakened assertion cannot make a green suite red; only its pair can say whether the count is load-bearing. See M4b |
| M2 the anti-vacuity `MIN_BYTES` guard lowered to `-1` | the case reading a path that does not exist | **survived on an intact tree — invalid mutant as written.** `assertFileExists()` already covers a missing path, so the plan's expected killer does not exist. Probed properly instead, below |
| M3 one literal altered by one character in the test's data set | that literal's case only | killed — exactly 1 failure, the `Rename album` case |
| M3b the same literal altered in `albums.tpl`, the template that emits it | that literal's case only | killed — exactly 1 failure, the same case. This is the regression the guard exists for |
| M4 `substr_count(...)` replaced by presence-only (`str_contains(...) ? 1 : 0`) | the two-occurrence cases | killed — exactly 2 failures, `Add tag` and `Remove tag`, the only entries whose expected count is 2 |
| M4b the literal duplicated in `albums.tpl` so it occurs twice | the count assertion | killed — 1 failure. Together with M4 this is what proves the assertion counts rather than merely looks |
| M5 one `$lang[...]` key renamed in `local/language/de_DE.lang.php` | `testTheLanguageFileStillTranslatesTheKey` | killed — 1 failure |
| M6 `language/de_DE/admin.lang.php` given a key the override also carries | `testTheOverrideDoesNotShadowACoreGermanString` | killed — 1 failure |
| M7 the `%s` and `%d` placeholders reordered in the override's format string | `testTheFormatStringsKeepTheirPlaceholderSequence` | killed — 1 failure |

### The anti-vacuity guard is reachable, and it took a second probe to show it

M2 survived because on an intact checkout nothing is vacuous. Whether the guard is dead code or
simply idle was settled by forcing the state it exists for: `albums.tpl` was replaced with the
single line `{'Rename album'|@translate}` — 28 bytes, still holding the literal, so the count
assertion passes on a file that has lost everything else.

- With `MIN_BYTES` in place: 1 failure, `Tests: 183, Assertions: 515`, reporting
  *anti-vacuity: too little was read from admin/themes/default/template/albums.tpl*.
- With `MIN_BYTES` lowered to `-1`: `OK (183 tests, 516 assertions)`.

So the guard is the only thing standing between a gutted template and a green run. Whoever
deletes it removes the watchman, not the risk.

### What the pass did not change

Nothing. No test was strengthened as a result — the two survivors are both invalid mutants with
their reason recorded above, not weak assertions. That is a real finding rather than a null
result: it says the guard's assertions are exactly as tight as the behaviour they watch, and no
tighter.

## The handbook's own claims, and the E2E gap they exposed (2026-08-31)

`docs/handbuch/` documents five workflows. Reconciling every control it names against the
three suites found that most of what it tells a reader to click was witnessed by nothing:
the controls are assembled or revealed by JavaScript, so the integration layer's page-source
assertions cannot reach them, and a change to any of them would leave the handbook quietly
describing a screen nobody has.

Nineteen specs were added. None writes anything: where a workflow's outcome is already proven
at the integration layer - creating an album, associating a photo - the spec stops at the
control the handbook names rather than restating the outcome one layer up
(`.claude/rules/testing.md`, placement rule).

- `plugins/provenance/tests/e2e/core-admin-screens.spec.js` (+8, with `support/CoreAdminPages.js`).
  Core has no suite of its own, which is why this file is already its home. The album row's six
  actions and their labels, the sort action's disabled state, the add-album dialog, the tag menu's
  five entries, the merge panel, the upload screen's Optionen control, the Batch Manager's
  associate picker, and `[NEG]` four core admin screens refusing a normal account.
- `plugins/provenance/tests/e2e/handbuch-pages.spec.js` (+7). The handbook pages opened over
  `file://`, which is the plan's own success criterion and a fact no server-side check can reach.
- `plugins/typetags/tests/e2e/normal-account.spec.js` (+1). See below.

Both suites gained a second setup test saving a non-administrator session; neither had one.

### The finding: a whole suite that could not witness its own permission model

Every typetags spec ran as `typetags_webmaster`. Decision 0005 deliberately opens colour
assignment on the picture page to any logged-in non-guest, and `04-schlagworte.html` builds a
section on that - it tells readers without administrator rights to add and remove coloured tags
there. A webmaster passes an admin gate as readily as a non-admin one, so the suite could not
tell the two apart: had those methods become `admin_only`, nothing would have gone red and the
handbook would have been wrong for most of its readers. Watched red by adding an `is_admin()`
guard to `ws_typetags_image_addTag()`.

### The finding: the handbook documented a button by what its icon looks like

Writing the row-action spec surfaced that `Automatische Sortierreihenfolge` orders **sub-albums**
(`albums.js:110-118` sets `simpleAutoOrder` to `str_sub_album_order`) and is greyed out for an
album with none (`albums.js:392-393`). `01-alben.html` said it reorders the album's photos. The
page was corrected in the same change.

The spec that records it is stated as an **invariant** - the action is inert exactly on rows
marked as having no children - rather than as a distribution. Every album on this install is
currently top-level, so only the inert side is exercised today; a spec demanding both would go
red the day somebody nests an album. The first draft did demand both, and its anti-vacuity guard
fired immediately, which is how the flat tree was discovered.

### Every new spec watched go red

All nineteen passed on their first run, which is normally the tell that a test recorded code
rather than drove it. Each mutant below was applied to production code, the container's
`md5sum` compared against the host's before the run (Mutagen delays a host edit reaching the
runtime), and reverted afterwards; `git diff` over `admin/`, `include/`, `themes/` and
`language/` was empty at the end.

| Mutant | Expected killer | Result |
|---|---|---|
| `is_admin()` guard added to `ws_typetags_image_addTag()` | the normal-account colour spec | killed |
| `.move-cat-order` greying removed (`albums.js:393`) | the sort-action invariant | killed |
| a row action's `title` replaced with an English literal (`albums.js:361`) | the row-label spec | killed |
| the `Duplicate` entry deleted from the tag menu (`tags.tpl:70`) | the five-entries spec | killed |
| `Confirm merge` translated into German (`tags.tpl:110`) | the `[ERR]` merge spec | killed |
| `id="place-end"` renamed on the add-album radio (`albums.tpl:181`) | the add-album dialog spec | killed |
| `slideToggle()` removed from the Optionen control (`photos_add_direct.js:92`) | the upload-screen spec | killed |
| `id="associate_as"` renamed (`batch_manager_global.tpl`) | the associate-picker spec | killed |
| the stylesheet href broken on one handbook page | that page's `file://` spec | killed |
| the normal session file replaced with the webmaster's | the four `[NEG]` refusal specs | killed (all four) |

Two notes on honesty. The refusal specs are the one case where the mutant is the **fixture**
rather than production code: an admin gate has no production mutation that makes a non-admin
pass without opening the gate for real, so the spec's discriminating power is shown by handing
it the wrong account. It has to be run with `--no-deps`, or the setup project rewrites the
session before the specs read it - the first attempt did exactly that and reported four passes
that meant nothing.

And one mutant was **discarded rather than recorded as surviving**: forcing
`style="display:block"` onto `#uploadOptionsContent` left the spec green, because
`photos_add_direct.js:90` hides the element on init regardless. That mutant changed no
observable behaviour, so it was an invalid mutant, not a weak test; it was replaced with the
`slideToggle()` removal above, which is the behaviour the spec actually claims to watch.

### Suites after the change (measured 2026-08-31)

E2E: provenance 48, typetags 32, persons 31. Both changed suites pass twice in a row and in
reverse file order. The PHP suites were not re-run: this change touches no PHP production code,
only documentation, E2E specs and one new documentation checker.

### What is still not covered, and why

Named rather than dropped, per *report gaps, don't hide them*:

- **The album description and the four photo texts on the public page.** Server-rendered, so the
  right layer is a source assertion, not a browser; adding a browser test would restate a lower
  layer's rule.
- **Creating an album through the add-album dialog end to end.** The dialog is covered; the
  outcome is `CoreAlbumCharacterizationTest`'s. A browser spec would have to create a real album
  and clean it up, and a half-failed run leaves exactly the leftover Phase 1 of this plan spent
  its time removing.
- **The tag delete confirmation and the orphan-tags `Überprüfung` dialog.** Both destructive on
  confirm; both documented from the template rather than witnessed.
- **Persons rename and delete on the admin screen.** `AdminPersonsPage.js` carries no locators
  for them. Pre-existing gap, unrelated to the handbook.

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
| 2026-08-30 | Phase 3 of the persons plan opened two manual boxes: open a written file in digiKam or an exiftool GUI and confirm the face shows in the right place; and confirm an `_original` sidecar sits beside the written image. | **Replaced 2026-08-30** by `plugins/persons/tests/Integration/WriteRegionsTest.php`. The second box is `testTheOriginalBytesAreKeptAsASidecar` (the sidecar exists and is byte-for-byte the pre-write size). The first box's falsifiable half is `testAnIndependentLibraryFindsTheRegionInTheStandardXmpPacket`: ImageMagick (`convert <file> xmp:-`) extracts the raw XMP packet knowing nothing about MWG, and the namespace URI, `mwg-rs:Name`, `mwg-rs:Type`, `stArea:unit` and all four coordinates are asserted as text in it. That is what an exiftool round trip cannot witness — exiftool reading back what exiftool wrote cannot tell the standard slot apart from one only exiftool knows. Watched red against a write payload with `RegionInfo` emptied. **It found a real defect on its first run**: `persons_clip_region()` round-tripped every region through the corner form, so a box needing no clipping came back as `0.10000000000000003` and that is what went into every file. Fixed, with `RegionGeometryTest::testARegionInsideTheFrameIsReturnedByteForByteUnchanged` (`assertSame`, not a delta — a tolerance is exactly what hid it). What survives is whether a GUI *draws* the box where a human expects — the same subjective half already open for digiKam below. |
| 2026-08-30 | Phase 3's concurrency case was watched failing against two mutants before being trusted (`testConcurrentWritersEachLandTheirOwnFace`, eight processes). This is *proving a check can actually fail*, not mutation testing — one targeted change each, at the layer that owns the behaviour, per `.claude/rules/mutation-testing.md`'s unit-only scope for mutant tables. | Not replaceable. Recorded because the two mutants fail differently and only one of them is the mode worth designing against: with **no lock at all** the writers collide inside exiftool ("Temporary file already exists") and every worker exits non-zero — loud. With the **lock narrowed to the write**, so the file is read before it is taken, all eight writers report success and the file comes back holding **one** face. The silent one is why `persons_apply_change()` holds the lock across the whole read-merge-write. The container was confirmed to have picked up each mutant (`md5sum` polled against the host) before any run, and the file restored from a pristine copy after. |

| 2026-08-30 | Phase 4 of the persons plan opened one manual box: call `pwg.persons.getList` from the browser as a normal user and confirm the recency ordering. | **Replaced 2026-08-30** by `plugins/persons/tests/Integration/SearchTest.php` → `testGetListWithNoQueryReturnsRecentPersonsMostRecentFirst` and `testTaggingAnExistingPersonAgainMovesThemToTheFrontOfTheRecentList`, both issued over `ws.php` as `persons_normal` with a session cookie — the same request the browser makes, so a spec would restate it one layer up. Automating it **found a real defect**: the first test forces `piwigo_persons.lastmodified` with SQL, and a person row was inserted once and never updated again, so `ORDER BY lastmodified DESC` meant *most recently created*, not *most recently used* — tagging somebody the gallery already knew left them at the back of the picker forever. The second test earns the value through a real `addRegion` instead, was watched failing for exactly that reason, and drove `persons_touch_persons()`, which bumps only the names the call added (touching during the reindex would count a person as used because somebody *else* was tagged on the same photo). |
| 2026-08-30 | Phase 5 of the persons plan opened two manual boxes: resize the window slowly across a derivative-switch threshold and confirm the boxes track the photo; and check on a HiDPI display, where `rvas_choose()` removes `usemap` and takes a different branch. | **Both replaced 2026-08-31** by `plugins/persons/tests/e2e/overlay.spec.js`. The resize box is `the boxes track the photo across a stepped resize`, which walks 1200 -> 1000 -> 900 -> 800 -> 620 and asserts the geometry at every settled step. The HiDPI box is a `deviceScaleFactor: 2` describe block: `places the boxes on the rescaled photo`, and `leaves the theme click handler able to navigate` - the branch with no `<area>` map, which the 1x navigation spec never reaches. Both carry the anti-vacuity assertion that proves the branch was really taken: at least one step changed the rendered width, and `usemap` is absent. Recorded because the first attempt at this entry claimed **both** were unautomatable and both claims were wrong: the resize one was written off after a `settle()` that returned on the pre-resize layout made it look as though a resize never crosses a threshold (it does - the guard caught the stale read), and the HiDPI one was written off without trying `deviceScaleFactor` at all. |

| 2026-08-31 | Phase 5 verification pass: the two browser-only facts nothing covered — a box is hidden until the photo is hovered, and hovering a name dims the photo outside that box. | **Automated the same day** as `a box is hidden until the photo is hovered` and `hovering a name dims the photo outside that box`. Neither is reachable below E2E: the page source carries a class name, not a computed opacity or a `box-shadow` spread, and the dim is a `:has()` selector that would stop matching with no other symptom. The dim spec found a defect **in itself** on its first run — `dimSpread()` mapped `'1280px'` through `Number`, which is `NaN`; `Math.max` of a `NaN` is `NaN`, which crosses the Playwright bridge as `null` and compares false against everything. It was a check that had silently stopped checking, and only printing the value found it. Now `parseFloat`, with the reason recorded in the helper. |
| 2026-08-31 | Phase 6 of the persons plan opened two manual boxes: tag a face on a real photo and confirm the box sits where it was drawn after reload; and confirm the picker never covers the face being tagged, at several box positions. | **Both replaced 2026-08-31** by `plugins/persons/tests/e2e/editor.spec.js`. The first is the tail of `a drawn box survives a reload with its name on it`, which records the drawn rectangle's rendered box before committing and compares the reloaded box against it within 2px — the whole round trip, display box to MWG's pre-rotation centre origin, into the file, out again, and back to a fraction of the photo's rendered size. The second is `the picker never covers the box being named`, which drags at five positions including all four corners and asserts the picker's intersection with the drawn box is exactly zero. Both were watched red: the first against `toStored()` dropping the centre offset, the second against the placement scorer picking the *most* overlapping candidate instead of the least. A real photo and a human's eye are not what either box needed — what it needed was a box drawn by a mouse and read back out of the file by a process that is not the plugin. |
| 2026-08-31 | Phase 6 verification pass: two things the phase built that its own success criteria never named — the per-box delete affordance, and the disabled editor on a host with no exiftool. | **Covered 2026-08-31** by `plugins/persons/tests/e2e/editor.spec.js`: `the delete control is reachable only while tagging`, `deleting a box removes it from the page and from the file` (read back by the independent exiftool call), and `offers the editor disabled, with the reason on it`. The last forces the state instead of waiting for it — `seed.php --exiftool=missing` writes a `persons_exiftool_path` config row pointing at a directory holding no binary, which `load_conf_from_db()` copies into `$conf` before plugins load. All three were watched red: `box.remove()` made a no-op, the delete button's `display: none` made `block`, and `PERSONS_EXIFTOOL` hardcoded true. Recorded because a criteria list is not a coverage list — both features would have shipped untested at the layer that owns them. |

| 2026-08-31 | Phase 7 of the persons plan opened one manual box: confirm the admin tagging screen and the public page place an identical region identically. | **Replaced 2026-08-31** by `plugins/persons/tests/e2e/admin.spec.js` → `a region drawn here sits where the public page draws it`. It tags a face on the admin screen, then measures the same region on both surfaces as fractions of the photo's rendered box — the only frame of reference they share, since they render different derivatives at different sizes — and asserts the two agree within 0.01 of the photo. Watched red against `$image['rotation'] = 1` forced on the admin screen, which also killed `an administrator draws a box and the file carries it`. The link injection got its own spec for the same reason: `PhotoModifyAnchorTest` guards the anchor string, but only `the photo properties screen links to it` witnesses the prefilter actually running — watched red against a removed `set_prefilter()` call. |
| 2026-08-31 | Phase 7 build: the first admin spec run turned three green editor specs red. | Not a regression in the phase's code — two defects the phase's own leftovers exposed. `PicturePage.typeName()` waited for *an* option rather than the one it typed, and the picker opens on the most recently used persons, so on an install with any, Enter committed a stranger; it now waits for the option carrying that name. And `seed.php --restore` did its person cleanup only after finding a snapshot, so a person created through the UI outlived every later restore. Both fixed in the same commit; the leftover row that found them was deleted by hand. Recorded because the suite passed twice in a row before this phase and would have kept passing — the flake needed a second spec creating a person to become visible. |
| 2026-08-31 | Phase 7 verification pass: the two refusal branches of the admin screen its own criteria never named — a photo id that no longer exists, and an id that is not a usable one. | **Covered 2026-08-31** by `plugins/persons/tests/Integration/AdminPhotoScreenTest.php`: `testAPhotoThatNoLongerExistsRendersAMessageRatherThanAnError` and `testAnUnusableImageIdIsRefusedBeforeTheScreenIsReached` (`0`, `-1`, `abc`). Integration rather than E2E: no browser is needed to witness a page that renders a message instead of a diagnostic, and the E2E layer already owns the screen's editor. Both watched red — the first against the `pwg_db_num_rows()` guard removed, the second against the dispatcher's bound loosened to `< 0`, which answered **HTTP 500** on `image_id=abc`. That 500 is the finding: the dispatcher's int cast is what stands between a hand-edited admin URL and a fatal error, and nothing had been asserting it. |
| 2026-08-31 | Phase 8 of the persons plan opened one manual box: delete the two tables, run a full rescan, and confirm the index comes back. | **Replaced 2026-08-31** by `plugins/persons/tests/Integration/IndexRebuildTest.php` → `testDroppingTheTablesAndRescanningRebuildsTheIndex`, which uninstalls the plugin through `pwg.plugins.performAction` (dropping both tables), reinstalls, rescans the whole gallery through `pwg.persons.rescan` in chunks, and snapshots both tables verbatim first so a failure — exactly the case where the rescan did *not* restore everything — puts the install back. It asserts **nothing the index held is missing**, and that its own two fixture photos come back exactly; it deliberately does not assert the rebuilt index is byte-identical, because a rescan reads every file and a file whose regions were never indexed contributes new rows. Demanding otherwise would assert a fact about this gallery rather than about this code. That is also why the test is the one destructive outlier of the persons suite, flagged as such in `.claude/rules/plugin-test-suites.md`. |
| 2026-08-31 | Phase 9 verification pass: four items the plan's own Testing Strategy listed and that had never landed — reconciling every bullet against the suite is what found them. | **All four closed 2026-08-31.** (1) The last cell of the merge decision table, E=1 A=1 R=1, is now `MergeRegionsTest::testARegionInBothAddAndRemoveIsKept` — and the plan's predicted outcome was **wrong**: the add wins, because `persons_rename_person()` removes the old name and re-adds the same boxes under the new one in one call, so remove-wins would delete a renamed person's regions. (2) The anti-vacuity byte floor moved into `PicturePageSourceTest::page()` and `markup()`, so all 13 scans carry it instead of the three that had it by hand; watched red with the constant raised past the page size. (3) `testTheColoredTagsInjectionAndTheOverlayCoexistOnOnePage` is the only place both plugins' picture-page prefilters are observed on one rendered page; it skips loudly rather than passing vacuously when the install renders no Colored Tags markup, and was watched red with `PERSONS_TPL_INJECT_POINT` broken. (4) `editor.spec.js` → `picking an existing person from the list tags that same person`, which has to draw, name and delete a box first: `ws_persons_getList()` never offers somebody already on this photo. Watched red with the reuse lookup in `persons_person_id_from_name()` disabled — which also surfaced that `piwigo_persons.name` carries a UNIQUE index, so a duplicate person row is impossible by schema and the spec asserts reuse rather than non-duplication. |
| 2026-08-31 | Phase 9 of the persons plan left one manual box: the `CLAUDE.md` commands copy-paste and run on a fresh checkout. | **Executed 2026-08-31, and it found two things.** A real clone was made inside the web container (`git clone --local --no-hardlinks /var/www/html /tmp/freshclone`) and the documented fresh-clone steps run against it: `composer install -d plugins/persons` and `npm install` both succeeded and `plugins/persons/vendor/bin/phpunit --testsuite unit` came back green (112 tests at that HEAD); the same for `plugins/provenance` (138). **Finding 1**: the documented steps omitted `git submodule update --init --recursive`, so on a fresh clone `plugins/typetags` is an empty directory and every typetags command fails on a missing `composer.json` — `.claude/rules/plugin-test-suites.md` now says so, in the same change that measured it. **Finding 2**: that init cannot succeed today — the superproject records submodule commit `44fdd06`, which is not on `github.com/christianbaumann/Piwigo-Colored-Tags` (the submodule is one commit ahead of its own origin), so git reports `not our ref`. Pushing it is an outward-facing action and is carried in `docs/backlog.md` rather than done here. The part that a suite can keep watching is now `CleanCheckoutTest::testEverySuiteEntryPointIsCommitted`, which asserts every file a documented command names is tracked — `git ls-files --error-unmatch`, not just unignored, because a file can be unignored and still never added. Watched red against `vendor/autoload.php` added to the list. |
| 2026-08-31 | Phase 4 of the handbook plan opened four manual boxes: the demo photos show no real person, the face shapes are big enough to drag a region box over, the German reads naturally, and the album looks like a gallery rather than an abstraction. | **Two closed and two reduced the same day**, as guards inside `docs/handbuch/tools/seed.php` rather than a suite of its own - the seed is scaffolding, and a test that tests a test is what `.claude/rules/test-design.md` forbids. `assert_scenes_are_photographable()` refuses any scene whose face box falls below `MIN_FACE_PIXELS`. `assert_regions_reached_the_file()` reads the written regions back with a plain exiftool call, so Phase 5 photographs coordinates that are known to be in the file. `assert_no_generated_photo_is_a_gallery_copy()` closes the falsifiable half of "no real person": every plugin fixture builds its photo by copying a real gallery image (`FixtureBuilder::createTestImage()`), and a seed written that way would publish one, so the generated files are compared byte for byte against every gallery file. `assert_german_texts_round_tripped()` proves the titles survive MariaDB and carries the anti-vacuity count that stops the set drifting to ASCII. All six were watched red: an undersized face, an all-ASCII title set, a writer shifting X by 0.05, a real gallery file passed into the copy guard, and the copy guard's own comparison set emptied. |
| 2026-08-31 | Phase 4 verification pass: two defects the mutants found, neither in the handbook's content. | Recorded because both would have been invisible on a green run. (1) The copy guard first compared against `piwigo_images.md5sum`; its own anti-vacuity guard fired immediately, because all 105 rows of this install carry a **null** checksum - the guard would have passed every generated photo for the wrong reason. It now compares files on disk, size first and a checksum only where one matches, so the usual run hashes nothing. (2) The face-size check first ran inside the insert loop, so the undersized-face mutant failed **after** six photo rows existed and before the snapshot named them, leaving state `--restore` could not undo. The pure scene check now runs before any write, the snapshot is extended as each photo lands rather than written once at the end, and `--restore` empties the demo directory unconditionally, since a scene drawn by a run that died before its insert is a leftover no snapshot can name. |
| 2026-08-31 | Handbook plan close-out: the nine-mutant pass over `GermanOverrideKeyTest`, the only unit-layer code the plan added (see the mutant table above). Each mutant was applied and reverted by hand, the container confirmed to hold the new bytes before every run, and a `sed` that matched nothing made a loud failure rather than a silent "survived". | Not automatable by rule - `.claude/rules/mutation-testing.md` forbids scripting patch/run/revert cycles against source, because the script is a second thing to keep correct and stops working the moment a patched line moves. Two mutants survived, both invalid, both recorded with their reason; the anti-vacuity guard needed a second probe before it could be called reachable. |
| 2026-08-31 | Handbook plan close-out: the three commands `.claude/rules/handbook.md` quotes were run end to end - seed, shoot, restore - to prove the docs quote something that works. It found the demo album still seeded from Phase 5 (6 albums / 111 photos instead of 5 / 105); `--restore` removed it and the install came back to 5 / 105. | Partly automatable and partly not. That the commands exit 0 and that `check.php` stays green is mechanical; that a re-shoot still *shows the right screen* is not - and a re-shoot is deliberately not byte-reproducible, because the demo album takes a fresh id each seed, so 7 of the 20 PNGs differed with nothing wrong. Pixel-diffing is refused in the non-coverage table above. |

### Open — no oracle, so no test

| Item | Why it cannot be automated |
|---|---|
| A first-time `PluginMaintain::install()` against an **empty schema** | Needs a throwaway Piwigo instance with its own database; this repository has one install and no way to provision another. Running it against the live install would mean `uninstall()` first, which drops `piwigo_typetags` and `piwigo_tags.id_typetags` — destroying real tag-colour data to test a method that is already idempotent by construction (`CREATE TABLE IF NOT EXISTS`, an `ALTER` guarded by `SHOW COLUMNS`, a `conf_update_param` guarded by `empty()`). The risk is not worth the coverage. What *is* automated: `CleanCheckoutTest` (4 tests) asserts every runtime file is committed and unignored — the half of "installs from a clean checkout" that this repository can actually get wrong — and `PluginActivationTest` asserts install()'s effects are present on the live install. |
| Badge contrast is *legible* for all 8 configured colours against the modus background | Subjective judgment. `get_color_text()` picks black or white by a lightness threshold and `rendering.spec.js` asserts the choice is applied, but whether the result reads comfortably is not a fact a machine can settle. |
| The hover opacity transition *feels* right | Subjective. The opacity values themselves are asserted; the perception is not. |
| A written file's caption *reads* correctly in a GUI viewer (macOS Preview, a phone gallery) | Subjective, and the falsifiable half is already automated: `WriteBackTest::testAnIndependentReaderFindsTheCaption` proves an independent library finds the caption in three standard slots. Whether a given viewer chooses to *show* that slot, and whether it looks right, is a judgment no assertion settles. Recorded while automating it: ImageMagick's EXIF reader replaces every non-ASCII byte with a dot while its IPTC and XMP readers do not — the file's bytes are correct, the reader is not. |
| A file produced by a **real** digiKam (or Picasa, or Apple Photos) rather than by exiftool in that tool's shape | No such binary is installed in the DDEV web image and none of these tools is scriptable from a container. The falsifiable half — that a file carrying MWG regions this plugin did not write is indexed, names and all, including the no-`AppliedToDimensions` shape digiKam produces — is automated (see the ledger). What stays is whether a given release of a given tool writes the shape recorded here; a fixture cannot settle that, only a file from that release can. |
| The **integration and E2E** suites on a fresh clone | They need the clone to be a served Piwigo with its own database, and this repository has one install and no way to provision another — the same limit already recorded above for a first-time `install()` against an empty schema. What a clone can be asked, it now is: the unit suites run in one, and the files the documented commands name are guarded by `CleanCheckoutTest`. |
| Whether a drawn figure could be mistaken for a real person, and whether the demo album reads as a plausible gallery rather than a confusing abstraction | Subjective. The falsifiable half is automated: `assert_no_generated_photo_is_a_gallery_copy()` proves no published photo is a byte copy of a gallery image, which is the only known route by which a private scan reaches the handbook. What the drawn shapes evoke is not a fact an assertion settles. |
| Whether the German titles, album description and photo texts read naturally | Subjective. The mechanical half is automated: every text is compared against its constant on the way out of MariaDB, and `assert_german_texts_round_tripped()` fails if the set stops carrying a German special character. Whether the wording sounds like a person wrote it has no oracle. |
| Committing does not *feel* slow with the pre-commit hook installed | Subjective, and a wall-clock assertion would violate *assert the causal fact, not a wall-clock figure*. |
| Whether each of the five documented workflows can actually be completed by following only `docs/handbuch/`, without reading the code | The oracle is a first-time reader, not a machine. What a machine can decide is checked by `docs/handbuch/tools/check.php`: every reference resolves, every screenshot is referenced, every page is well formed, and every `admin.php?page=` route quoted in the text resolves the way `admin.php` resolves it. Whether a step is *missing* from a sequence is not among those. |
| Whether the German of the handbook reads naturally and matches the on-screen wording exactly | Subjective on the first half, and unautomatable on the second: the handbook quotes what a screen says, and no assertion can compare running prose against a rendered screen. The strings themselves are guarded where they live - `GermanOverrideKeyTest` and `GermanAdminScreenTest` for the translated ones - so a wording change breaks a test at its source rather than silently diverging from the handbook. |
| Whether a screenshot still shows the screen the text beside it describes | Subjective. `assertOutput()` in `shoot.js` proves every declared shot exists and that no undeclared file sits beside them, and `assertNoForeignPhoto()` proves no frame held a photo outside the demo album. That a shot is *framed on the right thing* has no oracle. |
