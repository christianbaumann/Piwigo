---
id: 23
dependencies:
- 02
---

# Task 23: The menu photo count counts what the viewer can open

The menu count equals the number of photos the viewer can open.

## References

* `design.md#the-menu-photo-count-research-first`

## Work

* [x] Find why the menu showed 106 while API and albums gave 105; record the cause in a research note
  **Note:** `docs/agents/research/2026-10-17-menu-photo-count.md`. Cause: core's `nb_total_images` counted `image_category` rows without joining `images`/`categories`, so a link left by a deleted photo or album counted; the install held 5 such rows (menu 112 vs. 107 photo rows).
* [x] Fix in the right layer (core under *Defects in core are fixed in core*, else `galleryrules`)
  **Note:** core, `include/functions_user.inc.php` (two `INNER JOIN`s), decision 0074, bullet in `.claude/rules/fork-local-core-edits.md`. Characterized first: provenance `CoreMenuPhotoCountCharacterizationTest` (`[ERR]`, 6 cases x 4 roles: in album, no album, lounge, privacy level, ungranted private album, locked album).

## Verification

* [x] Integration per role: menu count equals the photos the role can open, with a photo in no album and one in the lounge
  **Note:** Verified via provenance `CoreMenuPhotoCountTest` (webmaster, admin, normal, generic; guest has no menu, decision 0055). Oracle: `pwg.categories.getImages` over all albums, plus the listed fixture photo opening on `picture.php`. Red before the fix (114 vs. 107), green after. Mutants: dropping the `images` join (110 vs. 107) and dropping the `categories` join (108 vs. 107) both killed by it.
* [x] Affected suites pass
  **Note:** Verified via the provenance integration suite under the suite lock, 2026-10-17: 363 tests, 2256 assertions, 3 skipped, no failure. No other plugin reads `nb_total_images` or the menu count (grep), so no other suite is affected. Characterization strength: dropping the count query's privacy-level filter turns the level case red; dropping its forbidden-album filter turns the private and locked cases red.

## Deviations from the design

* The design guessed a photo in no album or in the lounge, counted for admins. Neither is counted
  by core, for any role (characterized). The cause was dead `image_category` links.
* The dead rows in the local install (images 55910, 55917, 55950, 55989, 37388) are left in place;
  the count no longer sees them (decision 0074).
