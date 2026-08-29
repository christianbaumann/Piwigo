#!/usr/bin/env bash
#
# Point both repositories at the version-controlled hooks directory.
#
# A superproject core.hooksPath does NOT apply to commits made inside a
# submodule - git treats plugins/typetags as its own repository - and every
# plugin commit is a submodule commit. Configuring only the superproject would
# leave the hook silently not running on the commits that matter most.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOOKS_DIR="$PROJECT_ROOT/.githooks"

if [ ! -x "$HOOKS_DIR/pre-commit" ]
then
  echo "install-hooks: $HOOKS_DIR/pre-commit is missing or not executable" >&2
  exit 1
fi

git -C "$PROJECT_ROOT" config core.hooksPath .githooks
echo "superproject   core.hooksPath = $(git -C "$PROJECT_ROOT" config --get core.hooksPath)"

if [ -d "$PROJECT_ROOT/plugins/typetags/.git" ] || [ -f "$PROJECT_ROOT/plugins/typetags/.git" ]
then
  # Absolute, because the submodule's working tree is not the hooks directory's parent.
  git -C "$PROJECT_ROOT/plugins/typetags" config core.hooksPath "$HOOKS_DIR"
  echo "typetags       core.hooksPath = $(git -C "$PROJECT_ROOT/plugins/typetags" config --get core.hooksPath)"
else
  echo "install-hooks: plugins/typetags is not checked out - skipped" >&2
fi
