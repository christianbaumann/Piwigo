---
id: 05
dependencies: []
---

# Task 05: Fix the flaky overlay resize spec

`overlay.spec.js` › "the boxes track the photo across a stepped resize" fails intermittently. The
flake predates this change. It was found while verifying task 02 and is recorded in
`docs/backlog.md` (section *persons*). Per `e2e-tests.md`, a flaky test gets fixed and is never
retried into green.

## Findings so far

* Measured 2026-10-04 with `--repeat-each=10`: 3 of 10 failed on the branch, 4 of 10 at
  `9533125e0` (before task 02)
* Each failure is one box off by 30-50 px (`Received: 50`, once `29.9`), against `TOLERANCE_PX = 2`
* On the branch the failures were repeats 3, 6 and 9, which looks periodic. At `9533125e0` they were
  0, 1, 5 and 7, so the pattern may be a coincidence
* Suspects, none confirmed:
  * `settle()` (`tests/e2e/support/PicturePage.js`) returns while a derivative switch or the
    `overlay.js` debounced redraw is still pending
  * `imageRect()` and `boxRect()` are separate `evaluate` calls, so a layout shift between them
    puts them out of step
  * a production bug in `overlay.js` placement after a derivative switch (the spec would then be
    right)

## References

* `plugins/persons/tests/e2e/overlay.spec.js` (`RESIZE_STEPS`, `TOLERANCE_PX`)
* `plugins/persons/tests/e2e/support/PicturePage.js`: `settle()`, `SETTLE_FRAMES`, `imageRect()`, `boxRect()`
* `plugins/persons/template/overlay.js`: resize debounce, redraw on image `load`
* `.claude/rules/e2e-tests.md`, `.claude/rules/test-design.md` ("Assert the causal fact")
* Failure traces: `plugins/persons/test-results/overlay-person-region-over-*-stepped-resize-*/trace.zip`
  (git-ignored, local only)

## Work

* [ ] Reproduce with `--repeat-each=20` and keep the traces
* [ ] Read a failing trace: which step, which region, and whether the image or the box moved
  (photo vs. overlay rect at the failing step)
* [ ] Decide whether the cause is in the test (measurement timing) or in `overlay.js` (placement
  bug). If production: write the reproducing test first, then fix
* [ ] Fix the cause. Wait on a causal fact, never a timeout, and do not widen `TOLERANCE_PX`
* [ ] Remove the backlog entry in `docs/backlog.md`

## Verification

* [ ] `--repeat-each=30` on the spec: 30 of 30 pass
* [ ] If the fix is in the test: break the overlay placement on purpose and see the spec go red
* [ ] Persons E2E suite passes twice in a row
