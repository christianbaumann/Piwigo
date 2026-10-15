---
datetime: 2026-10-15T13:40:00+02:00
author: Christian Baumann
tags: [exploratory-testing, security, core-behaviour, core-defects, provenance, photoedit, persons, typetags, photoinfo, upload, handbook]
---

# Answers to the exploratory testing questions and findings

The exploratory testing sessions of 2026-10-13 to 2026-10-15
(`local/et-results/sessions/SES-*/session.md`, git-ignored) left 73 findings and open questions in
`local/et-results/coverage.md`. The tester answered all 34 open questions on 2026-10-15 (recorded
there as "Tester answer (2026-10-15)"); groups 1 to 6 hold those answers. A brainstorm on
2026-10-15 decided the remaining findings; groups 7 to 10 hold them, with the root cause found in
code for each. Finding ids are short: `13-03 F2` = `SES-2026-10-13-03/FND-02`.

## Overview

```
                 ┌───────────────── existing plugins ─────────────────┐   ┌─ plugins/galleryrules ──┐
Group 1 Security │ typetags (plain tag names)                         │   │ plain-text fields,      │
                 │ 5 plugins (CLI-only test-user scripts)             │   │                         │
                 │ tools/deploy (no PHP errors on the remote)         │   │                         │
Group 2 Data     │ photoedit (copy before restore, copies on delete)  │   │ replace, addSimple,     │
                 │ photoinfo (write only on change)                   │   │ last album refused      │
                 │ persons (delete removes tag)                       │   │                         │
Group 3 Herkunft │ provenance (first album wins, sub-album inherits)  │   │ addSimple completes     │
                 │ photoinfo (sync reads the date)                    │   │                         │
Group 4 Counts   │ typetags (addNewTag error)                         │   │ empty albums, menu count│
Group 5 Messages │ typetags, persons, local/language/de_DE.lang.php   │   │ blank name              │
                 │ provenance (Verlauf header "Von wem")              │   │                         │
Group 6 Handbook │ handbuch/03-fototexte.html, 06-drehen.html, 07-fotoseite.html, 02-fotos.html │
Group 7 Persons  │ persons (escaping, tag guard, upload hook, UI)     │   │                         │
Group 8 Tags     │ typetags (CSRF, festlegen, NFC, cache)             │   │                         │
Group 9 Files    │ provenance, photoinfo (IPTC charset, Notiz form,   │   │                         │
                 │ new links only), photoedit (_original sidecars)    │   │                         │
                 └────────────────────────────────────────────────────┘   └─────────────────────────┘
Group 10 Core    Piwigo core defects fixed in core, fork-local: album lock cache, upload chunks
defects          and page, album properties, getList cover, setInfo level, sync counts, tags.js,
                 tag menu count, orphan tags, album description, modus CSS and zoom
                 All fork-local core edits listed in .claude/rules/fork-local-core-edits.md
```

## Key Decisions

### Core behaviour changes go through a plugin

- **Decision:** Plugin first. Changes to core behaviour live in a new fork-local plugin,
  `plugins/galleryrules`, set up like the other five (suites, four test accounts, throwaway
  marker, `.gitignore` re-include, deploy activation), and use
  existing events, Smarty prefilters and wrapped `ws.php` methods, as `plugins/photoinfo` wraps
  `pwg.images.setInfo` ([decision 0039](../../../docs/agents/decisions/0039-core-screens-reach-photoinfo-through-their-own-events.md)).
  Core gets a one-line `trigger_notify()` only where no event exists, like the two provenance
  triggers listed in [CLAUDE.md](../../../CLAUDE.md) (moving to
  `.claude/rules/fork-local-core-edits.md`, see the next decision).
- **Reason:** Core stays close to upstream, so upstream pulls stay cheap. `plugins/persons` needed
  no core change at all. A plugin brings its own test suite; core has none.
- **Trade-offs:** Rejected: direct core edits with one decision each (merge conflicts on every
  upstream pull); mixed core edits for one-liners and a plugin for the rest. Defects in core are
  not behaviour changes; see the next decision.

### Defects in core are fixed in core

- **Decision:** A core defect (wrong result, false message, data loss, missing check) is fixed in
  core as a fork-local edit, also when it is only cosmetic. Each edit: the area is characterized
  first (`[ERR]`, own commit, `.claude/rules/testing.md` *Cover the ground before you move it*),
  then changed test-first, and listed in `.claude/rules/fork-local-core-edits.md`. Core
  characterization tests go into `plugins/provenance/tests/Integration/Core*CharacterizationTest`
  as today. Upstream may still be pulled from; nothing is pushed there
  (`.claude/rules/git-and-commits.md`).
- **Reason:** Most remaining findings are upstream defects in core JS and ws handlers (group 10). A
  plugin cannot reach core JS closures without fragile DOM patching. The tester chose complete
  fixes over a smaller core footprint.
- **Trade-offs:** More core lines to carry through upstream pulls. Rejected: plugin or `local/`
  override only, rest recorded as not fixed; severity triage (data, leak, false success in core,
  cosmetic only through `local/`).

### Fork-local core edits are listed in one rules file

