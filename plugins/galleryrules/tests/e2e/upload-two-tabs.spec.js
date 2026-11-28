// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const { UploadPage } = require('./support/UploadPage');
const { seedTwoEmpty, trackUploads, albumPhotos, restore } = require('./support/seed');

const UPLOAD_ROUTE = '**/ws.php?*method=pwg.images.upload&*';

/**
 * A PNG of random pixels, large enough that the page sends it in several chunks.
 * Random pixels make the bytes of each file its own, so duplicate detection never links them.
 *
 * @param {string} name
 */
function noisePng(name) {
  const buffer = execFileSync('convert', ['-size', '500x500', 'xc:', '+noise', 'Random', 'png:-'], { maxBuffer: 16 * 1024 * 1024 });
  return { name, mimeType: 'image/png', buffer, md5: crypto.createHash('md5').update(buffer).digest('hex') };
}

/** @param {import('@playwright/test').Request} request */
function chunkIndex(request) {
  const match = /name="chunk"\r\n\r\n(\d+)/.exec(request.postData() ?? '');
  return match ? Number(match[1]) : 0;
}

/**
 * Holds chunk k of each tab until every tab has sent its chunk k, then lets all of them through,
 * so the two uploads are on the server at the same time, chunk by chunk.
 *
 * @param {import('@playwright/test').Page[]} pages
 * @returns {Promise<number[]>} how many upload requests each page sent, read when the test asks
 */
async function interleaveChunks(pages) {
  /** @type {Map<number, import('@playwright/test').Route[]>} */
  const waiting = new Map();
  const sent = pages.map(() => 0);
  await Promise.all(
    pages.map((p, i) =>
      p.route(UPLOAD_ROUTE, (route) => {
        sent[i]++;
        const k = chunkIndex(route.request());
        const held = waiting.get(k) ?? [];
        held.push(route);
        waiting.set(k, held);
        if (held.length === pages.length) {
          waiting.delete(k);
          held.forEach((r) => r.continue());
        }
      })
    )
  );
  return sent;
}

let albums;

test.beforeEach(() => {
  restore();
  albums = seedTwoEmpty().album_ids;
});

test.afterEach(() => {
  albums.forEach((id) => trackUploads(id));
  restore();
});

/**
 * Starts one upload per tab, each to its own album, at the same time, and waits for both to finish.
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @param {{name: string, mimeType: string, buffer: Buffer}[]} files one per album
 */
async function uploadInTwoTabs(context, files) {
  const pages = [await context.newPage(), await context.newPage()];
  const sent = await interleaveChunks(pages);
  const uploads = pages.map((p) => new UploadPage(p));
  for (let i = 0; i < uploads.length; i++) {
    await uploads[i].goto(albums[i]);
    await uploads[i].addFiles([files[i]]);
  }

  await Promise.all(uploads.map((u) => u.startButton.click()));

  for (const u of uploads) {
    await expect(u.uploadedThumbnails).toHaveCount(1, { timeout: 30_000 });
    await expect(u.summary).toBeVisible();
    await expect(u.errors).toHaveCount(0);
  }
  return sent;
}

// [HAPPY] Two tabs of one account upload to two albums at once; each album gets its own file, whole.
test('two tabs upload different files to two albums at the same time', async ({ context }) => {
  test.setTimeout(60_000);
  const files = [noisePng('galleryrules-two-a.png'), noisePng('galleryrules-two-b.png')];

  const sent = await uploadInTwoTabs(context, files);

  sent.forEach((n) => expect(n).toBeGreaterThan(1));
  for (let i = 0; i < albums.length; i++) {
    const { photos, in_lounge } = albumPhotos(albums[i]);
    expect(photos.map((p) => [p.file, p.md5sum])).toEqual([[files[i].name, files[i].md5]]);
    expect(in_lounge).toBe(0);
  }
});

// [ECP] The same file name in both tabs: the server's chunk buffer is keyed by account and file name.
test('two tabs upload different files of one name to two albums at the same time', async ({ context }) => {
  test.setTimeout(60_000);
  const files = [noisePng('galleryrules-two-same.png'), noisePng('galleryrules-two-same.png')];

  const sent = await uploadInTwoTabs(context, files);

  sent.forEach((n) => expect(n).toBeGreaterThan(1));
  for (let i = 0; i < albums.length; i++) {
    const { photos, in_lounge } = albumPhotos(albums[i]);
    expect(photos.map((p) => [p.file, p.md5sum])).toEqual([[files[i].name, files[i].md5]]);
    expect(in_lounge).toBe(0);
  }
});
