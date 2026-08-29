- build a person tagging system
- save labels/ tags in the image meta data
- enforce a 1:1 photo-album relationship (core allows many-to-many via `piwigo_image_category`; freetext-per-album assumes 1:1)
- (low prio) provenance: make `owner` a reference to a people table, so "all photos provided by X" is queryable — distinct from person tagging (who is depicted)
- (low prio) provenance: detect file-vs-DB divergence after third-party metadata edits (candidate signal: `images.date_metadata_update`)
- (low prio) provenance: album-delete prompt for what happens to surviving photos' provenance — descoped from Phase 9, see [decision 0013](agents/decisions/0013-no-album-delete-prompt-in-v1.md); needs a third fork-local `begin_delete_categories` trigger, as core fires nothing before the rows are gone
- (low prio) provenance: `piwigo_provenance_history` has no retention, purge or row cap and only ever grows — deliberate for v1, with the measure-first growth path recorded in [decision 0016](agents/decisions/0016-no-history-retention-in-v1.md). Nothing is approaching a limit: 0 rows as of 2026-08-29, and an unchanged field writes no row at all
- (done 2026-08-29) provenance: E2E spec for the Batch Manager move prompt — `plugins/provenance/tests/e2e/move-provenance.spec.js` (3 specs). It was never verified by hand; it was blocked on a seedable install, and once the install was resynchronised the spec was written instead. Covers the prompt appearing with one labelled choice per mode, and `replace` / `clear` each performing a real move
- (done 2026-08-29) dev environment: seedable install restored — `galleries/` resynchronised via `admin.php?page=site_update&site=1&quick_sync=1` (4 albums, 105 photos) and marked throwaway by `create-test-users.php`. All three provenance suites run again. Two fixture defects and three orphaned `piwigo_image_category` rows the empty gallery had been hiding were fixed at the same time — see `docs/agents/TESTING.md`, *The gallery loss, and what running the suites again exposed*
- (open) testing: give `plugins/typetags` its own `create-test-users.php`, as `plugins/provenance` has. Its integration and E2E suites still read `TYPETAGS_TEST_USERNAME` / `TYPETAGS_TEST_PASSWORD` — a human's credentials — which `.claude/rules/testing.md` (*test accounts*) says a suite must never do, and which means no agent session can run them
- (open) dev environment: if gallery content is ever lost again, turn on MariaDB `general_log` first — the 2026-08-29 loss could not be explained because `log_bin` and `general_log` were both off and `delete_elements()` writes no activity row (decision 0011)

The two low-priority provenance items above — `owner` as a reference to a people table, and
file-vs-DB divergence detection — are **unchanged** by the metadata write-back plan
(`docs/agents/plans/2026-08-29-provenance-metadata-writeback.md`). Both were explicitly out of
scope (decisions 3a and 4a) and neither was made easier or harder by it. Divergence detection is
now also a stated consequence of
[decision 0015](agents/decisions/0015-provenance-columns-stay-out-of-the-metadata-mappings.md):
because no provenance column is a key in `use_iptc_mapping` / `use_exif_mapping`, no
synchronisation reads those columns back, so nothing notices a third-party edit.
