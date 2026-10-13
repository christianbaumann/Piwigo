---
id: 01
dependencies: []
---

# Task 01: Herkunft nachlesen

The handbook shows and explains the read-only provenance page: the *Details und Verlauf* link in
the picture page's Herkunft row, the *Herkunft* button on the album page, and what the page shows.
The two stale screenshots 04 and 11 show the current screens.

## References

* `design.md#findings` (finding 1)
* `design.md#scope-of-the-read-only-pages`
* `design.md#placement-of-the-provenance-page-text`
* `design.md#wer-darf-was-in-indexhtml`
* `design.md#hand-check`
* [decision 0064](../../../../docs/agents/decisions/0064-normal-users-read-provenance-and-tag-groups-on-read-only-pages.md)
* `.claude/rules/handbook.md` (seed, shoot, restore; never photograph the real gallery)
* `plugins/provenance/include/events_page.inc.php` (route, album button `#provenancePageLink`)
* `plugins/provenance/template/public_provenance.tpl` (picture page link), `provenance_page.tpl`
* `plugins/provenance/language/de_DE/plugin.lang.php` (German labels: *Details und Verlauf*, *Verlauf*, *Nicht gesetzt*, ...)
* `handbuch/tools/shoot.js` (`SHOTS`, entries `04-album-oeffentlich.png`, `11-foto-oeffentlich.png`)

## Work

* [x] Add `27-herkunft-seite.png` to `SHOTS` in `handbuch/tools/shoot.js`: an element shot of the provenance page (`index.php?/provenance/photo/<id>`) of a demo photo, as the `NORMAL` role, reached through the picture page's `#provenancePageLink`. The demo seed's `DEMO_ALBUM_PROVENANCE` must have been applied to the photo so the page shows values; extend the seed only if the history would otherwise be empty or show real data
  * **Note:** The history was empty (the seed inserts rows directly). `seed.php` now records five history rows for `sommerfest` through `provenance_record_changes()` as `persons_webmaster` (never the real webmaster), first deletes orphaned history of earlier objects with the demo ids (ids are reused after a restore), asserts all five rows landed, and `--restore` deletes them. Element shot is `#content` after clicking `#Provenance #provenancePageLink`.
* [x] Confirm the album page's *Herkunft* button lies inside the `#content` that shot 04 captures; if not, widen or add the element so the button is visible
  * **Note:** `#provenancePageLink` is inside `#content` (DOM probe): modus shows it icon-only, the folder icon at the right end of the button bar, title *Herkunft*. The text describes it that way.
* [x] Run the cycle: `seed.php --scenario=demo`, `shoot.js`, `seed.php --restore`. Keep 04, 11 and 27; revert every other re-shot image whose screen did not change
  * **Note:** Reverted 01, 03, 07, 09, 12, 17, 19, 20, 22, 26. Committed 26 is stale independently of this change (lacks *Drehen/Zuschneiden*, which normal accounts get since decision 0059); left for a separate re-shoot.
* [x] `03-fototexte.html`: new subsection "Herkunft nachlesen" after "Herkunft und Notiz": how to open it (link on the picture page, button on the album page), what it shows (four album values, the photo's note, *Verlauf* with date, field, before, after, cause, by), that it changes nothing, and that every logged-in user who can see the photo can open it. Reference shot 27
* [x] `03-fototexte.html:270` (Herkunft row on the picture page): mention the *Details und Verlauf* link and link to the new subsection
* [x] `01-alben.html` "Herkunft des Albums": one sentence linking to the new subsection for the album's read-only page
* [x] `index.html` "Wer darf was": add to the logged-in users that they can read the provenance of an album or photo with its history
* [x] Add a dated entry to the hand-check ledger in `docs/agents/TESTING.md`: the new subsection and shots 04, 11, 27 checked against the real pages

## Verification

* [x] `ddev exec php handbuch/tools/check.php` passes and reports 27 screenshots, all referenced
  * **Note:** `OK  8 pages, 68 references, 27 screenshots all referenced, 8 admin routes resolve`.
* [x] Shot 11 shows the *Details und Verlauf* link; shot 04 shows the *Herkunft* button; shot 27 shows the demo values and no real album, photo or person name
  * **Note:** Checked by viewing the images. The first shot 27 showed every value white on white (skin *newspaper* sets `dt, dd { color: white }`). Deviation from the design: fixed in `plugins/provenance/template/provenance_page.css` (`color: inherit`), test first: `provenance-page.spec.js` `the labels and values are readable against the page` (WCAG contrast >= 4.5) was red at contrast 1, green after the fix.
* [x] `seed.php --restore` leaves no demo album behind (install back to its album and photo counts)
  * **Note:** After `--restore`: 5 albums, 105 photos, 0 history rows for the demo album id.
* [x] Every German label the text quotes appears on the page exactly as written (checked in the browser as a normal account)
  * **Note:** Verified in shots 11, 27 and a DOM probe as `persons_normal`; *Nicht gesetzt* and *Keine Änderungen aufgezeichnet* only against `plugin.lang.php` (the demo shows neither).
* [x] `git status` shows only the intended files; no unchanged screenshot churn is committed
  * **Note:** Only 04, 11 and 27 among the screenshots.
