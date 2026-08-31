#!/usr/bin/env node
/**
 * Screenshot run for the German end-user handbook.
 *
 * Every image under docs/handbuch/assets/screenshots/ is produced here, so a
 * screen that changes is re-photographed by one command instead of rotting in
 * the pages that show it.
 *
 * It photographs the demo album and nothing else. The gallery this repository
 * points at holds recovered family scans of identifiable private people, so
 * every shot is an element screenshot with the demo content in frame rather
 * than a full page that could catch a real thumbnail in a sidebar.
 *
 * Usage:
 *   ddev exec php docs/handbuch/tools/seed.php --scenario=demo
 *   ddev exec bash -c 'set -a; . local/config/persons-test.env; set +a; \
 *     node docs/handbuch/tools/shoot.js'
 *
 * It reads the ids and face boxes the seed wrote to _data/handbuch/demo.json;
 * it never recomputes a box from the scene table, so the drawn face and the
 * rectangle a screenshot drags over cannot drift apart.
 *
 * It signs in with the persons suite's test accounts, never a human's: the
 * webmaster for the administration screens, persons_normal for the public
 * page, because the overlay and the tag badges are shown to any logged-in
 * non-guest and shooting them as an administrator would hide a permission
 * mistake.
 *
 * No pixel comparison and no baseline. Rejected as flaky for a photo gallery
 * (docs/agents/TESTING.md:428). What is checked is that every declared file
 * was written, is non-empty, and that nothing else appeared beside them.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { createRequire } = require('module');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const OUT_DIR = path.join(ROOT, 'docs', 'handbuch', 'assets', 'screenshots');
const DEMO_FILE = path.join(ROOT, '_data', 'handbuch', 'demo.json');

/**
 * Playwright is not a dependency of the handbook.
 *
 * The three plugin suites already carry it and share one pinned browser cache
 * (PLAYWRIGHT_BROWSERS_PATH in .ddev/config.yaml). Resolving it from the
 * persons plugin adds no download and no package.json under docs/.
 */
const PLAYWRIGHT_HOST = path.join(ROOT, 'plugins', 'persons');

/** The only host that answers from inside the web container. */
const BASE_URL = process.env.HANDBUCH_BASE_URL || 'http://localhost';

/** Matches the plugins' Desktop Chrome default, so shots are one width. */
const VIEWPORT = { width: 1280, height: 720 };

const NAV_TIMEOUT = 20000;
const ELEMENT_TIMEOUT = 15000;

/**
 * Where a photo lives on this install.
 *
 * Any image URL under one of these is gallery content. Everything else a page
 * loads - theme sprites, fontello, data: URIs - is chrome, and irrelevant to
 * whether a private face is in frame.
 */
const PHOTO_PATH_MARKERS = ['/galleries/', '/upload/', '/_data/i/'];

/**
 * The run must photograph at least this many frames that actually hold a photo.
 *
 * Without it the frame guard below is satisfied by twenty screenshots of empty
 * forms: it would report that no foreign photo was found because it found no
 * photo at all.
 */
const MIN_SHOTS_WITH_A_PHOTO = 5;

/**
 * The person a screenshot creates through the picker.
 *
 * Declared in seed.php as SHOOT_PERSONS and removed unconditionally by
 * --restore. Kept in step with that constant by hand; the shot below cancels
 * out of the picker rather than committing, so nothing is written today, but a
 * name typed into the picker is what the screenshot shows and --restore is the
 * only thing that would clean it up if it ever were.
 */
const SHOOT_PERSON = 'Clara Beispiel';

// ------------------------------------------------------------------ helpers

function fail(message) {
  process.stderr.write(message + '\n');
  process.exit(1);
}

function loadPlaywright() {
  const requireFromPlugin = createRequire(path.join(PLAYWRIGHT_HOST, 'package.json'));
  try {
    return requireFromPlugin('playwright');
  } catch (error) {
    fail(
      'Playwright is not installed in plugins/persons. Run:\n'
      + "  ddev exec bash -c 'cd plugins/persons && npm install'"
    );
  }
}

