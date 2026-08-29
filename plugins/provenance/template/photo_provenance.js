jQuery(function ($) {
  const message = $('#provenance-photo-message');

  $('#provenance-photo-save').on('click', function () {
    message.text('').removeClass('provenance-error');

    $.ajax({
      url: 'ws.php?format=json&method=pwg.provenance.setPhotoInfo',
      type: 'POST',
      dataType: 'json',
      data: {
        image_id: provenance_image_id,
        note: $('#provenance-photo-note').val(),
        pwg_token: provenance_pwg_token
      },
      // PwgError answers HTTP 200 with stat:"fail", so a refused save arrives
      // here and not in error().
      success: function (data) {
        if (data.stat === 'ok') {
          message.text(provenance_str_saved);
        } else {
          message.addClass('provenance-error').text(provenance_str_error + ' ' + (data.message || ''));
        }
      },
      error: function () {
        message.addClass('provenance-error').text(provenance_str_error);
      }
    });
  });
});