- **Decision:** The list of fork-local core edits moves from CLAUDE.md (*Project Overview*: the
  two provenance triggers, decision 0037, `handbuch.php`, the `tags.tpl` merge buttons, the modus
  zoom) into a new `.claude/rules/fork-local-core-edits.md`, verbatim, and every edit of this
  design is added there. CLAUDE.md keeps one line linking it under *Additional rules*, with the
  read trigger "read before editing core or pulling from upstream".
- **Reason:** About 15 new core edits do not fit CLAUDE.md's 100-line cap
  (`.claude/rules/backpressure.md`, *Documentation files have a length budget*). An upstream pull
  needs the full list in one place.
- **Trade-offs:** One more file to open before a core edit. Rejected: one summary line per area in
  CLAUDE.md (details scattered over decision files); only decision numbers in CLAUDE.md.

**Group 1: security and leaks**

### Titel, Autor, Info, album name and description are plain text

- **Decision:** All five fields are escaped completely on output. HTML is shown as text everywhere,
  as for provenance text ([decision 0009](../../../docs/agents/decisions/0009-provenance-text-is-never-html.md)).
- **Reason:** The photo page runs HTML in these fields for every viewer (SES-2026-10-14-04,
  SES-2026-10-13-04/FND-02 upheld, SES-2026-10-13-10). The image file already holds Info as plain
  text, so page and file agree.
- **Trade-offs:** Bold, italic and links in descriptions are lost. Rejected: an allow-list of tags
  in Info and descriptions; keeping raw HTML as trusted input (every logged-in user writes Info).

### Closing or reopening an album takes effect at once

- **Decision:** Core's `set_cat_visible()` (`admin/include/functions.php:748`) calls
  `invalidate_user_cache()` at its end, a fork-local core fix under *Defects in core are fixed in
  core*. Integration test as a normal and a generic account: 401 on the album and its photo
  right after Geschlossenes Album is saved, 200 right after it is cleared, with no cache purge
  between.
- **Reason:** `set_cat_visible()` updates `categories.visible` but never clears
  `piwigo_user_cache`, so `forbidden_categories` stays old until a webmaster purges it or another
  action calls `invalidate_user_cache()` (`include/ws_functions/pwg.categories.php:792`, `:1203`,
  `:1313`). A closed album stayed open to visitors (SES-2026-10-15-05/FND-01, a leak), a reopened
  one stayed closed (FND-02). The provenance_generic difference (SES-2026-10-15-04/FND-05) did not
  repeat and is dropped; it is the same mechanism. The fix covers both callers:
  `pwg.categories.setInfo` (`:943`, used by the album properties page) and the album options page
  (`admin/cat_options.php:53`, `:93`).
- **Trade-offs:** One more core line to carry through upstream pulls; every album lock rebuilds
  every user's cache once. Rejected: a `trigger_notify()` plus a plugin listener (same core
  footprint, one more indirection); wrapping `pwg.categories.setInfo` only
  (misses callers that bypass the API); out of scope (the leak stays).

### The remote shows no PHP errors

- **Decision:** The deploy writes `$conf['show_php_errors_on_frontend'] = false;` into the remote
  config (`tools/deploy/pwgdeploy/bootstrap.py`, beside `guest_access`), with a test and a
  decision. Local stays on.
- **Reason:** A PHP fatal error showed a stack trace with server paths (SES-2026-10-13-03). Core's
  default is `true` (`include/config_default.inc.php:554`) and nothing overrode it on the remote.
- **Trade-offs:** Errors on the remote are only in the server log. Rejected: off everywhere
  (local debugging gets harder); on everywhere.

### Test-user scripts run from the command line only

- **Decision:** Each plugin's `tests/Support/create-test-users.php` exits unless `PHP_SAPI` is
  `cli`, with a test per plugin that opens the URL and expects no account change.
- **Reason:** Locally the scripts can be opened over HTTP (SES-2026-10-13-07). The deploy already
  leaves `tests/` out (`tools/deploy/pwgdeploy/fileset.py`, `EXCLUDED_DIR_NAMES`).
- **Trade-offs:** Rejected: web-server deny rules for `plugins/*/tests/` (two configs to keep in
  step); no change.

### The API returns plain tag names

- **Decision:** Every `ws.php` answer carries the plain tag name; typetags adds its colour markup
  only when a page is rendered.
- **Reason:** API clients got inline `<span style=…>` in tag names (SES-2026-10-13-12). Plain text
  everywhere matches the plain-text decision above.
- **Trade-offs:** API clients get no colour. Rejected: plain name plus a `color` field; no change.

### Guest-readable `reflection.*` stays

- **Decision:** No change; [decision 0055](../../../docs/agents/decisions/0055-guests-are-locked-out.md)
  already lets guests use `pwg.session.*` and `reflection.*`.
- **Reason:** The question (SES-2026-10-13-05) is answered by a recorded decision.
- **Trade-offs:** Guests can still read the API catalogue.

**Group 2: silent data changes**

### Replace refuses when a file name is ambiguous

- **Decision:** The replace option of Fotos hinzufügen refuses when more than one photo in the album
  has the file name, and names the matching photos.
- **Reason:** It took the lower id without a hint (SES-2026-10-14-24). A wrong overwrite cannot be
  undone.