function loadDemo() {
  if (!fs.existsSync(DEMO_FILE)) {
    fail(
      'No demo album is seeded: ' + DEMO_FILE + ' is missing. Run:\n'
      + '  ddev exec php docs/handbuch/tools/seed.php --scenario=demo'
    );
  }

  const demo = JSON.parse(fs.readFileSync(DEMO_FILE, 'utf8'));
  if (!demo.album_id || !demo.photos || Object.keys(demo.photos).length === 0) {
    fail(DEMO_FILE + ' names no album or no photos; re-run the seed');
  }

  return demo;
}

function credentials(role) {
  const user = process.env['PERSONS_TEST_' + role + '_USERNAME'];
  const password = process.env['PERSONS_TEST_' + role + '_PASSWORD'];

  if (!user || !password) {
    fail(
      'Missing PERSONS_TEST_' + role + '_USERNAME / PERSONS_TEST_' + role + '_PASSWORD. '
      + 'Run `ddev exec php plugins/persons/tests/Support/create-test-users.php`, then '
      + 'source local/config/persons-test.env before running the screenshot script.'
    );
  }

  return { user, password };
}

/** Signs in the way plugins/persons/tests/e2e/auth.setup.js does. */
async function signIn(page, role) {
  const { user, password } = credentials(role);

  await page.goto(BASE_URL + '/identification.php');
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="password"]', password);
  await page.click('input[name="login"]');

  // The form is gone on success; on a wrong password it is still there.
  await page.waitForSelector('input[name="username"]', { state: 'detached', timeout: NAV_TIMEOUT });
}

async function open(page, url) {
  await page.goto(BASE_URL + url, { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT });
}

/**
 * Writes one element to disk.
 *
 * An element screenshot, never a full page: it keeps the frame on the demo
 * content, and it throws when the locator is not there rather than writing a
 * blank image of whatever the page happened to render.
 */
async function shoot(locator, file, imageDir) {
  await locator.waitFor({ state: 'visible', timeout: ELEMENT_TIMEOUT });
  await locator.scrollIntoViewIfNeeded();
  await assertNoForeignPhoto(locator, imageDir, file);
  await locator.screenshot({ path: path.join(OUT_DIR, file) });
}

/** Frames photographed so far that held at least one gallery photo. */
let framesHoldingAPhoto = 0;

/**
 * Refuses to photograph a frame holding a photo from outside the demo album.
 *
 * This is the one failure that cannot be undone once the handbook is
 * published: the gallery holds recovered family scans of identifiable private
 * people, and a screenshot of a screen that happens to carry a sidebar
 * thumbnail, a representative, or a recently-added strip publishes a face.
 * Every image the frame loads is checked, `src` and CSS background alike,
 * against the directory the seed reports.
 *
 * The by-eye review stays in the ledger - what a drawn shape evokes has no
 * oracle - but which file is on screen does, and this is it.
 */
async function assertNoForeignPhoto(locator, imageDir, file) {
  const found = await locator.evaluate((root, markers) => {
    const urls = [];
    const nodes = [root, ...root.querySelectorAll('*')];

    for (const node of nodes) {
      if (node.tagName === 'IMG' && node.currentSrc) {
        urls.push(node.currentSrc);
      }
      const background = window.getComputedStyle(node).backgroundImage;
      for (const match of background.matchAll(/url\(["']?([^"')]+)["']?\)/g)) {
        urls.push(match[1]);
      }
    }

    return urls.filter((url) => markers.some((marker) => url.includes(marker)));
  }, PHOTO_PATH_MARKERS);

  const foreign = found.filter((url) => !url.includes(imageDir));
  if (foreign.length > 0) {
    throw new Error(
      'a photo from outside the demo album is in frame: ' + foreign.join(', ')
    );
  }

  if (found.length > 0) {
    framesHoldingAPhoto += 1;
  }
}

