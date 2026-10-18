---
id: 54
dependencies:
- 48
---

# Task 54: The short-tag wrap check runs on a multi-word tag it forces

Added 2026-10-18 (user's answer 4a), from task 48's verify: `multiWord` is counted but never asserted, so the check can pass with no multi-word tag in the cloud.

## References

* Task 48, `plugins/provenance/tests/e2e/long-words.spec.js`, `.../support/wrap-seed.php`, `.claude/rules/test-design.md` (fixtures force their precondition)

## Work

* [ ] The seed creates a short multi-word tag on its photo (removed by `--restore`); the spec asserts at least one multi-word tag was checked

## Verification

* [ ] The new assertion goes red with the seed's tag removed; `long-words.spec.js` passes twice
