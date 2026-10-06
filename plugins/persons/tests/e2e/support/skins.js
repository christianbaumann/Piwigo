// @ts-check
const fs = require('fs');
const path = require('path');

/**
 * Every modus skin. Only some of them ship a stylesheet of their own, and with
 * it hf_base.css (themes/modus/template/header.tpl), so CSS placed there reaches
 * only part of them. 18 skins, measured 2026-10-05.
 */
const SKINS = fs
  .readdirSync(path.join(__dirname, '../../../../../themes/modus/skins'))
  .filter((file) => file.endsWith('.inc.php'))
  .map((file) => file.replace('.inc.php', ''));
const MIN_SKINS = 10;

module.exports = { SKINS, MIN_SKINS };
