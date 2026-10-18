// @ts-check
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

/**
 * No long word widens a gallery page (modus, task 37 of change
 * 2026-10-15-exploratory-testing-answers). A 255-character tag name and a
 * one-word album description are browsed at a desktop and a phone width; the
 * page itself may not scroll sideways.
 *
 * Home is this suite for the reason the core characterization tests are here:
 * core carries no suite of its own.
 */

const SEED = path.join(__dirname, 'support', 'wrap-seed.php');

const WIDTHS = [
  { name: 'desktop', width: 1920, height: 1080 },
  { name: 'phone', width: 390, height: 800 },
];

/** Sub-pixel layout can leave the scroll width one pixel past the client width. */
const ROUNDING_PX = 1;

/** @type {{album_id: number, tag_id: number, tag_name: string, short_tag_name: string, description: string}} */
let seeded;

test.beforeEach(() => {
  seeded = JSON.parse(execFileSync('php', [SEED], { encoding: 'utf8' }));
  expect(seeded.tag_name).toHaveLength(255);
  expect(seeded.description.length).toBeGreaterThan(300);
});

test.afterEach(() => {
  execFileSync('php', [SEED, '--restore'], { encoding: 'utf8' });
});

/** @param {import('@playwright/test').Page} page */
async function overflowOf(page) {
  return page.evaluate(() => {
    const root = document.documentElement;
    return { scroll: root.scrollWidth, client: root.clientWidth };
  });
}

/** A tag name this short never needs to break inside a word. */
const SHORT_TAG_CHARS = 60;

// [ECP] a short tag in the cloud breaks only at its spaces: never more lines than words
test('a short multi-word tag in the cloud breaks only at spaces', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto('/tags.php?display_mode=cloud');
  const result = await page.evaluate((shortChars) => {
    let checked = 0;
    const multiWord = [];
    const broken = [];
    for (const a of document.querySelectorAll('#fullTagCloud span a')) {
      const text = a.textContent.trim();
      if (text.length > shortChars) continue;
      checked++;
      const words = text.split(/\s+/).length;
      if (words > 1) multiWord.push(text);
      const range = document.createRange();
      range.selectNodeContents(a);
      const lines = new Set(Array.from(range.getClientRects()).map((r) => Math.round(r.top)));
      if (lines.size > words) broken.push(text);
    }
    return { checked, multiWord, broken };
  }, SHORT_TAG_CHARS);
  expect(result.checked).toBeGreaterThan(1);
  // Anti-vacuity: the seeded multi-word tag was among those checked.
  expect(result.multiWord).toContain(seeded.short_tag_name);
  expect(result.broken).toEqual([]);
});

for (const size of WIDTHS) {
  test.describe(size.name, () => {
    test.use({ viewport: { width: size.width, height: size.height } });

    // [HAPPY] the description is one word wider than the window
    test(`the album description does not widen the page at ${size.width} px`, async ({ page }) => {
      await page.goto(`/index.php?/category/${seeded.album_id}`);
      const description = page.locator('.additional_info', { hasText: seeded.description.slice(0, 20) });
      await expect(description).toBeVisible();
      // Anti-vacuity: unwrapped, the word is wider than the window.
      const wordWidth = await description.evaluate((el) => {
        const context = document.createElement('canvas').getContext('2d');
        context.font = getComputedStyle(el).font;
        return context.measureText(el.textContent.trim()).width;
      });
      expect(wordWidth).toBeGreaterThan(size.width);

      const { scroll, client } = await overflowOf(page);
      expect(scroll).toBeLessThanOrEqual(client + ROUNDING_PX);
    });

    // [HAPPY] the tag name is 255 characters. Desktop only: the letter list lays its
    // columns out in a table that is 938 px wide on a phone with or without the
    // tag, a layout defect unrelated to long words.
    if (size.name === 'desktop') test(`a 255-character tag does not widen the tag list at ${size.width} px`, async ({ page }) => {
      await page.goto(`/tags.php?display_mode=letters`);
      // The letter list names every tag; the cloud shows only the top 200 by photo count.
      const link = page.locator(`.tagLetterContent a[href*="/tags/${seeded.tag_id}-"]`);
      await expect(link).toBeVisible();
      const { scroll, client } = await overflowOf(page);
      expect(scroll).toBeLessThanOrEqual(client + ROUNDING_PX);
    });

    // [HAPPY] the cloud sets each tag nowrap; the 255-character one must still wrap
    test(`a 255-character tag does not widen the tag cloud at ${size.width} px`, async ({ page }) => {
      await page.goto(`/tags.php?display_mode=cloud`);
      const link = page.locator(`#fullTagCloud a[href*="/tags/${seeded.tag_id}-"]`);
      await expect(link).toBeVisible();
      const { scroll, client } = await overflowOf(page);
      expect(scroll).toBeLessThanOrEqual(client + ROUNDING_PX);
    });

    test(`a 255-character tag does not widen the tag's photo list at ${size.width} px`, async ({ page }) => {
      await page.goto(`/index.php?/tags/${seeded.tag_id}-provenance-e2e-langwort`);
      await expect(page.locator('.titrePage')).toBeVisible();
      const { scroll, client } = await overflowOf(page);
      expect(scroll).toBeLessThanOrEqual(client + ROUNDING_PX);
    });
  });
}
