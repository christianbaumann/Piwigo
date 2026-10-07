/*
 * Click-to-edit for the Info row on the picture page. A successful save reloads
 * the page, so the row shows the text the way core renders a description.
 */
(function () {
  var view = document.getElementById('photoinfo-info-view');
  var form = document.getElementById('photoinfo-info-form');
  if (!view || !form) {
    return;
  }

  var textarea = form.querySelector('textarea');
  var message = form.querySelector('.photoinfo-message');
  var saveButton = form.querySelector('input[type=submit]');
  var saved = textarea.value;

  function openEditor() {
    view.hidden = true;
    form.hidden = false;
    textarea.focus();
  }

  function closeEditor() {
    textarea.value = saved;
    message.textContent = '';
    form.hidden = true;
    view.hidden = false;
  }

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
    saveButton.disabled = true;

    var body = new URLSearchParams();
    body.set('method', 'pwg.photoinfo.setInfo');
    body.set('image_id', form.dataset.imageId);
    body.set('info', textarea.value);
    body.set('pwg_token', form.dataset.token);

    fetch(form.dataset.wsUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (json) {
        if (json.stat !== 'ok') {
          throw new Error(json.message || '');
        }
        if (!json.result.written) {
          // The text is saved; only the file did not take it.
          saved = json.result.info;
          textarea.value = saved;
          view.textContent = saved;
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
})();
