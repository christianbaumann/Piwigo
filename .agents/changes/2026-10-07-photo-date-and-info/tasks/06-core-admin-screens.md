---
id: 06
dependencies:
- 03
---

# Task 06: Core admin screens keep database and file in agreement

A date or description changed in core's photo edit screen or in the Batch Manager reaches the
plugin columns and the image file, so no screen can make them disagree.

## References

* `design.md#core-admin-screens-set-an-exact-date`
* `design.md#core-description-edits-rewrite-the-caption`
* `admin/picture_modify.php:23-181` (`date_creation`, `comment`)
* `admin/batch_manager_global.php:294-321` (`date_creation` action), and its description action if present
* `include/ws_functions/pwg.images.php` (`pwg.images.setInfo`, same fields over the API)
* `plugins/provenance/include/events_admin.inc.php` (`loc_begin_admin_page` hooks on these screens)

## Work

* [ ] Characterize the save of `date_creation` and `comment` on each path as it is today (`[ERR]`), before hooking
* [ ] Find the event each path offers after saving (or the narrowest hook available); record it
* [ ] Date saved there: precision `day`, no qualifier, no end date; file written as in task 03
* [ ] Description saved there: composed caption and `XMP-pwginfo:Info` rewritten as in task 02
* [ ] Removing the date there clears the plugin columns and the file's date tags
* [ ] Tests: integration per save path

## Verification

* [ ] A date set in the photo edit screen shows as `14. März 1965` on the picture page and is in the file
* [ ] A date set for several photos in the Batch Manager reaches each photo's file
* [ ] A description changed in the photo edit screen is first in each file caption
* [ ] A photo with `ca. 1965` whose date is changed in core shows the new exact date, without `ca.`
* [ ] A photo with the range `1965–1970` whose date is changed in core loses its end too (`photoinfo_date_end` and its precision NULL), never showing `2019–1970` (found in task 04's review)
* [ ] A photo saved as `1965` (precision `year`) whose date is set to 14.03.1965 in core shows `14. März 1965`, not `1965`: until this task hooks core's save paths, the plugin's precision outlives a core edit and silently drops the day (found in task 03's review)
* [ ] photoinfo and provenance suites pass twice in a row
