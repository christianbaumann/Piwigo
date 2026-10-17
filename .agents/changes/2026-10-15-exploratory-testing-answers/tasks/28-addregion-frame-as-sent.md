---
id: 28
dependencies: []
---

# Task 28: `addRegion` stores the frame as sent

A frame is stored exactly as drawn unless a stated rule changes it.

## References

* `design.md#addregion-stores-the-frame-as-sent`
* `docs/agents/decisions/0077-addregion-clips-an-overrunning-box-and-keeps-the-axis-that-fits.md`

## Work

* [x] Find the rule that turned y 0.1/h 0.3 into 0.125/0.25 on 1200x800; unit test it
  * Rule: MWG clipping in `persons_clip_region()`. Centre-origin y 0.1, h 0.3 puts the top edge at -0.05; cut to 0 gives top 0, bottom 0.25, centre 0.125, h 0.25. Photo size is irrelevant (normalized). The editor clamps drawn boxes, so only a hand-made request can overrun.
* [x] Store as sent unless a rule requires the change; record the rule if one stays
  * Clip stays (decision 0077). Side finding fixed: after clipping one axis the other axis was recomputed too (w 0.2 came back 0.20000000000000007); now only the clipped axis is recomputed.

## Verification

* [x] Unit: the sent frame comes back unchanged (or the kept rule is pinned)
  * **Note:** Verified via `RegionGeometryTest::testABoxOverrunningTheTopEdgeIsStoredAsTheClippedFrame` (watched red on the unclipped axis first); mutants killed: horizontal keep removed (that test), vertical override always (that test and `testABoxOverrunningTwoEdgesIsClippedOnBoth`), vertical keep removed (`testABoxOverrunningTheLeftEdgeKeepsTheVerticalAxisAsSent`, added after it survived)
* [x] Integration: read-back equals the sent frame
  * **Note:** Verified via `AddRegionTest::testABoxInsideTheFrameIsReadBackAsSent` and `testABoxOverrunningTheTopEdgeIsStoredClipped` (answer and index row)
* [x] persons suites pass
  * **Note:** unit 218 OK (217 + the left-edge mirror test added in verification); integration 212 tests OK, 1 skipped (measured 2026-10-17). E2E not run: the editor is unchanged.

## Deviations

* The design said "store as sent unless a rule requires the change"; the MWG clip is kept as that rule, plus the per-axis fix above (not in the design).
* No separate characterization commit: the clip itself was already covered (`RegionGeometryTest` left-edge, `MergeRegionsTest`); the new tests pin the top edge and the read-back.
