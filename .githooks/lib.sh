# Shared constants for the pre-commit gate and its self-test.
#
# Single source of truth: tools/test-hooks.sh builds its probes from these values
# instead of typing a second copy that rots the day only one side is edited.

# Paths the vacuity ratchet applies to.
TEST_PATH_PATTERN='(^|/)tests/|Test\.php$|\.spec\.js$'

# The vacuous-assertion signature. This is the defect the whole test-pyramid work
# started from: an assertion whose condition ends in `|| true` cannot fail.
VACUOUS_PATTERN='|| true'

# The suites fast enough and self-contained enough to gate a commit on - one per
# plugin that has one. Each entry is a whole command, run under `ddev exec`.
UNIT_SUITES=(
  "plugins/typetags/vendor/bin/phpunit --testsuite unit --configuration plugins/typetags/phpunit.xml"
  "plugins/provenance/vendor/bin/phpunit --testsuite unit --configuration plugins/provenance/phpunit.xml"
  "plugins/persons/vendor/bin/phpunit --testsuite unit --configuration plugins/persons/phpunit.xml"
)

# --- documentation length budget -------------------------------------------
#
# CLAUDE.md is loaded into every session's context whether the task needs it or
# not; a .claude/rules/ file is loaded only when its read-trigger matches. Length
# in the root file is therefore a tax on every task, which is why its cap is the
# tighter one. The rationale and the "how to split" procedure live in
# .claude/rules/backpressure.md.
#
# These are hard caps, not a growth ratchet: as of 2026-08-30 no tracked file
# exceeds either, so there is no inherited backlog to grandfather. If that ever
# stops being true, convert this to a ratchet rather than raising the cap.

# Files the caps apply to, as extended-regex patterns matched against the staged
# path, each with the cap that applies to it.
CLAUDE_MD_PATTERN='(^|/)CLAUDE\.md$'
CLAUDE_MD_MAX_LINES=100

RULES_MD_PATTERN='(^|/)\.claude/rules/[^/]+\.md$'
RULES_MD_MAX_LINES=500
