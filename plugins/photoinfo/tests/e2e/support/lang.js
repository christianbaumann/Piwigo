// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const PLUGIN_DIR = path.join(__dirname, '../../../');

/**
 * The plugin's German text for the English key a PHP constant holds, read from
 * the constant and the de_DE language file, so a spec never carries a second
 * typed copy of either. Mirrors FixtureBuilder::germanText().
 *
 * @param {string} constantName e.g. 'PHOTOINFO_FILE_NOT_WRITABLE_MESSAGE'
 * @returns {string}
 */
function germanTextOf(constantName) {
  const script = 'define("PHOTOINFO_PATH", $argv[1]); include PHOTOINFO_PATH."include/functions.inc.php";'
    + ' $lang = array(); include PHOTOINFO_PATH."language/de_DE/plugin.lang.php";'
    + ' $key = constant($argv[2]); echo json_encode(array("key" => $key, "text" => isset($lang[$key]) ? $lang[$key] : null));';
  const { key, text } = JSON.parse(execFileSync('php', ['-r', script, PLUGIN_DIR, constantName], { encoding: 'utf8' }));
  if (!text || text === key) {
    throw new Error(`photoinfo's de_DE language file has no German text for '${key}' (${constantName})`);
  }
  return text;
}

module.exports = { germanTextOf };
