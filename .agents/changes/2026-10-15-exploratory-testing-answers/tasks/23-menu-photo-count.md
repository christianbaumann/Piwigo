---
id: 23
dependencies:
- 02
---

# Task 23: The menu photo count counts what the viewer can open

The menu count equals the number of photos the viewer can open.

## References

* `design.md#the-menu-photo-count-research-first`

## Work

* [ ] Find why the menu showed 106 while API and albums gave 105; record the cause in a research note
* [ ] Fix in the right layer (core under *Defects in core are fixed in core*, else `galleryrules`)

## Verification

* [ ] Integration per role: menu count equals the photos the role can open, with a photo in no album and one in the lounge
* [ ] Affected suites pass
