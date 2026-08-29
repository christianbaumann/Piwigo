// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/**
 * Force a known album provenance state and return the state that was achieved.
 *
 * seed.php asserts its own precondition took effect and fails loudly if it did
 * not, so a spec never runs over a state it merely hoped for. The returned
 * object is what a spec should assert against - not a shape guessed from the
 * scenario name.
 *
 * @param {'no-provenance'|'with-provenance'} scenario
 * @param {number} [albumId]
 */
function seed(scenario, albumId) {
  const args = [SEED_SCRIPT, `--scenario=${scenario}`];
  if (albumId !== undefined) {
    args.push(`--album=${albumId}`);
  }
  return JSON.parse(execFileSync('php', args, { encoding: 'utf8' }));
}

/** Put back whatever the seeds in this test recorded. Safe to call unseeded. */
function restore() {
  execFileSync('php', [SEED_SCRIPT, '--restore'], { encoding: 'utf8' });
}

module.exports = { seed, restore };
