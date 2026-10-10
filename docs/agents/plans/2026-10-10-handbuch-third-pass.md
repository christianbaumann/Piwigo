---
date: 2026-10-10T17:43:28+00:00
git_commit: a2a9e953a7ec48bcc34a8c97b7ca8cce64e60380
branch: master
topic: "Handbook third pass: fixes, missing tests, new page Die Fotoseite"
tags: [plan, handbuch, photoinfo, persons, photoedit, provenance, core, documentation]
status: draft
---

# Handbook third pass, implementation plan

## Overview

Apply the approved findings of
[2026-10-10-handbuch-review.md](../research/2026-10-10-handbuch-review.md) (source of truth, IDs
A1-A9, B1-B12, C1-C3, D1-D7, E1-E7, F1-F3, G1-G2, O1-O9). Owner answers 2026-10-10: O1-O6 a,
O7 b, O8 b, O9 a; planning questions P1-P5 a.

## Current State Analysis

- 7 pages, 25 shots. 9 wrong claims, 12 incomplete, 3 unsettled by code (research note).
- Three small code defects behind the text: hard-coded English in core `tags.tpl:110-111`
  (G1), English `PHOTOINFO_ADMIN_ERROR_PREFIX` and `PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE` (G2),
  photoinfo's *Abbrechen* reading as the main button under the default skin (O8).
- Several claims the handbook relies on have no test (P3).

## Desired End State

- Every finding in the research note fixed in the pages, with the decisions O1-O9, P1-P5.
- New page `handbuch/07-fotoseite.html` with the zoom section from 05 and shot 26.
- Every falsifiable claim fixed by this pass is witnessed by a test, or carries a recorded reason.
- All suites named below green; `check.php` green; dated "third pass" in `docs/agents/TESTING.md`.

### UI Mockups

Date and info editor, newspaper skin (O8 / P1):

```
now                                   after
+---------------------------+         +---------------------------+
| [ Speichern ] [Abbrechen] |         | [ Speichern ]  Abbrechen  |
|   dark grey     blue      |         |   skin submit  plain text |
+---------------------------+         +---------------------------+
```

Page chain:

```
index -> 01 -> 02 -> 03 -> 04 -> 05 -> 06 -> 07 Die Fotoseite (new)
                                  |
                                  +-- "Das Foto vergroessern" moves to 07; 05 links there
```

### Key Discoveries

- Newspaper skin: all buttons blue (`themes/modus/css/base.css.tpl:97`), submit dark grey
  (`themes/modus/skins/newspaper.css:122-125`). Selectors are `INPUT[type=...]`, so an id selector
  in `plugins/photoinfo/template/info.css` wins.
- persons' `tests/e2e/support/skins.js` lists every modus skin (`SKINS`, `MIN_SKINS`); the
  pattern to copy is `persons/tests/e2e/editor.spec.js:468-483` with `?skin=<name>`.
- photoinfo locators: `support/PicturePage.js:17-18` (info), `:35-36` (date).
- `core-admin-screens.spec.js:251-283` asserts `Confirm merge` / `Cancel`, written to go red
  "the day the handbook must change". Locators are ids/classes (`CoreAdminPages.js:159-163`); JS
  binds by id/class (`admin/themes/default/js/tags.js:571, 730`). de_DE keys exist
  (`language/de_DE/admin.lang.php:755, 801`).
- `PHOTOINFO_ADMIN_ERROR_PREFIX` used once, `photoinfo/include/events_core_edit.inc.php:327`,
  admin context only; photoinfo's `plugin.lang` loaded only in `events_public.inc.php:36`. Tests
  build their oracle from the constant (`CoreEditTest.php:20`, `TagWriteTest.php:17`).
- `handbuch-pages.spec.js:31-34` and `check.php:61` glob pages; the spec demands >= 1 `<img>` on
  every page except index (`:64-66`). Every PNG must be referenced (`check.php:235-249`) and
  declared in `shoot.js` `SHOTS` (`assertOutput()`, `:791-822`).
- `seed.php:886` sets `added_by` to `$conf['webmaster_id']` (= user 1 `chriss` here).

## What We're NOT Doing

