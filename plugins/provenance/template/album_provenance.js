jQuery(function ($) {
  const modal = $('#provenance-modal');
  const message = $('#provenance-message');

  function toggle() {
    message.text('').removeClass('provenance-error');
    modal.fadeToggle();
  }

  $('#provenance-open, #provenance-modal-close').on('click', toggle);

  $(window).on('click', function (e) {
    if (e.target === modal[0]) {
      toggle();
    }
  });

  $(document).on('keyup', function (e) {
    if (e.key === 'Escape' && modal.is(':visible')) {
      toggle();
    }
  });

  $('#provenance-save').on('click', function () {
    message.text('').removeClass('provenance-error');

    $.ajax({
      url: 'ws.php?format=json&method=pwg.provenance.setAlbumInfo',
      type: 'POST',
      dataType: 'json',
      data: {
        cat_id: provenance_album_id,
        physical_album: $('#provenance-physical-album').val(),
        owner: $('#provenance-owner').val(),
        scanned_on: $('#provenance-scanned-on').val(),
        note: $('#provenance-note').val(),
        pwg_token: provenance_pwg_token
      },
      // PwgError answers HTTP 200 with stat:"fail", so a refused save arrives
      // here and not in error() - which only fires for a transport failure.
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
