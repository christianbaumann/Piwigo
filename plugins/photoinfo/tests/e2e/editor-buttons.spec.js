// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');
const { SKINS, MIN_SKINS } = require('../../../persons/tests/e2e/support/skins');

/**
 * Cancel in the date and info editors is a quiet secondary control: no fill,
 * no border, in every skin. Save keeps whatever the skin makes of a submit
 * button, so a rule that quiets Cancel cannot reach it. Decision O8 of the
 * handbook third pass: under newspaper Cancel was blue and read as the main
 * button.
 */

const TRANSPARENT = 'rgba(0, 0, 0, 0)';
const EDITORS = /** @type {const} */ (['date', 'info']);

/** @type {ReturnType<typeof seed>} */
let fixture;

test.beforeAll(() => {
  restore();
  fixture = seed('photo');
});

test.afterAll(() => {
  restore();
});

test('[ERR] the skin list is not empty', () => {
  expect(SKINS.length).toBeGreaterThanOrEqual(MIN_SKINS);
  expect(SKINS).toContain('newspaper');
});

for (const skin of SKINS) {
  test(`[ECP] ${skin}: Abbrechen has no fill and no border, Speichern keeps the skin's submit look`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(`${fixture.picture_path}&skin=${skin}`);

    for (const editor of EDITORS) {
      const look = await picture.editorButtonLook(editor);
      // Anti-vacuity: the styles were really read, not empty objects.
      expect(look.probe.borderTopWidth, editor).not.toBe('');
      expect(look.probe.backgroundColor, editor).not.toBe('');

      expect(look.cancel.backgroundColor, editor).toBe(TRANSPARENT);
      expect(look.cancel.backgroundImage, editor).toBe('none');
      expect(look.cancel.borderTopWidth, editor).toBe('0px');
      expect(look.save, editor).toEqual(look.probe);
    }
  });
}