- No restructuring beyond moving the zoom section to 07 (O7). Files 01-06 keep their names.
- No product fix for B8 (silent tag-write failure on the picture page): skipped test + backlog (O6).
- No change to the newspaper skin or any theme file (P1).
- No new handbook topics (P5): no info panel walkthrough, no slideshow, download or favourites.
- No translation of exiftool's own error text (P2).
- No test for A3 (upload result link target): browser coverage of the uploader is refused in
  `docs/agents/TESTING.md` (non-coverage table); the text fix stands on the code reading
  `admin/themes/default/js/photos_add_direct.js:468-470`.
- B9 (daily cap) and B12 (API/IPTC exceptions) stay out of the handbook.
- No remote deploy; Q28 stays with the owner.

## Implementation Approach

Code first, test-first, one commit per fix. Then tests for today's behaviour the text will rely
on, each watched red by breaking the behaviour. Then the screen checks, then text page by page,
then shots, then records. Commit on `master` after each green step (memory: work on master).

---

## Phase 1: Code fixes behind the text

### Overview

Three defects the handbook currently works around. Each: failing test, fix, suite green, commit.

### Changes Required

#### [x] 1.1 O8: photoinfo *Abbrechen* as a quiet secondary control

**Test first**: `plugins/photoinfo/tests/e2e/editor-buttons.spec.js` (new), page object additions
in `support/PicturePage.js` (`cancelLook()`, `saveLook()`, reading computed `backgroundColor`,
`backgroundImage`, `borderTopWidth`). The skin list is required from persons'
`../../../../persons/tests/e2e/support/skins.js`, so it has one home.

