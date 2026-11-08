/*
 * Click-to-edit for the Info row on the picture page. The text is saved when
 * the field is left or on "Done", never on a timer, and the row updates from
 * the web service's answer without reloading the page.
 */
(function () {
  var view = document.getElementById('photoinfo-info-view');
  var form = document.getElementById('photoinfo-info-form');
  var editButton = document.getElementById('photoinfo-info-edit');
  var status = document.querySelector('#PhotoInfo .pwg-row-status');
  if (!view || !form || !editButton || !status) {
    return;
  }

  var STATES = ['saving', 'saved', 'dirty', 'error'];
  var textarea = form.querySelector('textarea');
  var saved = textarea.value;
  var inflight = null;

  function showStatus(state, text) {
    STATES.forEach(function (name) {
      status.classList.toggle('pwg-row-status--' + name, name === state);
    });
    status.textContent = text;
  }

  function clearStatus() {
    showStatus('', '');
  }

  function warnBeforeLeaving(event) {
    event.preventDefault();
    event.returnValue = '';
  }

  function isDirty() {
    return textarea.value !== saved;
  }

  function trackDirty() {
    if (isDirty()) {
      window.addEventListener('beforeunload', warnBeforeLeaving);
    } else {
      window.removeEventListener('beforeunload', warnBeforeLeaving);
    }
  }

  function showText(html) {
    if (html === '') {
      var placeholder = document.createElement('span');
      placeholder.className = 'photoinfo-placeholder';
      placeholder.textContent = view.dataset.placeholder;
      view.textContent = '';
      view.appendChild(placeholder);
    } else {
      view.innerHTML = html;
    }
  }

  function openEditor() {
    view.hidden = true;
    editButton.hidden = true;
    form.hidden = false;
    textarea.focus();
  }

  function closeEditor() {
    form.hidden = true;
    view.hidden = false;
    editButton.hidden = false;
  }

  function send() {
    if (!isDirty()) {
      return Promise.resolve(true);
    }
    showStatus('saving', form.dataset.textSaving);

    var sent = textarea.value;
    var body = new URLSearchParams();
    body.set('method', 'pwg.photoinfo.setInfo');
    body.set('image_id', form.dataset.imageId);
    body.set('info', sent);
    body.set('pwg_token', form.dataset.token);

    return fetch(form.dataset.wsUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat !== 'ok') {
          throw new Error(json.message || '');
        }
        saved = json.result.info;
        showText(json.result.info_html);
        if (textarea.value === sent) {
          textarea.value = saved;
        }
        trackDirty();
        if (!json.result.written) {
          // The text is saved; only the file did not take it.
          showStatus('error', form.dataset.errorWrite.replace('%s', json.result.message_l10n));
        } else if (isDirty()) {
          showStatus('dirty', form.dataset.textDirty);
        } else {
          showStatus('saved', form.dataset.textSaved);
        }
        return true;
      })
      .catch(function (error) {
        var reason = error.message || form.dataset.errorSave;
        showStatus('error', form.dataset.textNotSaved.replace('%s', reason));
        return false;
      });
  }

  // Saves are chained, so a blur and a click on "Done" in a row send one request.
  function save() {
    inflight = (inflight || Promise.resolve()).then(send);
    return inflight;
  }

  editButton.addEventListener('click', openEditor);
  view.addEventListener('click', openEditor);
  view.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openEditor();
    }
  });

  textarea.addEventListener('input', function () {
    trackDirty();
    if (isDirty()) {
      showStatus('dirty', form.dataset.textDirty);
    } else {
      clearStatus();
    }
  });

  textarea.addEventListener('blur', function () {
    save();
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    save().then(function (ok) {
      if (ok) {
        closeEditor();
      }
    });
  });
})();
