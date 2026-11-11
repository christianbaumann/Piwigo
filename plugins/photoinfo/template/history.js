/* The list of a photo's last changes. It opens collapsed and reads
   pwg.provenance.getHistory when opened and after any save the page makes.
   The rows' own scripts (photoinfo, typetags, persons, photoedit) are not
   touched: a save is recognised by the ws method it calls, on fetch() and on
   jQuery's ajax alike. An undo, from here or from a row, is announced by the
   photoinfo:undone event on document. */
(function () {
  'use strict';

  var details = document.getElementById('photoinfo-history');
  if (!details) {
    return;
  }

  var list = document.getElementById('photoinfo-history-list');
  var count = document.getElementById('photoinfo-history-count');
  var empty = document.getElementById('photoinfo-history-empty');
  var warning = document.getElementById('photoinfo-history-warning');
  var all = document.getElementById('photoinfo-history-all');
  var perPage = parseInt(details.dataset.perPage, 10);
  var undoFields = details.dataset.undoFields.split(',');
  var isAdmin = details.dataset.isAdmin === '1';
  var SAVE_METHOD = /(^|[?&=])(pwg\.photoinfo\.set(Date|Info)|typetags\.image\.(addTag|removeTag|addNewTag)|pwg\.persons\.(addRegion|deleteRegion)|pwg\.photoedit\.apply)(&|$)/;
  var REFRESH_DELAY_MS = 300;
  var timer = null;

  function undoable(row) {
    return !row.undone && !row.undoes_id && undoFields.indexOf(row.field) >= 0 &&
      (row.display.own || isAdmin);
  }

  function undo(row, action, button) {
    var body = new URLSearchParams();
    body.set('history_id', row.id);
    body.set('pwg_token', details.dataset.token);
    button.disabled = true;
    nativeFetch(details.dataset.wsUrl + '&method=pwg.photoinfo.undo', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat === 'ok') {
          // The rows listen too, and so does the refresh below.
          document.dispatchEvent(new CustomEvent('photoinfo:undone', { detail: json.result }));
          return;
        }
        button.disabled = false;
        action.querySelector('.photoinfo-undo-error').textContent = json.message;
      })
      .catch(function () { button.disabled = false; });
  }

  function undoControl(row, action) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'photoinfo-undo';
    button.textContent = details.dataset.undoLabel;
    var error = document.createElement('span');
    error.className = 'photoinfo-undo-error';
    button.addEventListener('click', function () { undo(row, action, button); });
    action.appendChild(document.createTextNode(' '));
    action.appendChild(button);
    action.appendChild(error);
  }

  function item(row) {
    var entry = row.display;
    var li = document.createElement('li');
    if (entry.undone) {
      li.className = 'photoinfo-history-undone';
    }
    var text = document.createElement('span');
    text.className = 'photoinfo-history-text';
    text.textContent = entry.text;
    var meta = document.createElement('span');
    meta.className = 'photoinfo-history-meta';
    meta.textContent = (entry.by ? entry.by + ' · ' : '') + entry.when;
    var action = document.createElement('span');
    action.className = 'photoinfo-history-action';
    li.appendChild(text);
    li.appendChild(document.createTextNode(' — '));
    li.appendChild(meta);
    li.appendChild(action);
    if (undoable(row)) {
      undoControl(row, action);
    }
    return li;
  }

  function show(json) {
    var rows = json.result.histories;
    var total = json.result.paging.total_count;
    list.textContent = '';
    rows.forEach(function (row) {
      list.appendChild(item(row));
    });
    count.textContent = total;
    empty.hidden = total > 0;
    all.hidden = total <= perPage;
  }

  function refresh() {
    var query = new URLSearchParams();
    query.set('method', 'pwg.provenance.getHistory');
    query.set('object', 'photo');
    query.set('object_id', details.dataset.imageId);
    query.set('per_page', perPage);
    query.set('fields', details.dataset.fields);
    query.set('formatted', 'true');
    return nativeFetch(details.dataset.wsUrl + '&' + query.toString(), { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat === 'ok') {
          show(json);
        }
      })
      .catch(function () { /* the list keeps what it showed */ });
  }

  function refreshSoon() {
    clearTimeout(timer);
    timer = setTimeout(refresh, REFRESH_DELAY_MS);
  }

  function bodyText(body) {
    if (body === undefined || body === null) {
      return '';
    }
    return typeof body === 'string' ? body : (body instanceof URLSearchParams ? body.toString() : '');
  }

  var nativeFetch = window.fetch.bind(window);
  window.fetch = function (url, init) {
    var request = String(url) + '&' + bodyText(init && init.body);
    var promise = nativeFetch.apply(window, arguments);
    if (SAVE_METHOD.test(request)) {
      promise.then(refreshSoon, function () {});
    }
    return promise;
  };

  if (window.jQuery) {
    window.jQuery(document).ajaxSuccess(function (event, xhr, settings) {
      if (SAVE_METHOD.test(String(settings.url) + '&' + bodyText(settings.data))) {
        refreshSoon();
      }
    });
  }

  // An undo started in this list or in a row: the list shows its entry.
  document.addEventListener('photoinfo:undone', function (event) {
    // The undo is done; only the image file kept the old value.
    warning.textContent = event.detail.written === false
      ? details.dataset.writeFailed.replace('%s', event.detail.message_l10n || '') : '';
    refreshSoon();
  });

  details.addEventListener('toggle', function () {
    if (details.open) {
      refresh();
    }
  });
}());