- **Trade-offs:** The user must rename one photo first. Rejected: take the newest and warn; keep
  and document the oldest-wins behaviour.

### `addSimple` refuses a file identical to an existing photo

- **Decision:** An `addSimple` of a file identical to an existing photo is refused with an error
  naming that photo; the photo stays untouched.
- **Reason:** It returned the existing id and silently renamed that photo (SES-2026-10-15-01).
- **Trade-offs:** Rejected: return the existing id and keep its name; keep core's behaviour and
  document it.

### A restore through replace keeps a copy of the edited state

- **Decision:** Before the replace overwrites a photo, the current file is copied to
  `_data/photoedit/originals/`, as every photoedit save does.
- **Reason:** A restore made no copy of the edited state (SES-2026-10-14-24). The file is the only
  record of an edit ([decision 0034](../../../docs/agents/decisions/0034-photo-edits-change-the-file.md)),
  so a wrong restore must be undoable.
- **Trade-offs:** More disk use. Rejected: copy only for photos with an earlier photoedit copy;
  warn in ch6 only.

### Copies of a deleted photo move aside

- **Decision:** When a photo is deleted (`delete_elements`), its copies move to
  `_data/photoedit/originals/deleted/<id>-<deletion time>/`.
- **Reason:** Copies stayed without limit, and ids are reused after a delete, so an old photo's
  copies could carry a new photo's id (SES-2026-10-14-20). Nothing is lost.
- **Trade-offs:** No size limit yet. Rejected: delete them with the photo; keep them in place and
  document matching by save time.

### Tags are written to the file only on a real change

- **Decision:** photoinfo writes the file only when the photo's tags changed, and returns
  `tags_written: false` otherwise. A test checks the file checksum is unchanged.
- **Reason:** A remove that changed nothing returned `tags_written: true` (SES-2026-10-13-02). A
  needless write changes a tracked file under `galleries/` and leaves an `_original` copy.
- **Trade-offs:** Rejected: keep the write and report `false`; record the write as idempotent.

### Deleting a person removes the person's tag

- **Decision:** A person delete also removes the tag from photos and the tag list, and the keywords
  from the files.
- **Reason:** The tag stayed after the delete (SES-2026-10-13-03). The file is the source of truth
  ([decision 0020](../../../docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md)).
- **Trade-offs:** One exiftool write per affected photo. Rejected: move the tag to `Freitext`; keep
  it.

### A photo's last album cannot be removed

- **Decision:** Removing a photo's last album (Batch Manager "Von Album abtrennen", properties
  page, API) is refused; moving the photo stays possible.
- **Reason:** The photo became orphaned and invisible to non-admins with no hint
  (SES-2026-10-14-04, SES-2026-10-14-07).
- **Trade-offs:** No orphaned photos by choice any more. Rejected: a warning dialog; no change.

**Group 3: Herkunft and upload**

### Herkunft: first album wins, sub-albums inherit

- **Decision:** A photo that already has Herkunft keeps it when it joins another album (first
  album wins). A photo without its own Herkunft in a sub-album takes the nearest parent album's
  values automatically. `handbuch/03-fototexte.html` states both rules; tests pin them.
- **Reason:** A photo in a second album kept the first album's Herkunft, and ch3 did not say so
  (SES-2026-10-14-01). Sub-album photos got no Herkunft and there was no path to apply it
  (SES-2026-10-15-16).
- **Trade-offs:** For sub-albums, rejected: an "auch Unteralben" option on the parent's apply;
  no change.

### `addSimple` completes the upload

- **Decision:** `addSimple` completes the upload itself: the photo joins its album and takes the
  album's Herkunft at once, with no wait in the upload lounge.
- **Reason:** Photos stayed outside their album and without Herkunft until a separate
  `uploadCompleted` call (SES-2026-10-14-05).
- **Trade-offs:** Rejected: keep the lounge and document `uploadCompleted` for clients; no change.

### The sync reads the photo's date from the file

- **Decision:** The sync reads `XMP-pwginfo:DateEDTF` first, then `DateCreated`, with an
  integration test using a dated file.
- **Reason:** A re-added file got no `date_creation` although the photo page had written the date
  into it (SES-2026-10-14-18). The date lives in the file
  ([decision 0043](../../../docs/agents/decisions/0043-photoinfo-requires-provenance-and-rebuilds-from-the-file.md)).
- **Trade-offs:** Rejected: a `pwg.photoinfo.rescan` after each sync; rely on the deploy's
  rescan.

**Group 4: visibility and counts**

### Empty albums are hidden from visitors

- **Decision:** Empty albums are hidden from visitors; "In Galerie öffnen" shows a note instead.
- **Reason:** An empty album showed its description to visitors (SES-2026-10-15-04/FND-01). It
  matches the fork's sub-album count fix
  ([decision 0037](../../../docs/agents/decisions/0037-album-thumbnails-count-only-listed-subalbums.md)).
- **Trade-offs:** Rejected: visible and documented in ch1; visible to admins only, silently.

### The menu photo count: research first

- **Decision:** Find why the menu shows 106 photos while the API and album counts give 105
  (SES-2026-10-13-01). Then the menu counts what the viewer can open, with a test.
