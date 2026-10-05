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
    /** Core's information list the person row sits in. */
    this.infoList = page.locator('#standard');
    this.personRow = page.locator('#standard #Persons');
    /** The names cell of that row, without its label. */
    this.personRowNames = page.locator('#standard #Persons dd');
    /** Whatever the theme uses to go to the next photo; the click-through spec asserts against it. */
    this.nextLink = page.locator('#linkNext');

    /* ── the theme's display controls ───────────────────────────────── */

    /** Core's size menu button and the box it opens. */
    this.sizeMenuButton = page.locator('#derivativeSwitchLink');
    this.sizeMenu = page.locator('#derivativeSwitchBox');
    /** The toolbar button that starts the slideshow. */
    this.slideshowButton = page.locator('a:has(> .pwg-icon-slideshow)');
    /** The photo inside the slideshow's own container, which only exists in slideshow mode. */
    this.slideshowImage = page.locator('#slideshow #theMainImage');
    /** The theme's zoom buttons in the toolbar. */
    this.zoomFitButton = page.locator('#zoomFit');
    this.zoomNaturalButton = page.locator('#zoomNatural');
    this.zoomInButton = page.locator('#zoomIn');
    this.zoomOutButton = page.locator('#zoomOut');
    /** The ellipsis that opens modus' collapsed action menu on a narrow screen. */
    this.actionMenuSwitch = page.locator('#imageActionsSwitch');

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

  /**
   * Waits until the photo file has loaded and its rendered box has stopped
   * moving.
   *
   * For pages with no overlay to compare against - settle() needs one. Until
   * the derivative is chosen the photo shows the theme's loading GIF, which is
   * `complete` at once and holds still, so a src equal to the loader's is
   * rejected outright. After that the rendered box has to hold still across
   * SETTLE_FRAMES consecutive frames, which a later src swap resets.
   */
  async settleImage() {
    await this.page.evaluate(() => {
      window.__pictureSettle = { key: null, frames: 0 };
    });

    await this.page.waitForFunction(
      (needed) => {
        const state = window.__pictureSettle;
        const image = document.getElementById('theMainImage');
        if (!state || !image || !image.complete || image.naturalWidth < 2) {
          return false;
        }
        const loader = document.querySelector('.img-loader-derivatives');
        if (loader && image.currentSrc === loader.currentSrc) {
          return false;
        }

        const r = image.getBoundingClientRect();
        const key = [image.currentSrc, r.left, r.top, r.width, r.height].join('|');
        if (r.width < 2 || state.key !== key) {
          state.key = key;
          state.frames = 0;
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
   * What the photo element is showing: its rendered box, the pixel size of the
   * loaded file, and the map it points at.
   */
  async imageDisplay() {
    return this.image.evaluate((el) => {
      const r = el.getBoundingClientRect();
      return {
        width: r.width,
        height: r.height,
        naturalWidth: el.naturalWidth,
        naturalHeight: el.naturalHeight,
        usemap: el.getAttribute('usemap'),
      };
    });
  }

  /**
   * The photo's derivatives as the theme knows them (`RVAS.derivatives`), each
   * with its pixel size and type, smallest first.
   *
   * @returns {Promise<Array<{w: number, h: number, type: string}>>}
   */
  async derivatives() {
    return this.page.evaluate(() => RVAS.derivatives.map((d) => ({ w: d.w, h: d.h, type: d.type })));
  }

  /**
   * Clicks the photo at a point given in fractions of its rendered size.
   *
   * @param {number} x
   * @param {number} y
   */
  async clickImageAt(x, y) {
    const image = await this.imageRect();
    await this.page.mouse.click(image.left + image.width * x, image.top + image.height * y);
  }

  /**
   * Clicks the part of the photo that is on screen, at a point given in
   * fractions of that part.
   *
   * A photo zoomed past its area is mostly scrolled out of #theImage, so a
   * point given in fractions of the whole photo can lie outside the window.
   *
   * @param {number} x
   * @param {number} y
   */
  async clickVisiblePhotoAt(x, y) {
    const visible = await this.visiblePhotoRect();
    await this.page.mouse.click(visible.left + visible.width * x, visible.top + visible.height * y);
  }

  /**
   * Picks one size in core's size menu, the way a visitor does.
   *
   * Each entry follows the check mark core emits with a per-type id, which is
   * the only stable handle the menu offers.
   *
   * @param {string} type a derivative type, as in derivatives()
   */
  async chooseSize(type) {
    await this.sizeMenuButton.click();
    await this.sizeMenu.waitFor({ state: 'visible' });
    await this.page.locator(`#derivativeChecked${type} + a`).click();
  }

  /** Starts the slideshow from the toolbar and waits until it is showing. */
  async startSlideshow() {
    await this.slideshowButton.click();
    await this.slideshowImage.waitFor({ state: 'visible' });
  }

  /**
   * The area the photo is fitted into: the content width of #theImage, and the
   * viewport height below the top of its content.
   */
  async fitArea() {
    return this.page.evaluate(() => {
      const area = document.getElementById('theImage');
      const style = window.getComputedStyle(area);
      const width = area.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
      const top = area.getBoundingClientRect().top + window.scrollY + parseFloat(style.paddingTop);
      return { width, height: window.innerHeight - top };
    });
  }

  /**
   * Opens modus' collapsed action menu, which replaces the toolbar's action
   * buttons below 600 px.
   */
  async openActionMenu() {
    await this.actionMenuSwitch.click();
    await this.zoomFitButton.waitFor({ state: 'visible' });
  }

  /**
   * Where each entry's label text starts, in px from the left of the window:
   * the zoom buttons' own text, and every other action button's
   * `.pwg-button-text`.
   */
  async actionLabelLefts() {
    return this.page.evaluate(() => {
      const textLeft = (el) => {
        const range = document.createRange();
        range.selectNodeContents(el);
        return range.getBoundingClientRect().left;
      };
      return {
        zoom: ['zoomFit', 'zoomNatural'].map((id) => textLeft(document.getElementById(id))),
        others: Array.from(
          document.querySelectorAll('#imageToolBar .actionButtons .pwg-button:not(.zoomButton) .pwg-button-text')
        ).map(textLeft),
      };
    });
  }

  /**
   * Narrows the photo's area without resizing the window, the way a page
   * scrollbar that appears after load does.
   *
   * @param {number} px
   */
  async narrowAreaBy(px) {
    await this.page.evaluate((by) => {
      const area = document.getElementById('theImage');
      area.style.width = area.getBoundingClientRect().width - by + 'px';
    }, px);
  }

  /** Presses `Einpassen` and waits until the photo has settled at the new size. */
  async zoomToFit() {
    await this.zoomFitButton.click();
    await this.settleImage();
  }

  /** Presses `100 %` and waits until the photo has settled at the new size. */
  async zoomToNatural() {
    await this.zoomNaturalButton.click();
    await this.settleImage();
  }

  /** Presses `+` and waits until the photo has settled at the new size. */
  async zoomIn() {
    await this.zoomInButton.click();
    await this.settleImage();
  }

  /** Presses `−` and waits until the photo has settled at the new size. */
  async zoomOut() {
    await this.zoomOutButton.click();
    await this.settleImage();
  }

  /**
   * Presses a key with the focus wherever it is, and waits until the photo has
   * settled.
   *
   * @param {string} key
   */
  async pressKey(key) {
    await this.page.keyboard.press(key);
    await this.settleImage();
  }

  /**
   * Turns the mouse wheel over the middle of the visible photo, with Ctrl held
   * or not, and waits until the photo has settled.
   *
   * Also reports whether the wheel event reached the window with its default
   * prevented - the only part of "the browser does not zoom the page" a
   * headless browser can show.
   *
   * @param {number} deltaY negative turns the wheel away from the viewer
   * @param {{ctrl?: boolean}} [options]
   * @returns {Promise<{defaultPrevented: boolean}>}
   */
  async wheelOverPhoto(deltaY, { ctrl = false } = {}) {
    await this.page.evaluate(() => {
      window.__pictureWheel = null;
      window.addEventListener(
        'wheel',
        (e) => {
          window.__pictureWheel = { defaultPrevented: e.defaultPrevented };
        },
        { once: true }
      );
    });
    const visible = await this.visiblePhotoRect();
    await this.page.mouse.move(visible.left + visible.width / 2, visible.top + visible.height / 2);
    if (ctrl) {
      await this.page.keyboard.down('Control');
    }
    await this.page.mouse.wheel(0, deltaY);
    if (ctrl) {
      await this.page.keyboard.up('Control');
    }
    await this.page.waitForFunction(() => window.__pictureWheel !== null);
    await this.settleImage();
    return this.page.evaluate(() => window.__pictureWheel);
  }

  /** How far the page and #theImage are scrolled, in CSS pixels. */
  async scrollPositions() {
    return this.page.evaluate(() => {
      const area = document.getElementById('theImage');
      return { pageY: window.scrollY, areaLeft: area.scrollLeft, areaTop: area.scrollTop };
    });
  }

  /**
   * Scrolls #theImage to a position given in fractions of how far it can scroll.
   *
   * @param {number} x
   * @param {number} y
   */
  async scrollAreaTo(x, y) {
    await this.page.evaluate(
      ([fx, fy]) => {
        const area = document.getElementById('theImage');
        area.scrollLeft = (area.scrollWidth - area.clientWidth) * fx;
        area.scrollTop = (area.scrollHeight - area.clientHeight) * fy;
      },
      [x, y]
    );
  }

  /**
   * The point of the photo at the middle of what #theImage shows - its client
   * box, cut to the window - in fractions of the photo's size.
   */
  async centredPhotoPoint() {
    return this.page.evaluate(() => {
      const image = document.getElementById('theMainImage').getBoundingClientRect();
      const area = document.getElementById('theImage');
      const box = area.getBoundingClientRect();
      const left = Math.max(box.left + area.clientLeft, 0);
      const right = Math.min(box.left + area.clientLeft + area.clientWidth, window.innerWidth);
      const top = Math.max(box.top + area.clientTop, 0);
      const bottom = Math.min(box.top + area.clientTop + area.clientHeight, window.innerHeight);
      const x = (left + right) / 2;
      const y = (top + bottom) / 2;
      return { x: (x - image.left) / image.width, y: (y - image.top) / image.height };
    });
  }

  /**
   * Makes the page longer below the photo, the way a long description or
   * comment thread does.
   *
   * @param {number} px
   */
  async lengthenPageBy(px) {
    await this.page.evaluate((by) => {
      const spacer = document.createElement('div');
      // min-height, not height: modus' body is a flex column, which shrinks a plain height away.
      spacer.style.minHeight = by + 'px';
      document.body.appendChild(spacer);
    }, px);
  }

  /**
   * Scrolls the page itself down, and returns how far it got.
   *
   * @param {number} y
   */
  async scrollPageTo(y) {
    return this.page.evaluate((to) => {
      window.scrollTo(0, to);
      return window.scrollY;
    }, y);
  }

  /** The part of the photo that is on screen: inside #theImage and inside the window. */
  async visiblePhotoRect() {
    return this.page.evaluate(() => {
      const image = document.getElementById('theMainImage').getBoundingClientRect();
      const area = document.getElementById('theImage');
      const box = area.getBoundingClientRect();
      const left = Math.max(image.left, box.left);
      const top = Math.max(image.top, box.top);
      const right = Math.min(image.right, box.left + area.clientWidth, window.innerWidth);
      const bottom = Math.min(image.bottom, box.top + area.clientHeight, window.innerHeight);
      return { left, top, width: right - left, height: bottom - top };
    });
  }

  /**
   * The zoom buttons' boxes and label colours, in toolbar order, and the
   * colours of the icon and the label text on the toolbar's other action
   * buttons - the toolbar shows icons, the phone's action menu labels.
   */
  async zoomControlLook() {
    return this.page.evaluate(() => {
      const other = '#imageToolBar .actionButtons .pwg-button:not(.zoomButton)';
      const icon = document.querySelector(`${other} .pwg-icon`);
      const text = document.querySelector(`${other} .pwg-button-text`);
      return {
        iconColor: icon ? window.getComputedStyle(icon).color : null,
        textColor: text ? window.getComputedStyle(text).color : null,
        buttons: ['zoomOut', 'zoomFit', 'zoomNatural', 'zoomIn'].map((id) => {
          const button = document.getElementById(id);
          const r = button.getBoundingClientRect();
          const label = button.querySelector('.zoomLabel') || button;
          const range = document.createRange();
          range.selectNodeContents(button);
          const text = range.getBoundingClientRect();
          return {
            id,
            left: r.left,
            right: r.right,
            top: r.top,
            textLeft: text.left,
            textRight: text.right,
            color: window.getComputedStyle(label).color,
          };
        }),
      };
    });
  }

  /**
   * How wide #theImage's content is against what it shows, whether it actually
   * scrolls - a scrollLeft that sticks at 0 means it does not - and how wide the
   * page is against the window. Leaves #theImage scrolled back to the left.
   */
  async overflowWidths() {
    return this.page.evaluate(() => {
      const area = document.getElementById('theImage');
      area.scrollLeft = 1;
      const scrolls = area.scrollLeft > 0;
      area.scrollLeft = 0;
      return {
        areaScroll: area.scrollWidth,
        areaClient: area.clientWidth,
        areaScrolls: scrolls,
        pageScroll: document.documentElement.scrollWidth,
        window: document.documentElement.clientWidth,
      };
    });
  }

  /** Whether the photo still carries the <area> map; the theme removes it whenever the photo is not shown at its file size. */
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

  /** Leaves tagging mode through the toggle, and waits until the mode is really off. */
  async exitTaggingMode() {
    await this.tagToggle.click();
    await this.taggingStage.waitFor({ state: 'detached' });
  }

  /** Leaves tagging mode with Esc - only valid with no draft open - and waits until it is off. */
  async exitTaggingModeWithEscape() {
    await this.page.keyboard.press('Escape');
    await this.taggingStage.waitFor({ state: 'detached' });
  }

  /**
   * Marks the document currently loaded, so a later navigation can be told
   * apart from none at all.
   *
   * The causal fact rather than a wait: a reload replaces the window object and
   * with it the marker. The mark is also cleared on beforeunload and pagehide,
   * which Chromium fires as soon as a navigation starts - so a reload that has
   * been asked for but not yet committed is seen even from the old document.
   */
  async markDocument() {
    await this.page.evaluate(() => {
      window.__personsDocumentMark = true;
      const clear = () => {
        window.__personsDocumentMark = false;
      };
      window.addEventListener('beforeunload', clear);
      window.addEventListener('pagehide', clear);
    });
  }

  /**
   * Whether the document markDocument() marked is still the one loaded.
   *
   * A context destroyed under the call is a navigation too, and answers false.
   */
  async sameDocument() {
    try {
      return await this.page.evaluate(() => window.__personsDocumentMark === true);
    } catch (error) {
      if (/Execution context was destroyed|navigation/i.test(String(error))) {
        return false;
      }
      throw error;
    }
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

  /**
   * Types a name into the picker and waits for the option carrying that name.
   *
   * Not merely for *an* option: the picker opens showing the most recently used
   * persons, so on an install that has any, the first option is already there
   * before a keystroke is read. Waiting for that one would leave the highlight
   * on somebody else's name, and Enter would commit them.
   *
   * @param {string} name
   */
  async typeName(name) {
    await this.pickerInput.fill(name);
    await this.pickerOption(name).waitFor();
  }

  /**
   * The picker entry that commits a given name - an existing person or the
   * create-new entry, both of which carry it in data-persons-name.
   *
   * @param {string} name
   */
  pickerOption(name) {
    return this.page.locator(
      `#persons-picker-list .persons-picker-option[data-persons-name="${name}"]`
    );
  }

  /**
   * The picker entry for a person who already exists, as opposed to the
   * create-new escape hatch, which carries the same name in the same attribute.
   * Telling them apart is the whole point of the reuse spec: committing the
   * create entry would make a second person of the same name.
   *
   * @param {string} name
   */
  existingPickerOption(name) {
    return this.page.locator(
      `#persons-picker-list .persons-picker-option[data-persons-name="${name}"]:not(.persons-picker-create)`
    );
  }

  /**
   * Types a search fragment and waits for the existing person it should match.
   *
   * Separate from typeName(): that one types a whole name and accepts either
   * entry, which is right when the person is being created.
   *
   * @param {string} fragment
   * @param {string} name
   */
  async searchForExisting(fragment, name) {
    await this.pickerInput.fill(fragment);
    await this.existingPickerOption(name).waitFor();
  }

  /** The region ids of the boxes the server has saved, in DOM order. */
  async savedRegionIds() {
    return this.savedBoxes.evaluateAll((els) =>
      els.map((el) => el.getAttribute('data-person-region'))
    );
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
