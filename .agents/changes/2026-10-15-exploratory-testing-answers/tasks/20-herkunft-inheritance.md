---
id: 20
dependencies:
- 01
---

# Task 20: Herkunft: first album wins, sub-albums inherit, only new links

A photo's Herkunft follows one documented rule on every link path.

## References

* `design.md#herkunft-first-album-wins-sub-albums-inherit`
* `design.md#only-new-album-links-inherit-herkunft`
* `plugins/provenance/include/events_inherit.inc.php`
* `admin/include/functions.php:2104`

## Work

* [ ] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
* [ ] The `associate_images_to_categories` trigger passes only inserted pairs; update its entry in `.claude/rules/fork-local-core-edits.md`
* [ ] A photo without own Herkunft in a sub-album takes the nearest parent album's values
* [ ] `handbuch/03-fototexte.html` states both rules; `check.php` passes

## Verification

* [ ] Integration: photo with Herkunft keeps it when linked to a second album
* [ ] Integration: photo without Herkunft linked to a second album takes the new album's values (15-16)
* [ ] Integration: sub-album photo takes the parent's values
* [ ] provenance suites pass; `ddev exec php handbuch/tools/check.php` passes
