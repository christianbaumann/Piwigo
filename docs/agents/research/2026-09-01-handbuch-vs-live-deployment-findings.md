---
date: 2026-09-01T14:05:58+00:00
git_commit: 5b24a25ff02b1cc647626d32c833160f95ac4f63
branch: master
topic: "Eight handbook findings from a live walkthrough at bilder.foerderverein-sefferweich.de, classified against the implementation"
tags: [research, handbuch, deployment, typetags, provenance, persons, tags, batch-manager, documentation]
status: complete
---

# Research: the eight live-deployment findings, classified

## Research Question

A walkthrough of `handbuch/` against the live sandbox at
`http://bilder.foerderverein-sefferweich.de/` produced eight findings. For each: is it a
functional defect in the application, an error in the handbook, or a test-data difference
between the remote and the local install? What do the plans say about how each area was
built?

## Summary

**No functional defect was found.** Every one of the eight is either a handbook error, a
test-data consequence of the deployment design, or — in two cases — correct application
behaviour the handbook fails to mention.

| # | Area | Classification |
|---|---|---|
| 1 | Eight colored tags missing | **Test data**, by design (decision 0023). Handbook is install-specific and wrong for the remote |
| 2 | "Alles" in the Batch Manager | **Handbook error** — the label depends on set size |
| 3 | Unused-tags notice | **Handbook gap** (24 h rule undocumented) + **tester misread**; "Überprüfung" does exist |
| 4 | Album provenance needs "Auf N Fotos anwenden" | **Handbook gap** — a required step is missing, and the album screen is undocumented |
| 5 | Title filled from filename on upload | **Handbook error** — the fallback is real but almost unreachable |
| 6 | Person names visible to guests as tags | **Handbook overclaim** — and a consequence never recorded in any decision |
| 7 | Tag layout on the picture page | **Handbook error, twice** — both the "als Text darunter" claim and the "+" block position |
| 8 | Four small items | 3 handbook errors, 1 non-issue |

Seven of the eight are documentation defects. The plans show why: the handbook was written
in Phase 6 of `2026-08-31-german-end-user-documentation.md` against the **local** install,
photographed from a generated demo album whose photo set fits one page, and its verification
criteria "each of the five workflows can be completed by following only the handbook" and
"every screenshot matches the text next to it" are recorded as **still open** — no oracle but
a first-time reader (plan lines 996-1002). This walkthrough is exactly that reader, and it
found what those open items predicted.

---

## Detailed Findings

### 1. The eight colored tags — test data, by deployment design

The handbook asserts install-specific data: `handbuch/04-schlagworte.html:138-151` — "In
dieser Galerie sind acht Schlagworte farbig" followed by the list. The plan asked for this
deliberately: "Notes the eight existing German colored tags" (plan line 957).

**The claim is verbatim-correct locally.** Measured 2026-09-01 against the dev database: 8 tag
rows, all 8 carrying a non-null `id_typetags`, names matching the handbook list exactly.

| tag id | name | typetag id | color |
|---|---|---|---|
| 1 | Personen | 1 | `#FFFFB6` |
| 3 | Arbeiten | 2 | `#FFCA4F` |
| 4 | Gewerbe | 3 | `#BE6CB7` |
| 5 | Feste, Bräuche, Jahreskreis | 4 | `#D8D900` |
| 6 | Vereine, Gruppierungen | 5 | `#007DAD` |
| 7 | Friedhof, Kirche, Kapelle | 6 | `#77A600` |
| 8 | Stationen durch das Leben | 7 | `#FF759A` |
| 9 | Häuser, Ortsansichten | 8 | `#938953` |

So the handbook did not invent or drift from anything — it recorded this install's state
accurately. The divergence is entirely the remote's, and it is designed-in.

**Why the remote has none.** Nothing carries tags to the remote:

- No database is transferred in either direction
  ([decision 0023](../decisions/0023-no-database-transfer-to-the-remote.md)). The deploy
  uploads files and posts to `install.php`.
- Tag colors are pure database state: `piwigo_typetags` plus a `piwigo_tags.id_typetags`
  column added by `plugins/typetags/maintain.class.php:29-32`. Neither is in any file.
