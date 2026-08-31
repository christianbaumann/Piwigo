---
date: 2026-08-31T09:36:35+00:00
git_commit: a37a19cb1f2935fffcca8d9b11e4ab5e5703f14e
branch: master
topic: "German end-user documentation with screenshots for albums, photos, text, tags and persons"
tags: [research, documentation, german, screenshots, albums, tags, persons, provenance, test-coverage]
status: complete
---

# Research: German end-user documentation for the five core workflows

## Research Question

Produce German end-user documentation, with screenshots, as simple HTML, covering: creating
albums and their description text; adding photos to albums; adding text to photos; tagging
images with tags; tagging persons. Ensure proper test images exist, generating them if
necessary. Where a use case is not covered by tests, add coverage.

Research only. This document maps what exists; it proposes nothing.

## Summary

All five workflows exist and are reachable from the admin panel and the public picture page.
Three findings dominate what a documentation task has to work with:

1. **No publishable photo exists anywhere in the repo or install.** All 105 gallery images
   are recovered personal family scans of identifiable private people, tracked in git
   deliberately because they are the only copies left. Every plugin fixture is a byte copy of
   one of them. Screenshots of the real gallery would publish private faces. ImageMagick 7.1.1
   and exiftool 13.25 are available in the web container and can generate synthetic photos and
   write XMP into PNG, verified 2026-08-31.
2. **The three fork plugins are heavily tested; Piwigo core is not tested at all.** The five
   use cases split cleanly: person tagging (E) is covered at all three layers, the provenance
   free-text field (C2) likewise, colored-tag assignment (D, plugin half) likewise. Core album
   creation (A), core photo upload (B), core photo title/description/author (C1) and core tag
   administration (D, core half) have no test at any layer.
3. **Several strings the documentation will screenshot are untranslated** and render in English
   or French on this German install. Notably `Album %s now contains %d photos` (the upload
   confirmation), `Album updated`, `Rename Tag`, `Add tag` / `Remove tag`, `Batch Manager
   Filter`, and two hardcoded French literals in typetags (`Couleur`, `Créer`). Verified by
   grep against `language/de_DE/*.lang.php`, 2026-08-31.

The install runs German, DDEV is up, and the site answers on `https://piwigo.ddev.site` from the
host and `http://localhost` only from inside the web container.

## Detailed Findings

### A. Creating an album and its description text

Two separate screens. **Creation carries no description field at all**; the description is set
afterwards on the properties screen.

| Step | URL | File |
|---|---|---|
| Album tree | `admin.php?page=albums` | `admin/albums.php`, `albums.tpl` |
| Add-album modal | same page, `#AddAlbum` | `albums.tpl:225-273`, `albums.js:471-498` |
| Properties (description) | `admin.php?page=album-<id>-properties` | `admin/cat_modify.php`, `cat_modify.tpl` |

- Creation posts `name`, `parent`, `position` to `pwg.categories.add` (`albums.js:219-241`), which
  reaches `create_virtual_category()` (`admin/include/functions.php:1435`). The name input has
  **no `name` and no `id` attribute**; it is selected as `.AddAlbumLabelUsername input`
  (`albums.tpl:167-169`).
- The description is a plain `<textarea name="comment" id="cat-comment">` at
  `cat_modify.tpl:141-142`. No WYSIWYG is attached; the only enhancement is an expand modal whose
  copy is kept in sync by `cat_modify.js:441-463`. There is no `<form>` and no POST handler:
  saving is AJAX to `pwg.categories.setInfo` (`cat_modify.js:70-85`), which is `admin_only` and
  `post_only`.
- Storage: `piwigo_categories.name` and `piwigo_categories.comment`
  (`install/piwigo_structure-mysql.sql:44,46`). `$conf['allow_html_descriptions']` is `true`
  (`include/config_default.inc.php:225`), so with a valid `pwg_token` markup survives; without one
  `strip_tags()` applies (`pwg.categories.php:954`).
