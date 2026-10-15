---
id: 21
dependencies: []
---

# Task 21: The sync reads the photo's date from the file

A re-added file gets the date the photo page wrote into it.

## References

* `design.md#the-sync-reads-the-photos-date-from-the-file`
* decision 0043

## Work

* [ ] On metadata sync, photoinfo reads `XMP-pwginfo:DateEDTF`, then `DateCreated`, into the photo's date

## Verification

* [ ] Integration: a dated file added by sync gets `date_creation` (and the partial date)
* [ ] photoinfo suites pass