- Tags are not in the image files either, and cannot be read back from them. Four independent
  reasons, any one sufficient:
  1. No DB transfer. Activating the plugins runs `maintain.class.php install()` on the remote,
     so `piwigo_typetags` and `piwigo_tags.id_typetags` are created **empty** — schema, not data.
  2. `$conf['use_iptc'] = false` (`include/config_default.inc.php:363`), and the generated
     remote config sets only `assume_https` plus the two exiftool paths
     (`tools/deploy/pwgdeploy/bootstrap.py:164-171`), so the remote runs the default.
     `get_sync_metadata()` calls `get_sync_iptc_data()` only inside `if ($conf['use_iptc'])`
     (`admin/include/functions_metadata.php:243-247`).
  3. `$conf['use_exif'] = true`, but `use_exif_mapping` is `array('date_creation' =>
     'DateTimeOriginal')` only (`config_default.inc.php:404-406`), so the keywords branch at
     `functions_metadata.php:102-105` is unreachable and the tag-creation loop at
     `admin/site_update.php:876-892` is never fed — even though `sync_meta=1` is posted on
     every deploy (`bootstrap.py:47-58`).
  4. Even with `use_iptc` on: IPTC is read from the JPEG **APP13** segment via `iptcparse()`
     (`include/functions_metadata.inc.php:21-36`), and all 105 tracked gallery images are
     **PNG**, which has no APP13.

  And colors could never travel that way in any case — IPTC keywords map to tag *names* only;
  `id_typetags` and `piwigo_typetags.color` have no metadata mapping at all.
- `docs/backlog.md:31` still lists "save labels/ tags in the image meta data" as open work,
  which is the same fact from the other side.

Person regions *can* survive the trip, because they live in the image file
([decision 0020](../decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md))
— but note the deploy tool does **not** call `pwg.persons.rescan`; `grep -rn rescan
tools/deploy/**/*.py` returns nothing, and it is a manual post-deploy step. Provenance has no
path at all — it is database-only unless write-back ran before upload. Tags are in the
provenance category, not the persons one.

Note also that `git ls-files upload` is **0** — `upload/` is git-ignored, so photos added
through the web UI never reach the remote either; only the 106 tracked paths under
`galleries/` do.

**Consequence.** Chapter 4's list is wrong *for the remote*, and everything downstream of it —
the whole "Farbige Schlagworte auf der Fotoseite setzen" section — describes a UI that
correctly renders nothing there: `typetags_picture_tags()` builds `$TYPETAGS_UNASSIGNED` from
an `INNER JOIN` on the typetags table (`plugins/typetags/include/events_public.inc.php:151-157`),
so with no colored tags the block is empty and the `{if}` at `:181` suppresses it. The
application is behaving correctly; the handbook describes a different install's data as if it
were a property of the software.

### 2. Batch Manager "Alles" — handbook error

`admin/themes/default/template/batch_manager_global.tpl:325-330`:

```smarty
{if $nb_thumbs_set > $nb_thumbs_page}
    <a href="#" id="selectAll">{'The whole page'|@translate}</a>
    <a href="#" id="selectSet">{'The whole set'|@translate}</a>
{else}
    <a href="#" id="selectAll">{'All'|@translate}</a>
{/if}
    <a href="#" id="selectNone">{'None'|@translate}</a>
    <a href="#" id="selectInvert">{'Invert'|@translate}</a>
```

German: `Alles` (`language/de_DE/common.lang.php:118`), `Die ganze Seite` / `Das ganze Set`
(`language/de_DE/admin.lang.php:669-670`), `Nichts` / `Invertieren` (`:601-602`).

`$nb_thumbs_page` counts rows actually fetched for the page
(`admin/batch_manager_global.php:520, 587`), `$nb_thumbs_set` is
`count($page['cat_elements_id'])` (`:610`). The page size defaults to
`$conf['batch_manager_images_per_page_global'] = 20`
(`include/config_default.inc.php:1019-1021`).

The tester is right. `handbuch/02-fotos.html:112-116` names only **Alles**, which appears
only when the set fits one page. The handbook's screenshot (`07-stapelverarbeitung.png`) was
taken against the generated demo album — a handful of photos, one page — so the screenshot
and the text agree with each other and with nothing else. Note also that choosing
`display=all` collapses the multi-page case back to the single `Alles` link
(`admin/batch_manager_global.php:500-506`), so both labels are reachable on the same set.

