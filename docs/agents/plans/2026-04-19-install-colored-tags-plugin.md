---
date: "2026-04-19T12:23:23.210605+00:00"
git_commit: ab91a39614de706dfa0759957f5a548ad958967d
branch: master
topic: "Install Piwigo-Colored-Tags plugin via git submodule"
tags: [plan, plugins, typetags, git-submodule]
status: complete
completed: 2026-08-29
---

# Install Piwigo-Colored-Tags Plugin Implementation Plan

## Overview

Install the [Piwigo-Colored-Tags](https://github.com/christianbaumann/Piwigo-Colored-Tags) plugin into this Piwigo instance as a git submodule at `plugins/typetags/`, then activate it through the Piwigo admin UI.

## Current State Analysis

- The `plugins/` directory contains only `index.php` (security redirect). No plugins are installed.
- `.gitignore` contains `/plugins/*` with an exception only for `!/plugins/index.php`. This means `plugins/typetags/` is currently gitignored and must be explicitly allowed before `git submodule add` will work.
- No `.gitmodules` file exists — this will be the first submodule in the repo.
- The plugin's internal ID is `typetags`, so the directory **must** be named `plugins/typetags/`.

## Desired End State

- `plugins/typetags/` exists as a git submodule pointing to `https://github.com/christianbaumann/Piwigo-Colored-Tags.git`
- `.gitignore` has an exception `!/plugins/typetags` so the submodule is tracked
- `.gitmodules` is created and committed
- The plugin is installed and activated in the Piwigo admin UI
- Tag colors can be configured at `admin.php?page=plugin-typetags`

## What We're NOT Doing

- Not modifying any Piwigo core files
- Not configuring tag colors (that's a separate admin task)
- Not installing any other plugins

## Phase 1: Add git submodule

### Overview
Add a `.gitignore` exception for the plugin directory, then add the submodule.

### Changes Required:

#### [x] 1. Add gitignore exception
**File**: `.gitignore`
**Change**: Add `!/plugins/typetags` after the existing `!/plugins/index.php` line so git tracks the submodule.

#### [x] 2. Add git submodule
**Command**:
```bash
git submodule add https://github.com/christianbaumann/Piwigo-Colored-Tags.git plugins/typetags
```

This creates:
- `plugins/typetags/` — the plugin files
- `.gitmodules` — submodule configuration (new file)
- Updated `.gitignore` with the exception

#### [x] 3. Commit and push the changes
Commit the new `.gitmodules`, updated `.gitignore`, and the submodule reference, then push to the remote.

```bash
git add .gitignore .gitmodules plugins/typetags
git commit -m "add Colored Tags plugin as git submodule"
git push
```

### Success Criteria:

#### Automated Verification:
- [x] `plugins/typetags/main.inc.php` exists
- [x] `.gitmodules` contains `[submodule "plugins/typetags"]`
- [x] `git submodule status` shows the typetags submodule

---

## Phase 2: Activate plugin in Piwigo admin (manual — user action)

### Overview
The plugin must be installed and activated through the Piwigo admin UI. This triggers the `maintain.class.php` lifecycle hooks which create the necessary database tables.

### Steps (user performs manually):

#### [x] 1. Start DDEV (if not running)
```bash
ddev start
```

#### [x] 2. Activate the plugin
1. Log into the Piwigo admin panel
2. Navigate to **Plugins > Manage** (or visit `admin.php?page=plugins_installed`)
3. Find **"Colored Tags"** (typetags) in the list of uninstalled plugins
4. Click **Install**, then **Activate**

#### [x] 3. Verify plugin works
- Visit `admin.php?page=plugin-typetags` to confirm the configuration page loads

### Success Criteria:

#### Manual Verification — automated 2026-08-29:

All three boxes had an oracle in the database or over HTTP, so none of them needed a human.
They are now `tests/Integration/PluginActivationTest.php` in the typetags submodule, and
each was watched failing against a deliberate break before being trusted.

- [x] Plugin appears in the installed plugins list with status "Active" —
  `PluginActivationTest::testPluginIsInstalledAndActive`. The list is a rendering of
  `piwigo_plugins`, which is where `activate()` writes, so asserting the row asserts what
  the list would show. **Proven able to fail**: setting `state='inactive'` turns it red
  (and takes three other tests with it, since an inactive plugin registers no WS methods).
- [x] Plugin configuration page loads without errors —
  `PluginActivationTest::testConfigurationPageRendersEveryConfiguredColour`. Asserts more
  than HTTP 200: every colour in `piwigo_typetags` must actually be painted onto the page,
  so a shell that renders an empty list cannot pass. Expected values are read from the
  table rather than typed into the test. **Proven able to fail**: renaming the template's
  `background-color:` property turns it red alone.
- [x] Tags in the gallery can be assigned colors —
  `PluginActivationTest::testTagCanBeAssignedAColour`, driving `typetags.tags.setType`,
  which is the write the admin tags screen performs. **Proven able to fail**: replacing the
  handler's `WHERE id IN (...)` with `WHERE id IN (-1)` turns it and its state-transition
  sibling red, and nothing else.

Two tests beyond the three boxes, because writing them exposed the gaps:
`::testTagColourCanBeRemovedAgain` `[ST]` (the same method with `typetag_id = 0`) and
`::testGuestCannotAssignAColour` `[NEG]` — `setType` is the one `admin_only` method in this
plugin, and nothing had asserted that gate. Removing `admin_only` turns that one red alone.

---

## Testing Strategy

**Superseded 2026-08-29.** When this plan was written the project had no test suite and
verification was entirely manual. `plugins/typetags` now carries unit, integration and E2E
suites — see [docs/agents/TESTING.md](../TESTING.md) for the conventions and the commands.

The five manual steps below are all automated by
`tests/Integration/PluginActivationTest.php` except the first two, which are facts about
the checkout rather than about the running application:

1. `plugins/typetags/main.inc.php` exists after submodule add — Phase 1 automated criterion
2. `git submodule status` shows the submodule — Phase 1 automated criterion
3. The plugin appears in the admin plugin list — `::testPluginIsInstalledAndActive`
4. Install + activate succeeded without errors — same test (state is `active`)
5. The tag colour configuration page loads — `::testConfigurationPageRendersEveryConfiguredColour`

## References

- [Research document](../research/2026-04-19-colored-tags-plugin-installation.md)
- [Plugin repository](https://github.com/christianbaumann/Piwigo-Colored-Tags)
