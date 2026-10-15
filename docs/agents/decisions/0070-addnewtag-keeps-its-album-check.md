# 0070 — `addNewTag` keeps its album check

Date: 2026-10-15
Status: accepted
Implements the design section *`addNewTag` keeps its album check* of change
`.agents/changes/2026-10-15-exploratory-testing-answers`.

## Context

`typetags.image.addNewTag` refused a photo that sits in no album with `404 Image not found`, also
for an administrator, while core's own tag methods accept such a photo from an administrator
(SES-2026-10-13-06). The answer looked like a bug: the photo exists.

The check is `typetags_image_visible()` (`plugins/typetags/main.inc.php`): the photo must sit in an
album the account may see, by core's permission condition. The method backs the tag field on the
picture page, and a photo without an album has no picture page.

## Decision

- The check stays, administrators included.
- The refusal names the reason: `l10n('Photo is in no visible album')`, in German "Foto ist in
  keinem sichtbaren Album" (`local/language/de_DE.lang.php`, guarded by provenance's
  `GermanOverrideKeyTest`). The code stays 404. A photo that does not exist gets the same answer,
  so the message tells nobody whether a hidden photo exists.
- `AddNewTagTest::testAPhotoInNoAlbumIsRefusedWithTheReasonEvenForAWebmaster` records it.

### What was rejected

- Letting administrators through like core: the method would tag photos no page shows a tag
  field for, and the album check would differ by role for no gain.
- No change: the old message read as a defect.

## Consequences

- An API client tagging a photo in no album uses core's `pwg.images.setInfo` or
  `pwg.tags.*` methods instead.
- `typetags.image.addTag` and `removeTag` still answer `Image not found`; only the method the
  finding named changed.