### 3. Unused tags — a handbook gap plus a misreading

Two separate claims in the finding; they resolve differently.

**"Überprüfung gibt es nicht mehr" — not correct.** Both wordings are live, at different
points of the same flow:

- The banner: `admin/tags.php:73-80` composes `l10n('You have %d orphan tags %s')` with a
  link labelled `l10n('Review')`. German: `Sie haben %d nicht benutzte Schlagworte: %s`
  (`language/de_DE/admin.lang.php:1020`) and `Überprüfung` (`:1045`).
- The link opens a dialog rather than navigating — `admin/themes/default/js/tags.js:8-40` —
  whose content is `'You have %s1 orphan : %s2'` → `Sie haben %s1 verwaiste : %s2` (`:1017`)
  and whose buttons are `Delete them` / `Keep them` → `Lösche diese` / `Behalte diese`
  (`:1093`, `:1065`).

The strings the tester found by grepping are the *dialog's*; the handbook describes the
*banner's*. `handbuch/04-schlagworte.html:104-110` describes the whole sequence — banner,
link, then "Lösche diese"/"Behalte diese" — and is **accurate**.

**Why no message appeared — real, and undocumented.**
`admin/include/functions.php:430-442`:

```php
  FROM '.TAGS_TABLE.'
    LEFT JOIN '.IMAGE_TAG_TABLE.' ON id = tag_id
  WHERE tag_id IS NULL
    AND lastmodified < SUBDATE(NOW(), INTERVAL 1 DAY)
```

A tag must be unused **and** untouched for 24 hours. `piwigo_tags.lastmodified` is
`ON UPDATE CURRENT_TIMESTAMP` (`install/piwigo_structure-mysql.sql:364`), so creating or
renaming a tag resets the clock. Creating an unused tag and reloading can therefore never
show the notice — this is correct behaviour, and the handbook's "Trägt kein Foto ein
Schlagwort mehr, meldet die Seite oben …" implies it is immediate. That is the gap.
`delete_orphan_tags()` uses the same query, so notice and deletion cannot disagree.

**Reproduced 2026-09-01** on the dev database, which is the tester's exact scenario. A
throwaway tag `zz_orphan_probe` was inserted, then both queries run against it:

- unused, by the bare `LEFT JOIN … WHERE tag_id IS NULL`: **1 row** — the probe tag
- reported by `get_orphan_tags()`, i.e. the same query plus the 24 h clause: **0 rows**

The probe tag was deleted afterwards; the table is back to its 8 rows. So a freshly created
unused tag is genuinely invisible to the notice, exactly as observed on the remote.

**And the local install does show the banner**, which is why the handbook documents it: 7 of
the 8 tags are unused and all are ~3042 hours old (measured 2026-09-01), so
`get_orphan_tags()` returns 7 rows. The plan even records the screenshot consequence — "The
tags admin overlaps its unused-tags warning with the search box at 1280px, so shot 12 alone
is taken at a 1600px viewport" (plan line 806). The handbook author saw a populated banner;
the remote tester could not, for two independent reasons at once.

On the remote the count was zero because there are no tags at all (finding 1).

### 4. Album provenance requires "Auf N Fotos anwenden" — handbook gap, correct behaviour

The tester is right, and this is the most substantive documentation gap of the eight.

**The album screen is not documented at all.** `Physisches Album` occurs only in
`handbuch/03-fototexte.html`; no album page mentions the provenance section. What
`03-fototexte.html:107-110` says is that the four fields "gelten für das ganze Album und
werden dort gepflegt, in den Eigenschaften des Albums" — true about authority, silent about
the mechanism.

**The mechanism is a three-button, two-step design.**
`plugins/provenance/template/album_provenance.tpl:21-35` renders `Herkunft` (opens the
modal), `Auf N Fotos anwenden`, and `In N Dateien schreiben`.

- `Einstellungen sichern` in the modal → `pwg.provenance.setAlbumInfo` →
  a single `UPDATE piwigo_categories` (`include/ws_functions.inc.php:153-157`). It touches
  `piwigo_images` nowhere.
- `Auf N Fotos anwenden` → `pwg.provenance.applyToPhotos` → `mass_updates()` onto
  `piwigo_images` (`ws_functions.inc.php:268-280`), via `provenance_copy_down_map()`
  (`include/functions.inc.php:177-185`), which maps the album's `provenance_note` to the
  photos' `provenance_album_note` and leaves each photo's own `provenance_note` alone.
