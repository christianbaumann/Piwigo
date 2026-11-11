/**
 * The person tagging editor on the public picture page.
 *
 * "+ Person markieren" in the Personen row starts one action: draw a box, name
 * it, done. The drawing state ends by itself once the name is chosen, and on
 * Esc. Outside it the page behaves exactly as overlay.js left it: the overlay
 * takes no pointer events, so the theme's click-to-navigate handler and the
 * <area> map keep the whole photo to themselves.
 *
 * Drawing is what makes the photo a drawing surface, which is also why the map
 * is taken off the image while it lasts - three consumers of the same click
 * cannot all be right, and the drawn box is the one the user asked for.
 *
 * A region is deleted with the x beside its name in the Personen row. Every
 * write updates the row and the boxes in place from the web service's answer;
 * the page is never reloaded.
 *
 * ---------------------------------------------------------------------------
 * The one piece of region math that has to live in the browser.
 *
 * A box is drawn in display space - the photo as it is shown, already rotated.
 * The API stores MWG's convention: normalized, centre origin, *before*
 * rotation. toStored() is the inverse of persons_display_box(): the page shows
 * the raw file turned persons_display_turns() quarters clockwise, so going back
 * takes images.rotation's own code in clockwise quarters (core counts it
 * counter-clockwise, decision 0035). Nothing else here touches coordinates. The
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

	/* The web service's answer for a mark that is not there (pwg.persons.deleteRegion). */
	var NOT_FOUND = 404;

	function clamp(value, low, high) {
		return Math.min(high, Math.max(low, value));
	}

	function init() {
		var config = document.getElementById('persons-editor');
		var stage = document.getElementById('persons-stage');
		var overlay = document.getElementById('persons-overlay');
		var addButton = document.getElementById('persons-tag-add');
		var namesEl = document.getElementById('persons-names');
		var message = document.getElementById('persons-editor-message');
		var image = document.getElementById('theMainImage');

		if (!config || !stage || !overlay || !addButton || !namesEl || !message || !image) {
			return;
		}

		var status = addButton.parentNode.querySelector('.pwg-row-status');
		/* Disabled in the markup when the server cannot write into image files. */
		var canWrite = !addButton.disabled;

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
		/* What the list on screen was searched for, the newest search sent, and
		   how many are unanswered: Enter acts only on a list that matches the box. */
		var listedFor = '';
		var searchSeq = 0;
		var searching = 0;
		/* Writes sent and not yet answered. */
		var pending = 0;
		/* The faces in the Personen row: id, name, url, deletable. */
		var faces = facesFromRow();

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
			listedFor = typed;

			var exact = false;
			var exactIndex = -1;

			persons.forEach(function (person, index) {
				var item = document.createElement('li');
				item.className = 'persons-picker-option';
				item.setAttribute('data-persons-name', person.name);
				item.textContent = person.name;
				list.appendChild(item);

				if (person.name.toLowerCase() === typed.toLowerCase()) {
					exact = true;
					exactIndex = index;
				}
			});

			if (typed !== '' && !exact) {
				var create = document.createElement('li');
				create.className = 'persons-picker-option persons-picker-create';
				create.setAttribute('data-persons-name', typed);
				create.textContent = str('create') + ' "' + typed + '"';
				list.appendChild(create);
			}

			/* With text typed, Enter takes the exact match or else creates the
			   typed name; a partial match is taken only by moving to it. */
			if (typed === '') {
				setHighlight(0);
			} else {
				setHighlight(exact ? exactIndex : persons.length);
			}
		}

		function search() {
			var typed = input.value.trim();
			var seq = ++searchSeq;
			searching++;
			var url = WS_URL + '&method=pwg.persons.getList&image_id=' + imageId +
				(typed === '' ? '' : '&q=' + encodeURIComponent(typed));

			return fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (data) {
					if (seq === searchSeq) {
						renderOptions(data.stat === 'ok' ? data.result.persons : [], typed);
					}
				})
				.catch(function () {
					if (seq === searchSeq) {
						renderOptions([], typed);
					}
				})
				.then(function () {
					searching--;
				});
		}

		function searchSoon() {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(function () {
				searchTimer = null;
				search();
			}, SEARCH_DEBOUNCE_MS);
		}

		/** Runs the commit of the highlighted entry once the list is for what is typed. */
		function commitHighlighted() {
			var all = options();
			if (highlight >= 0 && all[highlight]) {
				commit(all[highlight].getAttribute('data-persons-name'));
			}
		}

		function enterPressed() {
			var typed = input.value.trim();
			var stale = searchTimer !== null || searching > 0 || typed !== listedFor;

			if (!stale) {
				commitHighlighted();
				return;
			}

			window.clearTimeout(searchTimer);
			searchTimer = null;
			search().then(commitHighlighted);
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
			searchTimer = null;
			list.textContent = '';
			highlight = -1;
		}

		/* ── the draft box ───────────────────────────────────────────── */

		function say(text, failed) {
			message.textContent = text;
			message.classList.toggle('persons-editor-error', !!failed);
		}

		var STATUS_STATES = ['saving', 'saved', 'dirty', 'error', 'undone'];

		/* A save that came back with a history entry id (photoinfo is active) offers Undo. */
		function showStatus(state, text, historyId) {
			if (!status) {
				return;
			}
			STATUS_STATES.forEach(function (name) {
				status.classList.toggle('pwg-row-status--' + name, name === state);
			});
			status.textContent = text;
			if (historyId) {
				var undoButton = document.createElement('button');
				undoButton.type = 'button';
				undoButton.className = 'pwg-row-undo';
				undoButton.textContent = str('undo');
				undoButton.addEventListener('click', function () { undo(undoButton, historyId); });
				status.appendChild(document.createTextNode(' · '));
				status.appendChild(undoButton);
			}
		}

		function undo(button, historyId) {
			button.disabled = true;
			post('pwg.photoinfo.undo', { history_id: historyId, pwg_token: token }).then(function (data) {
				if (data.stat === 'ok') {
					document.dispatchEvent(new CustomEvent('photoinfo:undone', { detail: data.result }));
				} else {
					showStatus('error', data.message || str('failed'));
				}
			}).catch(function () {
				showStatus('error', str('failed'));
			});
		}

		function showFailure(reason) {
			showStatus('error', str('not-saved').replace('%s', reason));
		}

		function updateButton() {
			addButton.disabled = !canWrite || tagging || pending > 0;
		}

		function removeDraft() {
			if (draft) {
				draft.el.remove();
				draft = null;
			}
			closePicker();
		}

		/** The faces the server rendered into the row, read back from its markup. */
		function facesFromRow() {
			var result = [];
			Array.prototype.forEach.call(namesEl.querySelectorAll('.persons-name'), function (entry) {
				var label = entry.querySelector('.persons-name-label');
				var deletable = (entry.getAttribute('data-persons-deletable') || '').split(',');
				(entry.getAttribute('data-persons-regions') || '').split(',').forEach(function (id) {
					if (id !== '') {
						result.push({
							id: Number(id),
							name: label.textContent,
							url: label.getAttribute('href') || '',
							deletable: deletable.indexOf(id) !== -1
						});
					}
				});
			});
			return result;
		}

		/** display-space corner fractions -> what the API stores. */
		function toStored(box) {
			var region = {
				x: box.left + box.w / 2,
				y: box.top + box.h / 2,
				w: box.w,
				h: box.h
			};

			/* Undoes the display turn, (4 - code) % 4 clockwise; negative codes are none, as in PHP. */
			var turns = rotation > 0 ? rotation % 4 : 0;
			for (var turn = 0; turn < turns; turn++) {
				region = { x: 1 - region.y, y: region.x, w: region.h, h: region.w };
			}

			return region;
		}

		/** What toStored() turned into the stored box, back to display fractions: left, top, w, h. */
		function toDisplay(region) {
			var turns = rotation > 0 ? rotation % 4 : 0;
			for (var turn = 0; turn < turns; turn++) {
				region = { x: region.y, y: 1 - region.x, w: region.h, h: region.w };
			}
			return { left: region.x - region.w / 2, top: region.y - region.h / 2, w: region.w, h: region.h };
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

		/**
		 * One write and everything around it, including the row's status. A
		 * refused write arrives in the resolved promise, not the rejected one:
		 * PwgError answers HTTP 200 with stat:"fail". An answer that lands after
		 * drawing ended still updates the row and the boxes.
		 */
		function write(method, params, onSuccess, onRefused) {
			pending++;
			updateButton();
			showStatus('saving', str('saving'));

			return post(method, params).then(function (data) {
				if (data.stat !== 'ok') {
					if (onRefused && onRefused(data)) {
						return;
					}
					showFailure(data.message || str('failed'));
					say(str('failed') + ' ' + (data.message || ''), true);
					return;
				}
				onSuccess(data);
				if (data.result && data.result.tags_written === false) {
					showStatus('error', str('write').replace('%s', data.result.tags_message || ''));
				} else {
					showStatus('saved', str('saved'), data.result && data.result.history_id);
				}
			}).catch(function () {
				showFailure(str('failed'));
				say(str('failed'), true);
			}).then(function () {
				pending--;
				updateButton();
			});
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
		 * need a second copy of the display conversion in this file.
		 */
		function adopt(region, name) {
			var el = draft.el;
			draft = null;

			el.classList.remove('person-draft');
			el.setAttribute('data-person-region', region.id);

			var label = document.createElement(region.url ? 'a' : 'span');
			label.className = 'person-box-label';
			if (region.url) {
				label.setAttribute('href', region.url);
			}
			label.textContent = name;
			el.appendChild(label);
		}

		/** Draws the boxes of listed faces the photo does not show yet (an undone delete brings one back). */
		function addBoxesNotShown(regions) {
			var known = knownRegionIds();
			regions.forEach(function (region) {
				if (region.type !== 'Face' || known.indexOf(region.id) !== -1) {
					return;
				}
				var el = document.createElement('div');
				el.className = 'person-box';
				el.setAttribute('data-person-region', region.id);
				place(el, toDisplay(region));
				var label = document.createElement(region.url ? 'a' : 'span');
				label.className = 'person-box-label';
				if (region.url) {
					label.setAttribute('href', region.url);
				}
				label.textContent = region.name;
				el.appendChild(label);
				overlay.appendChild(el);
			});
		}

		/** Removes the boxes whose region the server no longer lists. */
		function dropBoxesNotIn(regions) {
			var ids = regions.map(function (region) { return region.id; });
			Array.prototype.forEach.call(overlay.querySelectorAll('.person-box[data-person-region]'), function (el) {
				if (ids.indexOf(Number(el.getAttribute('data-person-region'))) === -1) {
					el.remove();
				}
			});
		}

		/**
		 * Rebuilds the Personen row from the region list both write methods
		 * answer with - the rule persons_assign_overlay() renders it by: faces
		 * only, each name once, in the order the list comes in.
		 *
		 * One known difference: the server leaves out a face whose box cannot be
		 * drawn (centre off the photo, no size), the API list does not. Such a
		 * name shows here until the next load. Accepted - it needs a file tagged
		 * elsewhere with a broken region.
		 */
		function renderPersonRow(regions) {
			faces = regions.filter(function (region) {
				return region.type === 'Face';
			}).map(function (region) {
				return { id: region.id, name: region.name, url: region.url || '', deletable: !!region.deletable };
			});
			renderNames();
		}

		function renderNames() {
			var order = [];
			var byName = {};
			faces.forEach(function (face) {
				if (!byName[face.name]) {
					byName[face.name] = [];
					order.push(face.name);
				}
				byName[face.name].push(face);
			});

			namesEl.textContent = '';
			order.forEach(function (name, index) {
				var group = byName[name];
				var url = group[0].url;
				var deletable = group.filter(function (face) { return face.deletable; });

				if (index > 0) {
					namesEl.appendChild(document.createTextNode(', '));
				}

				var entry = document.createElement('span');
				entry.className = 'persons-name';
				entry.setAttribute('data-persons-regions', group.map(function (face) { return face.id; }).join(','));
				entry.setAttribute('data-persons-deletable', deletable.map(function (face) { return face.id; }).join(','));

				var label = document.createElement(url ? 'a' : 'span');
				label.className = 'persons-name-label';
				if (url) {
					label.setAttribute('href', url);
				}
				label.textContent = name;
				entry.appendChild(label);

				if (deletable.length) {
					var remove = document.createElement('button');
					remove.type = 'button';
					remove.className = 'persons-name-delete';
					remove.title = str('remove');
					remove.setAttribute('aria-label', str('remove'));
					entry.appendChild(remove);
				}

				namesEl.appendChild(entry);
			});
		}

		/**
		 * On a refused save the drawn box stays exactly where it was so the user
		 * can retry or cancel it - it is never silently dropped. Where drawing
		 * was cancelled while the save was on its way, nobody can retry: the box
		 * goes.
		 */
		function commit(name) {
			if (!draft || draft.saving || name === '') {
				return;
			}

			var stored = toStored(draft.box);
			var saving = draft;
			saving.saving = true;

			say('');

			write('pwg.persons.addRegion', {
				image_id: imageId,
				name: name,
				x: stored.x,
				y: stored.y,
				w: stored.w,
				h: stored.h,
				type: 'Face',
				pwg_token: token
			}, function (data) {
				var known = knownRegionIds();
				var added = data.result.regions.filter(function (region) {
					return known.indexOf(region.id) === -1;
				});

				renderPersonRow(data.result.regions);
				dropBoxesNotIn(data.result.regions);

				/* The draft was removed before the answer came: the box is no
				   longer there to adopt. */
				if (draft !== saving) {
					return;
				}

				closePicker();
				/* The highest unknown id: the new row is inserted last, and any
				   other unknown id is a region somebody else added since this
				   page loaded. */
				adopt(added.length ? added[added.length - 1] : { id: 0, url: '' }, name);
				exit();
			}).then(function () {
				if (draft === saving) {
					saving.saving = false;
					if (!tagging) {
						removeDraft();
					}
				}
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
			/* A drag inside a frame draws a new one; only the name and the x keep their click. */
			if (!tagging || event.button !== 0 || event.target.closest('.person-box-label') || (draft && draft.saving)) {
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

		namesEl.addEventListener('click', function (event) {
			var button = event.target.closest('.persons-name-delete');
			if (!button) {
				return;
			}

			var entry = button.closest('.persons-name');
			var deletable = entry.getAttribute('data-persons-deletable').split(',');
			/* One press removes one region; a name with several boxes needs one per box. */
			var regionId = Number(deletable[deletable.length - 1]);
			if (!regionId) {
				return;
			}

			clearHighlight();
			say('');

			write('pwg.persons.deleteRegion', { region_id: regionId, pwg_token: token }, function (data) {
				renderPersonRow(data.result.regions);
				dropBoxesNotIn(data.result.regions);
			}, function (data) {
				/* 404: gone already, or no longer visible - the server answers both alike. */
				if (Number(data.err) !== NOT_FOUND) {
					return false;
				}
				faces = faces.filter(function (face) { return face.id !== regionId; });
				renderNames();
				var box = overlay.querySelector('.person-box[data-person-region="' + regionId + '"]');
				if (box) {
					box.remove();
				}
				say(str('gone'), false);
				showStatus('saved', str('saved'));
				return true;
			});
		});

		/* An undo of a region entry, started here or in the change list: re-read the photo's faces. */
		document.addEventListener('photoinfo:undone', function (event) {
			var result = event.detail;
			if (result.field !== 'region' || Number(result.image_id) !== imageId) {
				return;
			}
			fetch(WS_URL + '&method=pwg.persons.getRegions&image_id=' + imageId, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (data) {
					if (data.stat === 'ok') {
						renderPersonRow(data.result.regions);
						dropBoxesNotIn(data.result.regions);
						addBoxesNotShown(data.result.regions);
					}
				}).catch(function () {
					/* the row keeps what it showed; the status below still tells what happened */
				}).then(function () {
					if (result.written === false) {
						showStatus('error', str('write').replace('%s', result.message_l10n || ''));
					} else {
						showStatus('undone', str('undone'));
					}
				});
		});

		/* Pointing at or focusing a name shows its box(es). */

		function highlightBoxesOf(entry) {
			(entry.getAttribute('data-persons-regions') || '').split(',').forEach(function (id) {
				var box = overlay.querySelector('.person-box[data-person-region="' + id + '"]');
				if (box) {
					box.classList.add('person-box-highlight');
				}
			});
		}

		function clearHighlight() {
			Array.prototype.forEach.call(overlay.querySelectorAll('.person-box-highlight'), function (el) {
				el.classList.remove('person-box-highlight');
			});
		}

		function highlightFrom(event) {
			clearHighlight();
			var entry = event.target.closest ? event.target.closest('.persons-name') : null;
			if (entry && namesEl.contains(entry)) {
				highlightBoxesOf(entry);
			}
		}

		namesEl.addEventListener('mouseover', highlightFrom);
		namesEl.addEventListener('focusin', highlightFrom);
		namesEl.addEventListener('mouseleave', clearHighlight);
		namesEl.addEventListener('focusout', clearHighlight);

		/* ── drawing state ───────────────────────────────────────────── */

		function enter() {
			if (tagging || !canWrite) {
				return;
			}

			tagging = true;
			stage.classList.add('persons-tagging');
			updateButton();
			say(str('draw'));

			/* The map would consume the mousedown that starts a drag. Taken off
			   for the duration and put back on the way out, so navigation is
			   exactly as it was. */
			savedUsemap = image.getAttribute('usemap');
			if (savedUsemap !== null) {
				image.removeAttribute('usemap');
			}
		}

		/** Ends drawing. A box whose save is on its way stays until the answer lands. */
		function exit() {
			if (!tagging) {
				return;
			}

			tagging = false;
			stage.classList.remove('persons-tagging');
			if (!draft || !draft.saving) {
				removeDraft();
			} else {
				closePicker();
			}
			say('');
			updateButton();

			if (savedUsemap !== null) {
				image.setAttribute('usemap', savedUsemap);
				savedUsemap = null;
			}
		}

		addButton.addEventListener('click', enter);

		/* Another editor on the same photo (photoedit) asks drawing to end. */
		document.addEventListener('persons:cancel', exit);

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
				enterPressed();
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

			exit();
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
