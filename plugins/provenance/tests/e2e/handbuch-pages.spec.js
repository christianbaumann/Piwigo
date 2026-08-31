// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * The handbook pages themselves, opened the way a reader opens them.
 *
 * `docs/handbuch/tools/check.php` reads the files: it proves every reference
 * resolves on disk and every page parses. It cannot say whether a browser
 * actually paints them, and the plan's own success criterion is that the pages
 * "open correctly from the filesystem with no server" - a `file://` fact no
 * server-side check can reach.
 *
 * Home is this suite for the same reason the core admin specs are here: the
 * handbook belongs to no plugin, and core carries no suite of its own.
 *
 * Nothing here is navigated over HTTP, so no session and no fixture is needed.
 */

const HANDBUCH_DIR = path.resolve(__dirname, '../../../../docs/handbuch');

/**
 * Lower bound on the pages a run must have opened.
 *
 * Without it a directory that stopped matching would report no problem: zero
 * pages, zero failures. The handbook is six pages; six is the floor.
 */
const MIN_PAGES = 6;

const pages = fs
  .readdirSync(HANDBUCH_DIR)
  .filter((name) => name.endsWith('.html'))
  .sort();

test.describe('the handbook opens from the filesystem', () => {
  test('the run found every page it is supposed to open', () => {
    expect(pages.length).toBeGreaterThanOrEqual(MIN_PAGES);
    expect(pages).toContain('index.html');
  });

  for (const name of pages) {
    test(`${name} paints with its stylesheet and every screenshot`, async ({ page }) => {
      const problems = [];
      page.on('console', (message) => {
        if (message.type() === 'error') {
          problems.push(message.text());
        }
      });
      page.on('pageerror', (error) => problems.push(String(error)));

      await page.goto('file://' + path.join(HANDBUCH_DIR, name));

      // The stylesheet is a relative href, which is the reference most likely
      // to break when a page moves. A page that lost it still renders, just
      // unstyled, so the body's own constrained measure is the witness.
      const measure = await page.evaluate(() => getComputedStyle(document.body).maxWidth);
      expect(measure).not.toBe('none');

      const images = page.locator('img');
      const count = await images.count();
      // index.html carries no screenshot; every other page must carry at least
      // one, or the reference check below would pass on an empty set.
      if (name !== 'index.html') {
        expect(count).toBeGreaterThan(0);
      }

      const broken = await page.evaluate(() =>
        Array.from(document.images)
          .filter((image) => !image.complete || image.naturalWidth === 0)
          .map((image) => image.getAttribute('src'))
      );
      expect(broken).toEqual([]);

      expect(problems).toEqual([]);
    });
  }
});
