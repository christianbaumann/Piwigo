# 0062 — `pwg.provenance.writeBack` keeps no webmaster check

Date: 2026-10-12
Status: accepted
A decision not to fix. Answers the third open finding of
[the role permissions research](../research/2026-10-11-role-permissions.md).

## Context

`pwg.provenance.writeBack` rewrites the original image files of up to a chunk of photos. The
research listed it as an open finding because it is `admin_only` and has no `is_webmaster()`
check, unlike the persons methods that reach past one photo
([0057](0057-persons-admin-screen-admin-sees-webmaster-edits.md)).

## Decision

- No change. Every administrator, webmaster or not, may run the write-back. The permission matrix
  of the research gives Admin `edit` on `provenance.writeback`.
- The gate stays what it is: `admin_only` in the registration (`plugins/provenance/main.inc.php`),
  then not a guest and a matching `pwg_token` in `ws_provenance_writeBack()`.

## Consequences

- An administrator who is not the webmaster can rewrite original files. This is intended. The
  `_original` sidecar exiftool leaves beside each written file is the copy of the bytes before it.
- `WriteBackTest::testANonAdminIsRefused` witnesses the refusal of a normal account. No suite
  runs the method as a non-webmaster administrator, because provenance has no such test account.
  Checked by hand 2026-10-12: `persons_admin` (status `admin`) called it with an empty
  `image_ids` and got `{"written":0,"failed":[]}`, `persons_normal` got `401 Access denied`.
- Anyone re-reading the finding should cite this file rather than add the check.
