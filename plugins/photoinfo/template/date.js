/*
 * Click-to-edit for the Datum row on the picture page. The month needs a year
 * and the day a month, so the controls are enabled in that order; the day list
 * follows the month and leap years. A successful save reloads the page.
 */
(function () {
  var view = document.getElementById('photoinfo-date-view');
  var form = document.getElementById('photoinfo-date-form');
  if (!view || !form) {
    return;
  }

  var year = form.elements.year;
  var month = form.elements.month;
  var day = form.elements.day;
  var message = form.querySelector('.photoinfo-message');
  var saveButton = form.querySelector('input[type=submit]');
  var minYear = parseInt(form.dataset.minYear, 10);
  var maxYear = parseInt(form.dataset.maxYear, 10);
  var saved = { year: year.value, month: month.value, day: day.dataset.day };
  var placeholder = form.dataset.placeholder;

  function hasYear() {
    return /^\d{4}$/.test(year.value.trim());
  }

  function daysInMonth(y, m) {
    // Day 0 of the next month is the last day of this one.
    return new Date(y, m, 0).getDate();
  }

  function fillDays(selected) {
    var count = month.value === '' ? 0 : daysInMonth(parseInt(year.value, 10), parseInt(month.value, 10));
    while (day.options.length > 1) {
      day.remove(1);
    }
    for (var d = 1; d <= count; d++) {
      day.add(new Option(String(d), String(d)));
    }
    day.value = selected !== '' && parseInt(selected, 10) <= count ? selected : '';
  }

  function updateControls() {
    month.disabled = !hasYear();
    day.disabled = month.disabled || month.value === '';
    fillDays(day.value || '');
  }

  function reset() {
    year.value = saved.year;
    month.value = saved.month;
    day.value = '';
    month.disabled = !hasYear();
    day.disabled = month.disabled || month.value === '';
    fillDays(saved.day || '');
  }

  function openEditor() {
    view.hidden = true;
    form.hidden = false;
    year.focus();
  }

  function closeEditor() {
    reset();
    message.textContent = '';
    form.hidden = true;
    view.hidden = false;
  }

  year.addEventListener('input', updateControls);
  month.addEventListener('change', updateControls);
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

    var y = year.value.trim();
    if (y !== '' && (!hasYear() || parseInt(y, 10) < minYear || parseInt(y, 10) > maxYear)) {
      message.textContent = form.dataset.errorYear.replace('%d', minYear).replace('%d', maxYear);
      return;
    }

    saveButton.disabled = true;

    var body = new URLSearchParams();
    body.set('method', 'pwg.photoinfo.setDate');
    body.set('image_id', form.dataset.imageId);
    body.set('year', y);
    body.set('month', month.disabled ? '' : month.value);
    body.set('day', day.disabled ? '' : day.value);
    body.set('pwg_token', form.dataset.token);

    fetch(form.dataset.wsUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat !== 'ok') {
          throw new Error(json.message || '');
        }
        if (!json.result.written) {
          // The date is saved; only the file did not take it.
          saved = { year: y, month: body.get('month'), day: body.get('day') };
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
