---
id: 28
dependencies: []
---

# Task 28: `addRegion` stores the frame as sent

A frame is stored exactly as drawn unless a stated rule changes it.

## References

* `design.md#addregion-stores-the-frame-as-sent`

## Work

* [ ] Find the rule that turned y 0.1/h 0.3 into 0.125/0.25 on 1200x800; unit test it
* [ ] Store as sent unless a rule requires the change; record the rule if one stays

## Verification

* [ ] Unit: the sent frame comes back unchanged (or the kept rule is pinned)
* [ ] Integration: read-back equals the sent frame
* [ ] persons suites pass
