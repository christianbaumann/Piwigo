---
id: 03
dependencies:
- 02
---

# Task 03: Titel, Autor, Info, album name and description are plain text

No stored markup runs on any gallery page; descriptions keep their line breaks.

## References

* `design.md#titel-autor-info-album-name-and-description-are-plain-text`
* `design.md#album-description-keeps-lines-long-words-wrap`
* decision 0009

## Work

* [x] In `galleryrules`, escape Titel, Autor, Info, album name and description completely on output through core's render events (`render_element_name`, `render_element_description`, `render_category_name`, `render_category_description`, and what the picture page uses for Autor)
* [x] Album description: escape, then `nl2br`
* [x] Check every page that shows these fields: album list, album page, picture page, menu, breadcrumbs, admin screens that render them unescaped

## Verification

* [x] Integration, per field, as normal and generic: a stored `<img onerror>` comes back as text in the page source
  **Note:** Verified via `plugins/galleryrules/tests/Integration/PlainTextFieldsTest` (24 field/page/role cases incl. thumbnail, breadcrumb, menu, album list, provenance pages)
* [x] E2E: no script runs on the picture page and album page for a stored payload
  **Note:** Verified via `plugins/galleryrules/tests/e2e/plain-text.spec.js` (picture page, album page, album list)
* [x] A three-line description shows three lines
  **Note:** Verified via `PlainTextFieldsTest::testAThreeLineDescriptionShowsThreeLines` and unit `PlainTextTest`
* [x] Characterization of the plain-text-free paths unchanged; suites pass
  **Note:** Verified via `plugins/provenance/tests/Integration/CoreFieldRenderingCharacterizationTest` (green before and after); all six PHPUnit suites run, see report
