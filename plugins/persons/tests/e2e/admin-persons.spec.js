// @ts-check
const { test, expect } = require('@playwright/test');
const { seed, restore, personCounts } = require('./support/seed');
const { AdminPersonsPage } = require('./support/AdminPersonsPage');

/**
 * The persons list in the admin panel.
 *
 * What the list *contains* is asserted over HTTP in AdminPersonsScreenTest, at
 * a layer that can seed exactly the rows it wants. What only a browser can
 * witness is the rescan: it is a chunked run driven entirely by JavaScript, one
 * request per chunk, and nothing below this layer executes it.
 *
 * Runs as the plugin's webmaster account - see the chromium-admin project in
 * playwright.config.js.
 *
 * The rescan re-reads every photo in the gallery, which is what the button
 * says it does - the seeded photo and the install's own alike. The index is
 * derived from the files, so the run rebuilds it; on an install whose photos
 * carry regions written elsewhere, it also indexes those. That is the
 * behaviour, not a side effect of the test, and it is one more reason none of
 * these suites is safe against a production install.
 */

/** A rescan shells out to exiftool once per photo, so it is allowed real time. */
const RESCAN_TIMEOUT_MS = 90_000;

test.describe('the persons admin screen', () => {
  test.slow();

  /** @type {ReturnType<typeof seed>} */
  let seeded;

  test.beforeEach(() => {
    seeded = seed('overlay');
  });

  test.afterEach(() => {
    restore();
  });

  /**
   * The rescan runs to the end, and what the screen then says agrees with the
   * index.
   *
   * Two independent claims. The counters the run publishes are its own account
   * of how many photos it covered; the counts in the table are read back from
   * the rebuilt index and compared against the database by a second process,
   * because a screen that agreed only with itself would prove nothing.
   */
  test('the rescan completes and the counts match the database', async ({ page }) => {
    const screen = new AdminPersonsPage(page);
    await screen.open();

    // Anti-vacuity: the seeded people really are in the index before the run,
    // so there is something for the rescan to preserve and the table to show.
    const names = seeded.regions.map((region) => region.name);
    expect(names.length).toBeGreaterThan(0);

    for (const name of names) {
      await expect(screen.row(name)).toHaveCount(1);
    }

    await screen.runRescan(RESCAN_TIMEOUT_MS);

    const total = Number(await screen.rescanProgress.getAttribute('data-total'));
    expect(total).toBeGreaterThan(0);
    expect(Number(await screen.rescanProgress.getAttribute('data-done'))).toBe(total);

    await screen.open();

    const inDatabase = personCounts();

    for (const name of names) {
      const expected = inDatabase[name];
      // The rescan reads the regions back out of the file, so a person the seed
      // put there must still be in the index afterwards.
      expect(expected, `${name} is not in the index after the rescan`).toBeTruthy();
      expect(expected.regions).toBeGreaterThan(0);

      await expect(screen.row(name)).toHaveCount(1);
      expect(await screen.counts(name)).toEqual(expected);
    }
  });
});