- Navigation: sidebar **Alben > Verwaltung**, header button **Album hinzufügen**, then the node's
  edit pencil to reach **Beschreibung** and **Einstellungen sichern**.
- `plugins/provenance` injects a second block into this screen (Herkunft: Physisches Album,
  Eigentümer, Gescannt am, Notiz), anchored on the core save button
  (`plugins/provenance/include/functions.inc.php:585`). Those are separate columns, not the core
  `comment`.

Untranslated on this screen: `Album updated`, `An error has occured while saving album settings`,
`Rename album`, `No photos in the current album, no thumbnail available`.

### B. Adding photos to albums

Five paths. Every link row in `piwigo_image_category` is written by exactly three writers, and
one of them bypasses both fork triggers.

| Path | URL | Writer | Fork trigger |
|---|---|---|---|
| Web uploader (plupload) | `admin.php?page=photos_add` | lounge, then `associate_images_to_categories()` | yes, deferred |
| FTP + filesystem sync | `admin.php?page=site_update&site=1` | `mass_inserts()` in `admin/site_update.php:677` | yes |
| Batch Manager associate/move | `admin.php?page=batch_manager` | `associate_images_to_categories()` / `move_images_to_categories()` | yes |
| Photo properties, linked albums | `admin.php?page=photo-<id>-properties` | `move_images_to_categories()` (not associate) | yes |
| `pwg.images.add` / `pwg.images.setInfo` | web service | `ws_add_image_category_relations()` (`pwg.images.php:171-175`) | **no** |

- The uploader is **plupload**, not uploadify (`photos_add_direct.tpl:4-5`, logic in
  `admin/themes/default/js/photos_add_direct.js`). It calls `pwg.images.upload` per chunk
  (`:106`) then `pwg.images.uploadCompleted` (`:437`).
- The **lounge is active on this install** (`lounge_activate_threshold = 1`, DB row
  `lounge_active = true`). Uploaded photos therefore land in `piwigo_lounge` first, and the album
  link plus the fork trigger only materialise when `empty_lounge()` runs, usually on
  `pwg.images.uploadCompleted` but possibly on a later page load (`lounge_max_duration` 300 s).
- Accepted types: `$conf['picture_ext'] = jpg,jpeg,png,gif,webp`
  (`include/config_default.inc.php:46`); anything wider needs
  `$conf['upload_form_all_types']`, default false. Chunk 500 kB, max file 1000 MB.
- Uploads land in `upload/YYYY/MM/DD/<timestamp>-<hash>.<ext>`; sync leaves files where they are
  under `galleries/`.
- Sync rejects filenames outside `/^[a-zA-Z0-9-_.]+$/` (`config_default.inc.php:952`), so umlauts
  and spaces fail with `PWG-UPDATE-1`. The **Simulation** checkbox is checked by default
  (`site_update.tpl:100`).
- The photo-properties "Linked albums" control calls **move**, not associate
  (`admin/picture_modify.php:119-126`): albums left out of the selection are unlinked. The
  storage album cannot be unlinked (`:354-359`).

Untranslated: `Album %s now contains %d photos` (the post-upload confirmation the documentation
would screenshot), `%d photos updated`, `Remove`, `Batch Manager Filter`, `No filter, add one`,
`format %s added`, `format %s removed`.

### C. Adding text to photos

Two distinct things, and they behave differently.

**C1, core fields** on `admin.php?page=photo-<id>-properties` (`admin/picture_modify.php`,
`picture_modify.tpl`):

| Label (German) | input | Column |
|---|---|---|
| Titel | `name` | `images.name` |
| Autor | `author` | `images.author` |
| Aufnahmedatum | `date_creation` | `images.date_creation` |
| Beschreibung | `comment` (textarea `#description`) | `images.comment` |

The filename is read-only (`picture_modify.tpl:124`). Save is a normal POST guarded by
`check_pwg_token()`, sanitised unless `allow_html_descriptions`, written by `single_update()`
(`picture_modify.php:81-104`). Batch Manager global mode offers bulk **Set author / Set title /
Set creation date** but **no bulk description**; unit mode offers all four per photo and saves via
`pwg.images.setInfo` (`batchManagerUnit.js:345-377`).

