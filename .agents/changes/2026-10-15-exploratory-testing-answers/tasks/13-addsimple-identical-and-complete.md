---
id: 13
dependencies:
- 02
---

# Task 13: `addSimple` refuses an identical file and completes the upload

An `addSimple` upload ends in its album with Herkunft, or is refused when the file already exists.

## References

* `design.md#addsimple-refuses-a-file-identical-to-an-existing-photo`
* `design.md#addsimple-completes-the-upload`

## Work

* [x] In `galleryrules`, wrap `pwg.images.addSimple`: identical file refused with an error naming the existing photo, which stays untouched
* [x] A successful `addSimple` empties the lounge for that photo, so it joins its album and inherits Herkunft at once

## Verification

* [x] Integration: identical file refused, existing photo's name and file unchanged
  **Note:** Verified via `AddSimpleTest::testAnIdenticalFileIsRefusedAndTheExistingPhotoStaysUntouched` (error 409 naming `#<id>`; name, file, path, md5, file bytes, albums and lounge rows unchanged; no second row). Red before the wrapper existed.
* [x] Integration as normal: the returned album URL answers 200 at once; Herkunft set without `uploadCompleted`
  **Note:** Verified via `AddSimpleTest::testTheReturnedUrlAnswersANormalAccountAtOnce`, `testAnAddedPhotoIsInItsAlbumWithItsHerkunftAtOnce` and `testANormalAccountsCachedCountIncludesThePhotoAtOnce` (lounge forced on). The first two were red before the change (401, not in album).
* [x] galleryrules and provenance suites pass
  **Note:** see *Verification run* below.

## Deviations from the design

- The refusal applies only where core would merge the upload into the existing photo: a new photo
  (no `image_id`) with `upload_detect_duplicate` on. With the option off core adds a second photo and
  renames nothing, so nothing is refused (`testWithDuplicateDetectionOffTheFileBecomesASecondPhoto`);
  a replace (`image_id` given) is never checked (`testAReplaceWithTheSameFileIsNotRefused`).
- "Empties the lounge for that photo" moves only the new photo's lounge rows (as `empty_lounge()`
  does, through `associate_images_to_categories()`), not the whole lounge, and fires no
  `empty_lounge` event.

## Strength check (flips under the suite lock, restored by EXIT trap)

| Mutant | Expected killer | Result |
|---|---|---|
| drop `$conf['upload_detect_duplicate']` from the guard | duplicate-detection-off case | killed |
| drop `empty($params['image_id'])` from the guard | replace-with-same-file case | killed |
| no `galleryrules_leave_lounge()` call | in-album/Herkunft and URL cases | killed (2) |
| no `invalidate_user_cache()` in `galleryrules_leave_lounge()` | cached-count case | killed |

## Verification run (2026-10-15, all under `flock /tmp/pwg-suite.lock`)

- galleryrules unit: OK (22 tests)
- galleryrules integration: OK (35 tests), again with `--order-by=reverse`: OK; `AddSimpleTest` twice: OK (6 tests)
- galleryrules E2E (`npx playwright test`): 9 passed
- provenance unit: OK (276 tests); provenance integration: OK (340 tests, 3 skipped, the existing skips)
