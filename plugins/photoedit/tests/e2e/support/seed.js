// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/**
 * Creates a throwaway album with one copied photo and returns what was made.
 *
 * @param {'photo'|'marked'} scenario
 * @returns {{photo_id: number, album_id: number, width: number, height: number,
 *   picture_path: string, album_path: string}}
 */
function seed(scenario) {
  return JSON.parse(execFileSync('php', [SEED_SCRIPT, `--scenario=${scenario}`], { encoding: 'utf8' }));
}

/** Backdates the seeded photo's derivatives, so the browser may cache them. */
function ageDerivatives() {
  execFileSync('php', [SEED_SCRIPT, '--age-derivatives'], { encoding: 'utf8' });
}

/** Removes whatever the seed created. Safe to call unseeded. */
function restore() {
  execFileSync('php', [SEED_SCRIPT, '--restore'], { encoding: 'utf8' });
}

module.exports = { seed, restore, ageDerivatives };
