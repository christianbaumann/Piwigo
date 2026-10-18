---
id: 48
dependencies:
- 37
---

# Task 48: The tag cloud does not widen the page

Added 2026-10-18 from task 37's uncovered gap (user's answer 3). The letter list on phones stays in `docs/backlog.md`.

## References

* Task 37 (Deviations), decision 0032, `docs/backlog.md`
* `themes/modus/css/tags.css` (`#fullTagCloud SPAN { white-space: nowrap }`), `tags.php`

## Work

* [-] A long tag in the tag cloud wraps instead of widening the page (a seed whose long tag is reliably in the cloud's top 200)
* [ ] Add the edit to `.claude/rules/fork-local-core-edits.md`; remove the tag-cloud line from `docs/backlog.md`

## Verification

* [-] E2E: 255-character tag in the cloud, no horizontal page scroll at 1920 and 390 wide; short multi-word tags still break only at spaces
* [ ] provenance E2E `long-words.spec.js` still passes

## WIP (paused 2026-10-18)

Where I stopped: tests written, never run (no seed, no flip, no lock taken; nothing to restore). Next: run `long-words.spec.js` under `flock /tmp/pwg-suite.lock` to watch the new cloud cases fail red, then fix `themes/modus/css/tags.css` (`#fullTagCloud SPAN`: replace `white-space: nowrap` so a long word wraps, e.g. `overflow-wrap: anywhere` plus keep short tags unbroken), rerun twice, run `roles.spec.js`, then the fork-local-core-edits line and the backlog line removal.

Findings: `tags.php` takes `$conf['full_tag_cloud_items_number']` (default 200) tags by count; a `piwigo_config` row overrides it. The seed now inserts that row (100000) and `--restore` deletes it (refuses to start if the install already has one).

Files changed:
- plugins/provenance/tests/e2e/support/wrap-seed.php (config row, state flag `cloud_row`)
- plugins/provenance/tests/e2e/long-words.spec.js (cloud case at both widths; the multi-word short-tag test is a rough draft: its Range-rect check is unproven and likely needs rewriting, e.g. compare each short tag's link height to one line)
- this task file