```js
for (const skin of SKINS) {
  test(`[ECP] ${skin}: Abbrechen has no fill and no border, Speichern keeps the skin's submit look`, ...)
}
test('[ERR] the skin list is not empty', () => expect(SKINS.length).toBeGreaterThanOrEqual(MIN_SKINS));
```

Assertions per skin, for both the date and the info editor: cancel `backgroundColor` transparent,
`backgroundImage` `none`, border width `0px`; save equals a probe `input[type=submit]` injected into
the same form (same props), so the fix cannot touch *Speichern*.

**Fix**: `plugins/photoinfo/template/info.css`

```css
#PhotoDate .photoinfo-cancel,
#PhotoInfo .photoinfo-cancel,
#PhotoDate .photoinfo-cancel:hover,
#PhotoInfo .photoinfo-cancel:hover {
  background: none;
  border: 0;
  box-shadow: none;
  color: inherit;
  text-decoration: underline;
  cursor: pointer;
}
```

#### [x] 1.2 O2: German *Fotoinfo:* prefix and not-writable message

**Test first**: `plugins/photoinfo/tests/Integration/CoreEditTest.php` and `TagWriteTest.php`:
replace the constant-built `WRITE_FAILED` oracle with the German literal
`'Fotoinfo: Die Datei fehlt oder ist nicht beschreibbar'` (exact wording fixed in the language
file; the test reads the de_DE language file's value, not a second typed copy, per
`.claude/rules/test-design.md` "do not transcribe production data"). Red: today the page shows
`Photo Info: File is missing or not writable`. Requires the integration admin to run in de_DE;
check `photoinfo_webmaster`'s language in `create-test-users.php` and set it there if needed.
The negative assertions (`TagWriteTest.php:706`, `FreitextAssignTest.php:248, 260`) switch to the
German prefix too.

**Fix**:
- `plugins/photoinfo/include/events_core_edit.inc.php:327`: `load_language('plugin.lang', PHOTOINFO_PATH)`
  once, then `l10n(PHOTOINFO_ADMIN_ERROR_PREFIX).l10n($result['message'])` (keys stay English).
- `plugins/photoinfo/language/de_DE/plugin.lang.php`, `en_UK/plugin.lang.php`: two keys.
- `handbuch/04-schlagworte.html:296` quotes *Fotoinfo:* (in Phase 4).

#### [x] 1.3 O1: translate the merge buttons in core

**Test first**: `plugins/provenance/tests/e2e/core-admin-screens.spec.js:251-283`: rename to
"the merge panel opens two interactions deep and its buttons are German", expect
`Zusammenführen bestätigen` and `Abbrechen`, read from core's de_DE file through the existing
label helper if the spec has one, else as literals with a comment naming
`language/de_DE/admin.lang.php:755, 801`. Red today.

**Fix**: `admin/themes/default/template/tags.tpl:110-111`

```smarty
<button class="icon-ok ConfirmMergeButton">{'Confirm merge'|@translate}</button>
<a id="CancelMerge">{'Cancel'|@translate}</a>
```

- `CLAUDE.md`: one bullet under fork-local core changes (`tags.tpl` merge buttons translatable).
- Upstream candidate noted in `docs/backlog.md`.

### Success Criteria

#### Automated Verification
- [x] 1.1 red before the CSS, green after: photoinfo E2E
- [x] 1.2 red before, green after: photoinfo unit + integration
- [x] 1.3 red before, green after: provenance E2E `core-admin-screens.spec.js`
- [ ] typetags E2E green (its prefilter touches `tags.tpl`)

#### Manual Verification
- [ ] Shots 24/25 re-taken in Phase 5 show the new look

---

## Phase 2: Tests for behaviour the text will rely on

### Overview

Characterize today's behaviour before the text describes it. Tag `[ERR]` where the oracle is the
code. Each passes first run, so each is watched red by breaking the behaviour, then reverted.

### Changes Required

#### [ ] 2.1 A2 md5 duplicate
`plugins/provenance/tests/Integration/CoreUploadCharacterizationTest.php`:
`testAByteIdenticalUploadLinksTheExistingPhotoInsteadOfAddingOne` `[ERR]`. Upload the fixture
twice into two albums; assert one image row, linked to both. Red by setting
`upload_detect_duplicate` false in the test's config row (restored in tearDown).

#### [ ] 2.2 B1 provenance buttons absent at 0 photos
`plugins/provenance/tests/Integration/` album screen source test (existing page-source test class
for the album screen): `testAnEmptyAlbumOffersNeitherApplyNorWrite` `[BVA]` 0 vs 1 photo. Red by
removing the condition in `album_provenance.tpl:21-34`.

#### [ ] 2.3 A5 first entry highlighted
`plugins/persons/tests/e2e/editor.spec.js`: "[HAPPY] the first entry is highlighted as the list
opens, and Enter takes it"; "[ECP] a partial match is listed before Anlegen". Red by removing
`setHighlight(0)` (`editor.js:576-581`).

#### [ ] 2.4 B10 success then failure still reloads
`editor.spec.js`: "[ST] leaving after a save and a later refused save reloads". Red by resetting
`changed` on failure.

#### [ ] 2.5 A7 nav arrows stay in edit mode
`plugins/photoedit/tests/e2e/edit-mode.spec.js`: "[ST] the navigation arrows stay visible in edit
mode and leave the page". Red by hiding them in `editor.css`.

#### [ ] 2.6 B11 tagging changes, then edit mode
`edit-mode.spec.js`: "[ST] entering edit mode after a tagging change reloads instead of opening".
Red by removing the reload in persons' `editor.js:541-545`.

#### [ ] 2.7 B8 known gap
`plugins/photoinfo/tests/e2e/tags-freitext.spec.js` or a new spec: "a failed tag write on the
picture page tells the user", `test.skip` with reason and link to the backlog entry.
`docs/backlog.md`: entry for typetags ignoring `tags_message`.

### Success Criteria

#### Automated Verification
- [ ] each new test green on unchanged code, red under its named break, green after revert
  (checksum poll per `.claude/rules/mutation-testing.md` before each run)
- [ ] provenance integration, persons E2E, photoedit E2E, photoinfo E2E green twice in a row

---

## Phase 3: Screen checks C1-C3

### Overview

Settle what the code cannot, then pin it.

#### [ ] 3.1 C1 *100 %* for `persons_normal`
Check whether the account sees originals (`enabled_high`), then compare the zoom at 100 % with the
original and the largest derivative. Pin in `persons/tests/e2e/picture-zoom.spec.js` as a normal
account: "[ECP] 100 % shows the largest size this account may load".

#### [ ] 3.2 C2 stale tooltip
Hover a stale box outside and inside tagging mode. Pin the result in `overlay.spec.js` (extend
"is drawn dashed, dimmed and with an explanation" with the German text and where it shows).

#### [ ] 3.3 C3 confirm buttons
`window.confirm` in the container's Chromium; record the labels in the TESTING.md ledger (browser
UI, no test).

### Success Criteria
- [ ] each finding recorded with result in the research note
- [ ] persons E2E green

---

## Phase 4: Handbook text, one commit per page

### Overview

Per page: apply the A/B/D/E rows, the O decisions; `check.php`; handbook E2E; commit.
Style: brief, steps as full sentences, notes may be fragments, no em/en dashes, no emojis.

#### [ ] 4.1 `01-alben.html`: A1, B1, D3, D4, E7
#### [ ] 4.2 `02-fotos.html`: A2, A3, B3, B4, B5, D3, D5, E7
#### [ ] 4.3 `03-fototexte.html`: A4, B6, D1 (O3), D5, E2, E4, E7
#### [ ] 4.4 `04-schlagworte.html`: A9, B7, B8 (O6), D1, D2 (O4), D6, E1, E6, E7; merge step and hinweis per O1; *Fotoinfo:* per O2
#### [ ] 4.5 `05-personen.html` + new `07-fotoseite.html`: A5, A6, B10, C1/C2 results, D1, E3, E6, E7; zoom section moves to 07 (O7, P5), 05 links it
#### [ ] 4.6 `06-drehen.html`: A7, A8, B11, C3, D1, D2, E7; nav gets *Weiter* to 07
#### [ ] 4.7 `index.html`: B2 (*Administration* link), D7, O3, O4 terms defined once, chapter 7, "sieben Arbeitsschritte"

### Success Criteria
- [ ] `ddev exec php handbuch/tools/check.php` green after each page
- [ ] provenance E2E `handbuch-pages.spec.js` green (07 needs its `<img>`: shot 26, Phase 5; until
  then 07 references shot 11, replaced in 5)

---

## Phase 5: Seed and screenshots

#### [ ] 5.1 `handbuch/tools/seed.php:886`: `added_by` = id of `persons_webmaster` looked up by
username; refuse if missing (names `create-test-users.php`)
#### [ ] 5.2 `handbuch/tools/shoot.js`: declare `26-fotoseite-leiste.png`, `#imageToolBar` as NORMAL
#### [ ] 5.3 seed, shoot, restore (`.claude/rules/handbook.md`); keep 09, 24, 25, 26 and 11 if A4's
caption changed it; revert every other re-shoot
#### [ ] 5.4 view each kept shot against its text; 07 references shot 26

