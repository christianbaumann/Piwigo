/**
 * Edit mode on the public picture page.
 *
 * While it is on, the page is a work surface rather than a viewer: the photo is
 * fitted and stays fitted, the theme's zoom, pan and click-to-navigate are held
 * back, and the persons overlay is hidden (the class on <html> does the hiding,
 * editor.css says what). Turning only turns the preview, and a Jcrop frame
 * over the turned preview, starting as the whole photo, marks the crop; it
 * turns along with the preview. Nothing is written until Save, which asks pwg.photoedit.apply first what the edit would cost
 * (a dry run), then writes and reloads the page. Cancel puts the page back as
 * it was.
 *
 * The theme's handlers are not unbound - they belong to the theme - but caught
 * on the window in the capture phase, which runs before any of them.
 */
(function () {
	'use strict';

	var ACTIVE = 'photoedit-active';
	/* The theme's zoom keys (themes/modus/js/photo.zoom.js). */
	var ZOOM_KEYS = ['+', '-', '0'];
	/* Core's photo navigation (themes/default/template/picture_nav_buttons.tpl):
	   the arrows on their own, Home, End and Up with Ctrl. */
	var NAV_KEYS = ['ArrowLeft', 'ArrowRight'];
	var CTRL_NAV_KEYS = ['Home', 'End', 'ArrowUp'];
	/* The crop as fractions of the turned photo: the whole photo is no crop. */
	var WHOLE = { l: 0, t: 0, r: 1, b: 1 };
	/* Digits a fraction is sent with: a pixel of a 100-megapixel scan. */
	var CROP_DIGITS = 6;

	function init() {
		var toggle = document.getElementById('photoedit-toggle');
		var image = document.getElementById('theMainImage');
		var area = document.getElementById('theImage');

		if (!toggle) {
			return;
		}

		/* A video or an embedded PDF has no photo to turn. */
		if (!image || !area) {
			toggle.parentNode.removeChild(toggle);
			return;
		}

		var root = document.documentElement;

		function active() {
			return root.classList.contains(ACTIVE);
		}

		/* Quarter turns clockwise the preview shows, 0 to 3. */
		var turns = 0;
		var crop = WHOLE;
		/* The Jcrop frame and the element it is drawn on, while edit mode is on. */
		var frame = null;
		var saving = false;

		/* A quarter turn swaps the photo's sides; shrink it so the turned photo
		   still fits the box the upright one fills. */
		function turnScale() {
			var w = image.offsetWidth;
			var h = image.offsetHeight;
			return turns % 2 === 1 ? Math.min(w / h, h / w) : 1;
		}

		function showTurns() {
			image.style.transform = turns === 0
				? ''
				: 'rotate(' + (turns * 90) + 'deg) scale(' + turnScale() + ')';
		}

		/* Where the turned preview sits on the page, in whole pixels. Worked out
		   rather than measured: the turn transitions, but always about the
		   photo's centre, so the centre of the box it occupies is right at every
		   moment and the size follows from the layout size. */
		function previewBox() {
			var bounds = image.getBoundingClientRect();
			var scale = turnScale();
			var w = image.offsetWidth * scale;
			var h = image.offsetHeight * scale;
			if (turns % 2 === 1) {
				var swap = w;
				w = h;
				h = swap;
			}
			return {
				left: Math.round(bounds.left + bounds.width / 2 - w / 2 + window.scrollX),
				top: Math.round(bounds.top + bounds.height / 2 - h / 2 + window.scrollY),
				width: Math.round(w),
				height: Math.round(h)
			};
		}

		/* The same turn as photoedit_turn_box() on the server. */
		function turnCrop(box, by) {
			for (var i = 0; i < (by + 4) % 4; i++) {
				box = { l: 1 - box.b, t: box.l, r: 1 - box.t, b: box.r };
			}
			return box;
		}

		function removeFrame() {
			if (frame) {
				frame.api.destroy();
				frame.holder.parentNode.removeChild(frame.holder);
				frame = null;
			}
		}

		/* Jcrop draws on an empty element laid over the preview rather than on
		   the photo: it would hide the photo and draw an unturned copy. Laid
		   over the page, outside #theImage, so the theme's handlers there never
		   see a drag on the frame. */
		function drawFrame() {
			removeFrame();
			var box = previewBox();
			var holder = document.createElement('div');
			var target = document.createElement('div');
			holder.id = 'photoedit-frame';
			holder.style.left = box.left + 'px';
			holder.style.top = box.top + 'px';
			target.style.width = box.width + 'px';
			target.style.height = box.height + 'px';
			holder.appendChild(target);
			document.body.appendChild(holder);

			var drawn = crop;
			jQuery(target).Jcrop({
				bgOpacity: 0.5,
				keySupport: false,
				setSelect: [drawn.l * box.width, drawn.t * box.height, drawn.r * box.width, drawn.b * box.height],
				onChange: function (c) {
					crop = {
						l: c.x / box.width,
						t: c.y / box.height,
						r: c.x2 / box.width,
						b: c.y2 / box.height
					};
				},
				/* A click beside the frame drops it: no crop. */
				onRelease: function () {
					crop = WHOLE;
				}
			});
			frame = { holder: holder, api: jQuery(target).data('Jcrop') };
		}

		/* Redrawn once per frame at most: the theme refits the photo on resize. */
		var redrawPending = false;
		function redrawFrame() {
			if (redrawPending) {
				return;
			}
			redrawPending = true;
			window.requestAnimationFrame(function () {
				redrawPending = false;
				if (active()) {
					drawFrame();
				}
			});
		}

		function cropParam() {
			if (crop.l <= 0 && crop.t <= 0 && crop.r >= 1 && crop.b >= 1) {
				return '';
			}
			return [crop.l, crop.t, crop.r, crop.b].map(function (f) {
				return Math.min(1, Math.max(0, f)).toFixed(CROP_DIGITS);
			}).join(',');
		}

		function turn(by) {
			/* Pointer events are off while saving, but a focused control still
			   answers Enter; the turn being written must not change under it. */
			if (saving) {
				return;
			}
			turns = (turns + by + 4) % 4;
			crop = turnCrop(crop, by);
			showTurns();
			drawFrame();
		}

		function post(params) {
			var body = new URLSearchParams(params);
			body.set('method', 'pwg.photoedit.apply');
			body.set('image_id', toggle.dataset.imageId);
			body.set('pwg_token', toggle.dataset.token);
			return fetch(toggle.dataset.wsUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			}).then(function (json) {
				if (!json || json.stat !== 'ok') {
					throw new Error(json && json.message ? json.message : 'pwg.photoedit.apply failed');
				}
				return json.result;
			});
		}

		function save() {
			if (saving) {
				return;
			}
			var requested = turns;
			var requestedCrop = cropParam();
			if (requested === 0 && requestedCrop === '') {
				leave();
				return;
			}
			/* Set once the write is sent: from then on the file may have changed,
			   whatever the response says, so the page is reloaded rather than
			   left offering a second save that would turn it again. */
			var written = false;
			saving = true;
			root.classList.add('photoedit-saving');
			post({ turns: requested, crop: requestedCrop, dry_run: 'true' }).then(function (preview) {
				var lost = preview.lost_regions || [];
				var warnings = [];
				if (lost.length) {
					warnings.push(toggle.dataset.confirmLost + ' ' + lost.join(', '));
				}
				if (preview.lossy) {
					warnings.push(toggle.dataset.confirmLossy);
				}
				if (warnings.length && !window.confirm(warnings.join('\n\n'))) {
					return false;
				}
				written = true;
				return post({ turns: requested, crop: requestedCrop }).then(function () {
					return true;
				});
			}).then(function (done) {
				if (done) {
					window.location.reload();
					return;
				}
				saving = false;
				root.classList.remove('photoedit-saving');
			}).catch(function (error) {
				window.alert(error.message);
				if (written) {
					window.location.reload();
					return;
				}
				saving = false;
				root.classList.remove('photoedit-saving');
			});
		}

		function enter() {
			if (toggle.dataset.unavailable) {
				window.alert(toggle.dataset.unavailable);
				return;
			}
			/* The persons editor draws on the same photo and owns Escape while it
			   tags; leave it first. It reloads the page if it saved anything. */
			var personsToggle = document.getElementById('persons-tag-toggle');
			if (personsToggle && document.querySelector('#persons-stage.persons-tagging')) {
				personsToggle.click();
			}
			/* Fit first: the frame is drawn on the fitted photo. */
			var fit = document.getElementById('zoomFit');
			if (fit) {
				fit.click();
			}
			root.classList.add(ACTIVE);
			crop = WHOLE;
			redrawFrame();
		}

		function leave() {
			if (saving) {
				return;
			}
			turns = 0;
			crop = WHOLE;
			showTurns();
			removeFrame();
			root.classList.remove(ACTIVE);
		}

		function onClick(id, handler) {
			document.getElementById(id).addEventListener('click', function (event) {
				event.preventDefault();
				handler();
			});
		}

		onClick('photoedit-toggle', enter);
		onClick('photoedit-cancel', leave);
		onClick('photoedit-turn-left', function () { turn(-1); });
		onClick('photoedit-turn-right', function () { turn(1); });
		onClick('photoedit-save', save);

		/* The theme refits the photo when the window changes; the frame follows. */
		window.addEventListener('resize', redrawFrame);
		if (window.ResizeObserver) {
			new ResizeObserver(redrawFrame).observe(image);
		}

		window.addEventListener('keydown', function (event) {
			var target = event.target;
			if (!active() || target.isContentEditable || (target.closest && target.closest('input, textarea, select'))) {
				return;
			}
			if (event.key === 'Escape') {
				event.preventDefault();
				leave();
				return;
			}
			if (ZOOM_KEYS.indexOf(event.key) !== -1 || NAV_KEYS.indexOf(event.key) !== -1
				|| (event.ctrlKey && CTRL_NAV_KEYS.indexOf(event.key) !== -1)) {
				event.stopPropagation();
			}
		}, true);

		window.addEventListener('wheel', function (event) {
			if (active() && (event.ctrlKey || event.metaKey) && area.contains(event.target)) {
				event.stopPropagation();
				event.preventDefault();
			}
		}, { capture: true, passive: false });

		/* A click on the photo navigates and a press starts a pan. At fit the
		   theme may put an image map on the photo, whose areas are links of their
		   own, so a click lands on an <area> rather than the photo. */
		['click', 'mousedown'].forEach(function (type) {
			window.addEventListener(type, function (event) {
				if (active() && area.contains(event.target) && event.target.closest('#theMainImage, map')) {
					event.stopPropagation();
					event.preventDefault();
				}
			}, true);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
