---
date: 2026-10-10
git_commit: a2a9e953a
branch: master
topic: "Handbook third pass: labels, claims, cross-page consistency, brevity, screenshots"
tags: [research, handbuch, documentation, persons, photoedit, photoinfo, typetags, provenance]
status: implemented 2026-10-11
---

# Handbook review, third pass

Scope and method agreed 2026-10-10 (brainstorm Q2-Q11, all `a`). Text after 2026-09-01 read line by
line, older text spot-checked. Labels against locale files and templates, claims against code,
all 25 shots viewed. Nothing changed yet.

Counts: 196 labels checked, 196 resolve verbatim. 169 claims checked: 9 wrong, 12 incomplete or
doubtful, 3 need a screen check.

## A. Wrong claims

| # | Page / place | Handbook says | Truth (source) | Fix |
|---|---|---|---|---|
| A1 | 01 Herkunft hinweis | values reach no photo without *Auf N Fotos anwenden*; row stays empty | photos added later inherit by themselves (`provenance/include/events_inherit.inc.php:4-18`); no provenance = row absent, not empty (`events_public.inc.php:40-45`). Also contradicts 03 L124-125 | "Gilt nur für Fotos, die schon im Album liegen. Später hinzukommende übernehmen die Werte selbst. Ohne diesen Schritt fehlt die Zeile **Herkunft**." |
| A2 | 02 Optionen | without the switch a second photo is created | byte-identical file (md5) only links the existing photo; `upload_detect_duplicate` on (`admin/include/functions_upload.inc.php:160-183`) | "...entsteht ein zweites Foto, außer die Datei ist byte-gleich mit einem vorhandenen: dann wird nur dieses mit dem Album verbunden." |
| A3 | 02 after upload | album name links to the album | links to the album's admin properties (`admin/themes/default/js/photos_add_direct.js:468-470`) | "...ein Link zu den Eigenschaften des Albums." |
| A4 | 03 Wo die Texte erscheinen, shot 11 caption | title as heading over the photo | title is in the top bar after the album path; the banner in the shot is drawn into the demo image (`handbuch/tools/seed.php:392-404`) | "in der Kopfzeile, nach dem Albumpfad"; caption without "mit seinem Titel" |
| A5 | 05 Enter hinweis | nothing highlighted, nothing happens | first entry always highlighted, also with empty field (`persons/template/editor.js:142, 576-581`); a partial match comes before *Anlegen "..."* | "Der erste Eintrag ist immer hervorgehoben. Für einen neuen Namen **Anlegen "..."** mit Pfeiltaste oder Klick wählen." |
| A6 | 05 Personenliste | rescan never changes files | writes regions of a file turned outside the gallery (`persons/include/index.inc.php:80, 162-260`) | "...ändert die Dateien nicht, außer ein Foto wurde außerhalb der Galerie gedreht: dann werden seine Markierungen mitgedreht." |
| A7 | 06 step 2 | all other buttons replaced | nav arrows stay (shot 22) and leave the page, dropping the preview (`photoedit/template/editor.css`, `.actionButtons`) | add: "Die Pfeile zum Blättern bleiben; ein Klick darauf verwirft die Vorschau." |
| A8 | 06 Rückfragen | face cut "ganz oder zu mehr als der Hälfte" | removed when its centre lies outside the crop or the rest is below minimum size (`persons/include/functions.inc.php:365-405`) | "...wenn die Mitte eines markierten Gesichts außerhalb des Zuschnitts liegt oder nur ein kleiner Rest bleibt." |
| A9 | 04 Farbige Schlagworte | "überall als farbige Plakette" | tag admin list shows a dot (same page, shot 12) | "auf der Fotoseite und in den Eigenschaften als farbige Plakette" |

## B. Incomplete or doubtful

