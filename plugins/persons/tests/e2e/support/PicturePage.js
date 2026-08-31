// @ts-check

/**
 * Page object for the public photo page with the person overlay on it.
 *
 * Every locator the public specs use lives here; a locator in a spec file is a
 * bug.
 */
class PicturePage {
  /**
   * Consecutive unchanged animation frames settle() demands.
   *
   * Comfortably more than overlay.js's RESIZE_DEBOUNCE_MS at 60fps, so a redraw
   * that is still queued cannot be mistaken for a layout that has stopped moving.
   */
  static SETTLE_FRAMES = 12;

  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    /** The photo element itself. Everything is measured against its rendered box. */
    this.image = page.locator('#theMainImage');
    /** The wrapper the prefilter puts around the photo; it supplies the positioning context. */
    this.stage = page.locator('#persons-stage');
    this.overlay = page.locator('#persons-overlay');
    this.boxes = page.locator('#persons-overlay .person-box');
    /** The read-only names row in core's information list. */
    this.personRow = page.locator('#standard #Persons');
    /** Whatever the theme uses to go to the next photo; the click-through spec asserts against it. */
    this.nextLink = page.locator('#linkNext');

    /* ── the editor ─────────────────────────────────────────────────── */

    this.tagToggle = page.locator('#persons-tag-toggle');
    this.editorMessage = page.locator('#persons-editor-message');
    this.picker = page.locator('#persons-picker');
    this.pickerInput = page.locator('#persons-picker-input');
    this.pickerOptions = page.locator('#persons-picker-list .persons-picker-option');
    /** The box being drawn, before it has been named and saved. */
    this.draft = page.locator('#persons-overlay .person-draft');
    /** Only the boxes that exist on the server; a draft has no region id yet. */
    this.savedBoxes = page.locator('#persons-overlay .person-box[data-person-region]');
    /** The stage only while tagging mode is on; waiting for it is how the mode is confirmed. */
    this.taggingStage = page.locator('#persons-stage.persons-tagging');
  }

  /** @param {string} path the picture_path the seed printed */
  async goto(path) {
    await this.page.goto(path);
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** @param {number} regionId */
  box(regionId) {
    return this.page.locator(`#persons-overlay .person-box[data-person-region="${regionId}"]`);
  }

  /**
   * Waits until the overlay has been placed over the photo.
   *
   * The causal fact, not a sleep: overlay.js sets the overlay's pixel size from
   * the photo's measured box, and the photo is a lazily loaded derivative, so
   * before that the overlay is a zero-sized element in the corner.
   */
  async waitForPlacement() {
    await this.image.waitFor({ state: 'visible' });
    await this.page.waitForFunction(() => {
      const image = document.getElementById('theMainImage');
      const overlay = document.getElementById('persons-overlay');
      if (!image || !overlay) {
        return false;
      }
      const imageRect = image.getBoundingClientRect();
      const overlayRect = overlay.getBoundingClientRect();
      return (
        imageRect.width > 1 &&
        Math.abs(imageRect.width - overlayRect.width) < 1 &&
        Math.abs(imageRect.height - overlayRect.height) < 1 &&
        Math.abs(imageRect.left - overlayRect.left) < 1 &&
        Math.abs(imageRect.top - overlayRect.top) < 1
      );
    });
  }

  /**
   * Loads the smallest derivative into the same element, the way the theme's
   * own derivative switch box does.
   *
   * changeImgSrc() is core's function on the picture page; calling it exercises
   * the real path rather than a synthetic src assignment, including the load
   * event the overlay redraws on.
   */
  async switchToSmallestDerivative() {
    await this.page.evaluate(() => {
      const smallest = RVAS.derivatives[0];
      changeImgSrc(smallest.url, smallest.type, smallest.type);
    });
  }

  /**
   * Waits until the photo is actually rendered narrower than it was.
   *
   * The causal fact behind "a smaller derivative was loaded". Waiting only for
   * the overlay to match the photo is not enough: between the src being set and
   * the new file arriving, the element still has its old size, the overlay still
   * matches it, and a measurement taken there reads the size from before the
   * switch.
   *
   * @param {number} px
   */
  async waitForImageNarrowerThan(px) {
    await this.page.waitForFunction((limit) => {
      const image = document.getElementById('theMainImage');
      if (!image) {
        return false;
      }
      const width = image.getBoundingClientRect().width;
      return width > 1 && width < limit;
    }, px);
  }

  /**
   * Waits until the photo has stopped changing size and the overlay has caught up.
   *
   * After a viewport change two things are in flight: the theme's resize handler
   * may pick another derivative - which only changes the rendered size once that
   * file has loaded - and overlay.js debounces its own redraw by
   * RESIZE_DEBOUNCE_MS. A check that merely asks "does the overlay match the
   * photo right now" is satisfied by the layout from *before* the resize, and
   * every measurement after it is one step stale.
   *
   * So stability is required to hold across a run of consecutive frames long
   * enough to outlast the debounce, and the run is reset by any change. Still
   * causal rather than a sleep: what is waited for is the layout not moving, not
   * a duration.
   */
  async settle() {
    await this.page.evaluate(() => {
      window.__personsSettle = { width: null, frames: 0 };
    });

    await this.page.waitForFunction(
      (needed) => {
        const state = window.__personsSettle;
        const image = document.getElementById('theMainImage');
        const overlay = document.getElementById('persons-overlay');
        if (!state || !image || !overlay) {
          return false;
        }

        const imageRect = image.getBoundingClientRect();
        const overlayRect = overlay.getBoundingClientRect();

        const matched =
          imageRect.width > 1 &&
          Math.abs(imageRect.width - overlayRect.width) < 1 &&
          Math.abs(imageRect.height - overlayRect.height) < 1 &&
          Math.abs(imageRect.left - overlayRect.left) < 1 &&
          Math.abs(imageRect.top - overlayRect.top) < 1;

        if (!matched || state.width !== imageRect.width) {
          state.width = imageRect.width;
          state.frames = matched ? 1 : 0;
          return false;
        }

        state.frames += 1;
        return state.frames >= needed;
      },
      PicturePage.SETTLE_FRAMES,
      { polling: 'raf' }
    );
  }

  /**
   * Hovers the photo, which is what reveals the boxes.
   *
   * They are held at opacity 0 until the visitor looks at the picture, so every
   * assertion about how a box *looks* has to go through here first.
   */
  async hoverStage() {
    await this.image.hover();
  }

  /** @param {number} regionId */
  async boxStyle(regionId) {
    return this.box(regionId).evaluate((el) => {
      const style = window.getComputedStyle(el);
      return {
        opacity: Number(style.opacity),
        borderStyle: style.borderTopStyle,
        title: el.getAttribute('title') || '',
      };
    });
  }

  /** @param {number} regionId */
  label(regionId) {
    return this.box(regionId).locator('.person-box-label');
  }

  /**
   * The spread of a box's dimming shadow, in pixels.
   *
   * How "everything outside this box goes dark" is implemented: one shadow
   * larger than any photo, clipped by the overlay. Zero when nothing is dimmed.
   *
   * @param {number} regionId
   */
  async dimSpread(regionId) {
    return this.box(regionId).evaluate((el) => {
      const shadow = window.getComputedStyle(el).boxShadow;
      const lengths = shadow.match(/-?\d+(\.\d+)?px/g) || [];
      // parseFloat, not Number: Number('1280px') is NaN, and Math.max of a NaN
      // is NaN, which crosses the bridge as null and compares false against
      // everything - a check that silently stops checking.
      return lengths.length ? Math.max(...lengths.map(parseFloat)) : 0;
    });
  }

  /** Whether the photo still carries the <area> map; rvas_choose() removes it on a HiDPI screen. */
  async hasImageMap() {
    return this.image.evaluate((el) => el.hasAttribute('usemap'));
  }

  /** The photo's rendered box, which is the only truthful source of its on-screen size. */
  async imageRect() {
    return this.image.evaluate((el) => {
      const r = el.getBoundingClientRect();
      return { left: r.left, top: r.top, width: r.width, height: r.height };
    });
  }

  /** @param {number} regionId */
  deleteButton(regionId) {
    return this.box(regionId).locator('.person-box-delete');
  }

  /** Turns the photo into a drawing surface, and waits until it really is one. */
  async enterTaggingMode() {
    await this.tagToggle.click();
    await this.taggingStage.waitFor();
  }

  /**
   * Drags a rectangle over the photo.
   *
   * The box is given in fractions of the photo's *rendered* size, which is the
   * only frame of reference that survives the theme swapping derivatives
   * underneath - a pixel offset would mean a different part of the picture at
   * every window width.
   *
   * @param {{left: number, top: number, w: number, h: number}} box
   */
  async dragBox(box) {
    const image = await this.imageRect();

    const fromX = image.left + box.left * image.width;
    const fromY = image.top + box.top * image.height;
    const toX = image.left + (box.left + box.w) * image.width;
    const toY = image.top + (box.top + box.h) * image.height;

    await this.page.mouse.move(fromX, fromY);
    await this.page.mouse.down();
    // Stepped, so the drag produces mousemove events rather than one jump.
    await this.page.mouse.move(toX, toY, { steps: 10 });
    await this.page.mouse.up();
  }

  /** Types a name into the picker and waits for the list to answer. */
  async typeName(name) {
    await this.pickerInput.fill(name);
    await this.pickerOptions.first().waitFor();
  }

  /** The names currently rendered on the saved boxes. */
  async savedNames() {
    return this.savedBoxes.locator('.person-box-label').allTextContents();
  }

  /** The rendered box of the rectangle being drawn, before it is saved. */
  async draftRect() {
    return this.draft.evaluate((el) => {
      const r = el.getBoundingClientRect();
      return { left: r.left, top: r.top, width: r.width, height: r.height };
    });
  }

  /** The rendered box of the name picker. */
  async pickerRect() {
    return this.picker.evaluate((el) => {
      const r = el.getBoundingClientRect();
      return { left: r.left, top: r.top, width: r.width, height: r.height };
    });
  }

  /** @param {number} regionId */
  async boxRect(regionId) {
    return this.box(regionId).evaluate((el) => {
      const r = el.getBoundingClientRect();
      return { left: r.left, top: r.top, width: r.width, height: r.height };
    });
  }
}

module.exports = { PicturePage };
