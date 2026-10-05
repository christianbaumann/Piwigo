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
 * 'empty' is the untagged photo the editor specs start from; it writes nothing
 * into the file, so what is found there afterwards was written by the browser.
 *
 * 'neighbours' is an untagged photo with a previous and a next one in the same
 * album, and also returns their previous_path and next_path.
 *
 * The returned width and height are the seeded photo's pixel size as stored
 * in piwigo_images. `source: 'small'` copies the smallest gallery image instead
 * of the first one, for the picture page's upscaling case.
 *
 * @param {'overlay'|'stale'|'empty'|'neighbours'} scenario
 * @param {{source?: 'first'|'small'}} [options]
 */
function seed(scenario, { source = 'first' } = {}) {
  return JSON.parse(
    execFileSync('php', [SEED_SCRIPT, `--scenario=${scenario}`, `--source=${source}`], { encoding: 'utf8' })
  );
}

/** Remove whatever the seed created. Safe to call unseeded. */
function restore() {
  execFileSync('php', [SEED_SCRIPT, '--restore'], { encoding: 'utf8' });
}

/**
 * What the photo's file says, read by a plain exiftool call in its own process.
 *
 * The independent reader: a spec that asserted a write landed by asking the
 * plugin's own parser would be satisfied by two copies of the same mistake.
 *
 * @param {number} photoId
 */
function readFileRegions(photoId) {
  return JSON.parse(
    execFileSync('php', [SEED_SCRIPT, `--read-file-regions=${photoId}`], { encoding: 'utf8' })
  );
}

/**
 * Force what the server believes about its exiftool binary.
 *
 * 'missing' points the plugin at a directory holding none, which is the state a
 * host without exiftool is in; 'present' puts the default back. Forced rather
 * than hoped for - a spec about the disabled editor must not pass because the
 * container happens to be broken.
 *
 * @param {'missing'|'present'} state
 */
function setExiftool(state) {
  return JSON.parse(
    execFileSync('php', [SEED_SCRIPT, `--exiftool=${state}`], { encoding: 'utf8' })
  );
}

/**
 * Every person in the index with the counts the database holds.
 *
 * The oracle the admin screen's own numbers are compared against - a browser
 * cannot reach MariaDB, so the comparison needs a second process that can.
 *
 * @returns {Record<string, {photos: number, regions: number}>}
 */
function personCounts() {
  return JSON.parse(execFileSync('php', [SEED_SCRIPT, '--person-counts'], { encoding: 'utf8' }));
}

module.exports = { seed, restore, readFileRegions, setExiftool, personCounts };
