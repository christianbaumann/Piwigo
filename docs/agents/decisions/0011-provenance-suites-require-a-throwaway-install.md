# 0011 — The provenance suites refuse to run without a throwaway-install marker

Date: 2026-08-29
Status: accepted

## Context

On 2026-08-29, during Phase 9 of the provenance plan, the local development
install lost its entire gallery: `piwigo_images` went to 0 rows (its
AUTO_INCREMENT stood at 1609), `piwigo_categories` to a single row, and
`upload/2026/04/19/` was emptied of every original file at 18:26:19. The full
integration suite had passed 14 minutes earlier with the gallery intact.

The cause was not identified. What the investigation established:

- `piwigo_activity` records **no** deletion of the lost content. The only
  deletions it holds are 72 fixture albums and 24 fixture photos, all in the
  fixture id range, all from `pwg.categories.delete` driven by
  `CoreDeleteCategoriesCharacterizationTest`.
- `delete_elements()` writes no activity row, so any caller of it leaves no
  trace at all.
- `admin/site_update.php` was the first suspect, because the last sync run
  coincides to the second with the directory's mtime. It is exonerated by its
  own scoping: it deletes only photos whose `storage_category_id` is in the set
  of physical albums it walked (`site_update.php:487-502`), and Piwigo's
  uploader never sets `storage_category_id` at all — no occurrence in
  `admin/include/functions_upload.inc.php` — so the uploaded photos could not
  have matched.
- `log_bin` and `general_log` are both `OFF` on this install, so there is no
  statement-level record to settle it.

## Decision

`FixtureBuilder::__construct()` refuses to build a fixture unless the install
carries the `piwigo_config` row `provenance_throwaway_install = '1'`, written
only by `tests/Support/create-test-users.php` — the script already documented
as never safe against production. The guard fails closed: an unmarked install
gets a message naming that script, never a run.

The guard is deliberately **unconditional rather than narrowed** to the
filesystem sync. The deleting path was never found, so a guard aimed at one
suspected path would be a guard on the wrong thing. What is known for certain is
the class of risk: these suites delete albums and photos through core, drive the
filesystem sync, and rewrite image files in place. That is enough to say they
belong only on an install whose content can be lost without cost.

## Consequences

- A fresh clone needs `create-test-users.php` before any integration or E2E run.
  It already did, for the credentials; it now also sets the marker.
- Running the suites against an install holding real photos takes a deliberate
  act — marking it — rather than being the default.
- The 2026-08-29 loss remains unexplained. If it recurs on a marked install,
  turning on `general_log` first would settle it; that is the next step, not a
  guess recorded as a cause.