/**
 * Unpins the sticky save bar for one shot.
 *
 * The admin forms pin their save bar to the bottom of the viewport. An element
 * screenshot renders the whole form at its full height, and the pinned bar
 * then lands across the middle of the image, over the fields it is not
 * covering in a real browser. Making it flow with the form is what a reader
 * scrolling the page actually sees.
 */
async function unpinSaveBar(page) {
  await page.addStyleTag({
    content: '.savebar-footer, .cat-modify-footer { position: static !important; }',
  });
}

/**
 * Dismisses the mobile-apps promotion on the upload screen.
 *
 * It is upstream marketing, it pulls an image from sandbox.piwigo.com, and it
 * fills the top third of the screen the handbook is describing. Dismissed the
 * way a user dismisses it, which stores a preference on the test account, so a
 * second run finds the screen already clean.
 */
async function dismissPromotion(page) {
  const closer = page.locator('.promote-apps .dont-show-again');
  if (await closer.count() === 0) {
    return;
  }
  await closer.click();
  await page.waitForSelector('.promote-apps', { state: 'hidden', timeout: ELEMENT_TIMEOUT });

  // Clicking it left the pointer on a tiptip, which would hang in the corner
  // of the shot as a tooltip for a control that is no longer there.
  await page.mouse.move(0, 0);
  await page.waitForFunction(() => {
    const tip = document.querySelector('#tiptip_holder');
    return tip === null || window.getComputedStyle(tip).display === 'none';
  }, null, { timeout: ELEMENT_TIMEOUT });
}

/**
 * Waits until an element has finished fading in.
 *
 * The admin modals animate their opacity. A screenshot taken the moment the
 * element becomes visible catches it half transparent, which reads as a
 * rendering fault in a handbook rather than as a dialog.
 */
async function waitForOpaque(page, selector) {
  await page.waitForFunction((sel) => {
    const element = document.querySelector(sel);
    if (element === null) {
      return false;
    }
    let node = element;
    while (node !== null && node.nodeType === 1) {
      if (parseFloat(window.getComputedStyle(node).opacity) < 1) {
        return false;
      }
      node = node.parentElement;
    }
    return true;
  }, selector, { timeout: ELEMENT_TIMEOUT });
}

/**
 * Waits until the persons overlay sits exactly on the photo.
 *
 * The theme swaps derivatives and overlay.js debounces its redraw, so a check
 * that merely asks whether the overlay is present is satisfied by the layout
 * from before the image loaded.
 */
async function waitForOverlayPlacement(page) {
  await page.waitForFunction(() => {
    const image = document.querySelector('#theMainImage');
    const overlay = document.querySelector('#persons-overlay');
    if (!image || !overlay || !image.complete || image.naturalWidth === 0) {
      return false;
    }
    const a = image.getBoundingClientRect();
    const b = overlay.getBoundingClientRect();
    return a.width > 0 && Math.abs(a.width - b.width) < 1 && Math.abs(a.height - b.height) < 1
      && Math.abs(a.left - b.left) < 1 && Math.abs(a.top - b.top) < 1;
  }, null, { timeout: ELEMENT_TIMEOUT });
}

/** Drags a rectangle over one of the drawn faces, in rendered pixels. */
async function dragOverFace(page, face) {
  const box = await page.locator('#theMainImage').boundingBox();
  if (box === null) {
    throw new Error('the photo has no bounding box; the overlay cannot be drawn on');
  }

  const left = box.x + (face.x - face.w / 2) * box.width;
  const top = box.y + (face.y - face.h / 2) * box.height;

  await page.mouse.move(left, top);
  await page.mouse.down();
  await page.mouse.move(left + face.w * box.width, top + face.h * box.height, { steps: 12 });
  await page.mouse.up();
}

