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

* [x] Characterize the touched core area first (`[ERR]`, own commit) in `plugins/provenance/tests/Integration/Core*CharacterizationTest` or its unit suite, and watch each case go red when the behaviour is broken
  **Note:** `CoreAssociationTriggerCharacterizationTest`, three cases; each killed by a mutant of the trigger in `admin/include/functions.php` (trigger fired outside the `count($inserts)` guard; `image_ids` narrowed to inserted photos; `category_ids` narrowed to inserted albums), one case per mutant, nothing else moved. Two of the three recorded the defect and were replaced in the change by requirement cases in `InheritTest`
* [x] The `associate_images_to_categories` trigger passes only inserted pairs; update its entry in `.claude/rules/fork-local-core-edits.md`
* [x] A photo without own Herkunft in a sub-album takes the nearest parent album's values
* [x] `handbuch/03-fototexte.html` states both rules; `check.php` passes

## Verification

* [x] Integration: photo with Herkunft keeps it when linked to a second album
  **Note:** Verified via `InheritTest::testAPhotoWithHerkunftKeepsItWhenLinkedToASecondAlbum` (photo properties form)
* [x] Integration: photo without Herkunft linked to a second album takes the new album's values (15-16)
  **Note:** Verified via `InheritTest::testAPhotoWithoutHerkunftTakesTheNewlyLinkedAlbumsValues` and `testOnlyThePhotoNewToTheAlbumInherits` (both red before the core change)
* [x] Integration: sub-album photo takes the parent's values
  **Note:** Verified via `InheritTest::testASubAlbumPhotoTakesTheParentsValues`, `testTheNearestAncestorWithHerkunftWins`, `testASubAlbumWithItsOwnHerkunftUsesIt`, `testASubAlbumWithoutHerkunftAnywhereAboveWritesNothing`, and unit `NearestAlbumWithValuesTest` (7 cases)
* [x] provenance suites pass; `ddev exec php handbuch/tools/check.php` passes
  **Note:** Verified 2026-10-16: provenance unit 283 OK, integration 351 OK (3 skipped), E2E 62 passed, `check.php` OK. The two new handbook paragraphs were read 2026-10-17 by a reviewing subagent (the user's choice): reworded for end users, the move exception (*Durch die des Zielalbums ersetzen*, `02-fotos.html`) added, and the unconditional "übernimmt sie von selbst" in `03-fototexte.html` and `01-alben.html` qualified with "sofern ... noch keine"; `check.php` OK

## Deviations

* The trigger keeps its payload shape (`image_ids`, `category_ids`) and fires **once per album**
  that got a new link, with that album's newly linked photos. Only per-album notifications can
  carry exactly the inserted pairs in that shape, so `provenance_inherit_associated()` is unchanged
  as the design asks. Album order is kept, so within one call the first album still wins.
* Sub-albums inherit **at link time only**: a photo joining an album (association, upload, sync,
  move with keep or replace) takes the album's own values, else those of its nearest ancestor that
  has any; all four values come from that one album. Photos already in a sub-album before its
  parent got Herkunft are not reached, and `Auf N Fotos anwenden` still copies the album's **own**
  values (on a sub-album without any, it clears them, as before). The handbook says to enter the
  Herkunft in the sub-album itself for those. Extending apply to ancestor values was left out:
  the album dialog would show empty fields while apply writes the parent's.
* `provenance_album_has_values()` moved from `events_inherit.inc.php` to `functions.inc.php`
  (unchanged), so the new pure `provenance_nearest_album_with_values()` beside it is unit-testable.
