## ftp deploy

- **A plugin schema change can miss the remote silently.** `activate` falls through to `install`
  only when there is no DB row; an existing row goes through `autoupdate_plugin()`, which compares
  the `Version:` header. So a schema change shipped without a version bump never reaches the
  remote tables, and the deploy reports the plugin `active` either way. Worse for `persons`, which
  has no `ALTER … MODIFY` path at all — a *changed* column definition does not propagate even with
  a bump. Accepted, not gated (the FTP deploy plan's *What We're NOT Doing*); a gate would have to
  read each plugin's header and diff it against the remote's `piwigo_plugins.version`.
- **The research note the deploy plan cites was never committed.**
  `docs/agents/research/2026-08-30-ftp-deployment-and-remote-install.md` does not exist in any
  branch, so every "research decision N" / "research §N" reference in the plan and in the decision
  files is a dead link. The substance survives in the plan's own summaries; the note itself is
  gone.
- **`deploy.local.json` still carries `admin.password: "REPLACE_ME"`.** The next install bakes in
  whatever is in that file. Set a real one before the next deploy.

*(Answered 2026-08-31: `.ddev` and `tools` are no longer uploaded — see
[decision 0022](agents/decisions/0022-the-tools-directory-is-not-published.md). Of `local/`, only
the `index.php` directory-listing guards ship; `local/config/` is excluded and the remote's config
is generated. `tools/deploy/README.md` has the full list.)*

## misc

- search function by: tag, person
- save labels/ tags in the image meta data
- enforce a 1:1 photo-album relationship (core allows many-to-many via `piwigo_image_category`; freetext-per-album assumes 1:1)
- (low prio) provenance: make `owner` a reference to a people table, so "all photos provided by X" is queryable — distinct from person tagging (who is depicted)
- (low prio) provenance: detect file-vs-DB divergence after third-party metadata edits (candidate signal: `images.date_metadata_update`)
- (low prio) provenance: album-delete prompt for what happens to surviving photos' provenance — descoped from Phase 9, see [decision 0013](agents/decisions/0013-no-album-delete-prompt-in-v1.md); needs a third fork-local `begin_delete_categories` trigger, as core fires nothing before the rows are gone
- (low prio) provenance: `piwigo_provenance_history` has no retention, purge or row cap and only ever grows — deliberate for v1, with the measure-first growth path recorded in [decision 0016](agents/decisions/0016-no-history-retention-in-v1.md). Nothing is approaching a limit: 0 rows as of 2026-08-29, and an unchanged field writes no row at all
- (done 2026-08-29) provenance: E2E spec for the Batch Manager move prompt — `plugins/provenance/tests/e2e/move-provenance.spec.js` (3 specs). It was never verified by hand; it was blocked on a seedable install, and once the install was resynchronised the spec was written instead. Covers the prompt appearing with one labelled choice per mode, and `replace` / `clear` each performing a real move
- (done 2026-08-29) dev environment: seedable install restored — `galleries/` resynchronised via `admin.php?page=site_update&site=1&quick_sync=1` (4 albums, 105 photos) and marked throwaway by `create-test-users.php`. All three provenance suites run again. Two fixture defects and three orphaned `piwigo_image_category` rows the empty gallery had been hiding were fixed at the same time — see `docs/agents/TESTING.md`, *The gallery loss, and what running the suites again exposed*
- (done 2026-08-29) testing: `plugins/typetags` has its own `TestUsers.php` and `create-test-users.php`, mirroring `plugins/provenance`. It creates `typetags_webmaster` and `typetags_normal` with generated passwords in the git-ignored `local/config/typetags-test.env`, so neither suite takes a human's credentials any more. Both had been unrunnable by any agent session for that reason; the integration suite (44 tests) and E2E suite (26 specs) ran green for the first time the same day
- (low prio) persons: face crops for a people page — a per-person thumbnail cropped to the region. Deferred with the plan (research answer 9); the open question is whether `IMG_CUSTOM` derivatives can be driven from a region box or whether this needs its own cache
- (low prio) persons: an administrator changing `images.coi` (the centre of interest) does not invalidate or move stored regions. Regions are stored pre-rotation against `AppliedToDimensions`, and `coi` changes neither, so nothing is currently wrong — but a future crop driven from `coi` would move every region and nothing would notice
- (low prio) persons: touch and pen input — the editor binds mouse events only, so a box cannot be drawn on a phone or tablet. The overlay itself is read-only and works everywhere
- (low prio) persons: merging two persons that turn out to be the same one. Rename gets there for a typo; two separate rows with regions in different files need every affected file rewritten, which is the rename write path over a set rather than a single person
- (low prio) persons: Microsoft `MP:RegionInfo` (the Picasa-era shape digiKam dual-writes) is deliberately not written — see the plan's *What We're NOT Doing*. It records no `AppliedToDimensions`, so a reader that trusts it after a resize places faces wrongly; worth revisiting only if a target tool turns out to read nothing else
- (open) dev environment: the `plugins/typetags` submodule is **one commit ahead of its own origin** — the superproject records `44fdd06` ("create the suite's own test accounts instead of taking a human's login") and `github.com/christianbaumann/Piwigo-Colored-Tags` does not have it, so `git submodule update --init` on a fresh clone fails with `not our ref` (measured 2026-08-31). Push that commit from `plugins/typetags`
- (open) dev environment: if gallery content is ever lost again, turn on MariaDB `general_log` first — the 2026-08-29 loss could not be explained because `log_bin` and `general_log` were both off and `delete_elements()` writes no activity row (decision 0011)

The two low-priority provenance items above — `owner` as a reference to a people table, and
file-vs-DB divergence detection — are **unchanged** by the metadata write-back plan
(`docs/agents/plans/2026-08-29-provenance-metadata-writeback.md`). Both were explicitly out of
scope (decisions 3a and 4a) and neither was made easier or harder by it. Divergence detection is
now also a stated consequence of
[decision 0015](agents/decisions/0015-provenance-columns-stay-out-of-the-metadata-mappings.md):
because no provenance column is a key in `use_iptc_mapping` / `use_exif_mapping`, no
synchronisation reads those columns back, so nothing notices a third-party edit.