// -------------------------------------------------------------- the shot set

/**
 * One entry per committed screenshot.
 *
 * `role` picks the session: WEBMASTER for administration screens, NORMAL for
 * the public page. The order is the order the handbook reads in.
 */
const SHOTS = [
  {
    file: '01-alben-verwaltung.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=albums');
      await page.waitForSelector('.tree .jqtree-element', { timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#content'), this.file, demo.image_dir);
    },
  },
  {
    file: '02-album-hinzufuegen.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=albums');
      await page.waitForSelector('.tree .jqtree-element', { timeout: ELEMENT_TIMEOUT });
      await page.click('.add-album-button label');
      await waitForOpaque(page, '.AddAlbumPopInContainer');
      await shoot(page.locator('.AddAlbumPopInContainer'), this.file, demo.image_dir);
    },
  },
  {
    file: '03-album-eigenschaften.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=album-' + demo.album_id + '-properties');
      await page.waitForFunction(
        (name) => document.querySelector('#cat-name') !== null
          && document.querySelector('#cat-name').value === name,
        demo.album_name,
        { timeout: ELEMENT_TIMEOUT }
      );
      await shoot(page.locator('#cat-modify'), this.file, demo.image_dir);
    },
  },
  {
    file: '04-album-oeffentlich.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, '/index.php?/category/' + demo.album_id);
      const count = Object.keys(demo.photos).length;
      await page.waitForFunction(
        (n) => document.querySelectorAll('#content .thumbnails li').length === n,
        count,
        { timeout: ELEMENT_TIMEOUT }
      );
      await shoot(page.locator('#content'), this.file, demo.image_dir);
    },
  },
  {
    file: '05-fotos-hochladen.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=photos_add&album=' + demo.album_id);
      await page.waitForSelector('#uploadForm', { state: 'visible', timeout: ELEMENT_TIMEOUT });
      await dismissPromotion(page);
      await shoot(page.locator('#photosAddContent'), this.file, demo.image_dir);
    },
  },
  {
    file: '06-fotos-album-waehlen.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=photos_add&album=' + demo.album_id);
      await page.waitForSelector('#selectedAlbum', { state: 'visible', timeout: ELEMENT_TIMEOUT });
      await dismissPromotion(page);
      // The pencil beside the selected album opens the album selector
      // (album_selector.js:135, mounted on #linkedAlbumSelector).
      await page.click('#selectedAlbumEdit');
      await page.waitForSelector('.linkedAlbumPopInContainer', { state: 'visible', timeout: ELEMENT_TIMEOUT });
      await page.waitForSelector('#searchResult .search-result-item', { timeout: ELEMENT_TIMEOUT });
      await waitForOpaque(page, '.linkedAlbumPopInContainer');
      await shoot(page.locator('.linkedAlbumPopInContainer'), this.file, demo.image_dir);
    },
  },
  {
    file: '07-stapelverarbeitung.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=batch_manager&filter=album-' + demo.album_id);
      const count = Object.keys(demo.photos).length;
      await page.waitForFunction(
        (n) => document.querySelectorAll('.thumbnails li').length === n,
        count,
        { timeout: ELEMENT_TIMEOUT }
      );
      await shoot(page.locator('#batchManagerGlobal'), this.file, demo.image_dir);
    },
  },
  {
    file: '08-stapelverarbeitung-zuordnen.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=batch_manager&filter=album-' + demo.album_id);
      await page.waitForSelector('.thumbnails li', { timeout: ELEMENT_TIMEOUT });
      await page.click('#selectAll');
      await page.selectOption('select[name="selectAction"]', 'associate');
      await page.waitForSelector('#action_associate', { state: 'visible', timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#action'), this.file, demo.image_dir);
    },
  },
  {
    file: '09-foto-eigenschaften.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=photo-' + demo.photos.sommerfest.id + '-properties');
      await page.waitForFunction(
        (title) => {
          const field = document.querySelector('#pictureModify input[name="name"]');
          return field !== null && field.value === title;
        },
        demo.photos.sommerfest.title,
        { timeout: ELEMENT_TIMEOUT }
      );
      await unpinSaveBar(page);
      await shoot(page.locator('#picture-content'), this.file, demo.image_dir);
    },
  },
  {
    file: '10-foto-notiz.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=photo-' + demo.photos.sommerfest.id + '-properties');
      await shoot(page.locator('#provenance-photo'), this.file, demo.image_dir);
    },
  },
  {
    file: '11-foto-oeffentlich.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.sommerfest.picture_path);
      await waitForOverlayPlacement(page);
      await shoot(page.locator('#content'), this.file, demo.image_dir);
    },
  },
  {
    file: '12-schlagworte-verwaltung.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      // The unused-tags warning and the search box overlap at 1280; the header
      // row needs more width to render without clipping its own text.
      await page.setViewportSize({ width: 1600, height: VIEWPORT.height });
      await open(page, '/admin.php?page=tags');
      await page.waitForSelector('.tag-name', { timeout: ELEMENT_TIMEOUT });
      // The list renders before its spinner is taken down; shooting earlier
      // leaves a loading indicator sitting over a tag name.
      await page.waitForSelector('.pageLoad', { state: 'hidden', timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#content'), this.file, demo.image_dir);
      await page.setViewportSize(VIEWPORT);
    },
  },
  {
    file: '13-schlagworte-zuordnen.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=photo-' + demo.photos.sommerfest.id + '-properties');
      // The selectize widget replaces the select, so wait for what it renders.
      await page.waitForSelector('select[name="tags[]"] + .selectize-control .item', {
        timeout: ELEMENT_TIMEOUT,
      });
      await shoot(
        page.locator('select[name="tags[]"]').locator('xpath=ancestor::p[1]'),
        this.file,
        demo.image_dir
      );
    },
  },
  {
    file: '14-schlagwort-badges.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.sommerfest.picture_path);
      await page.waitForSelector('#Tags dd a[data-tag-id]', { timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#Tags'), this.file, demo.image_dir);
    },
  },
  {
    file: '15-schlagwort-hinzufuegen.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.sommerfest.picture_path);
      await page.waitForSelector('#typetags-unassigned .typetag-add', { timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#typetags-unassigned'), this.file, demo.image_dir);
    },
  },
  {
    file: '16-personen-boxen.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.sommerfest.picture_path);
      await waitForOverlayPlacement(page);
      const boxes = page.locator('#persons-overlay .person-box[data-person-region]');
      await boxes.first().waitFor({ state: 'attached', timeout: ELEMENT_TIMEOUT });
      const found = await boxes.count();
      if (found !== demo.persons.length) {
        throw new Error('expected ' + demo.persons.length + ' region boxes, found ' + found);
      }
      // The boxes are transparent until the photo is hovered.
      await page.locator('#persons-stage').scrollIntoViewIfNeeded();
      await page.locator('#theMainImage').hover();
      await shoot(page.locator('#persons-stage'), this.file, demo.image_dir);
    },
  },
  {
    file: '17-personen-markieren.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.atelier.picture_path);
      await waitForOverlayPlacement(page);
      await page.click('#persons-tag-toggle');
      await page.waitForSelector('#persons-stage.persons-tagging', { timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#persons-stage'), this.file, demo.image_dir);
    },
  },
  {
    file: '18-personen-rechteck.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.atelier.picture_path);
      await waitForOverlayPlacement(page);
      await page.click('#persons-tag-toggle');
      await page.waitForSelector('#persons-stage.persons-tagging', { timeout: ELEMENT_TIMEOUT });
      await dragOverFace(page, demo.photos.atelier.faces[0]);
      await page.waitForSelector('#persons-overlay .person-draft', { timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#persons-stage'), this.file, demo.image_dir);
      await page.keyboard.press('Escape');
    },
  },
  {
    file: '19-personen-auswahl.png',
    role: 'NORMAL',
    async take(page, demo) {
      await open(page, demo.photos.atelier.picture_path);
      await waitForOverlayPlacement(page);
      await page.click('#persons-tag-toggle');
      await page.waitForSelector('#persons-stage.persons-tagging', { timeout: ELEMENT_TIMEOUT });
      await dragOverFace(page, demo.photos.atelier.faces[0]);
      await page.waitForSelector('#persons-picker', { state: 'visible', timeout: ELEMENT_TIMEOUT });
      await page.fill('#persons-picker-input', SHOOT_PERSON);
      await page.waitForSelector(
        '#persons-picker-list .persons-picker-option[data-persons-name="' + SHOOT_PERSON + '"]',
        { timeout: ELEMENT_TIMEOUT }
      );
      await shoot(page.locator('#persons-picker'), this.file, demo.image_dir);
      // Cancelled, not committed: the handbook shows the picker, it does not
      // need a person written into the demo photo's file.
      await page.keyboard.press('Escape');
    },
  },
  {
    file: '20-personen-verwaltung.png',
    role: 'WEBMASTER',
    async take(page, demo) {
      await open(page, '/admin.php?page=plugin-persons');
      await page.waitForSelector('#persons-table tbody tr', { timeout: ELEMENT_TIMEOUT });
      await shoot(page.locator('#content'), this.file, demo.image_dir);
    },
  },
];

// ------------------------------------------------------------------- the run

/**
 * Every file the run must have written, and nothing else.
 *
 * Re-running overwrites in place; a shot renamed in SHOTS without its old file
 * being deleted shows up here rather than accumulating silently beside the set
 * the handbook shows.
 */
function assertOutput() {
  const expected = SHOTS.map((shot) => shot.file).sort();
  const actual = fs.readdirSync(OUT_DIR).filter((name) => name.endsWith('.png')).sort();

  if (expected.length === 0) {
    fail('the shot list is empty; there is nothing to verify');
  }

  for (const file of expected) {
    const full = path.join(OUT_DIR, file);
    if (!fs.existsSync(full)) {
      fail('missing screenshot: ' + file);
    }
    if (fs.statSync(full).size === 0) {
      fail('empty screenshot: ' + file);
    }
  }

  if (framesHoldingAPhoto < MIN_SHOTS_WITH_A_PHOTO) {
    fail(
      'anti-vacuity: only ' + framesHoldingAPhoto + ' of ' + expected.length
      + ' frames held a photo at all, so the foreign-photo guard checked almost nothing'
    );
  }

  const extra = actual.filter((name) => !expected.includes(name));
  if (extra.length > 0) {
    fail('unexpected files in ' + OUT_DIR + ': ' + extra.join(', '));
  }
}

async function main() {
  const { chromium } = loadPlaywright();
  const demo = loadDemo();

  fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch();
  const sessions = {};

  try {
    for (const role of ['WEBMASTER', 'NORMAL']) {
      const context = await browser.newContext({ viewport: VIEWPORT });
      const page = await context.newPage();
      page.setDefaultTimeout(ELEMENT_TIMEOUT);
      await signIn(page, role);
      sessions[role] = page;
    }

    for (const shot of SHOTS) {
      try {
        await shot.take(sessions[shot.role], demo);
      } catch (error) {
        fail(shot.file + ': ' + error.message);
      }
      process.stdout.write('wrote ' + shot.file + '\n');
    }
  } finally {
    await browser.close();
  }

  assertOutput();
  process.stdout.write(
    SHOTS.length + ' screenshots in docs/handbuch/assets/screenshots/, '
    + framesHoldingAPhoto + ' of them holding a photo, none from outside the demo album\n'
  );
}

main().catch((error) => fail(error.stack || String(error)));
