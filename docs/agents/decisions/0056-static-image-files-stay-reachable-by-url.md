# 0056 — Static image files stay reachable by URL

Date: 2026-10-11
Status: accepted
A decision not to fix. Names the gap [0055](0055-guests-are-locked-out.md) leaves.

## Context

With guests locked out, no page and no API method hands a guest an image URL. The files
themselves are served by the web server without PHP: `galleries/`, `upload/` and the derivatives
under `_data/i/`. Whoever holds such a URL loads the image without logging in. `i.php` is PHP but
has no `check_status()` either: given a photo's path, a guest can ask it for any derivative size,
and it builds the file on demand. `galleries/` paths
follow the album folder names and can be guessed; `upload/` paths carry a random suffix.

## Decision

Leave them as they are. Upstream Piwigo serves them statically too.

### What was rejected

Web-server deny rules on the three directories plus serving every image through PHP, and a login
check in `i.php`. That touches `i.php` and every derivative URL core builds, needs the rule twice (nginx locally, Apache
`.htaccess` on the remote), and costs a PHP request per thumbnail.

## Consequences

- A URL copied out of a logged-in session keeps working for anyone it is passed to.
- Revisit if a guessed or leaked URL becomes a real concern.
