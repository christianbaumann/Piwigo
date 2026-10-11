---
id: 05
dependencies: []
---

# Task 05: Lounge emptying from a page view inherits in mode keep

When `check_lounge()` empties the lounge during any request, provenance gives the released photos the album values only where they have none, ignores request parameters and writes no history row.

## References

* `design.md#a-lounge-emptying-from-a-page-view-inherits-in-mode-keep`
* `include/common.inc.php:202` (`check_lounge()` before `include/user.inc.php`)
* `include/functions.inc.php` `check_lounge()`, `admin/include/functions.php` `empty_lounge()`
* `plugins/provenance/include/events_inherit.inc.php` `provenance_inherit_associated()`, `provenance_inherit_into()`
* `plugins/provenance/include/history.inc.php`

## Work

* [ ] Detect "user not loaded yet" in the inheritance handler and force mode `keep` without a history row in that case; keep the decision in a pure function with a unit test
* [ ] Integration: a photo in the lounge older than `lounge_max_duration` is released by a plain page request carrying a `replace`/`clear` parameter; it gets the album values, keeps existing values, and no history row appears
* [ ] Integration: `pwg.images.uploadCompleted` as admin still inherits with history as today
* [ ] Decision file: lounge inheritance from a page view

## Verification

* [ ] A page request that empties the lounge never replaces or clears existing provenance
* [ ] A released photo without provenance gets the album values
* [ ] No history row is written for the page-view emptying
* [ ] Admin upload completion behaviour is unchanged
* [ ] Provenance unit and integration suites pass, including the core characterization tests
