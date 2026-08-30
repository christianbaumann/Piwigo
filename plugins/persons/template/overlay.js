/**
 * Person region overlay for the public picture page.
 *
 * The boxes themselves are rendered by template/public_overlay.tpl and laid out
 * in percent inside #persons-overlay, so they are correct for any size that
 * element happens to have. This file has exactly one job: keep that element
 * exactly over the photo.
 *
 * The only truthful source of the photo's on-screen box is
 * getBoundingClientRect() on #theMainImage. rvas_choose() rewrites that
 * element's src, width and height on load and on every resize
 * (themes/modus/js/photo.autosize.js:35-100,145), so the width and height
 * attributes - and any measurement cached from them - are stale the moment the
 * window moves.
 *
 * No region math happens here. Every box was rotated into display orientation
 * and converted from MWG's centre origin to a top-left fraction by
 * include/events_public.inc.php, using the pure helpers the unit suite covers.
 */
(function () {
	'use strict';

	/* Long enough that a drag-resize does not relayout on every pixel, short
	   enough that the boxes are never visibly behind the photo. */
	var RESIZE_DEBOUNCE_MS = 60;

	/* Below this the element is not laid out yet (no src, or display:none). */
	var MIN_RENDERED_PX = 1;

	function init() {
		var stage = document.getElementById('persons-stage');
		var overlay = document.getElementById('persons-overlay');
		var image = document.getElementById('theMainImage');

		if (!stage || !overlay || !image) {
			return;
		}

		if (!overlay.querySelector('.person-box')) {
			return;
		}

		function place() {
			var imageRect = image.getBoundingClientRect();
			var stageRect = stage.getBoundingClientRect();

			if (imageRect.width < MIN_RENDERED_PX || imageRect.height < MIN_RENDERED_PX) {
				overlay.hidden = true;
				return;
			}

			overlay.hidden = false;
			overlay.style.left = (imageRect.left - stageRect.left) + 'px';
			overlay.style.top = (imageRect.top - stageRect.top) + 'px';
			overlay.style.width = imageRect.width + 'px';
			overlay.style.height = imageRect.height + 'px';
		}

		var timer = null;
		function placeSoon() {
			window.clearTimeout(timer);
			timer = window.setTimeout(place, RESIZE_DEBOUNCE_MS);
		}

		place();

		/* Fires again on every src rewrite, which is how a derivative switch is
		   normally noticed. */
		image.addEventListener('load', place);
		window.addEventListener('resize', placeSoon);

		if (window.ResizeObserver) {
			/* rvas_choose() can change only the width and height attributes,
			   leaving src alone - no load event, and nothing else would notice
			   that the photo just changed size. */
			new window.ResizeObserver(place).observe(image);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
