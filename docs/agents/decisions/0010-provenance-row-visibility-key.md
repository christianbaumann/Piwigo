# 0010 — The public provenance row is switched by a key inside `picture_informations`

Date: 2026-08-29
Status: settled

## Decision

Visibility of the public `#Provenance` row on `picture.php` is controlled by a `provenance`
key that the plugin's `install()` adds to the serialized `$conf['picture_informations']`
map, and `uninstall()` removes again. No separate config parameter, and no checkbox added
to the Display configuration screen.

Two consequences are accepted rather than fixed:

1. **Core drops the key whenever an administrator saves Administration → Configuration →
   Display.** `admin/configuration.php:101-112` holds a hardcoded `$display_info_checkboxes`
   list and lines 278-283 re-serialize `$_POST['picture_informations']` wholesale, so any
   key with no checkbox on that form disappears on save. The row then stops rendering, with
   no error anywhere. Core already loses its own `privacy_level` key the same way
   (`install/config.sql:54-57` seeds eleven keys; the form carries ten).
2. **The "a reinstall must not switch a row the administrator turned off back on" guard has
   no test.** `install()` is re-entered only through `update()`, which
   `admin/include/plugins.class.php:156-168` reaches only by downloading and extracting an
   extension archive; its `install` case (lines 133-137) is skipped outright while a
   `piwigo_plugins` row exists, so `activate` on an installed plugin calls no maintain
   method at all. `PluginActivationTest::testReinstallLeavesTheDisplayInfoKeyAsTheAdministratorSetIt`
   is skipped, carrying that reason.

## Why

The alternative was to prefilter `configuration_display.tpl` for a checkbox of our own and
hook the save so the key round-trips. That is a third prefilter, a new admin hook, and its
own tests — meaningful work for a toggle whose default is "on" and whose failure mode is a
missing row rather than data loss. Adding a private config parameter instead would put the
switch somewhere no administrator looks, which is worse than a switch that occasionally
resets to its default.

The key's absence renders **no** row, not an unconditional one: the injected template reads
`{if !empty($display_info.provenance) …}`, and an absent key is falsy. So a Display save
turns the feature off, never on — the safe direction for a field that names the person who
lent the photographs.

## What was written down instead of fixed

- `PicturePageSourceTest` forces the key on in `setUp()` and restores the original value in
  `tearDown()`, so it never runs over a state it merely hoped for, and covers the key-off
  case as `[DT]`.
- `tests/e2e/support/seed.php` forces the same precondition and puts the original map back
  on `--restore`, for the same reason.
- `PluginActivationTest::testDisplayInfoKeyFollowsTheInstallLifecycle` proves install adds
  the key and uninstall removes it. Verified to fail (2026-08-29) with the mutant that
  deletes the `add_display_info_key()` call.

## Revisit when

An administrator reports the row vanishing after a Display save, or the Display screen gains
a plugin-extensible checkbox list upstream.