- `In N Dateien schreiben` reads the **photos'** columns, not the album's
  (`ws_functions.inc.php:407-411`), so it writes what apply put there.

**The public row reads photo columns only, with no album fallback.**
`plugins/provenance/include/events_public.inc.php:19-50` reads `$picture['current']` — the
image row core already loaded — over `provenance_field_order()`
(`functions.inc.php:225-228`), all five of which are `piwigo_images` columns. The admin photo
screen reads the same row (`events_admin.inc.php:143-147`). So the two agree: before apply,
the admin photo screen shows four empty labels and the public page shows no Herkunft row.
There is no inconsistency in the application.

**One automatic exception the handbook could mention.** A photo that *joins* the album later
inherits without the button, through the two fork-local core triggers
(`include/events_inherit.inc.php`, registered `main.inc.php:50-53`), default mode `keep`.
Photos already in the album when the values were entered need the button.

**What the plans say.** The three-stage split is in the plan from the start —
`2026-08-29-provenance-metadata-writeback.md:72-77`: "A **second button** applies those values
to every photo in the album. **A third** writes them into the photos' image files."
Write-back being separate is a recorded, cost-driven decision (research conflict C2: unbounded
work in one 60 s request, no queue, no cron). The copy-down being a *manual button* rather
than automatic on album save is **not** separately argued anywhere — the research had answered
"propagate on album save" (Q4b), and the plan simply specifies a button from the Desired End
State onward without recording the deviation.

### 5. Title filled from the filename — handbook error

`admin/include/functions_upload.inc.php:366-369` sets `'name' => get_name_from_file($file)`
on the insert. `include/functions.inc.php:1302-1305` strips the extension and replaces
underscores with spaces: `zz_test_a.jpg` → `zz test a`. `admin/site_update.php:538` does the
same for the filesystem sync. Nothing overwrites it afterwards under defaults — `sync_metadata()`
only updates `name` when `$conf['use_iptc']` is on, which it is not.

The fallback the handbook describes is real — `render_element_name()`
(`include/functions_html.inc.php:530-537`) returns `get_name_from_file($info['file'])` when
`name` is empty — but no ingest path leaves `name` empty, so it is reachable only by a user
clearing the field by hand. Core itself treats the two as indistinguishable: the Batch Manager
tests `render_element_name($row) != get_name_from_file($row['file'])` to decide whether a real
title exists (`admin/batch_manager_global.php:590-591`).

So `handbuch/03-fototexte.html:29-31` ("Bleibt sie leer, zeigt Piwigo den Dateinamen an") is
technically true but describes a state a user will not encounter after an upload.

### 6. Person names visible to guests — handbook overclaim, and an unrecorded consequence

`handbuch/05-personen.html:16-17` — "Nicht angemeldete Besucher sehen von alldem nichts."

**True of the overlay and the Personen row.** `plugins/persons/include/events_public.inc.php:28-49`
returns early on `is_a_guest()` before registering the prefilter, so neither
`public_overlay.tpl` nor `public_persons.tpl` is injected — the markup is not in the page
source at all. The WS layer repeats the gate
([decision 0019](../decisions/0019-person-region-permission-model.md)).

**Not true of the name.** Every person is mirrored as an ordinary core tag:
`persons_person_id_from_name()` calls `tag_id_from_tag_name()` and stores the id
(`plugins/persons/include/index.inc.php:296-305`); `persons_sync_image_tags()` maintains the
`piwigo_image_tag` rows (`:321-376`). Its docblock states the intent — a person is an ordinary
tag "as far as the rest of Piwigo is concerned", which is what makes browsing, counting and
permalinks work with no new code.

Nothing filters those tags by origin. `picture.php:897` calls `get_common_tags()`
unconditionally; `include/functions_tag.inc.php:269-296` has no permission or origin
condition. `tags.php:14-16` gates on `ACCESS_GUEST` and `get_available_tags()`
(`functions_tag.inc.php:40-73`) filters only by album/image visibility. The plugin registers
no handler that could intervene (`main.inc.php`: `init`, `ws_add_methods`, `delete_elements`,
`loc_end_picture`, `loc_begin_admin_page`).

