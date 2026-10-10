// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/**
 * Creates a throwaway album with one copied photo and returns what was made.
 *
 * 'neighbour' is 'photo' with a next photo in the same album, whose page is
 * returned as next_path (null for every other scenario).
 *
 * @param {'photo'|'marked'|'regions'|'jpeg'|'neighbour'} scenario
 * @returns {{photo_id: number, album_id: number, width: number, height: number,
 *   kept_name: string, cut_name: string, picture_path: string, album_path: string,
 *   next_path: string|null}}
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
