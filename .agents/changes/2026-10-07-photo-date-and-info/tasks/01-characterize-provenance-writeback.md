---
id: 01
dependencies: []
---

# Task 01: Characterize provenance's file write-back as it is today

Provenance's caption composition, caption slots, exiftool runner and lock are covered by passing
tests that record today's behaviour, so the filter and the runner change in task 02 are made
against a regression net.

## References

* `design.md#photoinfo-requires-provenance-and-reuses-its-writer`
* `design.md#one-composed-caption-for-info-and-provenance`
* `design.md#open-points-for-research-and-planning`
* `.claude/rules/testing.md` ("Cover the ground before you move it")
* `.claude/rules/test-design.md` ("A test whose oracle is the code must say so")
* `plugins/provenance/include/functions.inc.php` (`provenance_caption_parts()`, `provenance_compose_caption()`, `provenance_caption_tags()`, `provenance_build_argfile()`, `provenance_truncate_for_iptc()`)
* `plugins/provenance/include/exiftool.inc.php` (`provenance_write_back()`, `provenance_exiftool_run()`, `provenance_lock_acquire()`)
* `plugins/provenance/include/events_photoedit.inc.php`
* `plugins/provenance/tests/` and `docs/agents/TESTING.md` (check what is already covered first)

## Work

* [x] List which behaviours task 02 can reach: caption order and separator, the five caption slots, argfile lines, IPTC truncation, the exiftool command (config path), lock acquire/timeout, photoedit lock handoff
* [x] For each one, record whether an existing test already covers it; add a unit test (pure parts) or an integration test (exiftool, file, lock) only for the gaps
* [x] Tag every new case `[ERR]` with a comment that its oracle is the current implementation, unless a requirement backs it
* [x] Prove each new test can fail by breaking the behaviour it watches (wait for the Mutagen/opcache sync described in `.claude/rules/mutation-testing.md`), then revert
* [x] Commit the tests on their own, before any production change

### Coverage map (measured 2026-10-07)

| Behaviour | Test | New? |
|---|---|---|
| Caption field order and ` \| ` separator | `Unit/ComposeCaptionTest` (order, separator, trimming, empty parts), `Unit/CaptionPartsTest` (labels, empty values) | no |
| A part under a key outside the field order is dropped by the composer | `Unit/ComposeCaptionTest::testAPartOutsideTheFieldOrderIsDropped` (`[ERR]`) | **yes** (added in verification) |
| The five caption slots | `Unit/BuildArgfileTest::testFullLineSequence`, `Integration/WriteBackTest::testEveryCaptionSlotAndCustomTagReadsBack` | no |
| Argfile lines (charset first, newline collapsing, leading dash, empty values) | `Unit/BuildArgfileTest` | no |
| IPTC truncation | `Unit/TruncateForIptcTest`, `Unit/BuildArgfileTest::testOnlyTheIptcSlotCarriesTheTruncatedCaption`, `Integration/WriteBackTest::testTextOverTheIptcCapIsTruncatedInIptcOnly` | no |
| exiftool command: `-config` path | `Integration/WriteBackTest::testEveryCaptionSlotAndCustomTagReadsBack` (the `pwgprov` tags only write with the config loaded), `Unit/ExiftoolConfigContractTest` | no |
| exiftool binary path override, failed run reported | `Integration/WriteBackTest::testWriteBackRefusesWithoutExiftoolAndTouchesNoFile`, `::testAFailedWriteIsRecordedAndLeavesNoOperationDirectory` | no |
| Lock excludes concurrent writers | `Integration/WriteBackTest::testConcurrentWritersNeverDestroyTheFile`, `Unit/LockPathTest` | no |
| Lock timeout: a held lock makes the run give up without touching the file | `Integration/LockTimeoutTest::testARunGivesUpOnAHeldLockWithoutWriting` (`[ERR]`, 30 s), with `::testARunWithTheLockFreeWritesTheFile` as its anti-vacuity sibling | **yes** |
| photoedit lock handoff (`provenance_photoedit_begin`/`_end`) | `plugins/photoedit/tests/Integration/ExclusionTest` (`[ST] provenance` case) - lives in photoedit's suite, which loads provenance's handlers | no |

