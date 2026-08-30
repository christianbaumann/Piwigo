# 0018 — The persons plugin's column-upgrade path is real but untestable, and the test for it is a skip

Date: 2026-08-30
Status: settled

## Decision

`persons_maintain::add_missing_columns()` stays in the code. The integration test that would
witness it, `PluginActivationTest::testAVersionBumpAddsAColumnAnOlderInstallIsMissing()`, is
**skipped** with the reason inline, not deleted and not rewritten into something that passes.

`PluginActivationTest::testEachTableHasTheDeclaredShape()` forces its precondition with a full
uninstall-then-activate rather than a deactivate/activate cycle, because only the former
actually rebuilds the tables.

## Why

`install()` builds both tables with `CREATE TABLE IF NOT EXISTS`, which never touches an
existing table. A column added in a later plugin version therefore reaches an existing install
only through the `ALTER` in `add_missing_columns()`, which runs from `install()`, which is
re-entered on an already-installed plugin only through `update()`.

Nothing a test can drive reaches `update()`. Read out of `admin/include/plugins.class.php`
(verified 2026-08-30):

- `case 'install'` breaks out immediately when a `piwigo_plugins` row already exists (lines
  133-137).
- `case 'activate'` calls `install` only when there is **no** row, and returns early when the
  plugin is already active (lines 187-195). On an installed-but-inactive plugin it calls no
  maintain method at all.
- `case 'update'` calls `extract_plugin_files('upgrade', $options['revision'], ...)` first and
  only proceeds when that returns `'ok'` (lines 155-185) — it needs a real extension archive
  fetched from the extension server.

The path is genuinely reachable **in production**: an upstream version bump ships as an archive
and does call `update()`, which delegates to `install()`. It is not dead code. It is code no
layer of this repo's suites can execute.

## How this was found

Not by reading. During Phase 1 verification the full suite failed on one run and passed on the
next — the "suites pass twice in a row" rule in
[`../../../.claude/rules/testing.md`](../../../.claude/rules/testing.md) catching a real
inter-test dependency.

The cause was traced by reproducing it deliberately: dropping `piwigo_person_region`.`rotation_at_write`
by hand and running the suite reproduced the exact failure, and the next run came back green.
The repair was not `add_missing_columns()` — it was the sibling test that uninstalls and
reinstalls, dropping and recreating the table. A test written against a deactivate/activate
cycle was then watched fail for precisely this reason before being turned into the skip.

The order dependency this exposed is the more serious half: a genuinely missing column would
have been silently repaired by whichever sibling test happened to run first, and never reported.
That is why the shape test now forces its own precondition.

## Consequences

- A future version of this plugin that adds a column cannot verify the upgrade in this repo.
  What it can do is un-skip the test on any install where an archive-driven `update()` is
  available, and say so in the commit.
- Do not "fix" the skip by asserting through a deactivate/activate cycle. That version was
  written, seen to pass without executing the `ALTER` at all, and rejected.
- Do not delete `add_missing_columns()` as dead code. It is the only upgrade path this plugin
  has.

## Related

- [0010](0010-provenance-row-visibility-key.md) — the same core trap hit by `plugins/provenance`,
  from the other direction (a guard that could not be reached rather than an `ALTER`).
- [0017](0017-no-e2e-tests-for-persons-phases-1-to-4.md) — the other Phase 1 decision.
