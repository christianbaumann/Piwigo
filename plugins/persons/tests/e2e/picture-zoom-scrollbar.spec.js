// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore } = require('./support/seed');
const { PicturePage } = require('./support/PicturePage');

/**
 * The zoomed photo's horizontal scroll bar stays inside the window (modus
 * photo.zoom.js, task 37 of change 2026-10-15-exploratory-testing-answers).
 *
 * #theImage carries 20 px of padding-bottom (hf_base.css) on top of the
 * max-height the zoom sets, so its box, and the scroll bar at the foot of it,
 * ended 20 px below the window. The oracle is geometry: the scroll bar's lower
 * edge against the window, with the page not scrolled.
 */

const WINDOW_HEIGHTS = [1080, 900];
const WINDOW_WIDTH = 1920;

test.afterEach(() => {
  restore();
});

for (const height of WINDOW_HEIGHTS) {
  test.describe(`window ${height} px high`, () => {
    test.use({ viewport: { width: WINDOW_WIDTH, height }, deviceScaleFactor: 1 });

    // [HAPPY] the photo at 100 % overflows its area, so the bar exists
    test('the scroll bar of the photo at 100 % ends inside the window', async ({ page }) => {
      const picture = new PicturePage(page);
      const seeded = seed('empty');
      await picture.goto(seeded.picture_path);
      await picture.settleImage();
      await picture.zoomToNatural();

      const bar = await page.evaluate(() => {
        const area = document.getElementById('theImage');
        const box = area.getBoundingClientRect();
        const style = getComputedStyle(area);
        const border = parseFloat(style.borderBottomWidth);
        return {
          pageY: window.scrollY,
          bottom: box.bottom - border,
          windowHeight: window.innerHeight,
          overflowsX: area.scrollWidth > area.clientWidth,
        };
      });

      // Anti-vacuity: the photo is wider than its area, so the area has a bar.
      // Its thickness is not asserted: headless Chromium draws overlay bars of
      // no layout width, which still sit at the foot of the box.
      expect(bar.overflowsX).toBe(true);
      expect(bar.pageY).toBe(0);
      expect(bar.bottom).toBeLessThanOrEqual(bar.windowHeight);
    });
  });
}
