// @ts-check
const path = require('path');

/**
 * Where auth.setup.js saves the session of one role.
 *
 * @param {'WEBMASTER'|'ADMIN'|'NORMAL'} role
 */
function statePath(role) {
  return path.join(__dirname, '..', '.state', `auth-${role.toLowerCase()}.json`);
}

module.exports = { statePath };
