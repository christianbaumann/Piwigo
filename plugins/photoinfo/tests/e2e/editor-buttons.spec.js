// @ts-check
const { test, expect } = require('@playwright/test');
const { PicturePage } = require('./support/PicturePage');
const { seed, restore } = require('./support/seed');
const { SKINS, MIN_SKINS } = require('../../../persons/tests/e2e/support/skins');

/**
 * The date and info editors have no Cancel; their Done keeps whatever the
 * skin makes of a submit button, so a rule that restyles buttons in the
 * editor cannot reach it.
 */

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

test('[ERR] the skin parameter takes effect: newspaper and clear paint a submit differently', async ({ page }) => {
  const picture = new PicturePage(page);
  const probeOf = async (skin) => {
    await picture.goto(`${fixture.picture_path}&skin=${skin}`);
    return (await picture.editorButtonLook('date')).probe;
  };
  expect(await probeOf('newspaper')).not.toEqual(await probeOf('clear'));
});

for (const skin of SKINS) {
  test(`[ECP] ${skin}: Fertig keeps the skin's submit look and there is no Abbrechen`, async ({ page }) => {
    const picture = new PicturePage(page);
    await picture.goto(`${fixture.picture_path}&skin=${skin}`);

    for (const editor of EDITORS) {
      // The date editor closes when focus leaves it, which would shift the next click.
      await picture.goto(`${fixture.picture_path}&skin=${skin}`);
      const look = await picture.editorButtonLook(editor);
      // Anti-vacuity: the styles were really read, not empty objects.
      expect(look.probe.borderTopWidth, editor).not.toBe('');
      expect(look.probe.backgroundColor, editor).not.toBe('');

      // Neither editor has a Cancel: Done is the only button.
      expect(look.cancel, editor).toBeNull();
      }
      expect(look.save, editor).toEqual(look.probe);
    }
  });
}
