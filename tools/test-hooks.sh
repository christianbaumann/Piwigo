#!/usr/bin/env bash
#
# Self-test for .githooks/pre-commit.
#
# A hook nobody has watched fail is a hook nobody knows works, and one that
# silently stops blocking is worse than none. This stages throwaway probes
# against a temporary index, runs the hook, and asserts the exit code. It never
# creates a commit and never touches the real index.
#
# Three cases, two red and one green, so the hook is proven able to both block
# and let a clean change through.

set -uo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOOK="$PROJECT_ROOT/.githooks/pre-commit"
. "$PROJECT_ROOT/.githooks/lib.sh"

PROBE_DIR="$PROJECT_ROOT/.hook-selftest/tests"
TMP_DIR="$(mktemp -d)"
failures=0

cleanup()
{
  rm -rf "$PROJECT_ROOT/.hook-selftest" "$TMP_DIR"
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

# ----------------------------------------------------------------------------

if [ "$failures" -eq 0 ]
then
  echo "test-hooks: all cases passed"
else
  echo "test-hooks: $failures case(s) failed"
fi

exit $((failures > 0))
