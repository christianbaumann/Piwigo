---
id: 11
dependencies: []
---

# Task 11: Tag writes clear the whole user cache

Tag list, tag page and Schlagwort-Gruppen are current right after any persons or typetags tag write.

## References

* `design.md#tag-writes-clear-the-whole-user-cache`
* decision 0004
* `plugins/persons/include/index.inc.php:450`, `plugins/typetags/main.inc.php:367`, `:429`, `:598`

## Work

* [ ] Replace `nb_available_tags = NULL` with `invalidate_user_cache()` in persons and typetags
* [ ] Decision superseding 0004
* [ ] typetags part as submodule commit, pushed

## Verification

* [ ] Integration: a user who read the tag list before sees the new count right after a mark add, a mark delete, a typetags add and remove
* [ ] Each test goes red with the old update
* [ ] persons and typetags suites pass
