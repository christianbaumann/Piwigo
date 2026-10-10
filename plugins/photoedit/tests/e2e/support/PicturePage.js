// @ts-check

/**
 * Page object for the public photo page with the edit controls on it.
 *
 * Every locator the specs use lives here; a locator in a spec file is a bug.
 */
class PicturePage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    this.page = page;

    this.image = page.locator('#theMainImage');
    /** The theme's next link; with no next photo it leads back to the album. */
    this.nextLink = page.locator('#linkNext');
    /** The toolbar's arrow to the next photo; a link only when there is one. */
    this.nextArrow = page.locator('#imageToolBar .navigationButtons a.pwg-button:has(.pwg-icon-arrow-e)');
    /** The toolbar's arrow to the previous photo; a link only when there is one. */
    this.previousArrow = page.locator('#imageToolBar .navigationButtons a.pwg-button:has(.pwg-icon-arrow-w)');

    this.editButton = page.locator('#photoedit-toggle');
    this.controls = page.locator('#photoedit-controls');
    this.turnLeftButton = page.locator('#photoedit-turn-left');
    this.turnRightButton = page.locator('#photoedit-turn-right');
    this.saveButton = page.locator('#photoedit-save');
    this.cancelButton = page.locator('#photoedit-cancel');
    /** The Jcrop frame editor.js lays over the preview. */
    this.cropFrame = page.locator('#photoedit-frame');
    /** <html> while edit mode is on. */
    this.editMode = page.locator('html.photoedit-active');

    /** The theme's zoom control, hidden in edit mode. */
    this.zoomFitButton = page.locator('#zoomFit');

    /** The persons plugin's overlay and its tagging toggle, hidden in edit mode. */
    this.personsOverlay = page.locator('#persons-overlay');
    this.personsEditor = page.locator('#persons-editor');
    this.personsTagToggle = page.locator('#persons-tag-toggle');
    /** The persons stage only while its tagging mode is on. */
    this.personsTagging = page.locator('#persons-stage.persons-tagging');
    this.personsPickerInput = page.locator('#persons-picker-input');
    /** The persons boxes that exist on the server; a draft has no region id yet. */
    this.personsSavedBoxes = page.locator('#persons-overlay .person-box[data-person-region]');

    /** The ellipsis that opens modus' collapsed action menu on a narrow screen. */
    this.actionMenuSwitch = page.locator('#imageActionsSwitch');
    /** The page header, far from the action menu; hovering it closes that menu. */
    this.header = page.locator('#imageHeaderBar');
  }

  /** @param {string} path the picture_path the seed printed */
  async goto(path) {
    await this.page.goto(path);
    await this.waitForPhoto();
  }

  /**
   * Waits until the photo has loaded and the theme has sized it.
   *
   * The theme first shows a loading GIF in the same element; a measurement taken
   * before the real file arrives reads the GIF's size.
   */
  async waitForPhoto() {
    await this.image.waitFor({ state: 'visible' });
    await this.page.waitForFunction(() => {
      const img = /** @type {HTMLImageElement|null} */ (document.getElementById('theMainImage'));
      return !!img && img.complete && !/ajax_loader/.test(img.src) && img.getBoundingClientRect().width > 1;
    });
  }

  /** The size of the file the photo element shows, as the browser decoded it. */
  async naturalSize() {
    return this.image.evaluate((img) => {
      const el = /** @type {HTMLImageElement} */ (img);
      return { width: el.naturalWidth, height: el.naturalHeight };
    });
  }

  /** The URL the photo element loaded. */
  async imageSrc() {
    return this.image.evaluate((img) => /** @type {HTMLImageElement} */ (img).currentSrc);
  }

  /** The inline transform the editor's turn preview put on the photo. */
  async previewTransform() {
    return this.image.evaluate((img) => /** @type {HTMLElement} */ (img).style.transform);
  }

  /**
   * Holds every pwg.photoedit.apply request until the returned function is
   * called, so a spec can act while a save is in flight.
   *
   * @returns {Promise<() => void>} releases the held requests
   */
  async holdApplyRequests() {
    /** @type {(() => void)[]} */
    const held = [];
    let released = false;
    await this.page.route('**/ws.php*', async (route) => {
      const body = route.request().postData() || '';
      if (!released && body.includes('method=pwg.photoedit.apply')) {
        await new Promise((resolve) => held.push(() => resolve(undefined)));
      }
      await route.continue();
    });
    return () => {
      released = true;
      held.splice(0).forEach((release) => release());
    };
  }

  /**
   * Lets the write reach the server, then hands the editor a response it cannot
   * read - what a PHP notice printed into the JSON looks like.
   */
  async garbleWriteResponse() {
    await this.page.route('**/ws.php*', async (route) => {
      const body = route.request().postData() || '';
      if (body.includes('method=pwg.photoedit.apply') && !body.includes('dry_run')) {
        const response = await route.fetch();
        await route.fulfill({ response, body: '<b>Notice</b>: something' + (await response.text()) });
        return;
      }
      await route.continue();
    });
  }

  /** Clicks Save and waits for the reload it ends in, and for the new photo. */
  async saveAndWaitForReload() {
    await Promise.all([
      this.page.waitForEvent('load'),
      this.saveButton.click(),
    ]);
    await this.waitForPhoto();
  }

  /**
   * The elements stacked above the photo at one point of the crop frame that
   * paint a background, so hide or darken the photo there. Read from the
   * rendered page (elementsFromPoint and computed styles), not from Jcrop's
   * options.
   *
   * @param {number} fx the point, as a fraction of the frame's width
   * @param {number} fy and of its height
   * @returns {Promise<string[]>} one 'tag.class: colour' per covering element
   */
  async layersCoveringPhotoAt(fx, fy) {
    const frame = await this.cropFrame.boundingBox();
    if (!frame) {
      throw new Error('no crop frame to look through');
    }
    return this.page.evaluate(({ x, y }) => {
      const covering = [];
      for (const el of document.elementsFromPoint(x, y)) {
        if (el.id === 'theMainImage') {
          return covering;
        }
        const colour = getComputedStyle(el).backgroundColor;
        const alpha = /rgba\([^)]*,\s*([\d.]+)\)/.exec(colour);
        const transparent = colour === 'transparent' || (alpha !== null && Number(alpha[1]) === 0);
        if (!transparent) {
          covering.push(`${el.tagName.toLowerCase()}.${el.className}: ${colour}`);
        }
      }
      throw new Error('the photo is not under that point of the frame at all');
    }, { x: frame.x + frame.width * fx, y: frame.y + frame.height * fy });
  }

  /** @param {'n'|'e'|'s'|'w'} edge */
  cropHandle(edge) {
    return this.cropFrame.locator(`.jcrop-handle.ord-${edge}`);
  }

  /**
   * Drags one edge of the crop frame to a fraction of the frame's width (e, w)
   * or height (n, s).
   *
   * @param {'n'|'e'|'s'|'w'} edge
   * @param {number} fraction
   */
  async dragCropEdge(edge, fraction) {
    const frame = await this.cropFrame.boundingBox();
    const handle = await this.cropHandle(edge).boundingBox();
    if (!frame || !handle) {
      throw new Error(`no crop frame or no ${edge} handle to drag`);
    }
    const from = { x: handle.x + handle.width / 2, y: handle.y + handle.height / 2 };
    const to = edge === 'e' || edge === 'w'
      ? { x: frame.x + frame.width * fraction, y: from.y }
      : { x: from.x, y: frame.y + frame.height * fraction };
    await this.page.mouse.move(from.x, from.y);
    await this.page.mouse.down();
    await this.page.mouse.move(to.x, to.y, { steps: 5 });
    await this.page.mouse.up();
  }

  /**
   * The persons picker entry that commits a given name, existing person or new.
   *
   * @param {string} name
   */
  personsPickerOption(name) {
    return this.page.locator(`#persons-picker-list .persons-picker-option[data-persons-name="${name}"]`);
  }

  /**
   * Tags one person in persons' tagging mode, which must be on: drags a box over
   * the photo, names it and waits until the server has saved it.
   *
   * @param {{left: number, top: number, w: number, h: number}} box fractions of the rendered photo
   * @param {string} name
   */
  async tagPerson(box, name) {
    const image = await this.image.boundingBox();
    if (!image) {
      throw new Error('the photo has no box to draw on');
    }
    await this.page.mouse.move(image.x + box.left * image.width, image.y + box.top * image.height);
    await this.page.mouse.down();
    await this.page.mouse.move(
      image.x + (box.left + box.w) * image.width,
      image.y + (box.top + box.h) * image.height,
      { steps: 10 }
    );
    await this.page.mouse.up();
    await this.personsPickerInput.fill(name);
    await this.personsPickerOption(name).click();
    await this.personsSavedBoxes.first().waitFor();
  }

  /**
   * Marks the document currently loaded, so a later navigation can be told
   * apart from none at all: a reload replaces the window object and the mark
   * with it, and beforeunload and pagehide clear it as soon as one starts.
   */
  async markDocument() {
    await this.page.evaluate(() => {
      const w = /** @type {any} */ (window);
      w.__photoeditDocumentMark = true;
      const clear = () => {
        w.__photoeditDocumentMark = false;
      };
      window.addEventListener('beforeunload', clear);
      window.addEventListener('pagehide', clear);
    });
  }

  /**
   * Clicks the edit button where it is expected to reload the page instead of
   * opening edit mode, and waits for the reload. The load event is listened for
   * before the click, so a reload over before the click resolves is not missed.
   */
  async clickEditAndAwaitReload() {
    const loaded = this.page.waitForEvent('load');
    await this.editButton.click();
    await loaded;
    await this.waitForPhoto();
  }

  /**
   * Counts the writes the editor sends from now on: pwg.photoedit.apply
   * requests that are not a dry run.
   *
   * @returns {() => number} the count so far
   */
  watchWrites() {
    let writes = 0;
    this.page.on('request', (request) => {
      const body = request.postData() || '';
      if (body.includes('method=pwg.photoedit.apply') && !body.includes('dry_run')) {
        writes++;
      }
    });
    return () => writes;
  }

  /** Whether the document markDocument() marked is still the one loaded. */
  async sameDocument() {
    try {
      return await this.page.evaluate(() => /** @type {any} */ (window).__photoeditDocumentMark === true);
    } catch (error) {
      if (/Execution context was destroyed|navigation/i.test(String(error))) {
        return false;
      }
      throw error;
    }
  }

  /** The photo's rendered width in CSS pixels. */
  async imageWidth() {
    return (await this.image.boundingBox())?.width ?? 0;
  }

  /**
   * Clicks the photo in the zone the theme turns into "next".
   *
   * photo.autosize.js navigates on a click in the right 30 % of the photo, more
   * than 15 px below its top edge.
   */
  async clickNextZone() {
    const box = await this.image.boundingBox();
    if (!box) {
      throw new Error('the photo has no box to click');
    }
    await this.page.mouse.click(box.x + box.width * 0.9, box.y + box.height / 2);
  }

  /** Ctrl + one wheel notch up over the middle of the photo. */
  async ctrlWheelOverPhoto() {
    const box = await this.image.boundingBox();
    if (!box) {
      throw new Error('the photo has no box to wheel over');
    }
    await this.page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await this.page.keyboard.down('Control');
    await this.page.mouse.wheel(0, -100);
    await this.page.keyboard.up('Control');
  }
}

module.exports = { PicturePage };