So a guest cannot see *where* a face is, or that a photo carries regions — but can see *who
is in the photo*, in the Schlagworte row, and can browse all of that person's photos from
`tags.php`.

**Where this is recorded: almost nowhere.** Not in decision 0019 (which names both the tag
mirror and the "who is in this photo" disclosure, in the same document, without connecting
them), not in 0020, not in `TESTING.md`, not in `docs/backlog.md`, not in the plan — whose
every guest criterion is about the overlay, the editor and the WS methods. The single written
acknowledgement is a comment in
`plugins/persons/tests/Integration/PicturePageSourceTest.php:218-222`: the names are
"deliberately not asserted absent … Hiding that would mean changing core's row, not this
plugin's."

That makes this finding worth more than a handbook fix: it is a privacy consequence that is
implemented deliberately, tested deliberately, and undocumented outside a test comment.

### 7. Tag layout on the picture page — handbook error, twice

Both halves of the finding are correct, and both are verifiable from the templates.

**(a) Colored and colorless tags share one comma-separated line.**
`themes/default/template/picture.tpl:210-217` renders all assigned tags in a single `<dd>`:

```smarty
{foreach from=$related_tags item=tag name=tag_loop}{if !$smarty.foreach.tag_loop.first}, {/if}<a href="{$tag.URL}">{$tag.name}</a>{/foreach}
```

typetags does not restructure this. It hooks `render_tag_name`
(`plugins/typetags/main.inc.php:43-46`), so by the time the loop runs a colored tag's *name*
is already an inline badge `<span>` (`include/events_public.inc.php:73-85`) and a colorless
one is its plain name (`:70-72`). The prefilter only adds `data-tag-id` to the anchor in place
(`:176-177`). The AJAX assign path appends `", " + <a>` to the same `<dd>` (`:236-251`).

`themes/modus/` has no `picture.tpl` — modus declares `'parent' => 'default'` — so this is the
template in use.

