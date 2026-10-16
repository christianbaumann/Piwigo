// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const SEED_SCRIPT = path.join(__dirname, 'seed.php');

/** Runs seed.php with one argument and returns its JSON output. */
function run(arg) {
  return JSON.parse(execFileSync('php', [SEED_SCRIPT, arg], { encoding: 'utf8' }));
}

/**
 * Creates the payload albums and photo and returns what was made.
 *
 * @returns {{image_id: number, album_id: number, picture_path: string, album_path: string,
 *   album_list_path: string, marker: string,
 *   payloads: {name: string, author: string, comment: string, album_name: string, album_description: string}}}
 */
function seedPayload() {
  return run('--scenario=payload');
}

/**
 * Creates an album with two photos whose rows share one file name.
 *
 * @returns {{album_id: number, image_ids: number[], file: string}}
 */
function seedSameName() {
  return run('--scenario=same-name');
}

/** Removes whatever the seed created. Safe to call unseeded. */
function restore() {
  run('--restore');
}

module.exports = { seedPayload, seedSameName, restore };
