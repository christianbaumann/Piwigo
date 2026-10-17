<span class="galleryrules-album-name-missing" hidden>{'Name missing'|@translate}</span>
{html_style}
.galleryrules-album-name-missing { color: #EB3D33; font-weight: bold; }
{/html_style}
{footer_script}{literal}
(function () {
  var dialog = document.getElementById('AddAlbum');
  if (!dialog) { return; }
  var field = dialog.querySelector('.AddAlbumInputContainer input.user-property-input');
  var hint = dialog.querySelector('.galleryrules-album-name-missing');

  // Core sends a blank name and only logs the server's refusal. Capture-phase
  // listeners run before core's jQuery handlers and stop them.
  function refuseBlank(event) {
    if (field.value.trim() !== '') { return; }
    event.stopImmediatePropagation();
    event.preventDefault();
    hint.hidden = false;
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target.closest) { return; }
    if (target.closest('#AddAlbum .AddAlbumSubmit')) {
      refuseBlank(event);
    } else if (target.closest('.add-album-button, .move-cat-add')) {
      hint.hidden = true;
    }
  }, true);

  dialog.addEventListener('keyup', function (event) {
    if (event.key === 'Enter') { refuseBlank(event); }
  }, true);

  field.addEventListener('input', function () {
    if (field.value.trim() !== '') { hint.hidden = true; }
  });
})();
{/literal}{/footer_script}