| # | Page / place | Gap (source) | Fix |
|---|---|---|---|
| B1 | 01 Herkunft | both buttons also absent while the album has 0 photos (`album_provenance.tpl:21-34`) | one sentence |
| B2 | 01, 02, index | way into the admin area is the link **Administration** (`menubar_identification.tpl:21`); never named | index: "Die Verwaltung erreicht man über den Link **Administration**." |
| B3 | 02 Ins Album verschieben | provenance adds panel *Herkunft der verschobenen Fotos* (Beibehalten / Leeren / Durch die des Zielalbums ersetzen) (`provenance/main.inc.php:76`) | one sentence |
| B4 | 02 Große Mengen | same name rule for folders, skipped silently (`admin/site_update.php:243, 525`) | "Dasselbe gilt für Ordnernamen; solche Ordner werden ohne Meldung übergangen." Corrected 2026-10-10 in review: the folder itself is reported as `PWG-UPDATE-1` (`admin/site_update.php:301-306`), only the files in it are skipped silently (`:520`) |
| B5 | 02 Große Mengen | "zwei Wege" FTP vs `galleries/`: both need Synchronisieren, so one way | "Dateien per FTP in `galleries/` ablegen und unter Werkzeuge » Synchronisieren einlesen." |
| B6 | 03 Datum und Bilddatei | "erste Tag, den die Angabe umfasst" wrong for *vor 1965* (shows 1.1.1965) (`photoinfo/include/date.inc.php:135-140`) | "der erste Tag der eingetragenen Angabe" |
| B7 | 04 local-only hinweis | also any tag named *Ausstellung*, any group, case-insensitive (`photoinfo/include/functions.inc.php:503, 550-570`) | add "und jedes Schlagwort namens Ausstellung" |
| B8 | 04 Bilddatei | *Photo Info:* error only on properties screen and Batch Manager; picture page and tag admin fail silently (`photoinfo/include/events_tags.inc.php:323-329`; typetags ignores `tags_message`) | add "Auf der Fotoseite und in der Schlagwortverwaltung erscheint keine Meldung." See D6 |
| B9 | 04 Eintippen | cap 255 new tags per account and day, message *Heute wurden schon zu viele neue Schlagworte eingegeben* (decision 0045) | skip: no family member hits 255 |
| B10 | 05 reload | one success then a failure still reloads (`editor.js:292-296, 309-321`) | "Wurde nichts geändert, lädt die Seite nicht neu." Drop the failure clause |
| B11 | 06 Markierungsmodus | if tagging changed something, leaving it reloads and edit mode does not open | add "Wurde dabei etwas markiert, lädt die Seite neu; dann erneut **Drehen/Zuschneiden** klicken." |
| B12 | 04 Bilddatei "auf welchem Weg auch immer" | not API uploads, not IPTC import (decision 0050) | keep: no end-user path reaches them |

## C. Screen checks open

| # | Claim | Why code cannot settle it |
|---|---|---|
| C1 | 05 zoom: *100 %* = original size | a viewer without original access gets the largest derivative (`themes/modus/js/photo.zoom.js:46-50`); check with `persons_normal` |
| C2 | 05 stale-box tooltip shown on hover | box takes no pointer events outside tagging mode (`overlay.css:29-35`); probably only in tagging mode |
| C3 | 06 *OK* / *Abbrechen* | native `window.confirm`, label from the browser; German Chrome expected |

Done in the fix phase, before the page's text changes.

Results (2026-10-10):

- **C1**: *100 %* is the largest file the viewer is offered (`naturalScale()`). core offers the
  original only with `enabled_high = 'true'`, the column default; every account here has it
  (`chriss`, `guest`, the test accounts), so for all of them *100 %* is the original size. Without
  it, *100 %* is the largest derivative. Pinned both ways in `persons/tests/e2e/picture-zoom.spec.js`
  (`seed.php --originals=allowed|denied`).
- **C2**: the tooltip shows, but not over the box itself outside tagging mode: the box takes no
  pointer events, its name label does, and the label inherits the box's `title`. So outside tagging
  mode the explanation comes up over the name; in tagging mode anywhere over the box. Pinned in
  `persons/tests/e2e/overlay.spec.js` ("explains itself ..."), German text read from the de_DE file.
- **C3**: checked 2026-10-11 in a headed Chrome (de-DE): the photoedit question shows **Abbrechen** and **OK**, as 06 says (ledger in `docs/agents/TESTING.md`).

## D. Consistency between pages

| # | Issue | Where | Fix |
|---|---|---|---|
| D1 | who to tell: "die Verwaltung der Galerie benachrichtigen" vs "ein Fall für die Serververwaltung"; *Verwaltung* also means the admin area | 03 L252, 04 L298, 05 L206, 06 L116, L134 | one term, see E3 |
| D2 | local vs remote gallery: "diese Galerie", "Online-Galerie, die aus den Bilddateien aufgebaut wird", "veröffentlichte Galerie", "Arbeitskopie" | 04 L225-227, 06 L14-16 | one pair, see E4 |
| D3 | menu path: "Alben » Verwaltung" vs "Verwaltung » Alben » Verwaltung"; "Fotos » Hinzufügen" vs "Verwaltung » Fotos » Hinzufügen" | 01 L6/L32, 02 L6/L15/L94 | always the full path with "Verwaltung »" |
| D4 | "Fotoseite" = public picture page, but 01 L119 uses it for photo properties (Albumnotiz) | 01 L119 | "In den Eigenschaften eines Fotos heißt..." |
| D5 | nav labels differ from page titles: "Zurück: Album anlegen", "Zurück: Fotos hinzufügen" | 02 L160, 03 L279 | use the h2 titles |
| D6 | key name: *Enter* (05) vs *Eingabetaste* (04) | 04 L263 | *Enter*, as the screen says it |
| D7 | index "Wer darf was": first item headed *Verwaltung* (an area), others are roles | index L14 | heading *Administratoren* |

