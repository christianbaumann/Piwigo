---
id: 02
dependencies:
- 01
---

# Task 02: Info text, end to end

An administrator clicks the Info row in `#imageInfos`, edits the photo's description in a
textarea, saves it, and finds it on the page and in the image file, ahead of the provenance text.
This task also creates `plugins/photoinfo` and its test infrastructure.

## References

* `design.md#a-new-plugin-pluginsphotoinfo`
* `design.md#photoinfo-requires-provenance-and-reuses-its-writer`
* `design.md#info-text-is-cores-comment`
* `design.md#click-to-edit-per-field-administrators-only`
* `design.md#replace-cores-rows-move-the-description-into-the-panel`
* `design.md#one-composed-caption-for-info-and-provenance`
* `design.md#the-file-carries-photoinfos-values-separately-and-a-rescan-rebuilds-them`
* `design.md#ui`
* `.claude/rules/piwigo-architecture.md` (plugin system, web services, code style, security patterns)
* `docs/agents/decisions/0036-prefilters-include-plugin-templates.md`
* `plugins/provenance/main.inc.php`, `plugins/provenance/include/events_public.inc.php` (row injection pattern)
* `plugins/persons/include/` and commit `c85d93519` (an editable row in `#imageInfos`)
* `themes/default/template/picture.tpl:146-330`, `picture.php:830` (`COMMENT_IMG`)
* `plugins/persons/tests/Support/` (test users script, `FixtureBuilder`, `WsClient` to copy the shape from)

## Work

* [ ] Create `plugins/photoinfo` (`main.inc.php` header, `maintain.class.php`, license banners, core code style); add its `!` entry to `.gitignore`
* [ ] Refuse activation when provenance is not active, with a message naming it
* [ ] Provenance: add `trigger_change('provenance_caption_parts', $parts, $image)` where the caption is composed, and an optional exiftool config parameter to `provenance_exiftool_run()`; task 01's tests stay green
* [ ] photoinfo handles `provenance_caption_parts`: its part (info text) goes first, separated from the provenance part by a blank line
* [ ] Declare the `pwginfo` XMP namespace in `plugins/photoinfo/exiftool/pwginfo.config` with an `Info` tag
* [ ] `pwg.photoinfo.setInfo` (`admin_only`, `post_only`, `pwg_token`): saves `images.comment`, then writes the composed caption to the five slots and `XMP-pwginfo:Info` under provenance's lock; reports a failed write without losing the saved text
* [ ] Picture page: an Info row in `#imageInfos` via prefilter `{include}`; read-only for everyone, click-to-edit textarea for administrators; rendered like core renders `comment` (`render_element_description`)
* [ ] The description is no longer shown above the photo
* [ ] Test infrastructure: `photoinfo_webmaster`, `photoinfo_admin`, `photoinfo_normal` via a committed `create-test-users.php`; throwaway-install marker; `FixtureBuilder` with a copied photo; PHPUnit and Playwright configs
* [ ] Tests at the lowest layer: caption part composition (unit), WS permissions and file content (integration), click-to-edit and save in the browser (E2E)

## Verification

* [ ] An administrator edits the info text on the picture page; after a reload the new text shows in the Info row and not above the photo
* [ ] exiftool reads the info text, then a blank line, then the provenance text from `XMP-dc:Description`, `IPTC:Caption-Abstract` and `EXIF:ImageDescription`, and the info text alone from `XMP-pwginfo:Info`
* [ ] A provenance write-back (album "apply to photos") keeps the info text first in the caption
* [ ] A normal user and a guest see the row read-only, and `pwg.photoinfo.setInfo` refuses them
* [ ] Clearing the text removes it from the database and the file
* [ ] Activating photoinfo with provenance inactive fails with a message naming provenance
* [ ] Provenance suites and photoinfo unit, integration and E2E suites pass twice in a row
