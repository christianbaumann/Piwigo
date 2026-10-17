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

/**
 * Creates an album holding no photo.
 *
 * @returns {{album_id: number, note: string}}
 */
function seedEmpty() {
  return run('--scenario=empty');
}

/**
 * Creates three tags: `one` on one photo, `two` on two, `none` on none.
 *
 * @returns {{names: {one: string, two: string, none: string}, tag_ids: {one: number, two: number, none: number}}}
 */
function seedTags() {
  return run('--scenario=tags');
}

/** @returns {number} how many albums the install holds */
function albumCount() {
  return run('--album-count').count;
}

/** @returns {string} the create-album dialog's German hint for a blank name */
function albumNameHint() {
  return run('--album-name-hint').hint;
}

/**
 * Records an album a spec created through the browser, so restore() deletes it.
 *
 * @param {string} name
 */
function trackAlbum(name) {
  run(`--track-album=${name}`);
}

/**
 * Records the photos a spec uploaded into an album through the browser, so restore() deletes them.
 *
 * @param {number} albumId
 * @returns {number[]} the photo ids found
 */
function trackUploads(albumId) {
  return run(`--track-uploads=${albumId}`).image_ids;
}

/** Removes whatever the seed created. Safe to call unseeded. */
function restore() {
  run('--restore');
}

module.exports = { seedPayload, seedSameName, seedEmpty, seedTags, albumCount, albumNameHint, trackAlbum, trackUploads, restore };
