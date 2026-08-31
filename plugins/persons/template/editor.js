/**
 * The person tagging editor on the public picture page.
 *
 * Everything here is switched on by one mode toggle. Outside tagging mode the
 * page behaves exactly as overlay.js left it: the overlay takes no pointer
 * events, so the theme's click-to-navigate handler and the <area> map keep the
 * whole photo to themselves.
 *
 * Entering the mode is what makes the photo a drawing surface, which is also
 * why the map is taken off the image while it lasts - three consumers of the
 * same click cannot all be right, and the drawn box is the one the user asked
 * for.
 *
 * ---------------------------------------------------------------------------
 * The one piece of region math that has to live in the browser.
 *
 * A box is drawn in display space - the photo as it is shown, already rotated.
 * The API stores MWG's convention: normalized, centre origin, *before*
 * rotation. toStored() is the inverse of persons_rotate_region() followed by
 * persons_corner_to_center(), and nothing else here touches coordinates. The
 * drag is in the browser, so this conversion cannot be pushed down to PHP the
 * way the render path was.
 * ---------------------------------------------------------------------------
 */
(function () {
	'use strict';

	/* Long enough that typing a name is one query per pause, not per keystroke. */
	var SEARCH_DEBOUNCE_MS = 200;

	/* Space between the drawn box and the picker, in CSS pixels. */
	var PICKER_GAP_PX = 8;

	var WS_URL = 'ws.php?format=json';

	function clamp(value, low, high) {
		return Math.min(high, Math.max(low, value));
	}

	function init() {
		var config = document.getElementById('persons-editor');
		var stage = document.getElementById('persons-stage');
		var overlay = document.getElementById('persons-overlay');
		var toggle = document.getElementById('persons-tag-toggle');
		var message = document.getElementById('persons-editor-message');
		var image = document.getElementById('theMainImage');

		if (!config || !stage || !overlay || !toggle || !message || !image) {
			return;
		}

		var imageId = Number(config.getAttribute('data-persons-image'));
		var token = config.getAttribute('data-persons-token');
		var rotation = Number(config.getAttribute('data-persons-rotation')) || 0;
		var minFraction = Number(config.getAttribute('data-persons-min-fraction'));

		function str(name) {
			return config.getAttribute('data-persons-str-' + name) || '';
		}

		var tagging = false;
		var savedUsemap = null;
		var draft = null;
		var searchTimer = null;
		var highlight = -1;

		/* ── the picker ──────────────────────────────────────────────── */

		var picker = document.createElement('div');
		picker.id = 'persons-picker';
		picker.hidden = true;
		picker.innerHTML =
			'<div class="persons-picker-title"></div>' +
			'<input type="text" id="persons-picker-input" autocomplete="off">' +
			'<ul id="persons-picker-list"></ul>' +
			'<div class="persons-picker-hint"></div>';
		stage.appendChild(picker);

		picker.querySelector('.persons-picker-title').textContent = str('who');
		picker.querySelector('.persons-picker-hint').textContent = str('hint');

		var input = picker.querySelector('#persons-picker-input');
		var list = picker.querySelector('#persons-picker-list');

		function options() {
			return Array.prototype.slice.call(list.querySelectorAll('.persons-picker-option'));
		}

		function setHighlight(index) {
			var all = options();
			if (!all.length) {
				highlight = -1;
				return;
			}
			highlight = (index + all.length) % all.length;
			all.forEach(function (option, i) {
				option.classList.toggle('persons-picker-highlighted', i === highlight);
			});
		}

		/**
		 * Rebuilds the list from a result set, plus the create-new escape hatch.
		 *
		 * The create entry is offered whenever what was typed is not already an
		 * exact match, which is the only way a person nobody has tagged yet ever
		 * gets a name.
		 */
		function renderOptions(persons, typed) {
			list.textContent = '';

			var exact = false;

			persons.forEach(function (person) {
				var item = document.createElement('li');
				item.className = 'persons-picker-option';
				item.setAttribute('data-persons-name', person.name);
				item.textContent = person.name;
				list.appendChild(item);

				if (person.name.toLowerCase() === typed.toLowerCase()) {
					exact = true;
				}
			});

			if (typed !== '' && !exact) {
				var create = document.createElement('li');
				create.className = 'persons-picker-option persons-picker-create';
				create.setAttribute('data-persons-name', typed);
				create.textContent = str('create') + ' "' + typed + '"';
				list.appendChild(create);
			}

			setHighlight(0);
		}

		function search() {
			var typed = input.value.trim();
			var url = WS_URL + '&method=pwg.persons.getList&image_id=' + imageId +
				(typed === '' ? '' : '&q=' + encodeURIComponent(typed));

			return fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (data) {
					renderOptions(data.stat === 'ok' ? data.result.persons : [], typed);
				})
				.catch(function () {
					renderOptions([], typed);
				});
		}

		function searchSoon() {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(search, SEARCH_DEBOUNCE_MS);
		}

		/**
		 * Puts the picker where it hides the least of the box being named.
		 *
		 * Four candidates - below, above, right, left - each clamped into the
		 * stage and then scored by how much of the drawn box it covers. Without
		 * this the picker sits on top of the face the user is looking at, which
		 * is the one thing they need to see while naming it.
		 */
		function positionPicker() {
			if (!draft || picker.hidden) {
				return;
			}

			var stageBox = stage.getBoundingClientRect();
			var box = draft.el.getBoundingClientRect();
			var w = picker.offsetWidth;
			var h = picker.offsetHeight;

			var candidates = [
				{ left: box.left - stageBox.left, top: box.bottom - stageBox.top + PICKER_GAP_PX },
				{ left: box.left - stageBox.left, top: box.top - stageBox.top - h - PICKER_GAP_PX },
				{ left: box.right - stageBox.left + PICKER_GAP_PX, top: box.top - stageBox.top },
				{ left: box.left - stageBox.left - w - PICKER_GAP_PX, top: box.top - stageBox.top }
			];

			var best = null;
			var bestOverlap = Infinity;

			candidates.forEach(function (candidate) {
				var left = clamp(candidate.left, 0, Math.max(0, stageBox.width - w));
				var top = clamp(candidate.top, 0, Math.max(0, stageBox.height - h));

				var overlapW = Math.max(0, Math.min(left + w, box.right - stageBox.left) -
					Math.max(left, box.left - stageBox.left));
				var overlapH = Math.max(0, Math.min(top + h, box.bottom - stageBox.top) -
					Math.max(top, box.top - stageBox.top));

				var overlap = overlapW * overlapH;

				if (overlap < bestOverlap) {
					bestOverlap = overlap;
					best = { left: left, top: top };
				}
			});

			picker.style.left = best.left + 'px';
			picker.style.top = best.top + 'px';
		}

		function openPicker() {
			picker.hidden = false;
			input.value = '';
			renderOptions([], '');
			positionPicker();
			input.focus();
			search().then(positionPicker);
		}

		function closePicker() {
			picker.hidden = true;
			window.clearTimeout(searchTimer);
			list.textContent = '';
			highlight = -1;
		}

		/* ── the draft box ───────────────────────────────────────────── */

		function say(text, failed) {
			message.textContent = text;
			message.classList.toggle('persons-editor-error', !!failed);
		}

		function removeDraft() {
			if (draft) {
				draft.el.remove();
				draft = null;
			}
			closePicker();
		}

		/** display-space corner fractions -> what the API stores. */
		function toStored(box) {
			var region = {
				x: box.left + box.w / 2,
				y: box.top + box.h / 2,
				w: box.w,
				h: box.h
			};

			var turns = (4 - (rotation % 4) + 4) % 4;
			for (var turn = 0; turn < turns; turn++) {
				region = { x: 1 - region.y, y: region.x, w: region.h, h: region.w };
			}

			return region;
		}

		function place(el, box) {
			el.style.left = (box.left * 100) + '%';
			el.style.top = (box.top * 100) + '%';
			el.style.width = (box.w * 100) + '%';
			el.style.height = (box.h * 100) + '%';
		}

		function post(method, params) {
			var body = new URLSearchParams();
			Object.keys(params).forEach(function (key) {
				body.set(key, params[key]);
			});

			/* The method travels in the query string, the way both sibling
			   plugins call ws.php. */
			return fetch(WS_URL + '&method=' + method, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			}).then(function (response) { return response.json(); });
		}

		function knownRegionIds() {
			return Array.prototype.map.call(
				overlay.querySelectorAll('.person-box[data-person-region]'),
				function (el) { return Number(el.getAttribute('data-person-region')); }
			);
		}

		/**
		 * Turns the drawn box into a saved one.
		 *
		 * The box keeps the geometry the user drew rather than being re-derived
		 * from the response: they are the same region, and re-deriving it would
		 * need a second copy of the display conversion in this file. The label is
		 * plain text - the link to the person's gallery page is core's index URL,
		 * built server-side, and it appears on the next load of the page.
		 */
		function adopt(regionId, name) {
			var el = draft.el;
			draft = null;

			el.classList.remove('person-draft');
			el.setAttribute('data-person-region', regionId);

			var label = document.createElement('span');
			label.className = 'person-box-label';
			label.textContent = name;
			el.appendChild(label);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'person-box-delete';
			remove.textContent = '×';
			el.appendChild(remove);
		}

		/**
		 * A refused save arrives in the resolved promise, not the rejected one:
		 * PwgError answers HTTP 200 with stat:"fail". The drawn box stays exactly
		 * where it was so the user can retry or cancel it - it is never silently
		 * dropped.
		 */
		function commit(name) {
			if (!draft || name === '') {
				return;
			}

			var stored = toStored(draft.box);

			say('');

			post('pwg.persons.addRegion', {
				image_id: imageId,
				name: name,
				x: stored.x,
				y: stored.y,
				w: stored.w,
				h: stored.h,
				type: 'Face',
				pwg_token: token
			}).then(function (data) {
				if (data.stat !== 'ok') {
					say(str('failed') + ' ' + (data.message || ''), true);
					return;
				}

				var known = knownRegionIds();
				var added = data.result.regions.filter(function (region) {
					return known.indexOf(region.id) === -1;
				});

				closePicker();
				adopt(added.length ? added[0].id : 0, name);
			}).catch(function () {
				say(str('failed'), true);
			});
		}

		/* ── drawing ─────────────────────────────────────────────────── */

		function boxBetween(startX, startY, endX, endY) {
			var rect = overlay.getBoundingClientRect();
			if (rect.width < 1 || rect.height < 1) {
				return null;
			}

			var x0 = clamp(Math.min(startX, endX) - rect.left, 0, rect.width) / rect.width;
			var x1 = clamp(Math.max(startX, endX) - rect.left, 0, rect.width) / rect.width;
			var y0 = clamp(Math.min(startY, endY) - rect.top, 0, rect.height) / rect.height;
			var y1 = clamp(Math.max(startY, endY) - rect.top, 0, rect.height) / rect.height;

			return { left: x0, top: y0, w: x1 - x0, h: y1 - y0 };
		}

		overlay.addEventListener('mousedown', function (event) {
			if (!tagging || event.button !== 0 || event.target.closest('.person-box')) {
				return;
			}

			event.preventDefault();
			removeDraft();
			say('');

			var startX = event.clientX;
			var startY = event.clientY;

			var el = document.createElement('div');
			el.className = 'person-box person-draft';
			overlay.appendChild(el);
			draft = { el: el, box: { left: 0, top: 0, w: 0, h: 0 } };

			function onMove(moveEvent) {
				var box = boxBetween(startX, startY, moveEvent.clientX, moveEvent.clientY);
				if (!box || !draft) {
					return;
				}
				draft.box = box;
				place(draft.el, box);
			}

			function onUp(upEvent) {
				document.removeEventListener('mousemove', onMove);
				document.removeEventListener('mouseup', onUp);

				var box = boxBetween(startX, startY, upEvent.clientX, upEvent.clientY);
				if (!box || !draft) {
					return;
				}

				draft.box = box;
				place(draft.el, box);

				if (box.w < minFraction || box.h < minFraction) {
					removeDraft();
					say(str('too-small'), true);
					return;
				}

				openPicker();
			}

			document.addEventListener('mousemove', onMove);
			document.addEventListener('mouseup', onUp);
		});

		/* ── deleting ────────────────────────────────────────────────── */

		overlay.addEventListener('click', function (event) {
			var button = event.target.closest('.person-box-delete');
			if (!tagging || !button) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			var box = button.closest('.person-box');
			var regionId = Number(box.getAttribute('data-person-region'));
			if (!regionId) {
				return;
			}

			say('');

			post('pwg.persons.deleteRegion', { region_id: regionId, pwg_token: token })
				.then(function (data) {
					if (data.stat !== 'ok') {
						say(str('failed') + ' ' + (data.message || ''), true);
						return;
					}
					box.remove();
				})
				.catch(function () {
					say(str('failed'), true);
				});
		});

		/* ── the mode ────────────────────────────────────────────────── */

		function enter() {
			tagging = true;
			stage.classList.add('persons-tagging');
			toggle.textContent = str('done');

			/* The map would consume the mousedown that starts a drag. Taken off
			   for the duration and put back on the way out, so navigation is
			   exactly as it was. */
			savedUsemap = image.getAttribute('usemap');
			if (savedUsemap !== null) {
				image.removeAttribute('usemap');
			}
		}

		function exit() {
			tagging = false;
			stage.classList.remove('persons-tagging');
			toggle.textContent = str('tag');
			removeDraft();
			say('');

			if (savedUsemap !== null) {
				image.setAttribute('usemap', savedUsemap);
				savedUsemap = null;
			}
		}

		toggle.addEventListener('click', function () {
			if (tagging) {
				exit();
			} else {
				enter();
			}
		});

		input.addEventListener('input', searchSoon);

		input.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				setHighlight(highlight + 1);
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				setHighlight(highlight - 1);
			} else if (event.key === 'Enter') {
				event.preventDefault();
				var all = options();
				if (highlight >= 0 && all[highlight]) {
					commit(all[highlight].getAttribute('data-persons-name'));
				}
			}
		});

		list.addEventListener('click', function (event) {
			var option = event.target.closest('.persons-picker-option');
			if (option) {
				commit(option.getAttribute('data-persons-name'));
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape' || !tagging) {
				return;
			}

			if (draft) {
				removeDraft();
			} else {
				exit();
			}
		});

		window.addEventListener('resize', positionPicker);
		image.addEventListener('load', positionPicker);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
