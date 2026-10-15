---
id: 34
dependencies: []
---

# Task 34: IPTC carries its character set

Umlauts and the en dash read correctly in every IPTC reader.

## References

* `design.md#iptc-carries-its-character-set`

## Work

* [ ] Add `-IPTC:CodedCharacterSet=UTF8` in `photoinfo_build_argfile`, `photoinfo_build_date_argfile`, `photoinfo_build_tags_argfile`, `provenance_build_argfile`

## Verification

* [ ] Unit: each argfile holds the line
* [ ] Integration: an independent reader (ImageMagick) reads `Ä` and `–` correctly from caption and keywords
* [ ] provenance and photoinfo suites pass
