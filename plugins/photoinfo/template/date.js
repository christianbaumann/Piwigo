/*
 * Editor for the Datum row on the picture page, opened by its edit button. A date's month needs a
 * year and its day a month, so the controls are enabled in that order; the day
 * list follows the month and leap years. The qualifier needs a year too, and
 * only "zwischen" shows the range end, which has controls of its own. All parts
 * are saved as one value when focus leaves the form or on Done; the row text
 * and the row status are updated in place, the page is not reloaded.
 */
(function () {
  var view = document.getElementById('photoinfo-date-view');
  var form = document.getElementById('photoinfo-date-form');
  if (!view || !form) {
    return;
  }

  var BETWEEN = 'between';

  var qualifier = form.elements.qualifier;
  var endBlock = form.querySelector('.photoinfo-date-end');
  var message = form.querySelector('.photoinfo-message');
  var editButton = document.getElementById('photoinfo-date-edit');
  var doneButton = form.querySelector('.photoinfo-done');
  var status = document.getElementById('photoinfo-date-status');
  var minYear = parseInt(form.dataset.minYear, 10);
  var maxYear = parseInt(form.dataset.maxYear, 10);
  var placeholder = form.dataset.placeholder;

  function daysInMonth(y, m) {
    // Day 0 of the next month is the last day of this one.
    return new Date(y, m, 0).getDate();
  }

  /** One date's year field and month and day dropdowns. */
  function DateFields(year, month, day) {
    this.year = year;
    this.month = month;
    this.day = day;
  }

  DateFields.prototype.hasYear = function () {
    return /^\d{4}$/.test(this.year.value.trim());
  };

  DateFields.prototype.yearInRange = function () {
    var y = parseInt(this.year.value, 10);
    return this.hasYear() && y >= minYear && y <= maxYear;
  };

  DateFields.prototype.fillDays = function (selected) {
    var count = this.month.value === '' ? 0 : daysInMonth(parseInt(this.year.value, 10), parseInt(this.month.value, 10));
    while (this.day.options.length > 1) {
      this.day.remove(1);
    }
    for (var d = 1; d <= count; d++) {
      this.day.add(new Option(String(d), String(d)));
    }
    this.day.value = selected !== '' && parseInt(selected, 10) <= count ? selected : '';
  };

  DateFields.prototype.update = function () {
    this.month.disabled = !this.hasYear();
    this.day.disabled = this.month.disabled || this.month.value === '';
    this.fillDays(this.day.value || '');
  };

  DateFields.prototype.set = function (values) {
    this.year.value = values.year;
    this.month.value = values.month;
    this.day.value = '';
    this.month.disabled = !this.hasYear();
    this.day.disabled = this.month.disabled || this.month.value === '';
    this.fillDays(values.day || '');
  };

  /** What the WS method gets: a disabled control sends ''. */
  DateFields.prototype.values = function () {
    return {
      year: this.year.value.trim(),
      month: this.month.disabled ? '' : this.month.value,
      day: this.day.disabled ? '' : this.day.value,
    };
  };

  var start = new DateFields(form.elements.year, form.elements.month, form.elements.day);
  var end = new DateFields(form.elements.end_year, form.elements.end_month, form.elements.end_day);

  var saved = {
    qualifier: qualifier.value,
    start: { year: start.year.value, month: start.month.value, day: start.day.dataset.day },
    end: { year: end.year.value, month: end.month.value, day: end.day.dataset.day },
  };
  var EMPTY = { year: '', month: '', day: '' };

  /**
   * Compares two dates at the coarser of their precisions, as the server does:
   * below zero when a lies before b.
   */
  function compare(a, b) {
    var parts = ['year', 'month', 'day'];
    for (var i = 0; i < parts.length; i++) {
      if (a[parts[i]] === '' || b[parts[i]] === '') {
        return 0;
      }
      var difference = parseInt(a[parts[i]], 10) - parseInt(b[parts[i]], 10);
      if (difference !== 0) {
        return difference;
      }
    }
    return 0;
  }

  function isRange() {
    return !qualifier.disabled && qualifier.value === BETWEEN;
  }

  function updateQualifier() {
    qualifier.disabled = !start.hasYear();
    endBlock.hidden = !isRange();
  }

  function reset() {
    qualifier.value = saved.qualifier;
    start.set(saved.start);
    end.set(saved.end);
    updateQualifier();
  }

  function openEditor() {
    view.hidden = true;
    editButton.hidden = true;
    form.hidden = false;
    start.year.focus();
  }

  function closeEditor() {
    message.textContent = '';
    form.hidden = true;
    view.hidden = false;
    editButton.hidden = false;
  }

  function showStatus(kind, text) {
    status.className = 'pwg-row-status pwg-row-status--' + kind;
    status.textContent = text;
  }

  function yearError() {
    return form.dataset.errorYear.replace('%d', minYear).replace('%d', maxYear);
  }

  /** @return {string} why the form cannot be sent, or '' */
  function validate(from, to) {
    if (from.year === '') {
      return '';
    }
    if (!start.yearInRange()) {
      return yearError();
    }
    if (!isRange()) {
      return '';
    }
    if (to.year === '') {
      return form.dataset.errorEndMissing;
    }
    if (!end.yearInRange()) {
      return yearError();
    }
    return compare(to, from) < 0 ? form.dataset.errorEnd : '';
  }

  start.year.addEventListener('input', function () { start.update(); updateQualifier(); });
  start.month.addEventListener('change', function () { start.update(); });
  end.year.addEventListener('input', function () { end.update(); });
  end.month.addEventListener('change', function () { end.update(); });
  qualifier.addEventListener('change', function () {
    if (qualifier.value !== BETWEEN) {
      end.set(EMPTY);
    }
    updateQualifier();
  });
  editButton.addEventListener('click', openEditor);

  /** The value the form holds now, in the shape of `saved`. */
  function current() {
    var from = start.values();
    var kind = from.year === '' || qualifier.disabled ? '' : qualifier.value;
    var to = from.year === '' || !isRange() ? EMPTY : end.values();
    return { qualifier: kind, start: from, end: to };
  }

  function same(a, b) {
    return a.qualifier === b.qualifier
      && a.start.year === b.start.year
      && a.start.month === b.start.month && a.start.day === b.start.day
      && a.end.year === b.end.year && a.end.month === b.end.month && a.end.day === b.end.day;
  }

  function markDirty() {
    if (saving) {
      return;
    }
    if (same(current(), saved)) {
      status.className = 'pwg-row-status';
      status.textContent = '';
    } else {
      showStatus('dirty', form.dataset.statusDirty);
    }
  }

  var saving = false;

  function showDate(text) {
    if (text === '') {
      view.innerHTML = '';
      var span = document.createElement('span');
      span.className = 'photoinfo-placeholder';
      span.textContent = placeholder;
      view.appendChild(span);
    } else {
      view.textContent = text;
    }
  }

  /** Sends the form as one value unless it is unchanged or incomplete; closes the editor when `close` and it worked. */
  function save(close) {
    if (saving) {
      return;
    }
    message.textContent = '';
    var value = current();
    if (same(value, saved)) {
      if (close) {
        closeEditor();
      }
      return;
    }
    var error = validate(value.start, value.end);
    if (error !== '') {
      message.textContent = error;
      return;
    }

    saving = true;
    doneButton.disabled = true;
    showStatus('saving', form.dataset.statusSaving);

    var body = new URLSearchParams();
    body.set('method', 'pwg.photoinfo.setDate');
    body.set('image_id', form.dataset.imageId);
    body.set('qualifier', value.qualifier);
    body.set('year', value.start.year);
    body.set('month', value.start.month);
    body.set('day', value.start.day);
    body.set('end_year', value.end.year);
    body.set('end_month', value.end.month);
    body.set('end_day', value.end.day);
    body.set('pwg_token', form.dataset.token);

    fetch(form.dataset.wsUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat !== 'ok') {
          throw new Error(json.message || '');
        }
        // The date is saved even when only the file did not take it.
        saved = value;
        showDate(json.result.date);
        saving = false;
        doneButton.disabled = false;
        if (json.result.written) {
          showStatus('saved', form.dataset.statusSaved);
          if (close) {
            closeEditor();
          }
        } else {
          showStatus('error', form.dataset.statusFailed.replace('%s', json.result.message_l10n));
        }
      })
      .catch(function (error) {
        saving = false;
        doneButton.disabled = false;
        showStatus('error', form.dataset.statusFailed.replace('%s', error.message || form.dataset.errorSave));
      });
  }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  // Focus moving between the form's own controls is not leaving it.
  form.addEventListener('focusout', function (event) {
    if (!event.relatedTarget || !form.contains(event.relatedTarget)) {
      save(true);
    }
  });
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    save(true);
  });

  reset();
})();
