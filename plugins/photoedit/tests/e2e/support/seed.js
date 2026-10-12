// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/**
 * Creates a throwaway album with one copied photo and returns what was made.
 *
 * 'neighbours' is 'photo' with a previous and a next photo in the same album,
 * whose pages are returned as previous_path and next_path (null for every
 * other scenario).
 *
 * 'warnings' is 'jpeg' with the two regions of 'regions', indexed and drawn by
 * drawnBy (a TestUsers role; nobody when omitted).
 *
 * @param {'photo'|'marked'|'regions'|'jpeg'|'neighbours'|'warnings'} scenario
 * @param {string} [drawnBy]
 * @returns {{photo_id: number, album_id: number, width: number, height: number,
 *   kept_name: string, cut_name: string, picture_path: string, album_path: string,
 *   previous_path: string|null, next_path: string|null}}
 */
function seed(scenario, drawnBy) {
  const args = [SEED_SCRIPT, `--scenario=${scenario}`];
  if (drawnBy) {
    args.push(`--drawn-by=${drawnBy}`);
  }
  return JSON.parse(execFileSync('php', args, { encoding: 'utf8' }));
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