Mutant record:

| Mutant | Expected killer | Result |
|---|---|---|
| `if (flock($handle, LOCK_EX \| LOCK_NB))` → `if (true or flock(...))` in `provenance_lock_acquire()` | `LockTimeoutTest::testARunGivesUpOnAHeldLockWithoutWriting` | killed ("the runner wrote while another writer held the lock"); sibling stayed green; green again after revert |
| `provenance_compose_caption()` loops over `field_order + array_keys($parts)` instead of the field order | `ComposeCaptionTest::testAPartOutsideTheFieldOrderIsDropped` | killed; nothing else moved (1 of 198 unit tests); green after revert |
| `provenance_lock_acquire()` gives up on the first failed `flock` (no retry loop) | none | not recorded as a mutant: it survives both lock tests by design. A wall-clock lower bound would be a machine-speed proxy (`test-design.md`), and `WriteBackTest::testConcurrentWritersNeverDestroyTheFile` (every writer must succeed) is the test that depends on the retry |

### Handoff to task 02 (found in verification, 2026-10-07)

What task 02 will run into. These are not gaps in this task's coverage; they are places where task 02 must change behaviour that is now pinned, so a red test there is a decision rather than a bug (`testing.md`, "Cover the ground before you move it").

* **The blank line cannot pass through today's argfile.** `provenance_build_argfile()` runs the caption through `provenance_sanitize_argfile_value()`, which turns every newline into a space. That is pinned by `BuildArgfileTest::testNewlineInTheCaptionNeverProducesASecondLine`, and it guards decision C8 (one argument per line). "Info, blank line, provenance" needs another way to carry a newline in an argfile, for example exiftool's `#[CSTR]` argfile header with C escapes, checked against exiftool 12.76 on the remote. The guard against a value splitting into a second argument must still hold.
* **The composer drops foreign keys and joins with ` | `.** A `photoinfo` part added through `provenance_caption_parts` is dropped (`ComposeCaptionTest::testAPartOutsideTheFieldOrderIsDropped`). The blank-line join has to happen outside `provenance_compose_caption()`, or the composer has to change deliberately.
* **The concurrency worker loads no plugin layer.** `tests/Support/write-back-worker.php` doesn't load `include/functions_plugins.inc.php`, so a `trigger_change()` inside `provenance_write_back()` is a fatal there, and `WriteBackTest::testConcurrentWritersNeverDestroyTheFile` goes red for that reason alone.
* **The Provenienz row is a second caller.** `include/events_public.inc.php:36-37` composes the same parts for the picture page. If the filter goes into `provenance_caption_parts()`, the info text leaks into the Provenienz row. Put the filter in `provenance_write_back()` only, and have task 02 assert the row stays provenance-only on a photo that has a comment. `PicturePageSourceTest` cannot catch the leak today: its fixture has no comment and it uses `assertStringContainsString`.
* **exiftool takes one `-config`.** A second `-config` on the command line does not add `pwginfo` next to `pwgprov`. A run that writes both namespaces needs one config that loads the other (config files are Perl, so `pwginfo.config` can `require` `pwgprov.config`), or separate runs. `WriteBackTest::testEveryCaptionSlotAndCustomTagReadsBack` is the test that would catch `pwgprov` tags getting lost.

Accepted as-is: `LockTimeoutTest` retypes the `PROVENANCE_XMP_CONFIG` path from `main.inc.php`, as `write-back-worker.php` already does. `main.inc.php` cannot be loaded without Piwigo core.

## Verification

* [x] Every behaviour on the list has a named test **Note:** see the coverage map above; one gap (foreign caption-part key) was found and closed in verification by a subagent review
* [x] Each new test went red when its behaviour was broken, and green after the revert **Note:** mutant record above; host/container checksums compared before each run; `LockTimeoutTest` re-run green after its rename, unit suite 198/198
* [x] Provenance unit and integration suites pass twice in a row (commands in `.claude/rules/plugin-test-suites.md`) **Note:** unit 197 tests OK, integration 188 tests OK (3 pre-existing skips), both runs, 2026-10-07
* [x] No production file changed in this task **Note:** `git status` shows only the new test file