## E. Style and brevity (Q11 = A)

Steps stay full sentences; notes and hints may be fragments.

| # | Where | Issue | Fix |
|---|---|---|---|
| E1 | 04 L147-150 | "zwölf Farben ... Elf davon ... diese acht" has no antecedent | "Zwölf Farben (Gruppen). Acht sind Themen:" ... "Dazu vier besondere Gruppen." |
| E2 | 03 L46-47, L235-237, L270 | Beschreibung = Info said three times | once, in Datum und Bilddatei |
| E3 | 05 L178, L194 | "Weil jede markierte Person zugleich ein gewöhnliches Schlagwort ist" twice | once |
| E4 | 03 L40-43, L52-54 | "siehe unten, **Heading**" | anchor link |
| E5 | all pages | intros restate the index box (who may do what) | keep one sentence per page |
| E6 | 05 L32, 04 L32-36 | bold used for non-labels ("gestrichelter Rahmen", list lead-ins) | bold only for screen labels |
| E7 | all pages | general filler pass: "Das ist der Weg für...", "Ohne diesen Schritt bleibt die Änderung nicht erhalten", "Der Kurzhinweis lautet" repeated | cut per page during the fix |

## F. Screenshots

All 25 shots match their text except A4 (shot 11 caption) and below. No English or French text
in any shot.

| # | Shot | Issue | Fix |
|---|---|---|---|
| F1 | 09 | "Hinzugefügt von chriss": owner's login in a published shot | seed sets `added_by` to a test account; re-shoot 09 |
| F2 | 01, 06 | real album names (Sefferweich ...); no photos | see owner decision O4 |
| F3 | 11, 09 etc. | demo image carries a drawn-in title banner that looks like a page heading (A4) | keep; fix text only |

## G. Untranslated strings

| # | String | Source | Note |
|---|---|---|---|
| G1 | *Confirm merge*, *Cancel* | hard-coded in core `admin/themes/default/template/tags.tpl:110-111`, no `|@translate`; de_DE keys exist (`admin.lang.php:755`) | language file cannot fix it; needs a core template edit. See O1 |
| G2 | *Photo Info:* error prefix | `PHOTOINFO_ADMIN_ERROR_PREFIX`, `plugins/photoinfo/include/functions.inc.php:24` | translate via `l10n()`; see O2 |

## Owner decisions (answered 2026-10-10)

| # | Question | Decision |
|---|---|---|
| O1 | G1: *Confirm merge*, *Cancel* in core `tags.tpl` | `|@translate` on both literals; fork-local core change recorded in `CLAUDE.md`; 04 hinweis dropped |
| O2 | G2: *Photo Info:* prefix | *Fotoinfo:* through `l10n()`, test-first |
| O3 | D1 who to tell | "den Webmaster benachrichtigen" |
| O4 | D2 local/remote | *Arbeitskopie* and *Online-Galerie*, defined once in the index |
| O5 | F2 real album names in 01, 06 | accepted |
| O6 | B8 silent tag-write failure | documented now; product fix to `docs/backlog.md` |
| O7 | zoom section in the persons page | **moved to a page of its own, *Die Fotoseite*** (`07-fotoseite.html`, appended so no file or shot is renamed); 05 links to it; index lists it |
| O8 | date editor: *Abbrechen* looks like the main button | **fixed now in photoinfo**: *Speichern* is the primary button in both editors (`public_date.tpl`, `public_info.tpl`, `info.css`); E2E assertion first; re-shoot 24, 25 |
| O9 | findings | all approved; B9, B12 skipped as proposed |

## Fix order

1. O8 photoinfo button styling: failing E2E assertion, fix, re-run photoinfo E2E.
2. O2 *Fotoinfo:* prefix: failing test, fix, re-run photoinfo unit + integration.
3. O1 core `tags.tpl`: failing assertion in `core-admin-screens.spec.js`, fix, `CLAUDE.md` core-change bullet.
4. Screen checks C1-C3.
5. One commit per page: 01, 02, 03, 04, 05, 06, new 07, index; `check.php` before each. For each wrong
   claim, check the control has a test; add one at the lowest layer if not (Q7).
6. Re-shoot 09 (F1, seed `added_by`), 11 if the caption needs it, 24, 25; seed, shoot, restore.
   Revert churn. `shoot.js` and `check.php` learn about 07 only if it carries a shot.
7. `docs/backlog.md`: B8 product fix. `docs/agents/TESTING.md`: dated "third pass" section.