Metadata sync from file is available at `...&sync_metadata=1`, but on this install
`use_iptc = false` and `use_exif_mapping` maps only `date_creation`, so a sync touches nothing
else. Notably the IPTC slot provenance writes to (`2#120`) is never read back.

**C2, the provenance free-text field.** Column `images.provenance_note`, labelled **Notiz**,
edited in a block injected before `<div class="savebar-footer">` on the same photo screen, saved
by `pwg.provenance.setPhotoInfo`. Four further columns (Physisches Album, Eigentümer, Gescannt am,
Albumnotiz) are inherited from the album and shown read-only on the photo. Write-back composes a
caption from all five, joined by ` | `, into `EXIF:ImageDescription`, `IPTC:Caption-Abstract`
(capped at 2000 bytes), `XMP-dc:Description`, `XMP-photoshop:Headline`, `XMP-tiff:ImageDescription`
plus a custom `XMP-pwgprov` namespace. Labels are resolved through `l10n()`, so a German admin's
write-back puts German labels in the file.

Public rendering: title in `<h2>`, description as `p.imageComment`, author and dates in the info
list, provenance injected before the list closes, all in `themes/default/template/picture.tpl`.

### D. Tagging images with tags

Core tag CRUD and the fork's colored-tag layer are separate systems sharing two tables.

- **Core admin**: `admin.php?page=tags` (`admin/tags.php`, `tags.tpl`). The PHP file only renders
  and deletes orphans; create, rename, delete, duplicate and merge all go through
  `pwg.tags.*` from `admin/themes/default/js/tags.js`. All are `admin_only`.
- **Assignment**: photo properties uses a Selectize `tags[]` and `set_tags()` (full replace);
  Batch Manager global has add/remove actions; unit mode replaces per photo via
  `pwg.images.setInfo`, which is `admin_only`.
- **plugins/typetags** adds `piwigo_typetags(id,name,color)` and `piwigo_tags.id_typetags`. A tag
  is colored iff `id_typetags` is not null. It renders badges via the `render_tag_name` filter,
  contrast text chosen at luminance threshold 0.45.
- **The fork feature end users care about**: on the public picture page, a logged-in non-guest
  sees unassigned colored tags as clickable `+` badges and can add or remove them inline via
  `typetags.image.addTag` / `removeTag`. Gate is `!is_a_guest()` plus `pwg_token`, deliberately
  not `admin_only` (decision 0005). A recorded, unfixed consequence: no per-image visibility
  check, so a logged-in user can tag an image in an album they cannot browse.
- This install already has 8 German colored tags (Personen, Arbeiten, Gewerbe, Feste/Bräuche,
  Vereine, Friedhof/Kirche, Stationen durch das Leben, Häuser/Ortsansichten), which is authentic
  material for screenshots.

Untranslated or wrong-language: `Rename Tag`, `Add tag`, `Remove tag`, `Remove color`, and two
hardcoded French literals, `Couleur` (`plugins/typetags/include/events_admin.inc.php:61`) and
`Créer` (`plugins/typetags/template/tags.tpl:73`).

### E. Tagging persons

The most complete of the five, and the only one with a full German translation of its own
(`plugins/persons/language/de_DE/plugin.lang.php`, 34 keys, no gaps).

End-user flow on the public picture page (`picture.php?/<id>/category/<cat>`), for any logged-in
non-guest:

1. Hovering the photo reveals white boxes with name labels; hovering a label dims the rest.
2. **Personen markieren** enters tagging mode, which also strips the image's `usemap` so the
   theme's click-to-navigate does not eat the drag.
3. Dragging a rectangle opens a picker (**Wer ist das?**) pre-filled with the 10 most recently
   used persons, narrowing server-side as you type, with an **Anlegen "..."** escape hatch. The
   picker scores four placements and picks the one least covering the drawn box.
4. Enter commits via `pwg.persons.addRegion`; Esc cancels; the `x` control deletes.
5. Boxes older than the current crop render dashed at 50 percent with a German explanation.