Nothing in core or in the three plugins produces a badges-block-plus-text-below layout. The
handbook says it twice: the caption at `04-schlagworte.html:185` ("die übrigen stehen als Text
darunter") and the body at `:206-208` ("sie stehen als Text unter den Plaketten"). Both wrong.

**(b) The "+" block sits at the foot of the right column, below Herkunft.** Three anchors, and
the ordering falls out of them:

| Plugin | Anchor | Lands |
|---|---|---|
| provenance | `"{/strip}\n</dl>"` (`include/functions.inc.php:605`) | last rows **inside** `dl#standard` |
| persons | `"{/strip}\n</dl>"` (`include/functions.inc.php:102`) | last rows **inside** `dl#standard` |
| typetags | `'{if isset($metadata)}'` (`include/events_public.inc.php:5`) | **after** `</dl>` |

In `picture.tpl` those are lines 300-301 (`{/strip}` / `</dl>`) and line 303
(`{if isset($metadata)}`). So the resulting order is: Tags row → … → Herkunft and Personen
rows → `#typetags-unassigned` → `dl#Metadata`. The "+" badges are unconditionally below
Herkunft, exactly as observed.

This is not a regression. `2026-04-27-picture-page-tag-assignment.md:15, 47, 58` specifies "a
UI **below the picture page info box**" and notes "the info box closes at `picture.tpl:301`,
followed by the metadata section at line 303". The handbook's claim that the badges stand
"darunter" *the Schlagworte section* is the part that was never true. (The plan's own prose at
lines 335-348 calls the anchor `"{/strip}\n</dl>"` while the shipped code uses
`{if isset($metadata)}`; the shipped anchor is the correct one, since the former is not unique
in that file and is now what provenance and persons use.)

Relative order of Herkunft vs Personen is **not pinned by any code** — both prepend at the
identical anchor with the same handler and prefilter weight, so it follows plugin load order,
and `get_db_plugins()` has no `ORDER BY` (`include/functions_plugins.inc.php:314-334`). The
comment at `plugins/persons/include/events_public.inc.php:51-56` says so explicitly.

Measured 2026-09-01, `SELECT id, state FROM piwigo_plugins WHERE state='active'` returns
`typetags, provenance, persons` in that order, so today provenance registers before persons
and its row renders above. That is an observation of one unordered query on one install, not
a guarantee — it is exactly the kind of fact that holds until a plugin is deactivated and
reactivated.

### 8. The four small items

**(a) Album edit URL — non-issue, handbook is imprecise but harmless.** The pencil links to
`admin.php?page=album-<id>` (`admin/themes/default/js/albums.js:361`). The `-properties`
suffix is an optional capture group in the alias (`admin.php:147-155`), and `admin/album.php:42`
defaults `$page['tab']` to `properties`, so both URLs render the identical screen.
`handbuch/01-alben.html:79` naming the `-properties` form is accurate as a URL; it just is not
the one the button produces.

**(b) "Die vier Textfelder" — handbook error.** `admin/themes/default/template/picture_modify.tpl`
order: Titel (145-149), Autor (151-155), Aufnahmedatum (157-166), **Verknüpfte Alben
(168-187), Album-Vorschaubild (189-195), Schlagworte (197-203)**, Beschreibung (205-209),
Datenschutzstufe (211-218). The three intervening fields are exactly where the tester says.
The handbook does list them separately under "Die weiteren Felder derselben Seite", so nothing
is missing — but grouping the four as one heading implies an adjacency the screen does not
have.

**(c) Merge hint placement — handbook error.** `admin/themes/default/template/tags.tpl:103-110`:
the hint is a `<p class="ItalicTextInfo">` at line 105, the `<select id="MergeOptionsChoices">`
is at 107. Above, not beside. `handbuch/04-schlagworte.html:86-87` says "steht daneben".

---

## What was measured, and how

Most of this document is read from source. Four facts were measured against the running DDEV
install on 2026-09-01, and are marked as such above:

| Fact | Command |
|---|---|
| 8 tags, all colored, names as the handbook lists them | `ddev mysql -e "SELECT t.id,t.name,t.id_typetags,tt.color FROM piwigo_tags t LEFT JOIN piwigo_typetags tt ON t.id_typetags=tt.id"` |
| 7 of 8 are orphans; ages ~3042 h | the `get_orphan_tags()` query, run verbatim |
| A fresh unused tag is not reported | insert `zz_orphan_probe`, run both queries, delete it |
| Active plugin row order `typetags, provenance, persons` | `ddev mysql -e "SELECT id,state FROM piwigo_plugins WHERE state='active'"` |

The probe tag was removed; the tag table is back to 8 rows. Nothing else was written.

Two things were **not** measured and are stated as code readings only: the rendered DOM order
of the picture page's info column (a fetch of `picture.php?/1/category/1` as `persons_normal`
returned an 867-byte redirect rather than the page, and chasing a valid URL was out of scope
for this pass), and anything about the remote's database beyond `pwg.getVersion`, which
answered `17.0.0beta1` — the same string as local, so the deploy tool's version guard
(exit `10`) would pass.

## Code References

- `admin/themes/default/template/batch_manager_global.tpl:325-330` — the `nb_thumbs_set > nb_thumbs_page` branch
- `admin/batch_manager_global.php:520, 587, 610` — where the two counters come from
- `admin/include/functions.php:430-442` — `get_orphan_tags()` and the 24-hour rule
- `admin/tags.php:73-80` — the orphan banner, `Review` link
- `admin/themes/default/js/tags.js:8-40` — the confirm dialog with `Delete them` / `Keep them`
- `admin/themes/default/template/tags.tpl:103-110` — merge hint above the select
- `admin/include/functions_upload.inc.php:366-369` — `name` set from the filename on upload
- `include/functions.inc.php:1302-1305` — `get_name_from_file()`
- `include/functions_html.inc.php:530-537` — `render_element_name()` fallback
- `admin.php:145-155`, `admin/album.php:42-47` — the `album-<id>[-tab]` alias and default tab
- `admin/themes/default/template/picture_modify.tpl:145-218` — photo properties field order
- `themes/default/template/picture.tpl:210-217, 300-303` — tags row and the two injection anchors
- `plugins/typetags/include/events_public.inc.php:5, 70-85, 151-157, 176-190` — render hook, unassigned query, prefilter
- `plugins/typetags/maintain.class.php:29-32` — `id_typetags` column, colors are DB-only
- `plugins/provenance/include/ws_functions.inc.php:153-157, 268-280, 407-411` — save vs apply vs write-back
- `plugins/provenance/include/events_public.inc.php:19-50` — public Herkunft row reads photo columns
- `plugins/provenance/include/functions.inc.php:177-185` — `provenance_copy_down_map()`
- `plugins/persons/include/index.inc.php:296-305, 321-376` — the tag mirror
- `plugins/persons/include/events_public.inc.php:28-49` — the guest gate
- `plugins/persons/tests/Integration/PicturePageSourceTest.php:218-222` — the only record of the guest/tag consequence
- `include/config_default.inc.php:363, 404-406` — `use_iptc` off, exif maps only `date_creation`
- `include/functions_tag.inc.php:40-73, 269-296` — no origin filter on either tag surface
- `admin/include/functions_metadata.php:102-105, 243-247` — the unreachable keywords branch
- `admin/site_update.php:876-892, 922` — the tag-creation loop the sync never feeds
- `tools/deploy/pwgdeploy/bootstrap.py:351-370` — the entire remote-mutating surface
- `tools/deploy/pwgdeploy/fileset.py:53, 73-77` — `local/config/` excluded, config generated