- **Reason:** The cause is unknown; likely a photo in no album or in the lounge, counted for admins.
- **Trade-offs:** Rejected: document the menu count as the admin view; no change.

### `addNewTag` keeps its album check

- **Decision:** `typetags_image_visible()` (`plugins/typetags/main.inc.php`) keeps requiring a
  visible album, admins included. The error becomes "Foto ist in keinem sichtbaren Album", with a
  decision and a test for a photo in no album.
- **Reason:** "Image not found" for a photo the core API accepts looked like a bug
  (SES-2026-10-13-06). Tag badges only appear on the picture page, which needs an album.
- **Trade-offs:** Rejected: let admins through like core; no change.

**Group 5: messages and UX**

### `+` and `x` report a failed save

- **Decision:** A failed save through `+` or `x` shows the same message with the reason as typing
  does, and the page undoes the badge change.
- **Reason:** Only typing reported a failure (SES-2026-10-14-23); the page could show a state that
  was never saved.
- **Trade-offs:** Rejected: a generic message without undo; no change.

### German messages give the reason

- **Decision:** "A person needs a name" gets a German translation, and an expired session gives
  its reason ("Sitzung abgelaufen – bitte neu anmelden"). Both get a row in
  `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`.
- **Reason:** One message mixed English into German (SES-2026-10-13-03), the other gave no reason
  after a 401 (SES-2026-10-13-02).
- **Trade-offs:** Rejected: translate only the persons message; no change.

### A blank album name shows a hint

- **Decision:** "Name fehlt" next to the field; a test proves no album is created.
- **Reason:** The dialog stayed open with no message (SES-2026-10-13-10).
- **Trade-offs:** Rejected: disable the save button while empty; keep upstream behaviour.

### The unused-tag cleanup leaves coloured and person tags alone

- **Decision:** Coloured-group and person tags stay out of the cleanup; the confirm lists one
  name per line; the wording ("1 nicht benutzte Schlagworte", "11 verwaiste :") is fixed. The
  exclusion goes into core `get_orphan_tags()` (`admin/include/functions.php:430`), which all three
  cleanup paths call (`admin/tags.php:34`, `maintenance_actions.php:61`, `maintenance_env.php:73`).
- **Reason:** Names with commas made 11 tags read as 15, and groups hold these tags on purpose
  (SES-2026-10-14-12).
- **Trade-offs:** Rejected: fix only list and wording; no change.

### Abbrechen removes what was already sent

- **Decision:** Abbrechen removes the chunks already sent and any half-created photo, with an
  integration test that nothing is left on the server. Built in core with *Upload chunks never
  leak into another upload* (group 10), since buffer and upload page change there anyway.
- **Reason:** ch2 said nothing, and the chunks stayed (SES-2026-10-14-15).
- **Trade-offs:** Rejected: rely on core's own cleanup; no change.

### Merge messages lose their braces

- **Decision:** `local/language/de_DE.lang.php` overrides both merge keys without the braces:
  `Tag(s) \{%s1\} succesfully merged into "%s2"` (`admin/themes/default/template/tags.tpl:21`)
  and `Group(s) \{%s1\} succesfully merged into "%s2"` (`group_list.tpl:21`). One row each in
  `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php`.
- **Reason:** The modal showed "Schlagwort(e) {A, B} …" (SES-2026-10-14-03). The braces are in
  core's key and in its German translation (`language/de_DE/admin.lang.php:1060`, `:1063`), so an
  override is enough. The group key has the same defect, unobserved.
- **Trade-offs:** Rejected: the tag key only.

### The tag menu counts "1 Foto" / "n Fotos": a fork-local core edit

- **Decision:** Two fork-local core edits beside the existing merge-button change in `tags.tpl`:
  - `tags.tpl:24` `var str_number_photos = '{'%d photos'}'` gets `|@translate`, and a new
    `str_number_photo` carries `'%d photo'|@translate`.
  - `admin/themes/default/js/tags.js:65`, `:91` and `:775` take `str_number_photo` when the count
    is 1.

  A core defect, so *Defects in core are fixed in core* applies; listed in
  `.claude/rules/fork-local-core-edits.md`. Following *Cover the ground before you move it*
  (`.claude/rules/testing.md`), the tag menu count is characterized first (`[ERR]`, its own
  commit), then changed test-first.
- **Reason:** The menu said "1 photos" (SES-2026-10-14-03). The string has no `|@translate` and no
  singular, so no override reaches it. The German keys `%d photo` / `%d photos` exist already
  (`language/de_DE/common.lang.php:286-287`). The file already carries one fork-local change.
- **Trade-offs:** Two more core lines to carry through upstream pulls. Rejected: a typetags
  prefilter plus a script that rewrites the count in the DOM after `tags.js` (follows core's
  markup, fragile); `|@translate` only, with an override "%d Foto(s)" (changes the text on every
  page using the key).

### PWG-UPDATE-1 names digits

- **Decision:** A German override of
  `The name of directories and files must be composed of letters, numbers, "-", "_" or "."`:
  "Verzeichnis- und Dateinamen müssen aus Buchstaben, Ziffern oder den Zeichen "-", "_" oder "."
  bestehen." Row in `GermanOverrideKeyTest`: `admin/site_update.php`, 1 occurrence. ch2 stays.