Admin surfaces: a full-size tagging screen at `admin.php?page=plugin-persons&image_id=<id>`,
reached only from an injected icon on the photo properties screen; and a persons list at
`admin.php?page=plugin-persons` with search, photo and region counts, rename, delete, and a
chunked **Alle Dateien neu einlesen** rebuild. Merge is deliberately not implemented.

Data model: `piwigo_persons` and `piwigo_person_region`, both a **derived index**. The image file
is the source of truth (decision 0020): regions live in `XMP-mwg-rs:RegionInfo` plus
`XMP-iptcExt:PersonInImage`, written by exiftool under a per-image lock held across the whole
read-merge-write. Each person is mirrored as an ordinary Piwigo tag, which is what makes
browse-by-person work with no new routing (`index.php?/tags/<id>-<url_name>`).

The tagging button is disabled, with a German tooltip, when exiftool is unavailable, probed per
render.

### Test images: nothing publishable exists

| Source | Count | What it is |
|---|---|---|
| `galleries/` (4 albums) | 105 PNG, ~87 MB | Recovered personal family and village scans, faces large and frontal |
| `upload/persons-test/` | 5 leftover PNG, 12.7 MB each | Byte copies of one scan, orphaned by interrupted E2E runs |
| `_data/tmp-mwg/mwgtest.jpg` | 1 | Flat gray 400x300, an ad-hoc experiment leftover |
| plugin fixtures | 0 | No plugin ships an image; all three copy the first gallery PNG by id |

All three fixture builders run `SELECT path FROM piwigo_images WHERE path LIKE '%.png' ORDER BY id
LIMIT 1`, which today resolves to a 3540x2383 scan of an elderly man walking a dog. No test
generates an image; the two ImageMagick calls in the suites are independent readers, not
generators.

The persons E2E seed writes **synthetic coordinates**, not detected faces: two fixed boxes at
(0.30,0.40,0.10,0.20) and (0.70,0.35,0.16,0.12), which do not coincide with any actual face in the
photo.

Generation is feasible. Verified in the web container 2026-08-31: ImageMagick 7.1.1-43 produced a
1200x800 PNG with drawn face-like shapes and German annotation, and exiftool 13.25 wrote metadata
into it. GraphicsMagick is also present, which the rules files do not mention.

### Screenshot tooling

Two options exist, neither currently used for documentation.

- **Playwright**, one config per plugin, viewport inherited from `Desktop Chrome` (1280x720),
  `screenshot: 'only-on-failure'`. There is **no `page.screenshot()`, no `toHaveScreenshot`, no
  snapshot baseline anywhere** in any suite. Pixel-diffing was explicitly rejected as flaky for a
  photo gallery (`docs/agents/TESTING.md:428`). `baseURL` defaults to `http://localhost`, valid
  only inside the container, which is why every documented command is `ddev exec`.
- **rodney + showboat**, both pre-allowed in settings, with `uvx rodney screenshot -w 1280 -h 720`
  and `uvx showboat image`. rodney drives Chrome on the **host**, so it needs
  `https://piwigo.ddev.site`. It has no login helper; a run must POST `identification.php` itself
  or reuse the existing `.rodney/chrome-data` profile.

Every screenshot output path is git-ignored today: `.agent-tests/`, `.rodney/`,
`plugins/*/test-results/`, `plugins/*/playwright-report/`. There is no committed location for
documentation assets, and `docs/` currently holds no HTML at all, only three PNGs about the
GitHub fork workflow.

Piwigo ships its own German end-user help as plain HTML in `language/de_DE/help/`
(`cat_modify.html`, `help_add_photos.html`, `synchronize.html` and others), served through
`admin/popuphelp.php?page=<name>`. That is the house style and prior art for this deliverable:
short `h2`/`h3`, `ul` of bolded field names with one-line explanations, `fieldset` for grouped
options. Parts of `help_add_photos.html` are themselves half-translated, with two English
paragraphs left in place.

### Test coverage of the five use cases

