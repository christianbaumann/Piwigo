---
date: "2026-04-19T12:20:42.991421+00:00"
git_commit: ab91a39614de706dfa0759957f5a548ad958967d
branch: master
topic: "Installation of Piwigo-Colored-Tags plugin"
tags: [research, codebase, plugins, typetags, installation]
status: complete
---

# Research: Installation of Piwigo-Colored-Tags Plugin

## Research Question
How to install the [Piwigo-Colored-Tags](https://github.com/christianbaumann/Piwigo-Colored-Tags) plugin into this Piwigo instance, specifically by checking out / downloading it into this directory.

## Summary

The Piwigo-Colored-Tags plugin (internal ID: `typetags`) must be placed in `plugins/typetags/` within the Piwigo root. Installation can be done via `git clone` or by downloading and extracting the repository. After placing the files, the plugin is activated through Piwigo's admin UI. The plugin directory **must** be named `typetags` — this is the plugin's internal ID used for database registration, lifecycle management, and admin routing.

## Detailed Findings

### Plugin Overview

- **Repository:** https://github.com/christianbaumann/Piwigo-Colored-Tags
- **Internal plugin ID:** `typetags`
- **Purpose:** Allows colorizing tags in the Piwigo gallery for visual categorization
- **License:** GPL-2.0
- **Languages:** PHP (77.9%), JavaScript (12.9%), Smarty templates (5.6%), CSS (3.6%)

### Plugin File Structure

```
typetags/
├── admin.php              — Admin interface for managing tag colors
├── main.inc.php           — Plugin entry point (loaded by Piwigo on every request when active)
├── maintain.class.php     — Lifecycle hooks (install, activate, deactivate, uninstall, update)
├── index.php              — Directory access control
├── LICENSE.txt
├── README.md
├── include/
│   ├── events_admin.inc.php   — Admin event handlers
│   ├── events_public.inc.php  — Public-facing tag rendering
│   ├── functions.inc.php      — Utility functions
│   └── index.php
├── language/              — Localization files (multiple languages)
└── template/              — Smarty templates for UI
```

### Installation Method: Git Clone

Since the Piwigo root is at `/Users/christian.baumann/git_repos/_own/piwigo/`, the plugin should be cloned into the `plugins/` subdirectory with the correct folder name:

```bash
cd /Users/christian.baumann/git_repos/_own/piwigo/plugins/
git clone https://github.com/christianbaumann/Piwigo-Colored-Tags.git typetags
```

The target directory name `typetags` is critical — it must match the plugin's internal ID.

### Alternative: Download as ZIP

```bash
cd /Users/christian.baumann/git_repos/_own/piwigo/plugins/
curl -L https://github.com/christianbaumann/Piwigo-Colored-Tags/archive/refs/heads/master.zip -o typetags.zip
unzip typetags.zip
mv Piwigo-Colored-Tags-master typetags
rm typetags.zip
```

### How Piwigo Discovers and Loads Plugins

1. **Discovery:** The `plugins` class (`admin/include/plugins.class.php:309-323`) scans `/plugins/` for subdirectories matching `^[a-zA-Z0-9-_]+$` that contain a `main.inc.php` file.

2. **Metadata extraction:** The first 2048 bytes of `main.inc.php` are parsed for metadata comments (`Plugin Name`, `Version`, `Description`, `Author`, etc.) — see `admin/include/plugins.class.php:331-407`.

3. **Installation:** Clicking "Install" in the admin UI calls `perform_action('install', 'typetags')` (`admin/include/plugins.class.php:108-304`), which:
   - Invokes `maintain.class.php`'s `install()` method (creates database tables for tag colors)
   - Inserts a row into the `plugins` database table with `state='inactive'`

4. **Activation:** Clicking "Activate" sets `state='active'` in the database and calls the `activate()` lifecycle hook.

5. **Runtime loading:** On every page request, `include/common.inc.php:159` calls `load_plugins()` (`include/functions_plugins.inc.php:432-445`), which queries for `state='active'` plugins and includes their `main.inc.php`.

### Post-Installation: Activation Steps

1. Log into Piwigo admin panel
2. Navigate to **Plugins > Manage** (or visit `admin.php?page=plugins_installed`)
3. Find "Colored Tags" (typetags) in the list of uninstalled plugins
4. Click **Install** then **Activate**
5. Configure tag colors at `admin.php?page=plugin-typetags`

### Git Submodule Consideration

Since the main Piwigo directory is itself a git repository, cloning the plugin into `plugins/typetags/` creates a nested git repository. Two approaches exist:

- **Simple clone (nested repo):** Works fine for development; the inner `.git` directory is ignored by the outer repo unless explicitly added.
- **Git submodule:** If version-tracking the plugin as part of this repo is desired:
  ```bash
  git submodule add https://github.com/christianbaumann/Piwigo-Colored-Tags.git plugins/typetags
  ```

### Current State of `plugins/` Directory

The `plugins/` directory currently contains only `index.php` (a security redirect file). No plugins are installed yet.

## Code References

- `admin/include/plugins.class.php:108-304` — `perform_action()` handles install/activate/deactivate/uninstall
- `admin/include/plugins.class.php:309-407` — `get_fs_plugins()` scans filesystem and parses metadata
- `include/functions_plugins.inc.php:342-352` — `load_plugin()` includes a plugin's `main.inc.php`
- `include/functions_plugins.inc.php:432-445` — `load_plugins()` loads all active plugins at bootstrap
- `include/functions_plugins.inc.php:362-427` — `autoupdate_plugin()` handles version change detection
- `include/common.inc.php:159` — Bootstrap call to `load_plugins()`

## Architecture Documentation

Piwigo's plugin system follows a filesystem-first discovery pattern: any directory in `plugins/` with a valid `main.inc.php` is recognized. The database tracks installation state (`active`/`inactive`). Lifecycle management uses a class extending `PluginMaintain` (in `maintain.class.php`), providing hooks for `install()`, `activate()`, `deactivate()`, `uninstall()`, and `update()`. The event/hook system (`add_event_handler` / `trigger_notify` / `trigger_change`) is how plugins extend Piwigo's behavior without modifying core files.
