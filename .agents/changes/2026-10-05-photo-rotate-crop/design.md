---
datetime: 2026-10-05T17:11:31+02:00
author: Christian Baumann
tags: [photoedit, picture-page, rotation, crop, persons, provenance, fork-local]
---

# Rotate and crop a photo, written into the file

## Goal

A webmaster turns a photo in 90° steps and crops it to a free rectangle, on the public picture
page. The result is written **into the image file**, so it survives a deploy and a rescan.

Status: designed and split into tasks (`tasks/`). Tasks 01-04 implemented.

## Current state (measured 2026-10-05)

- `images.rotation` (code 0..3, quarter turns clockwise) is core's only rotation. It is a
  display transform: `i.php:536` turns the derivative, `include/derivative.inc.php:82` swaps
  width and height. Core sets it only from EXIF Orientation (upload,
  `admin/include/functions_upload.inc.php:335`, or lazily in `i.php:481`).
- `pwg_image::get_rotation_angle()` returns `null` for anything but JPEG
  (`admin/include/image.class.php:272`).
- No screen turns or crops a photo. The only crop is the centre of interest (`images.coi`,
  `admin/picture_coi.php`), which steers square derivatives. Set on 0 photos.
- Gallery: 105 photos, all PNG scans under `galleries/` (synced, tracked in git, decision 0026),
  `rotation = 0` on all.
- Tools, local and remote: Imagick (remote 3.7.0 / ImageMagick 6.9.12), GD with PNG, exiftool
  (local 13.25, remote 12.76). `jpegtran` is **not** in the DDEV container.
- `persons` stores regions in the file, pre-rotation, against `AppliedToDimensions`, with
  `rotation_at_write`. `persons_correct_for_rotation()` (`plugins/persons/include/index.inc.php`)
  already rewrites regions after a physical rotation.
- `persons` locks `_data/persons/locks/`, `provenance` locks `_data/provenance/locks/`. Each
  locks the same image file under a different path, so the two do not exclude each other today.
- No database is transferred to the remote (decision 0023). Only what is in the file arrives.

Related: brainstorm of 2026-10-05 (this design), `docs/agents/decisions/0020-…`, `0023-…`,
`0026-…`, `0032-…`, `docs/backlog.md:46` (coi and regions).

## Target behaviour

```
#imageToolBar (webmaster only):  … [ Drehen/Zuschneiden ] …   (renamed from "Bearbeiten" in task 01: core's edit button already has that name)

edit mode on #theImage
  zoom/pan off, persons overlay hidden
  [ ↺ ] [ ↻ ]   Jcrop frame (starts as the whole photo)   [ Speichern ] [ Abbrechen ]
  preview only in the browser, nothing written until Speichern

Speichern
  1 dry_run → "2 Personen-Markierungen gehen verloren: Anna, Josef" (+ JPEG: quality note)
  2 confirm → write → page reloads, shows the new file
```

## Code shape

```
plugins/photoedit/                       new, fork-local, needs a `!/plugins/photoedit` re-include
├─ main.inc.php           ws_add_methods; loc_end_picture (webmaster only)
├─ maintain.class.php
├─ include/
│  ├─ functions.inc.php   PURE: request validation, crop fractions → pixel rect,
│  │                      point/box transform (used for the COI)
│  ├─ pipeline.inc.php    lock, backup, pwg_image, exiftool, DB, derivatives, events
│  └─ ws_functions.inc.php  pwg.photoedit.apply
├─ template/              picture_button.tpl, editor.js, editor.css
├─ language/              de_DE, en_UK
└─ tests/ Unit/ Integration/ e2e/ Support/   same layout as plugins/persons
```

### Web service `pwg.photoedit.apply`

| Param | Meaning |
|---|---|
| `image_id` | the photo |
| `turns` | 0..3, quarter turns clockwise, relative to what the page shows |
| `crop` | `l,t,r,b` as fractions of the **turned** view; empty = no crop |
| `dry_run` | 1 = compute and report, write nothing |
| `pwg_token` | CSRF |

POST only, `admin_only`, and the handler checks `is_webmaster()`. The handler includes
`admin/include/functions.php` and `admin/include/image.class.php` itself, as core does
(`include/ws_functions/pwg.categories.php:755`).

Response: `lost_regions` (list of names, from `photoedit_preview`), `lossy` (bool), and after a
write the new `width`, `height`.

### Events

| Event | Kind | Payload | persons | provenance |
|---|---|---|---|---|
| `photoedit_preview` | `trigger_change` | `$lost = array()`, image row, transform | adds the regions the transform drops, with names | — |
| `photoedit_begin` | `trigger_notify` | image row | takes its lock | takes its lock |
| `photoedit_end` | `trigger_notify`, fired in `finally` | image row, transform, `ok` | if `ok`: transforms regions, writes them, reindexes; then releases its lock | releases its lock |

`transform` describes the change to the **raw file**: `rotation_before` (the old
`images.rotation`, non-zero only for a JPEG), `turns`, `crop_px` (`x, y, w, h` in the turned
raw file), old and new width and height.

### Write pipeline

```
photoedit_begin
  └ own lock _data/photoedit/locks/<sha1(path)>.lock
backup   → _data/photoedit/originals/<id>-<timestamp>.<ext>
pwg_image: rotate (rotation_before + turns) → crop → write temp → rename over the file
exiftool -tagsFromFile <backup> -all:all   (+ JPEG: Orientation = 1)
DB       width, height, filesize, md5sum (if set), rotation = 0, coi transformed
delete_element_derivatives()
photoedit_end (ok = true | false)
```