Legend: full means the end-user behaviour is asserted; partial means the step is only a fixture or
only the plugin's own field is asserted.

| Use case | Unit | Integration | E2E |
|---|---|---|---|
| A. Album + description | none for core; provenance anchor guard only | partial, provenance block only; album rows made by raw SQL | partial, provenance modal only |
| B. Photos into albums | none | full for the association path, as `[ERR]` characterization, plus real upload via web services | partial, Batch Manager move only |
| C1. Core title/description/author | none | none | none |
| C2. Provenance note | full | full | full |
| D. Tags, colored-tag half | full | full | full |
| D. Tags, core CRUD half | none | one "page renders" assertion | none |
| E. Persons | full | full | full |

Core has no suite: no root `phpunit.xml`, no `composer.json`, no CI. The one core-adjacent
artefact, `tools/test_piwigo.php`, echoes OK/KO, never exits non-zero, and is referenced by no
runner.

The uncovered end-user steps most relevant to this documentation, since documenting a step means
walking it:

- Creating an album through the UI or `pwg.categories.add`; typing and saving an album
  description; the description rendering on the public album page; sub-album parenting.
- Uploading a photo through the browser at all (plupload chunking, progress, error states); the
  filesystem-sync screen driven as a user; associating an existing photo to an additional album
  from the Batch Manager.
- Photo title, description and author at every layer. `pwg.images.setInfo` is explicitly declared
  out of scope (`docs/agents/TESTING.md:198`).
- Creating, renaming, deleting or merging a tag; assigning a non-coloured tag through core's own
  UI; Batch Manager tag actions; tag autocompletion.

Already-recorded deliberate non-coverage that should not be mistaken for a gap: browse-by-tag
display modes, per-image visibility on `addTag` (decision 0005), touch and pen input on the region
editor, external-tool drift on region files (decision 0020), E2E for provenance phases 1 to 3
(decisions 0007, 0008) and persons phases 1 to 4 (decision 0017).

### Install state, measured 2026-08-31

10 albums, 110 images, 8 tags, 0 persons and 0 regions indexed. Five leftover `Persons E2E
<hex>` albums (ids 2141, 2635, 2639, 2641, 2644) and five orphan `persons-test-*` photo rows
(3015, 3617, 3621, 3623, 3626) survive from interrupted E2E runs; `--restore` did not clean up.
Any documentation screenshot of the album tree or the persons admin screen would show them.

## Code References

- `admin/albums.php`, `admin/themes/default/template/albums.tpl:225-273` - add-album modal
- `admin/themes/default/template/cat_modify.tpl:140-143` - album description textarea
- `include/ws_functions/pwg.categories.php:753-795`, `:891-983` - album create and setInfo
- `admin/include/functions.php:1435-1594` - `create_virtual_category()`
- `admin/include/functions.php:2024`, `:2094-2106` - association writer and fork trigger
- `admin/site_update.php:677-683` - sync inserts and the second fork trigger
- `include/ws_functions/pwg.images.php:171-175` - the writer that fires neither trigger
- `admin/include/functions_upload.inc.php:431-460` - lounge versus direct association
- `admin/themes/default/js/photos_add_direct.js:106`, `:437` - plupload endpoints
- `admin/picture_modify.php:79-164` - photo text save path
- `admin/picture_modify.php:119-126` - linked albums calls move, not associate
- `admin/tags.php`, `admin/themes/default/js/tags.js:181-752` - tag CRUD over web services
- `plugins/typetags/include/events_public.inc.php:131-374` - picture-page tag assignment
- `plugins/provenance/include/events_admin.inc.php:16-88`, `:118-190` - album and photo injection
- `plugins/persons/include/events_public.inc.php:28-82` - overlay injection and prefilter
- `plugins/persons/template/editor.js:316-350`, `:453-478` - commit and tagging mode
- `plugins/persons/admin/persons.php:33-58` - persons list with counts
- `plugins/persons/language/de_DE/plugin.lang.php` - the one complete German plugin translation
- `plugins/persons/tests/e2e/support/seed.php:52-55` - synthetic region coordinates
- `plugins/persons/tests/Support/FixtureBuilder.php:92-120` - fixture copies a real scan
- `docs/agents/TESTING.md:174-212` - deliberate non-coverage table
- `docs/agents/TESTING.md:455-465` - open hand-check ledger
- `language/de_DE/help/cat_modify.html`, `help_add_photos.html` - house style for German help