- **Reason:** The German message omits digits, which ch2 and the sync accept (SES-2026-10-14-18).
  The English source names them; core's translation (`language/de_DE/admin.lang.php:353`) drops
  them.
- **Trade-offs:** Rejected: a note in ch2 only; leave the core translation.

### `addRegion` stores the frame as sent

- **Decision:** Find the rule that changed the frame (sent y 0.1, h 0.3; stored y 0.125, h 0.25)
  with a unit test. Store the frame as sent unless a rule requires the change.
- **Reason:** A silent change to the frame is a bug either way (SES-2026-10-15-03).
- **Trade-offs:** Rejected: accept and document the rule; no change.

### Region ids may have gaps

- **Decision:** No change, no test. Region ids jumping from 279 to 281 after an aborted `addRegion`
  is expected.
- **Reason:** persons rebuilds a photo's regions by delete plus `mass_inserts`
  (`plugins/persons/include/index.inc.php:131-135`); InnoDB does not reuse an auto-increment
  value of a failed or rolled-back insert. Nothing depends on contiguous ids (SES-2026-10-15-03).
- **Trade-offs:** Rejected: an integration test that a failed `addRegion` leaves no row (the
  session's read-back showed none); a log check for the lost insert.

### `nb_sub_photos` counts sub-album photos only

- **Decision:** No change. `pwg.categories.move` reports in `nb_sub_photos` the photos in an
  album's sub-albums, without the album's own photos.
- **Reason:** `include/ws_functions/pwg.categories.php:1347-1359` sums only
  `get_subcat_ids()` minus the album itself. Album 35 held two photos, its moved sub-album 36 none,
  so 0 is correct (SES-2026-10-15-06, a misread).
- **Trade-offs:** Rejected: an `[ERR]` characterization test (no regression risk worth a test);
  report the album's own photos too (changes the API meaning).

**Group 6: handbook**

### ch6 names the restore path

- **Decision:** `handbuch/06-drehen.html` says the webmaster restores through the replace option
  of Fotos hinzufügen, and asks the user to name the photo and the time of the edit. No new code.
- **Reason:** ch6 said "den Webmaster benachrichtigen" with no way to name a state and no restore
  path (SES-2026-10-14-20, SES-2026-10-15-15). The replace option works (SES-2026-10-15-15).
- **Trade-offs:** Rejected: a webmaster-only list of earlier versions with a restore button;
  restore by hand on the file system.

### ch7 describes the narrow window as it works

- **Decision:** Keep the behaviour; change only `handbuch/07-fotoseite.html`:
  - `:58-59`: "linkes Viertel" / "rechte Viertel" become "knapp das linke Drittel" / "knapp das
    rechte Drittel" (the theme's side zones are 30 % of the width, SES-2026-10-14-02/FND-01).
  - `:64-66` gains: "− und + fehlen dort, die Tasten + und − vergrößern trotzdem; eine
    Vergrößerung aus dem breiten Fenster bleibt erhalten."

  `ddev exec php handbuch/tools/check.php` passes; the wording gets a hand-check row in the ledger
  of `docs/agents/TESTING.md`. No screenshot shows these sentences.
- **Reason:** Keys and the kept zoom stay useful; only the text was wrong (SES-2026-10-14-02).
- **Trade-offs:** "knapp ein Drittel" is approximate. Rejected: the exact "30 %" (too technical
  for the handbook's readers); keys off and reset to Einpassen in a narrow window; show − and +
  there too.

### Marking a person stays mouse-only

- **Decision:** No code change; `handbuch/05-personen.html` stays (`:63` already says "Mit
  gedrückter Maustaste"). New decision file
  `docs/agents/decisions/0069-marking-a-person-needs-a-mouse.md` names the gap: no keyboard way to
  draw a frame (WCAG 2.1 SC 2.1.1), and ArrowRight in marking mode opens the next photo and ends
  the mode. Both are accepted.
- **Reason:** Keyboard users can enter the mode, pick a name and delete their own frames, but
  cannot draw one (SES-2026-10-15-18/FND-01). The handbook already states the mouse.
- **Trade-offs:** Not keyboard-operable. No skipped test: the gap is a choice, not a defect to fix
  later (`.claude/rules/test-design.md`, *A known gap becomes a deliberately failing test*, does
  not apply). Rejected: draw, move and resize a frame with keys; only block arrow-key photo
  navigation in marking mode.

### The Verlauf column reads "Von wem"

- **Decision:** `plugins/provenance/language/de_DE/plugin.lang.php:37` `$lang['By']` becomes
  `'Von wem'`, as ch3 names it (SES-2026-10-15-17/FND-01). Provenance owns the string, so no local
  override. Before the change: find the tests and handbook screenshots that show "Von"; update
  those tests and re-shoot only those screenshots (`.claude/rules/handbook.md`). Every signed-in
  user keeps seeing the editors' login names.
- **Reason:** Header (`plugins/provenance/template/provenance_page.tpl:27`) and handbook
  disagreed. The login names were asked about and accepted.
- **Trade-offs:** Admin login names stay visible to every signed-in user. Rejected: a local
  override; change ch3 to "Von"; display names for non-admins; hide the column from non-admins.

**Group 7: persons**

```
cause                                   findings
A  addslashes input never stripped  ->  13-03 F2/F3, 13-08 F1, 15-08 F1, 13-11 F2
B  tag found by url_name            ->  13-03 F1, 14-21 F1 (setup)
C  person tag unguarded             ->  14-21 F1, 15-01 F1/F2, 15-02 F1
D  persistent tag cache kept        ->  14-22 F1, 13-02 F4            (decision in group 8)
E  no upload listener               ->  14-24 F1/F2, issue 14-22
UI box hit-testing, labels, picker  ->  15-08 F2/F3, 15-09, 15-10, 15-11, 15-12, 15-19 F1/F2, 14-06
```

### Person names are unslashed once, escaped at every SQL use

- **Decision:** `pwg.persons.addRegion` and `pwg.persons.rename` `stripslashes` the name once at
  the ws boundary. `persons_person_id_from_name()` (`plugins/persons/include/index.inc.php:334`)
  then escapes it for the SELECT, `tag_id_from_tag_name()` and `single_insert()` alike. A reindex
  error becomes a ws error, never an uncaught fatal. The polluted files of photos 100, 103, 104,
  105 are repaired once through the fixed `pwg.persons.rename`. Unit test with
  `D'Arcy "R" & Töchter`; integration test: two saves of that name, one person row.
- **Reason:** `include/common.inc.php:17-35` slashes all request input. persons writes the slashed
  name into the file and searches for it double-escaped, while `single_insert()` stores it
  unslashed. The second resolve inserts again and throws "Duplicate entry"
  (`index.inc.php:360` from `:105` or `:615`). Every later reindex of the photo crashes, so the
  photo is blocked. Failed saves reappear because the file was written before the reindex
  (`:732-746`). The backslashes in the photoedit refusal (13-11 F2) come from the file.
- **Trade-offs:** A name with a real backslash loses it. Rejected: a repair script for the files
  (rename covers four photos).

### A person's tag is found by exact name and re-created when missing

- **Decision:** persons resolves a person's tag by exact name, without core's `url_name` fallback.
  When `persons.tag_id` points at no tag, resolving the person creates the tag again;
  `persons_person_gallery_url()` (`index.inc.php:501`) checks the tag exists, as its comment says.
- **Reason:** `tag_id_from_tag_name()` (`admin/include/functions.php:1727`) matches by
  `str2url()`, so `Name` resolves to the marker tag `Name ?` (13-03 F1). A deleted tag left a
  person with a dead link (15-01 F2) and "Anlegen" joined the old person (15-02 F1).
- **Trade-offs:** Rejected: keep the fallback (marker tags stay reachable).

### Tag tools refuse to remove or delete a person's tag

- **Decision:** Removing a person's tag from a photo (typetags `x`, Eigenschaften,
  Stapelverarbeitung, `pwg.images.setInfo`, `pwg.tags.*`) or deleting it in Schlagwortverwaltung is
  refused. The message points to the mark on the photo or to Personen. The typetags `x` is not
  shown on a person tag. Lookup through `persons.tag_id`, as person tags carry no marker
  ([decision 0031](../../../docs/agents/decisions/0031-person-names-are-visible-to-guests-as-ordinary-tags.md)). One integration test per path.
- **Reason:** These paths removed the tag but kept the mark; the person link then gave 401 or 404
  (14-21 F1, 15-01 F1/F2). The mark is the source; its tag follows it
  ([decision 0020](../../../docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md)).
- **Trade-offs:** The only way to drop a person tag is to delete the mark or the person. Rejected:
  removing the tag deletes the mark (bypasses
  [decision 0065](../../../docs/agents/decisions/0065-normal-users-delete-only-the-regions-they-drew.md));
  persons re-adds the tag silently.

### An upload or replace merges file and gallery field by field

- **Decision:** persons and photoinfo listen to core's `loc_end_add_uploaded_file`
  (`admin/include/functions_upload.inc.php:426`). Per field (regions, Info, date, keywords): if the
  new file carries it, the file wins and the photo is reindexed from it; if not, the gallery's
  value is written into the file. A new upload indexes the regions already in its file. Supersedes
  the "uploads have no tag write" part of
  [decision 0050](../../../docs/agents/decisions/0050-uploads-and-iptc-import-do-not-write-tags.md)
  for replace only; IPTC import stays as 0050 says.
- **Reason:** A replace left the index at the edited frame (stale frame, 14-24 F1) and a file
  without the gallery's Info, region and keyword, which a rescan then deleted (14-24 F2). A mark
  already in an uploaded file needed a manual rescan (issue 14-22). Field by field covers both
  cases: a restored ch6 copy holds the right regions; a plain new scan holds none.
- **Trade-offs:** A replace file that carries an outdated field wins over the gallery. Rejected:
  file always wins (a plain replace loses marks); gallery always wins (a restore brings back stale
  frames).

### The marking editor: delete, draw, labels, picker

- **Decision:**
  - Covered `x`: the delete control sits above every box in marking mode (15-08 F2, 15-09 F1;
    `.person-box-delete` has no z-index, `plugins/persons/template/editor.css:108`).
  - A drag that starts inside a frame draws a new frame; `x` and label stay clickable (15-08 F3;
    `editor.js:454` returns on any `.person-box`).
  - Labels end with an ellipsis; on hover the full name shows on top, inside the photo edge
    (15-11 F1, 15-19 F1/F2; `overlay.css:69-85`).
  - Enter filters at once on the typed text and commits only an exact match, else Anlegen
    (15-12 F1; `SEARCH_DEBOUNCE_MS` 200, `editor.js:31`, stale list at `:216`).
  - `x` on a mark that is already gone: the 404 removes the box with "Markierung ist nicht mehr
    vorhanden"; absent and hidden still answer alike
    ([decision 0019](../../../docs/agents/decisions/0019-person-region-permission-model.md))
    (15-10 F1; `ws_functions.inc.php:366`).
  - Admin person search escapes `%` and `_` after `stripslashes` (14-06 F1;
    `plugins/persons/admin/persons.php:24-32`).

  E2E per item, except the search (integration).
- **Reason:** Each control looked active but did nothing, did something else, or named the wrong
  cause.
- **Trade-offs:** Labels of same-size frames still overlap; hover resolves them. Rejected: label
  collision layout.

**Group 8: tags**

### Typed tag names are NFC and match by name only

- **Decision:** typetags `addNewTag` (`plugins/typetags/main.inc.php:395`) and core `get_tag_ids`
  normalize input to NFC (`Normalizer::FORM_C`). The match drops the `url_name` fallback and keeps
  the collation match; the answer and the badge name the stored tag the photo got.
  `typetags_tag_exists()` (`:475`) follows the same rule.
- **Reason:** NFD input made a second tag with the same visible name (13-02 F1). The `url_name`
  fallback assigned `Name ?` and `Kategorie ?` for `Name` and `Kategorie` (13-02 F2). The column is
  `utf8mb3_general_ci`, so `Häuser Ortsansichten` equals `hauser ortsansichten` (13-02 F3), and the
  server refuses a second name it calls equal.
- **Trade-offs:** Case and accent variants still map to one tag; the user now sees which.
  Rejected: a binary collation (schema change, breaks `seed.collated()` in `tools/deploy`).

### Tag writes clear the whole user cache

- **Decision:** persons (`persons_invalidate_tag_cache()`, `index.inc.php:450`) and typetags
  (`main.inc.php:367`, `:429`, `:598`) call `invalidate_user_cache()` in place of
  `nb_available_tags = NULL`. New decision superseding
  [decision 0004](../../../docs/agents/decisions/0004-unscoped-tag-cache-invalidation-accepted.md).
- **Reason:** `get_available_tags()` (`include/functions_tag.inc.php:74`) caches per
  `cache_update_time` in `$persistent_cache`; only `invalidate_user_cache()` purges it. Tag list,
  tag page and Schlagwort-Gruppen stayed stale (14-22 F1, 13-02 F4). 0004's "cannot
  under-invalidate" was false.
- **Trade-offs:** Each tag write rebuilds every user's cache once.

### typetags writes need POST and a token

- **Decision:** `typetags.type.add`, `typetags.tags.setType`, the group delete (`admin.php:89`,
  today a GET link) and the `edittypetag` POST check `pwg_token` and take POST only;
  `typetag_id` is cast to int (`main.inc.php:174`). The plugin's `tags.js` sends them that way.
  Integration test per method: no token, refused, nothing changed.
- **Reason:** Group create and tag regrouping worked by GET without a token (14-09 F1, CSRF);
  `type.update` already checks one.
- **Trade-offs:** None.

### "Schlagwort-Farben festlegen" reports a failure

- **Decision:** The catch in `plugins/typetags/template/tags.js:136` shows the error, removes
  `loading` and hides the old "Farbe gespeichert"; `APIUpdateColorsTags` treats `stat:"fail"` as
  a failure. E2E with a forced failure, then a second save that goes through.
- **Reason:** One failure left the button spinning and blocked every later save until reload
  (15-14 F1, 15-21 F1).
- **Trade-offs:** None.

**Group 9: files and Herkunft**

### Only new album links inherit Herkunft

- **Decision:** The fork-local `associate_images_to_categories` trigger
  (`admin/include/functions.php:2104`) passes only the pairs it inserted.
  `provenance_inherit_associated()` is unchanged.
- **Reason:** It passed every checked album, so a photo took the Herkunft of an album it was
  already in, not of the newly linked one (15-16 F1).
- **Trade-offs:** None; provenance is the trigger's only listener.

### Saving the Notiz leaves the photo form clean

- **Decision:** provenance's prefilter places the Notiz block outside `<form id="pictureModify">`
  (`plugins/provenance/include/functions.inc.php:779`).
- **Reason:** Core's dirty tracking (`admin/themes/default/js/picture_modify.js:21-38`) counted the
  Notiz field; its AJAX save cannot reset the flag, so leaving warned of unsaved changes (14-01 F1).
- **Trade-offs:** Rejected: reset the flag from the plugin (closure-local, unreachable).

### IPTC carries its character set

- **Decision:** Every argfile builder adds `-IPTC:CodedCharacterSet=UTF8`
  (`photoinfo_build_argfile`, `_date_argfile`, `_tags_argfile`, `provenance_build_argfile`).
  Integration test reads the caption with an independent reader.
- **Reason:** exiftool writes UTF-8 bytes (`-charset iptc=UTF8`) without the marker, so readers
  show Latin-1 garbage for umlauts and the en dash (13-04 F1).
- **Trade-offs:** None.

### `_original` sidecars move aside on delete

- **Decision:** On `delete_elements`, a photo's exiftool `_original` sidecar moves with its
  photoedit copies to `_data/photoedit/originals/deleted/<id>-<deletion time>/`.
  `galleries/**/*_original` goes into `.gitignore`.
- **Reason:** provenance, photoinfo and persons keep exiftool's default backup on purpose; nothing
  removed it with the photo (14-21 F2, session issues). The sidecar is the only copy of the
  pre-write bytes, so it moves, not vanishes.
- **Trade-offs:** No size limit, as for the copies. Rejected: `-overwrite_original` (loses the
  backup).

**Group 10: core defects** (per *Defects in core are fixed in core*)

### Upload chunks never leak into another upload

- **Decision:** `ws_images_upload` (`include/ws_functions/pwg.images.php:1770-1821`) keys the
  buffer by user and file name and opens it with `wb` at chunk 0. A `.part` older than one day is
  removed on the next upload. Integration test: a cancelled upload, then a same-name upload by
  another admin stores exactly the new bytes.
- **Reason:** The buffer was `md5(name)`, shared by all admins, and chunk 0 appended (`ab`) to a
  stale `.part`, so a re-upload stored the cancelled bytes in front (14-15 F1). Nothing removed
  `.part` files (issue 15-07).
- **Trade-offs:** None.

### The upload page reports what the server did

- **Decision:** `admin/themes/default/js/photos_add_direct.js`: the Error handler guards
  `JSON.parse` (`:423`); a non-JSON answer counts as a failure with the server text (`:396`);
  `uploadCompleted` gets success and error callbacks and reports a failure (`:435-445`); the
  Abbrechen path checks `uploadCategory` for null (`:442`). E2E per case.
- **Reason:** A refused file hid the valid ones (14-07 F1); "forbidden file type" counted as
  uploaded (14-07 F2); a failed `uploadCompleted` reported "1 Fotos hochgeladen" (15-07 F1);
  Abbrechen before the first file threw and stuck the page (14-15 F2).
- **Trade-offs:** None.

### Album properties: move errors show, sub-albums are not offered

- **Decision:** `admin/themes/default/js/cat_modify.js:70-155` waits for `setInfo` and `move` and
  shows the move error; the parent picker excludes the album's own sub-albums
  (`album_selector.js`). "Just now" shows once and gets a German translation
  (`cat_modify.js:90-91`).
- **Reason:** A refused move into an own sub-album said "Album gespeichert" (14-10 F1). The info
  box read "Just now" twice, in English (15-04 F3).
- **Trade-offs:** None.

### API checks and counts

- **Decision:**
  - `pwg.categories.getList` returns the substitute's id as `representative_picture_id`, not the
    hidden photo's (`pwg.categories.php:497-514`, 14-13 F1, a leak of a hidden id).
  - `pwg.images.setInfo` refuses a `level` outside `available_permission_levels`, as
    `setPrivacyLevel` does (`pwg.images.php:1094`, 14-16 F1).
  - The sync counts errors after the metadata pass (`admin/site_update.php:820-898`, 14-18 F1) and
    counts the photos of removed folders as deleted (`:431-487`, 14-18 F2).

  Integration test per item.
- **Reason:** Each answer or counter disagreed with what the server stored.
- **Trade-offs:** None.

### Tag admin page errors show

- **Decision:** `admin/themes/default/js/tags.js`: a failed rename calls `showError()`
  (`:136-140`); Duplikat sets `raw_name` (`:414-420`). E2E per case.
- **Reason:** Renaming to an existing name failed silently (14-03 F1); after a Duplikat, search and
  delete threw a TypeError (14-09 F2, cause found in code: the Duplikat earlier in the same page
  load).
- **Trade-offs:** None.

### Album description keeps lines, long words wrap

- **Decision:** The album description is escaped (group 1), then `nl2br`. modus gets
  `overflow-wrap: anywhere` for tag names and `.additional_info`
  (`themes/modus/css/base.css.tpl`).
- **Reason:** Line breaks were lost (15-04 F2, `allow_html_descriptions` skips `pwg_nl2br`); a
  long word or a 255-character tag widened the page (15-04 F4, 13-02 F5).
- **Trade-offs:** None.

### The zoom's scroll bar sits inside the window

- **Decision:** `themes/modus/js/photo.zoom.js:98` subtracts `#theImage`'s padding-bottom from
  `max-height`. Extends [decision 0032](../../../docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md).
- **Reason:** The 20 px padding put the horizontal scroll bar below the window (14-17 F1).
- **Trade-offs:** None.

## Not changed

- Guest download of photo files by URL (13-01 F1):
  [decision 0056](../../../docs/agents/decisions/0056-static-image-files-stay-reachable-by-url.md).
- Refuted in their sessions: 13-05 F3, 14-06 F2.
- Test-environment issues (mkcert CA, `playwright trace open`, playwright-cli registry and
  requests, host sleep, Mutagen lag, stale `test-users.md` passwords): not product defects.
