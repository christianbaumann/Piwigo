---
id: 31
dependencies: []
---

# Task 31: Handbook ch6 and ch7; marking stays mouse-only

ch6 names the restore path, ch7 describes the narrow window as it works, and the keyboard gap is recorded.

## References

* `design.md#ch6-names-the-restore-path`
* `design.md#ch7-describes-the-narrow-window-as-it-works`
* `design.md#marking-a-person-stays-mouse-only`

## Work

* [ ] `handbuch/06-drehen.html`: webmaster restores via the replace option; user names photo and edit time
* [ ] `handbuch/07-fotoseite.html:58-66`: "knapp das linke/rechte Drittel"; − and + hidden, keys still zoom, zoom kept
* [ ] `docs/agents/decisions/0069-marking-a-person-needs-a-mouse.md`
* [ ] Hand-check rows in `docs/agents/TESTING.md`

## Verification

* [ ] `ddev exec php handbuch/tools/check.php` passes
* [ ] Ledger rows dated
* [ ] Relevant suites pass, each named with the command that ran (`.claude/rules/plugin-test-suites.md`)