### Success Criteria
- [ ] `shoot.js` `assertOutput()` passes; `check.php` green; `handbuch-pages.spec.js` green
- [ ] install back to 5 albums after `--restore`
- [ ] 09 shows `persons_webmaster`, not `chriss`

---

## Phase 6: Records

#### [ ] 6.1 `docs/agents/TESTING.md`: dated "third pass" section (findings, tests added, C1-C3
results, hand checks); fix "five documented workflows" (`:1166`); move-of-zoom ledger row
#### [ ] 6.2 `.claude/rules/handbook.md`: page count and topic list (eight files incl. index)
#### [ ] 6.3 `handbuch-pages.spec.js:27` comment ("six pages")
#### [ ] 6.4 `docs/backlog.md`: B8 product fix, `tags.tpl` upstream candidate
#### [ ] 6.5 research note `status: implemented`; this plan `status: implemented`

### Success Criteria
- [ ] `bash tools/test-hooks.sh` green; commit gate passes

---

## Testing Strategy

### Test Design Techniques Applied

- O8: `[ECP]` one case per skin (18 skins, measured 2026-10-05 in persons' `skins.js`), anti-vacuity
  `MIN_SKINS`; Speichern compared against a probe, not a hard-coded colour.
- O2: `[HAPPY]` failure shows German prefix and message once; `[NEG]` success shows no prefix
  (existing negative cases kept). Decision table not applicable: one condition, two outcomes.
- O1: `[HAPPY]` German labels. Boundary values not applicable: static labels.
- Phase 2: `[ERR]` for characterization (A2), `[BVA]` 0/1 photo (B1), `[ST]` for editor and
  edit-mode transitions (A7, B10, B11), `[HAPPY]`/`[ECP]` for list order (A5).

### Unit Tests
- None new: every change sits in templates, CSS, a message path or browser behaviour.
  `plugins/provenance/tests/Unit/GermanOverrideKeyTest.php` needs no row (O1 keys live in core,
  not in the override file).

### Integration Tests
- [x] `photoinfo CoreEditTest`: failed write shows German prefix and message `[HAPPY]`
- [x] `photoinfo TagWriteTest`: same through the tag path; absence cases `[NEG]`
- [x] `photoinfo FreitextAssignTest`: absence cases updated `[NEG]`
- [ ] `provenance CoreUploadCharacterizationTest`: byte-identical upload `[ERR]`
- [ ] provenance album screen source: buttons at 0 and 1 photos `[BVA]`

### End-to-End Tests
- [x] `photoinfo editor-buttons.spec.js`: per skin `[ECP]`
- [x] `provenance core-admin-screens.spec.js`: merge buttons German `[HAPPY]`
- [ ] `persons editor.spec.js`: A5 `[HAPPY]` `[ECP]`, B10 `[ST]`
- [ ] `photoedit edit-mode.spec.js`: A7 `[ST]`, B11 `[ST]`
- [ ] `persons picture-zoom.spec.js`: C1 `[ECP]`
- [ ] `persons overlay.spec.js`: C2 extended
- [ ] photoinfo: B8 `test.skip` known gap
- [ ] `provenance handbuch-pages.spec.js`: picks up 07 by glob

### Regression
- [ ] typetags unit, integration, E2E (core `tags.tpl`, tag paths)
- [ ] photoinfo unit, integration, E2E
- [ ] persons, photoedit, provenance: unit, integration, E2E
- [ ] each suite twice in a row

### Manual Testing Steps
1. C3: confirm dialog labels in the container's Chromium (browser UI, not reachable by a spec).
2. Read each changed page once in a browser over `file://`: German reads naturally (ledger row).

### Test Commands

All from `.claude/rules/plugin-test-suites.md`:

```bash
ddev exec plugins/photoinfo/vendor/bin/phpunit --testsuite unit --configuration plugins/photoinfo/phpunit.xml
ddev exec bash -c 'set -a; . local/config/photoinfo-test.env; set +a; \
  plugins/photoinfo/vendor/bin/phpunit --testsuite integration --configuration plugins/photoinfo/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/photoinfo-test.env; set +a; cd plugins/photoinfo && npx playwright test'
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; \
  plugins/provenance/vendor/bin/phpunit --testsuite integration --configuration plugins/provenance/phpunit.xml'
ddev exec bash -c 'set -a; . local/config/provenance-test.env; set +a; cd plugins/provenance && npx playwright test'
ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; cd plugins/persons && npx playwright test'
ddev exec bash -c 'set -a; . local/config/photoedit-test.env; set +a; cd plugins/photoedit && npx playwright test'
ddev exec bash -c 'set -a; . local/config/typetags-test.env; set +a; cd plugins/typetags && npx playwright test'
ddev exec php handbuch/tools/check.php
bash tools/test-hooks.sh
```

## Performance Considerations

None. O8 adds 18 skin cases to photoinfo E2E (persons' equivalent loop runs in seconds).

## Migration Notes

- `local/language` untouched. After deploy, purge compiled templates on the remote only if
  needed: `tags.tpl` is a core template, recompiled by mtime (no prefilter change).

## References

- Research: [2026-10-10-handbuch-review.md](../research/2026-10-10-handbuch-review.md)
- Prior passes: `docs/agents/TESTING.md` "Reading the handbook against the application" (2026-08-31, 2026-09-01)
- Skin loop pattern: `plugins/persons/tests/e2e/editor.spec.js:468-483`
- Rules: `.claude/rules/handbook.md`, `testing.md`, `test-design.md`, `mutation-testing.md`
