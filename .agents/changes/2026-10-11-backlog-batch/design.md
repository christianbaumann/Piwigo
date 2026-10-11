---
datetime: 2026-10-11T12:26:14+02:00
author: Christian Baumann
tags: [backlog, deploy, persons, provenance, typetags]
---

# Backlog batch: deploy safety, known bugs, typetags, persons leak

Seven open items from [docs/backlog.md](../../../docs/backlog.md), grouped into four packages and
worked in order. The upstream-PR item was dropped: nothing ever goes to `Piwigo/Piwigo`
([.claude/rules/git-and-commits.md](../../../.claude/rules/git-and-commits.md), commit `d51f7a894`).

## Overview

```
Package 1  Deploy safety       backlog: REPLACE_ME, persons rescan   tools/deploy (pytest)
    │
Package 2  Known bugs          provenance escaping, then rotation    red test exists → un-skip → fix
    │
Package 3  typetags submodule  admin prefilter, then B8 warning      one push to Piwigo-Colored-Tags
    │
Package 4  Leak diagnosis      persons seed / integration leak       research first, then test + fix
```

| Package | Item | Starting point |
|---|---|---|
| 1 | `admin.password: "REPLACE_ME"` | still in `tools/deploy/deploy.local.json`; `_required_string()` in `pwgdeploy/config.py` accepts it |
| 1 | deploy never runs `pwg.persons.rescan` | `pwgdeploy/bootstrap.py` already runs the chunked `pwg.photoinfo.rescan`; persons' method takes `image_ids` (max `PERSONS_WRITEBACK_MAX_CHUNK`) + `pwg_token`, `admin_only` (`plugins/persons/main.inc.php:173`) |
| 2 | provenance stores `a"b\c'd` with backslashes | skipped `plugins/provenance/tests/Integration/SetPhotoInfoTest::testQuotesAndBackslashesAreStoredAsTyped` |
| 2 | face boxes half a turn off on EXIF Orientation 6/8 | skipped `plugins/persons/tests/Unit/DisplayRotationTest`; [decision 0035](../../../docs/agents/decisions/0035-images-rotation-counts-counter-clockwise.md) |
| 3 | admin prefilter pastes `tags.tpl` | `typetags_admin_prefilter()` in `plugins/typetags/include/events_admin.inc.php`; [decision 0036](../../../docs/agents/decisions/0036-prefilters-include-plugin-templates.md) |
| 3 | failed tag write is silent on the picture page (B8) | skipped `[NEG]` spec in `plugins/photoinfo/tests/e2e/tags-freitext.spec.js`; answer fields in `plugins/photoinfo/include/events_tags.inc.php` |
| 4 | `seed.php --restore` and a green integration run leak files | no test, cause unknown |

The submodule is no longer ahead of its origin (`19593eb` on both, checked 2026-10-11), so
Package 3 is not blocked.

## Key Decisions

### Order of the packages

- **Decision:** Deploy safety, then known bugs, then typetags, then leak diagnosis.
- **Reason:** Package 1 protects the next deploy. Package 2 is cheap because the red tests exist. Package 4 is open-ended, so it goes last.
- **Trade-offs:** Rejected: known bugs first; typetags first.

### Guard against the `REPLACE_ME` placeholder

- **Decision:** The owner sets a real password. `config.py` refuses any credential value equal to `REPLACE_ME`, held in one named constant that the test reads. The trap paragraph in `.claude/rules/deployment.md` is updated in the same commit.
- **Reason:** A deploy on 2026-09-01 made the placeholder the live webmaster password (`deployment.md`). A guard stops it for every future credential file, not only this one.
- **Trade-offs:** Rejected: setting the password with no guard (the trap stays); a general weak-password check (not asked for).

### `pwg.persons.rescan` becomes a deploy step

- **Decision:** New step in `bootstrap.run()`. It reuses the photoinfo rescan's photo list and chunking, and a test reads `PERSONS_WRITEBACK_MAX_CHUNK` the way the existing tests read photoinfo's limit. Own report line; exit 0 when single photos fail, same policy as the photoinfo rescan.
- **Reason:** Person regions live only in the files ([decision 0020](../../../docs/agents/decisions/0020-persons-index-is-derived-the-file-is-the-source-of-truth.md)). A manual step after every deploy gets forgotten.
- **Trade-offs:** Rejected: an opt-in flag; a README-only manual step. Every deploy reads each file once more (one exiftool run per photo).

### Persons rescan runs before the photoinfo rescan

