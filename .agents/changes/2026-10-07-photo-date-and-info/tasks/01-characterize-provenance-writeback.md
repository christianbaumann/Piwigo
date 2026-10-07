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
| The five caption slots | `Unit/BuildArgfileTest::testFullLineSequence`, `Integration/WriteBackTest::testEveryCaptionSlotAndCustomTagReadsBack` | no |
| Argfile lines (charset first, newline collapsing, leading dash, empty values) | `Unit/BuildArgfileTest` | no |
| IPTC truncation | `Unit/TruncateForIptcTest`, `Unit/BuildArgfileTest::testOnlyTheIptcSlotCarriesTheTruncatedCaption`, `Integration/WriteBackTest::testTextOverTheIptcCapIsTruncatedInIptcOnly` | no |
| exiftool command: `-config` path | `Integration/WriteBackTest::testEveryCaptionSlotAndCustomTagReadsBack` (the `pwgprov` tags only write with the config loaded), `Unit/ExiftoolConfigContractTest` | no |
| exiftool binary path override, failed run reported | `Integration/WriteBackTest::testWriteBackRefusesWithoutExiftoolAndTouchesNoFile`, `::testAFailedWriteIsRecordedAndLeavesNoOperationDirectory` | no |
| Lock excludes concurrent writers | `Integration/WriteBackTest::testConcurrentWritersNeverDestroyTheFile`, `Unit/LockPathTest` | no |
| Lock timeout: a held lock makes the run give up without touching the file | `Integration/LockTimeoutTest::testARunWaitsForTheLockAndGivesUpWithoutWriting` (`[ERR]`, 30 s), with `::testARunWithTheLockFreeWritesTheFile` as its anti-vacuity sibling | **yes** |
| photoedit lock handoff (`provenance_photoedit_begin`/`_end`) | `plugins/photoedit/tests/Integration/ExclusionTest` (`[ST] provenance` case) - lives in photoedit's suite, which loads provenance's handlers | no |

Mutant record:

| Mutant | Expected killer | Result |
|---|---|---|
| `if (flock($handle, LOCK_EX \| LOCK_NB))` → `if (true or flock(...))` in `provenance_lock_acquire()` | `LockTimeoutTest::testARunWaitsForTheLockAndGivesUpWithoutWriting` | killed ("the runner wrote while another writer held the lock"); sibling stayed green; green again after revert |

## Verification

* [x] Every behaviour on the list has a named test **Note:** see the coverage map above
* [x] Each new test went red when its behaviour was broken, and green after the revert **Note:** mutant record above; host/container checksums compared before each run
* [x] Provenance unit and integration suites pass twice in a row (commands in `.claude/rules/plugin-test-suites.md`) **Note:** unit 197 tests OK, integration 188 tests OK (3 pre-existing skips), both runs, 2026-10-07
* [x] No production file changed in this task **Note:** `git status` shows only the new test file
