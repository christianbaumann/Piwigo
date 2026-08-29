// @ts-check
const { execFileSync } = require('child_process');
const path = require('path');

const CONFIG = path.join(__dirname, '..', '..', '..', 'exiftool', 'pwgprov.config');

/**
 * Read the provenance tags back out of an image file.
 *
 * Read with exiftool rather than by searching the file's bytes: on PNG the XMP
 * packet lands in a compressed zTXt chunk, so a substring search reports a false
 * negative on a file that is perfectly tagged.
 *
 * This is the only thing in the E2E suite that looks at anything other than the
 * page - and it is the point of the write-back: a spec that only believed the
 * progress bar would pass over a button that wrote nothing at all.
 *
 * @param {string} file absolute path of the image
 * @returns {Record<string, string>} exiftool's family-1 keys => value
 */
function readTags(file) {
  const out = execFileSync(
    'exiftool',
    ['-config', CONFIG, '-json', '-G1', '-charset', 'iptc=UTF8',
     '-EXIF:ImageDescription', '-IPTC:Caption-Abstract', '-XMP:all', file],
    { encoding: 'utf8' }
  );

  return JSON.parse(out)[0];
}

module.exports = { readTags };