- **Decision:** Persons first. The plan checks whether the order matters and records the answer.
- **Reason:** photoinfo's rescan can add person tags from keywords, so the persons index should exist first. This is an assumption, not yet verified.
- **Trade-offs:** Rejected: photoinfo first; leaving the order unchecked.

### Provenance double escaping

- **Decision:** First a skipped twin test for `setAlbumInfo`, to show whether it has the same bug. Then `stripslashes()` on request values in `ws_provenance_setPhotoInfo()` and `ws_provenance_setAlbumInfo()` (`plugins/provenance/include/ws_functions.inc.php`), as photoinfo does (`plugins/photoinfo/include/ws_functions.inc.php:34`), and un-skip both tests. One `SELECT` checks for rows already stored with backslashes; a migration only if it finds any.
- **Reason:** Core runs `addslashes()` over every request value (`include/common.inc.php`); the handler escapes again with `pwg_db_real_escape_string()`. The pattern is already proven in photoinfo.
- **Trade-offs:** Rejected: fixing `setPhotoInfo` only; an unconditional data migration.

### Rotation direction in persons

- **Decision:** Fix `persons_display_box()` (`plugins/persons/include/render.inc.php`) and the editor script together. Un-skip `DisplayRotationTest` and add the code-1 case. Check whether `persons_rotation_delta()` / `persons_correct_for_rotation()` (`plugins/persons/include/index.inc.php`) also assume clockwise turns.
- **Reason:** Both readers must agree, or boxes are drawn in one place and stored in another. New boxes on an affected photo are stored wrongly, so waiting lets damage start silently.
- **Trade-offs:** If the reindex correction is wrong too, the fix is larger than the backlog states. Rejected: display and editor only; waiting for an affected photo.

### typetags admin prefilter includes its template

- **Decision:** `typetags_admin_prefilter()` injects `{include file='<absolute path>'}` per decision 0036. Add a guard modelled on persons' `InjectedTemplateIncludeTest`. Remove the "typetags still pastes" exception from `.claude/rules/plugin-test-suites.md` and `.claude/rules/deployment.md`.
- **Reason:** A pasted template is frozen into the compiled page; Smarty checks only the host template's mtime.
- **Trade-offs:** Submodule commit plus push to `github.com/christianbaumann/Piwigo-Colored-Tags`. Rejected: no guard test; recording it as permanent.

### B8: warning on a failed tag write, picture page only

- **Decision:** photoinfo adds `tags_message_l10n` to its tag-write answer (`photoinfo_tag_write_answer()`), translated with `photoinfo_l10n_reason()` as `setInfo` already does for `message_l10n` (`plugins/photoinfo/include/ws_functions.inc.php:53`). typetags' picture-page script (`plugins/typetags/include/events_public.inc.php`) shows it after `typetags.image.addTag`, `removeTag` and `addNewTag` whenever `tags_written === false`. Un-skip the existing spec. The admin tag screen stays in the backlog.
- **Reason:** The visitor sees the tag badge and believes the file holds the tag. The German text already exists in photoinfo's `language/de_DE/plugin.lang.php`, and the skipped spec expects exactly that text, so no override key in `local/language/de_DE.lang.php` is needed (corrected 2026-10-11 while splitting the tasks).
- **Trade-offs:** Two repositories in one change: photoinfo (superproject) and typetags (submodule commit plus push). The admin tag screen keeps the gap. Rejected: both screens at once; a generic "save failed" text without the reason; an override key for a string photoinfo already translates.

### Persons leak: diagnosis before fix

- **Decision:** A research note in `docs/agents/research/` first: MariaDB `general_log` on, `upload/persons-test/` listed before and after one green integration run, and the test that leaves the file named. Then a reproducing test, then the fix.
- **Reason:** The cause is unknown. A skipped test that names no cause is prose in test form (`docs/backlog.md`).
- **Trade-offs:** Slower than a sweep. Rejected: a test based on a guess; a `--restore` sweep of everything named `persons-test-*` (hides the cause).

### Stale backlog line

- **Decision:** The first commit deletes the "typetags submodule is three commits ahead" line from `docs/backlog.md`.
- **Reason:** Superproject and origin both record `19593eb` (checked 2026-10-11).
- **Trade-offs:** None.

## Out of scope

- The upstream PR for the merge buttons: the change stays fork-local (`CLAUDE.md`, `.claude/rules/git-and-commits.md`).
- B8 on the admin tag screen.
- Every other `docs/backlog.md` item.
