// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const GERMAN_FILE = path.join(__dirname, '../../../language/de_DE/plugin.lang.php');

/**
 * The plugin's German string for a key, read from its language file, so a
 * spec never carries a second typed copy of it.
 *
 * @param {string} key
 * @returns {string}
 */
function germanString(key) {
  const lang = JSON.parse(execFileSync('php', ['-r', 'include $argv[1]; echo json_encode($lang);', GERMAN_FILE], { encoding: 'utf8' }));
  if (!(key in lang)) {
    throw new Error(`no German string for "${key}" in ${GERMAN_FILE}`);
  }
  return lang[key];
}

module.exports = { germanString };
