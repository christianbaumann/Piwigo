# 0002 — E2E runner location and browser pinning

Date: 2026-08-28
Status: settled

## Decision

Playwright runs **inside the DDEV web container**, against `http://localhost/`. No host-runner fallback.

## Why

Verified directly (2026-08-28): despite the container running Debian 13 (trixie), which is not
on Playwright's supported-distribution list, `npx playwright install --with-deps chromium`
resolved every dependency and Chromium launched headless, loaded
`http://localhost/picture.php?/1/category/1` and returned its title. There is no need for a
host-side runner, and no cross-network hop from a host browser to the container's nginx.

## Browser pinning

Two failure modes exist, and they needed two different fixes:

1. **Browser binaries** (`~/.cache/ms-playwright` by default) live in the container's
   writable layer, which DDEV discards on every `ddev restart` / image rebuild.
   Fixed by setting `PLAYWRIGHT_BROWSERS_PATH=/var/www/html/plugins/typetags/.playwright-browsers`
   via `web_environment` in `.ddev/config.yaml` — a path on the project's host-mounted volume,
   so it survives any container recreation. `.playwright-browsers/` is git-ignored.

2. **OS shared libraries** installed by `--with-deps` (`libnspr4`, `libnss3`, `libgbm1`,
   `libatk-bridge2.0-0`, the Mesa stack, ...) also live in the writable layer and are
   independently lost on restart — **confirmed by direct reproduction**: after fixing (1)
   alone and running `ddev restart`, Chromium failed with
   `libnspr4.so: cannot open shared object file: No such file or directory`, even though the
   browser binary itself was still present on the mounted volume. Fixed by baking
   `npx playwright@1.62.1 install-deps chromium` into `.ddev/web-build/Dockerfile.playwright`,
   which DDEV runs at image-build time, so the libraries become part of the image layer
   instead of the ephemeral container layer.

Verified stable across two consecutive `ddev restart` cycles: browser binaries remained on
disk and Chromium launched successfully both times, reproducing the original probe's title
(`Verschiedenes009 0`) each time.
