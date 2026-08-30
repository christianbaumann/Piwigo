#!/usr/bin/env bash
#
# Self-test for .githooks/pre-commit.
#
# A hook nobody has watched fail is a hook nobody knows works, and one that
# silently stops blocking is worse than none. This stages throwaway probes
# against a temporary index, runs the hook, and asserts the exit code. It never
# creates a commit and never touches the real index.
#
# Cases run red and green, so the hook is proven able to both block and let a
# clean change through - never only one of the two.

set -uo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOOK="$PROJECT_ROOT/.githooks/pre-commit"
. "$PROJECT_ROOT/.githooks/lib.sh"

PROBE_DIR="$PROJECT_ROOT/.hook-selftest/tests"
TMP_DIR="$(mktemp -d)"
failures=0

# The plugin probe below is written inside a tracked directory, so cleanup has to
# name it explicitly - a leftover would be committable.
PLUGIN_PROBE="plugins/provenance/tests/HookSelftestVacuousProbeTest.php"

cleanup()
{
  rm -rf "$PROJECT_ROOT/.hook-selftest" "$TMP_DIR"
  rm -f "$PROJECT_ROOT/$PLUGIN_PROBE"
}
trap cleanup EXIT INT TERM

fail()
{
  printf 'FAIL  %s\n' "$1"
  failures=$((failures + 1))
}

pass()
{
  printf 'ok    %s\n' "$1"
}

# --- probes -----------------------------------------------------------------

mkdir -p "$PROBE_DIR"

cat > "$PROBE_DIR/probe_clean.php" <<'PHP'
<?php
// Self-test probe: valid syntax, no vacuous assertion. The hook must let it through.
function typetags_hook_probe()
{
  return 1;
}
PHP

