# Git and Commits

Applies to this checkout of Piwigo. Read before committing, branching, or pulling from upstream.

## Remotes

Only one remote is configured: `origin` → `github.com/christianbaumann/Piwigo` (the fork). There is **no** `upstream` remote, so pulling from Piwigo/Piwigo needs it added first:

```bash
git remote add upstream https://github.com/Piwigo/Piwigo.git
```

Trunk is `master`. Working branches so far are named `fix/<topic>` (`fix/css-not-loading`, `fix/colored-tag-badge-on-picture`) and branch off `master`. There is no `development` branch.

## Commit convention

Prefix commits that relate to a GitHub issue with `issue #NNN` or `fixes #NNN` (auto-links). Fork-local work with no upstream issue uses a plain imperative subject — see `add Colored Tags plugin as git submodule`, `fix CSS not loading: add missing modus theme`.

Branches: `fix/<topic>` off `master`, matching what's already in the repo. This overrides the generic `development`-trunk default in the user-level CLAUDE.md.