## Architecture Documentation

- Admin screens are `admin.php?page=X` including `admin/X.php`, with clean aliases
  `album-<id>-<tab>` and `photo-<id>-<tab>` rewritten in `admin.php:145-167`.
- Fork plugins never edit core templates. They anchor Smarty prefilters on named constant strings
  and are guarded by unit tests asserting each anchor still occurs exactly once in the live
  template and that no active theme shadows it.
- Save paths on the newer screens are AJAX to web services, not form POSTs. Album properties and
  the persons and provenance blocks all work this way; the photo properties screen is the older
  POST style.
- Permission shapes differ deliberately and matter for documentation: colored-tag assignment and
  person tagging are open to any logged-in non-guest, while every provenance write, all core tag
  CRUD and all photo text editing are `admin_only`.
- The admin theme is `clear`, which ships no templates of its own and inherits everything from
  `admin/themes/default/template/`. The public theme is `modus`, which does not override
  `picture.tpl`.

## Open Questions

Each carries a decision taken 2026-08-31. No answer was given to any of them, so the preferred
option stands as the default and is recorded here rather than re-litigated later.

1. **Where does the deliverable live?**
   **Decided: `docs/handbuch/` in the repo, with `assets/screenshots/`.** Versioned with the code
   and reviewable, no mixing into upstream `language/de_DE/help/`. Needs one `.gitignore`
   exception for the screenshot PNGs. Rejected: the in-product help directory (a merge from
   upstream touches those files); `.agent-tests/` (git-ignored, does not survive a clone).
2. **What do the screenshots show?**
   **Decided: a generated demo album, seeded by a committed script.** Synthetic photos with
   face-like shapes and German titles, created with ImageMagick in the web container and removed
   again by a `--restore` flag, following the shape of the existing plugin seeds. Publishes
   nothing private, is reproducible, and gives person tagging a box to draw. Rejected: blurring
   real scans (manual and unrecoverable if missed once); cropping photo content out (impossible
   for the overlay and the region editor, which are photo content).
3. **Untranslated strings: fix or document as they render?**
   **Decided: fix the German translations first, then screenshot.** The handbook shows what users
   see, and `local/language/` overrides core strings without touching upstream files. Covers the
   two hardcoded French typetags literals as well. Rejected: documenting the half-English UI as
   normal; screenshotting first and fixing later, which dates every screenshot.
4. **Which provenance fields count as "text to photos"?**
   **Decided: Notiz is the per-photo field; the other four are documented as album-inherited and
   read-only.** This matches the code and explains why four fields on the photo screen cannot be
   edited. Rejected: presenting all five as photo text (wrong, implies four editable fields);
   omitting provenance (drops the fork's main text feature).
5. **How deep does new test coverage go?**
   **Decided: cover what the handbook documents, at the lowest layer that can express it.** That
   is album creation plus description, the core photo text fields, and core tag CRUD, all real
   gaps today. Scope is bounded by the documented blast radius per `.claude/rules/testing.md`
   rather than by "all of core". Rejected: an E2E spec per documented workflow (restates at the
   browser layer what belongs lower, and browser upload coverage was already declined with a
   stated reason, `docs/agents/TESTING.md` ledger 2026-08-29); documenting the gaps without
   adding tests.
6. **Clean up the leftover E2E state first?**
   **Decided: yes, remove the five `Persons E2E` albums and five orphan `persons-test-*` photo
   rows before screenshotting**, which also reclaims roughly 63 MB of duplicated scan copies.
   Rejected: also fixing whatever let `--restore` leak (cause unresearched, separate work);
   leaving them in and framing screenshots around them.
