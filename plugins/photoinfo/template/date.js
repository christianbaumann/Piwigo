/*
 * Click-to-edit for the Datum row on the picture page. A date's month needs a
 * year and its day a month, so the controls are enabled in that order; the day
 * list follows the month and leap years. The qualifier needs a year too, and
 * only "zwischen" shows the range end, which has controls of its own. A
 * successful save reloads the page.
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
  var saveButton = form.querySelector('input[type=submit]');
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
    form.hidden = false;
    start.year.focus();
  }

  function closeEditor() {
    reset();
    message.textContent = '';
    form.hidden = true;
    view.hidden = false;
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
  view.addEventListener('click', openEditor);
  view.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openEditor();
    }
  });
  form.querySelector('.photoinfo-cancel').addEventListener('click', closeEditor);

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    message.textContent = '';

    var from = start.values();
    var to = isRange() ? end.values() : EMPTY;
    var error = validate(from, to);
    if (error !== '') {
      message.textContent = error;
      return;
    }
    // No start clears the date, whatever else is chosen.
    var kind = from.year === '' || qualifier.disabled ? '' : qualifier.value;
    if (from.year === '') {
      to = EMPTY;
    }

    saveButton.disabled = true;

    var body = new URLSearchParams();
    body.set('method', 'pwg.photoinfo.setDate');
    body.set('image_id', form.dataset.imageId);
    body.set('qualifier', kind);
    body.set('year', from.year);
    body.set('month', from.month);
    body.set('day', from.day);
    body.set('end_year', to.year);
    body.set('end_month', to.month);
    body.set('end_day', to.day);
    body.set('pwg_token', form.dataset.token);

    fetch(form.dataset.wsUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat !== 'ok') {
          throw new Error(json.message || '');
        }
        if (!json.result.written) {
          // The date is saved; only the file did not take it.
          saved = { qualifier: kind, start: from, end: to };
          if (json.result.date === '') {
            view.innerHTML = '';
            var span = document.createElement('span');
            span.className = 'photoinfo-placeholder';
            span.textContent = placeholder;
            view.appendChild(span);
          } else {
            view.textContent = json.result.date;
          }
          message.textContent = form.dataset.errorWrite.replace('%s', json.result.message);
          saveButton.disabled = false;
          return;
        }
        window.location.reload();
      })
      .catch(function (error) {
        message.textContent = form.dataset.errorSave + (error.message ? ': ' + error.message : '');
        saveButton.disabled = false;
      });
  });

  reset();
})();
