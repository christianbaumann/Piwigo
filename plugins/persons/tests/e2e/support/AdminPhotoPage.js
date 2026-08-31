// @ts-check
const { PicturePage } = require('./PicturePage');

/**
 * Page object for the admin tagging screen.
 *
 * The screen renders the same stage, overlay and editor markup as the public
 * picture page - that is the point of it: one overlay implementation, two
 * surfaces - so every locator is inherited. What differs is the URL and the
 * link that leads there.
 */
class AdminPhotoPage extends PicturePage {
  /** @param {import('@playwright/test').Page} page */
  constructor(page) {
    super(page);

    /** The link this screen is reached by, injected into the photo screen's action bar. */
    this.photoScreenLink = page.locator('#persons-admin-photo-link');
  }

  /**
   * The screen for one photo.
   *
   * @param {number} photoId
   */
  static url(photoId) {
    return `/admin.php?page=plugin-persons&image_id=${photoId}`;
  }

  /** The core photo properties screen the link is injected into. */
  static photoScreenUrl(photoId) {
    return `/admin.php?page=photo-${photoId}`;
  }

  /**
   * @param {number} photoId
   * @returns {Promise<import('@playwright/test').Response|null>}
   */
  async open(photoId) {
    const response = await this.page.goto(AdminPhotoPage.url(photoId));
    await this.page.waitForLoadState('domcontentloaded');
    return response;
  }

  /** @param {number} photoId */
  async openPhotoScreen(photoId) {
    await this.page.goto(AdminPhotoPage.photoScreenUrl(photoId));
    await this.page.waitForLoadState('domcontentloaded');
  }

  /**
   * A box's geometry as fractions of the photo's rendered size.
   *
   * The frame of reference both surfaces have in common: they show different
   * derivatives at different sizes, so only fractions can be compared between
   * them.
   *
   * @param {number} regionId
   */
  async boxFractions(regionId) {
    const image = await this.imageRect();
    const box = await this.boxRect(regionId);

    return {
      left: (box.left - image.left) / image.width,
      top: (box.top - image.top) / image.height,
      w: box.width / image.width,
      h: box.height / image.height,
      imageWidth: image.width,
    };
  }
}

module.exports = { AdminPhotoPage };