cat > "$PROBE_DIR/probe_broken.php" <<'PHP'
<?php
// Self-test probe: deliberate syntax error.
function typetags_hook_probe(
{
  return 1;
}
PHP

# Built from VACUOUS_PATTERN rather than a typed second copy, so the probe cannot
# drift away from what the hook greps for.
cat > "$PROBE_DIR/probe_vacuous.php" <<PHP
<?php
// Self-test probe: valid syntax, but the condition cannot fail.
function typetags_hook_probe()
{
  return (1 === 2 $VACUOUS_PATTERN);
}
PHP

# --- the probes must actually differ from the clean baseline ----------------
#
# If a probe stops carrying what it is supposed to carry, the case below would
# run over nothing and pass green while proving nothing. Designed failure:
# "probe changed nothing", reported red.

for probe in probe_broken probe_vacuous
do
  if cmp -s "$PROBE_DIR/$probe.php" "$PROBE_DIR/probe_clean.php"
  then
    fail "$probe.php is identical to the clean baseline - it would prove nothing"
  else
    pass "$probe.php differs from the clean baseline"
  fi
done

# The vacuous probe must be syntactically valid, or it would be blocked by the
# syntax check and the case would say nothing about the vacuity ratchet.
if php -l "$PROBE_DIR/probe_vacuous.php" >/dev/null 2>&1
then
  pass "probe_vacuous.php is syntactically valid (so only the vacuity check can block it)"
else
  fail "probe_vacuous.php has a syntax error - it would test the wrong check"
fi

# --- cases ------------------------------------------------------------------

run_case()
{
  local name="$1" expected="$2" probe="$3"
  local actual

  rm -f "$TMP_DIR/index"
  (
    cd "$PROJECT_ROOT" || exit 1
    export GIT_INDEX_FILE="$TMP_DIR/index"
    git read-tree HEAD || exit 99
    git add -- "$probe" || exit 99
    "$HOOK" >"$TMP_DIR/out" 2>&1
  )
  actual=$?

  if [ "$actual" -eq "$expected" ]
  then
    pass "$name (exit $actual)"
  else
    fail "$name: expected exit $expected, got $actual"
    sed 's/^/      | /' "$TMP_DIR/out"
  fi
}

run_case "syntax error blocks"      1 ".hook-selftest/tests/probe_broken.php"
run_case "vacuous assertion blocks" 1 ".hook-selftest/tests/probe_vacuous.php"
run_case "clean file passes"        0 ".hook-selftest/tests/probe_clean.php"

# The three cases above stage from .hook-selftest/tests/, which TEST_PATH_PATTERN
# matches on its leading `tests/` segment. That says nothing about a plugin's own
# suite directory, which is where real test files actually live. This stages the
# same vacuous probe at plugins/provenance/tests/ and asserts the ratchet still
# bites - the manual box "watch the hook block a `|| true` in a
# plugins/provenance/tests/ file", done by machine on every run instead of once
# by hand.

mkdir -p "$(dirname "$PROJECT_ROOT/$PLUGIN_PROBE")"
cp "$PROBE_DIR/probe_vacuous.php" "$PROJECT_ROOT/$PLUGIN_PROBE"

if printf '%s\n' "$PLUGIN_PROBE" | grep -Eq "$TEST_PATH_PATTERN"
then
  pass "$PLUGIN_PROBE is inside the ratchet's scope"
else
  fail "$PLUGIN_PROBE does not match TEST_PATH_PATTERN - the case below would prove nothing"
fi

run_case "vacuous assertion blocks in a plugin suite" 1 "$PLUGIN_PROBE"

# --- documentation length budget --------------------------------------------
#
# Boundary pair per cap: a file at exactly the cap must pass and one line more
# must block. A cap tested only far from its edge says nothing about whether the
# comparison is `>` or `>=`.
#
# The probes are generated from the caps in lib.sh rather than from typed line
# counts, so raising a cap cannot leave a probe testing the old one.

DOC_PROBE_DIR="$PROJECT_ROOT/.hook-selftest/doc"
mkdir -p "$DOC_PROBE_DIR/.claude/rules"

# A markdown file of exactly $1 lines.
write_doc_probe()
{
  local path="$1" count="$2" i
  : > "$path"
  for (( i = 1; i <= count; i++ ))
  do
    printf 'line %d\n' "$i" >> "$path"
  done
}

write_doc_probe "$DOC_PROBE_DIR/CLAUDE.md"                    "$CLAUDE_MD_MAX_LINES"
write_doc_probe "$DOC_PROBE_DIR/CLAUDE-over.md"               "$((CLAUDE_MD_MAX_LINES + 1))"
write_doc_probe "$DOC_PROBE_DIR/.claude/rules/at-cap.md"      "$RULES_MD_MAX_LINES"
write_doc_probe "$DOC_PROBE_DIR/.claude/rules/over-cap.md"    "$((RULES_MD_MAX_LINES + 1))"

# The over-cap probe is renamed into place per case: the block only fires for a
# path the pattern matches, and CLAUDE-over.md is not such a path.
cp "$DOC_PROBE_DIR/CLAUDE-over.md" "$TMP_DIR/CLAUDE-over.md"

# Anti-vacuity: the probes must really carry the line counts the cases assume,
# or every assertion below passes on a file that was never long enough to block.
for probe_check in \
  "CLAUDE.md:$CLAUDE_MD_MAX_LINES" \
  "CLAUDE-over.md:$((CLAUDE_MD_MAX_LINES + 1))" \
  ".claude/rules/at-cap.md:$RULES_MD_MAX_LINES" \
  ".claude/rules/over-cap.md:$((RULES_MD_MAX_LINES + 1))"
do
  probe_path="${probe_check%:*}"
  probe_want="${probe_check##*:}"
  probe_got=$(awk 'END { print NR }' "$DOC_PROBE_DIR/$probe_path")
  if [ "$probe_got" -eq "$probe_want" ]
  then
    pass "doc probe $probe_path is $probe_got lines"
  else
    fail "doc probe $probe_path is $probe_got lines, expected $probe_want - the case would prove nothing"
  fi
done

# The patterns must actually match the probe paths, or the cases below stage a
# file the hook never looks at and pass for the wrong reason.
if printf '%s\n' ".hook-selftest/doc/CLAUDE.md" | grep -Eq "$CLAUDE_MD_PATTERN"
then
  pass "CLAUDE_MD_PATTERN matches a nested CLAUDE.md"
else
  fail "CLAUDE_MD_PATTERN does not match the probe path - the cases below prove nothing"
fi

if printf '%s\n' ".hook-selftest/doc/.claude/rules/at-cap.md" | grep -Eq "$RULES_MD_PATTERN"
then
  pass "RULES_MD_PATTERN matches a .claude/rules file"
else
  fail "RULES_MD_PATTERN does not match the probe path - the cases below prove nothing"
fi

run_case "CLAUDE.md at the cap passes"      0 ".hook-selftest/doc/CLAUDE.md"
run_case "rules file at the cap passes"     0 ".hook-selftest/doc/.claude/rules/at-cap.md"
run_case "rules file over the cap blocks"   1 ".hook-selftest/doc/.claude/rules/over-cap.md"

# One line over the CLAUDE.md cap, staged at a path the pattern matches.
mv "$DOC_PROBE_DIR/CLAUDE.md" "$DOC_PROBE_DIR/CLAUDE-at-cap.bak"
cp "$TMP_DIR/CLAUDE-over.md" "$DOC_PROBE_DIR/CLAUDE.md"
run_case "CLAUDE.md over the cap blocks"    1 ".hook-selftest/doc/CLAUDE.md"
mv "$DOC_PROBE_DIR/CLAUDE-at-cap.bak" "$DOC_PROBE_DIR/CLAUDE.md"

# The real files must be inside the budget too - the caps are worth nothing if
# the repository they govern already breaks them.
for tracked in "$PROJECT_ROOT/CLAUDE.md"
do
  tracked_lines=$(awk 'END { print NR }' "$tracked")
  if [ "$tracked_lines" -le "$CLAUDE_MD_MAX_LINES" ]
  then
    pass "$(basename "$tracked") is $tracked_lines lines (cap $CLAUDE_MD_MAX_LINES)"
  else
    fail "$(basename "$tracked") is $tracked_lines lines, over the $CLAUDE_MD_MAX_LINES-line cap"
  fi
done

for tracked in "$PROJECT_ROOT"/.claude/rules/*.md
do
  tracked_lines=$(awk 'END { print NR }' "$tracked")
  if [ "$tracked_lines" -le "$RULES_MD_MAX_LINES" ]
  then
    pass "rules/$(basename "$tracked") is $tracked_lines lines (cap $RULES_MD_MAX_LINES)"
  else
    fail "rules/$(basename "$tracked") is $tracked_lines lines, over the $RULES_MD_MAX_LINES-line cap"
  fi
done

# --- installation -----------------------------------------------------------
#
# Every case above invokes the hook directly, so all three stay green in a repo
# where git has never been told the hook exists. core.hooksPath is what makes
# git call it, and a submodule needs its own - the failure mode this guards is a
# fresh clone where nobody ran tools/install-hooks.sh and the gate silently does
# nothing.

check_hooks_path()
{
  local label="$1" repo="$2" configured resolved

  configured=$(git -C "$repo" config --get core.hooksPath)

  if [ -z "$configured" ]
  then
    fail "$label has no core.hooksPath - run tools/install-hooks.sh"
    return
  fi

  case "$configured" in
    /*) resolved="$configured" ;;
    *)  resolved="$(git -C "$repo" rev-parse --show-toplevel)/$configured" ;;
  esac

  if [ ! -d "$resolved" ]
  then
    fail "$label core.hooksPath points at $configured, which does not exist"
  elif [ "$(cd "$resolved" && pwd)" = "$(cd "$PROJECT_ROOT/.githooks" && pwd)" ]
  then
    pass "$label core.hooksPath resolves to .githooks"
  else
    fail "$label core.hooksPath resolves to $resolved, not $PROJECT_ROOT/.githooks"
  fi
}

check_hooks_path "superproject  " "$PROJECT_ROOT"

if [ -e "$PROJECT_ROOT/plugins/typetags/.git" ]
then
  check_hooks_path "plugins/typetags" "$PROJECT_ROOT/plugins/typetags"
else
  fail "plugins/typetags is not checked out - its hook wiring cannot be checked"
fi

# plugins/provenance is a plain directory in the superproject, not a submodule,
# so the superproject's core.hooksPath already covers its commits and
# install-hooks.sh needs no entry for it. The day it becomes a submodule that
# stops being true silently - this says so instead.
if [ -e "$PROJECT_ROOT/plugins/provenance/.git" ]
then
  fail "plugins/provenance is now its own repository - install-hooks.sh must configure it too"
else
  pass "plugins/provenance is a plain directory (superproject hooksPath covers it)"
fi

# Every gated suite must actually be runnable, or the hook's loop skips a plugin
# with nothing but a passing exit code to show for it.
for suite in "${UNIT_SUITES[@]}"
do
  set -- $suite
  if [ -x "$PROJECT_ROOT/$1" ]
  then
    pass "gated suite runner exists: $1"
  else
    fail "gated suite runner is missing or not executable: $1 (run composer install for that plugin)"
  fi
done

# --- git must actually run the hook on a real commit ------------------------
#
# The direct-invocation cases cannot see a wiring mistake, and the exit code
# alone does not prove a commit was withheld. This makes real commits - in a
# throwaway repository, never in this one - and asserts the commit count moved
# only when it should have.

run_commit_case()
{
  local name="$1" expected="$2" probe="$3"
  local repo="$TMP_DIR/repo" before after actual

  rm -rf "$repo"
  mkdir -p "$repo/tests"
  git init -q -b master "$repo" >/dev/null 2>&1
  git -C "$repo" config user.email "hook-selftest@example.invalid"
  git -C "$repo" config user.name "hook self-test"
  git -C "$repo" config commit.gpgsign false

  # Seed a HEAD first: the hook diffs the index against HEAD.
  printf '<?php\n' > "$repo/seed.php"
  git -C "$repo" add seed.php
  git -C "$repo" commit -q --no-verify -m "seed"

  git -C "$repo" config core.hooksPath "$PROJECT_ROOT/.githooks"

  cp "$PROBE_DIR/$probe" "$repo/tests/probe.php"
  git -C "$repo" add tests/probe.php

  before=$(git -C "$repo" rev-list --count HEAD)
  (cd "$repo" && git commit -m "probe" >"$TMP_DIR/out" 2>&1)
  actual=$?
  after=$(git -C "$repo" rev-list --count HEAD)

  if [ "$actual" -ne "$expected" ]
  then
    fail "$name: expected exit $expected, got $actual"
    sed 's/^/      | /' "$TMP_DIR/out"
    return
  fi

  if [ "$expected" -eq 0 ] && [ "$after" -ne $((before + 1)) ]
  then
    fail "$name: exit 0 but no commit was created"
  elif [ "$expected" -ne 0 ] && [ "$after" -ne "$before" ]
  then
    fail "$name: the commit was created despite the hook rejecting it"
  else
    pass "$name (exit $actual, HEAD $before -> $after)"
  fi
}

run_commit_case "git rejects a real commit"  1 "probe_broken.php"
run_commit_case "git accepts a clean commit" 0 "probe_clean.php"

# ----------------------------------------------------------------------------

if [ "$failures" -eq 0 ]
then
  echo "test-hooks: all cases passed"
else
  echo "test-hooks: $failures case(s) failed"
fi

exit $((failures > 0))
