// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/** Runs seed.php with one argument and returns its JSON output. */
function run(arg) {
  return JSON.parse(execFileSync('php', [SEED_SCRIPT, arg], { encoding: 'utf8' }));
}

/**
 * Creates a throwaway public album with one copied photo and returns what was made.
 *
 * @param {'photo'} scenario
 * @returns {{image_id: number, album_id: number, picture_path: string, album_path: string}}
 */
function seed(scenario) {
  return run(`--scenario=${scenario}`);
}

/**
 * The caption slots and the info tag in the photo's file, read by a plain exiftool call.
 *
 * @param {number} imageId
 * @returns {{'XMP-dc:Description': string|null, 'IPTC:Caption-Abstract': string|null,
 *   'EXIF:ImageDescription': string|null, 'XMP-pwginfo:Info': string|null}}
 */
function readFile(imageId) {
  return run(`--read-file=${imageId}`);
}

/**
 * The photo's images.comment as stored.
 *
 * @param {number} imageId
 * @returns {{comment: string|null}}
 */
function readRow(imageId) {
  return run(`--read-row=${imageId}`);
}

/** Removes whatever the seed created. Safe to call unseeded. */
function restore() {
  run('--restore');
}

module.exports = { seed, readFile, readRow, restore };
