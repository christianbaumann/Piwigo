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
)