## Architecture Documentation

Three patterns explain most of what the walkthrough hit.

**Anchor-based prefilter injection determines visual order.** All three plugins hook
`loc_end_picture` at neutral priority and call `set_prefilter()` at default weight, so nothing
about the *registration* orders them. What orders them is which string literal in `picture.tpl`
each one prepends to. Two plugins share an anchor inside `dl#standard` and are therefore
mutually unordered; the third uses a later anchor and is therefore always last. Any documentation
claim about where something appears on that page is a claim about anchors.

**The remote is a file-only replica.** Decision 0023 (no DB transfer) plus decision 0020
(regions live in the file) plus `use_iptc = false` together fix what survives a deploy: albums
and photos are re-scanned from disk by `site_update`, person regions can be re-read from image
metadata by a manual `pwg.persons.rescan`, and everything else that lives only in the database
— tags, tag colors, provenance columns — does not exist on the remote until it is entered
there. The whole remote-mutating surface is five steps in
`tools/deploy/pwgdeploy/bootstrap.py:351-370`; there is no `mysqldump`, no SQL and no `.sql`
path anywhere in the tool. The handbook was written against an install where all of it exists.

One piece of history explains the starting state: the web space was wiped by hand at the end
of the deploy session and the manifest deleted to match
(`2026-08-31-ftp-deployment-and-remote-install.md:1124-1152`), so the remote was rebuilt from
files alone with no tag ever entered on it.

**Provenance is album-authoritative but photo-resident.** The four album fields are the album's
to define and every reader reads the photo's copy. That is why saving is not enough, and why the
admin photo screen and the public page never disagree with each other — they read the same
columns. The copy is refreshed by an explicit button, or automatically for photos that join the
album later.

## Open Questions

- Whether the copy-down should stay a manual button. The research answered "propagate on album
  save" (Q4b) and the plan silently shipped a button instead. Only the *file* write-back split
  is argued (conflict C2, unbounded work in one request); the DB copy-down does not carry that
  cost and its manual-ness is unrecorded.
- Whether the guest-visible person name is intended. It is implemented deliberately and
  asserted deliberately, but the reasoning exists only as a test comment, and decision 0019
  names both halves without joining them. Worth a decision file either way, since the handbook
  currently states the opposite.
- Whether the handbook should assert install-specific data at all. The eight-tag list is the
  only place it does, and it is the finding that made the remote walkthrough look broken.
- `pwg.persons.rescan` is documented as the path by which regions return to the remote, but no
  code in `tools/deploy` calls it — the deploy's own `run()` stops at `site_update`. Whether it
  should be a sixth step, or stay a documented manual action, is undecided; today it is neither
  automated nor listed as a manual step in `tools/deploy/README.md`'s command sequence.
- The relative order of the Herkunft and Personen rows is unpinned. Today it resolves to
  provenance-above-persons (measured 2026-09-01), but any handbook or test claim about it
  would rest on `get_db_plugins()` returning rows in a stable order, which it does not
  promise. Neither the handbook nor any spec asserts it today, so nothing is broken.
- `typetags.type.add` is registered with **no** `admin_only` option
  (`plugins/typetags/main.inc.php:82-90`), unlike its sibling `typetags.tags.setType`
  (`:71-80`). Noticed while reading the method list; not investigated, and out of scope here.