## Key Decisions

### Change the file, not the database

- **Decision:** Rotation and crop are written into the image file. `images.rotation` is not used
  to store the turn.
- **Reason:** The file is the source of truth (decision 0020), and only files reach the remote
  (decision 0023). A rotation in the database is lost on the next remote install, and core ignores
  EXIF Orientation for PNG, so there is no place in a PNG core would read it from.
- **Trade-offs:** The pixels change. A view-only rotation (no persistence) and a
  database-only correction (lost on deploy) were rejected.

### 90° turns and a free rectangle only

- **Decision:** v1 turns in quarter turns and crops to a free rectangle with no fixed aspect
  ratio. Mirroring is not offered.
- **Reason:** Covers "scanned sideways" and "scan border". Both map person regions exactly.
- **Trade-offs:** Straightening by a free angle (deskew) is deferred to the backlog: it makes a
  rectangle region a rotated one and needs its own control.

### Turn first, then crop

- **Decision:** The crop frame is drawn on the turned preview, and the server turns before it crops.
- **Reason:** The frame the webmaster draws is the frame they see.
- **Trade-offs:** None worth recording.

### A new fork-local plugin

- **Decision:** `plugins/photoedit`, with no change to core or the `modus` theme.
- **Reason:** Different job from persons. A core edit adds upstream merge risk.
- **Trade-offs:** One more plugin with its own suites and test accounts.

### On the public picture page, webmaster only

- **Decision:** A `Drehen/Zuschneiden` button (task 01; first designed as `Bearbeiten`) in `#imageToolBar` (`add_picture_button()`), shown only to a
  webmaster. It opens an edit mode on the photo: zoom and pan off, the persons overlay hidden.
  Saved through `pwg.photoedit.apply`, then the page reloads.
- **Reason:** The webmaster sees the photo where visitors see it.
- **Trade-offs:** A dialog over the page and an admin tab were rejected. The `[NEG]` case needs an
  administrator who is not a webmaster, so the suite gets a third account.

### PNG and JPEG

- **Decision:** Both. A JPEG is re-encoded with Imagick at the quality read from the file
  (`getImageCompressionQuality()`, else 95). Its EXIF Orientation is baked into the pixels, then
  set to 1, and `images.rotation` becomes 0. The dry run reports `lossy`, and the page warns.
- **Reason:** Uploads may add JPEGs. `jpegtran` is not available locally and unknown on the remote.
- **Trade-offs:** Each save of a JPEG loses one generation. Lossless JPEG via `jpegtran` (crop snaps
  to 8/16 px) goes to the backlog.

### Person regions follow, lost ones are removed after a warning

- **Decision:** persons transforms its regions on `photoedit_end`. A region fully outside the crop,
  or below `persons_minimum_box_ok()` after `persons_clip_region()`, is removed with its
  person-photo link. The dry run lists those names before the write.
- **Reason:** A rescan cannot detect a crop, so the regions would point at the wrong place.
- **Trade-offs:** Refusing the save while a region is cut off was rejected (blocks the common case
  of cropping a stranger away). photoedit calling persons directly was rejected (hard coupling).

### One exclusion across all three plugins, through events

- **Decision:** photoedit fires `photoedit_begin` and `photoedit_end`. persons and provenance take
  and release their own lock there. photoedit holds its own lock as well.
- **Reason:** No plugin learns another plugin's lock paths. persons' lock is re-entrant within a
  process (`persons_lock_acquire()`), so its region write inside `photoedit_end` does not deadlock.
- **Trade-offs:** Fixes the photoedit case only. persons and provenance still do not exclude each
  other outside a photoedit write; that stays as it is.

### Backup in `_data`, no undo in v1

- **Decision:** Before each write the original is copied to `_data/photoedit/originals/`. There is
  no undo button.
- **Reason:** An exiftool `_original` sidecar next to a photo in `galleries/` appears as an
  untracked file in git. For `galleries/`, git is a second backup.
- **Trade-offs:** Restoring is a hand operation. Undo from the backup goes to the backlog.

### The centre of interest is transformed

- **Decision:** `images.coi` is turned and cropped with the same math. A COI fully outside the crop
  becomes `NULL`.
- **Reason:** Requested; keeps a set COI meaningful.
- **Trade-offs:** Resetting it was simpler.

### Deploy

- **Decision:** Edit locally, commit the changed file, deploy. On the remote, run
  `pwg.persons.rescan` afterwards, as already done. Recorded in `.claude/rules/deployment.md`.
- **Reason:** The remote is a sandbox (decision 0021), and the working copy is the source of truth
  for `galleries/` (decision 0026).
- **Trade-offs:** An edit made on the remote is not in git.

## Tests

- **Unit:** crop fractions → pixel rect, point and box transform (turn, crop, both), request
  validation. Persons' new region transform in persons' unit suite.
- **Integration:** a real PNG and a real JPEG are written; dimensions, XMP caption and MWG region
  are read back with ImageMagick (independent reader); DB columns, backup file, derivatives gone,
  lock held across begin/end; webmaster gate (`photoedit_admin` and `photoedit_normal` refused).
- **E2E:** button visible to the webmaster only, edit mode opens and cancels, turn + crop + save
  changes the shown photo, the lost-region warning names the person.
- Mutation table for the unit suite at the end (`.claude/rules/mutation-testing.md`).

## Backlog

- Straighten by a free angle (deskew)
- Lossless JPEG with `jpegtran`
- Undo from the `_data/photoedit/originals/` backup
