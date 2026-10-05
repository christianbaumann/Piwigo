/**
 * Edit mode on the public picture page.
 *
 * While it is on, the page is a work surface rather than a viewer: the photo is
 * fitted and stays fitted, the theme's zoom, pan and click-to-navigate are held
 * back, and the persons overlay is hidden (the class on <html> does the hiding,
 * editor.css says what). Turning only turns the preview; nothing is written
 * until Save, which asks pwg.photoedit.apply first what the edit would cost
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
		var saving = false;

		function showTurns() {
			if (turns === 0) {
				image.style.transform = '';
				return;
			}
			/* A quarter turn swaps the photo's sides; shrink it so the turned
			   photo still fits the box the upright one fills. */
			var w = image.offsetWidth;
			var h = image.offsetHeight;
			var scale = turns % 2 === 1 ? Math.min(w / h, h / w) : 1;
			image.style.transform = 'rotate(' + (turns * 90) + 'deg) scale(' + scale + ')';
		}

		function turn(by) {
			/* Pointer events are off while saving, but a focused control still
			   answers Enter; the turn being written must not change under it. */
			if (saving) {
				return;
			}
			turns = (turns + by + 4) % 4;
			showTurns();
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
			if (turns === 0) {
				leave();
				return;
			}
			var requested = turns;
			/* Set once the write is sent: from then on the file may have changed,
			   whatever the response says, so the page is reloaded rather than
			   left offering a second save that would turn it again. */
			var written = false;
			saving = true;
			root.classList.add('photoedit-saving');
			post({ turns: requested, dry_run: 'true' }).then(function (preview) {
				var lost = preview.lost_regions || [];
				if (lost.length && !window.confirm(toggle.dataset.confirmLost + ' ' + lost.join(', '))) {
					return false;
				}
				written = true;
				return post({ turns: requested }).then(function () {
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
			/* Fit first: the frame a later task draws is drawn on the fitted photo. */
			var fit = document.getElementById('zoomFit');
			if (fit) {
				fit.click();
			}
			root.classList.add(ACTIVE);
		}

		function leave() {
			if (saving) {
				return;
			}
			turns = 0;
			showTurns();
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
