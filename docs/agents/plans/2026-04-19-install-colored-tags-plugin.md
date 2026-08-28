---
date: "2026-04-19T12:23:23.210605+00:00"
git_commit: ab91a39614de706dfa0759957f5a548ad958967d
branch: master
topic: "Install Piwigo-Colored-Tags plugin via git submodule"
tags: [plan, plugins, typetags, git-submodule]
status: draft
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

#### [ ] 1. Start DDEV (if not running)
```bash
ddev start
```

#### [ ] 2. Activate the plugin
1. Log into the Piwigo admin panel
2. Navigate to **Plugins > Manage** (or visit `admin.php?page=plugins_installed`)
3. Find **"Colored Tags"** (typetags) in the list of uninstalled plugins
4. Click **Install**, then **Activate**

#### [ ] 3. Verify plugin works
- Visit `admin.php?page=plugin-typetags` to confirm the configuration page loads

### Success Criteria:

#### Manual Verification:
- [ ] Plugin appears in the installed plugins list with status "Active"
- [ ] Plugin configuration page loads without errors
- [ ] Tags in the gallery can be assigned colors

---

## Testing Strategy

This project has no formal test suite (no PHPUnit). Verification is entirely manual.

### Manual Testing Steps:
1. Confirm `plugins/typetags/main.inc.php` exists after submodule add
2. Confirm `git submodule status` shows the submodule
3. Confirm the plugin appears in the Piwigo admin plugin list
4. Confirm install + activate succeeds without errors
5. Confirm tag color configuration page loads

## References

- [Research document](../research/2026-04-19-colored-tags-plugin-installation.md)
- [Plugin repository](https://github.com/christianbaumann/Piwigo-Colored-Tags)
