# 0073 — Titel, Autor, Info, album name and description are plain text

Date: 2026-10-15
Status: settled

Raised by the exploratory testing sessions SES-2026-10-13-04, SES-2026-10-13-10 and
SES-2026-10-14-04: markup stored in a photo's title or Info ran on the picture page for every
viewer. Design: `.agents/changes/2026-10-15-exploratory-testing-answers/design.md`, *Titel, Autor,
Info, album name and description are plain text*.

## Decision

`plugins/galleryrules` escapes all five fields completely on output (`htmlspecialchars`,
`ENT_QUOTES | ENT_SUBSTITUTE`), as [0009](0009-provenance-text-is-never-html.md) does for
provenance text. What is stored is not changed. Markup is shown as text; bold, italics and links
in descriptions are lost.

- Through core's own events, no core edit: `render_element_name`, `render_element_description`,
  `render_category_name` and `render_category_description` at priority 10, before any handler that
  adds markup (core's `pwg_nl2br` runs at 50). Autor reaches the picture page through no event, so
  `loc_end_picture` re-assigns `INFO_AUTHOR`.
- The album description keeps its lines: escaped, then `nl2br`. Core breaks the lines itself only
  while `allow_html_descriptions` is off, so galleryrules does it only while that is on.
- Core escapes a thumbnail's alt text and tooltip a second time (`include/category_default.inc.php`).
  `loc_end_index_thumbnails` rebuilds both from the stored values, the tooltip through core's
  `get_thumbnail_title()`, which strips tags out of it as before.
- `ws.php` is left alone (`IN_WS`): JSON is data, not HTML, and keeps core's shape (names stripped
  of tags, descriptions raw). Core's admin JS that inserts those values is unchanged by this.
- Every page rendering these events now gets HTML, so a caller must not escape the result again.
  Provenance's read-only page did (`htmlspecialchars` on its breadcrumb) and no longer does.

## Not covered

- Mails sent from *Album benachrichtigen* (`admin/album_notification.php`) take the album name
  through `render_category_name`; the subject and the plain-text body show `&amp;` or `&#039;` for
  a name holding `&` or `'`. The context argument there is shared with the album list, so it cannot
  be told apart. Not fixed in this change; the text is still safe, only shown with entities.
- A photo without a Titel shows a name derived from its file name (`get_name_from_file()`), which
  passes through no event and is not one of the five fields.

## Tests

`plugins/galleryrules/tests/Unit/PlainTextTest`, `tests/Integration/PlainTextFieldsTest` (normal and
generic, every page per field), `tests/e2e/plain-text.spec.js`; the unchanged paths in
`plugins/provenance/tests/Integration/CoreFieldRenderingCharacterizationTest`.
