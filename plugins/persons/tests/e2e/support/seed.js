// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/**
 * Force a known set of regions on a throwaway photo and return what was
 * achieved.
 *
 * seed.php asserts its own precondition took effect and fails loudly if it did
 * not, so a spec never runs over a state it merely hoped for. The returned
 * object - including the expected box corners - is what a spec asserts against.
 *
 * @param {'overlay'|'stale'} scenario
 */
function seed(scenario) {
  return JSON.parse(execFileSync('php', [SEED_SCRIPT, `--scenario=${scenario}`], { encoding: 'utf8' }));
}

/** Remove whatever the seed created. Safe to call unseeded. */
function restore() {
  execFileSync('php', [SEED_SCRIPT, '--restore'], { encoding: 'utf8' });
}

module.exports = { seed, restore };
