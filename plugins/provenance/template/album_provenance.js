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

  // ── the copy-down ────────────────────────────────────────────────────────
  //
  // The album is cut into chunks here rather than on the server: one request
  // per chunk, one in flight at a time, so a large album never runs into the
  // production 60 s request ceiling. Halving the album keeps small albums to a
  // single request; the ceiling comes from the server, which refuses anything
  // larger.
  function provenanceChunks(ids) {
    const size = Math.max(1, Math.min(Math.round(ids.length / 2), provenance_apply_max_chunk));
    const chunks = [];

    for (let i = 0; i < ids.length; i += size) {
      chunks.push(ids.slice(i, i + size));
    }

    return chunks;
  }

  const applyButton = $('#provenance-apply');
  const applyMessage = $('#provenance-apply-message');
  const applyFill = $('#provenance-apply-bar-fill');
  const applyProgressBox = $('#provenance-apply-progress');

  // The counters are published on the element as well as painted, so what the
  // run has really covered is readable without measuring a bar mid-transition.
  function applyProgress(done, total) {
    applyFill.css('width', (total === 0 ? 0 : Math.round((done / total) * 100)) + '%');
    applyProgressBox.attr('data-done', done).attr('data-total', total);
    applyMessage.text(sprintf(provenance_str_applying, total) + '  ' + done + ' / ' + total);
  }

  function sprintf(template, value) {
    return template.replace('%d', value);
  }

  applyButton.on('click', function () {
    if (applyButton.hasClass('provenance-busy')) {
      return;
    }

    const total = provenance_photo_ids.length;
    const chunks = provenanceChunks(provenance_photo_ids);
    let done = 0;

    applyButton.addClass('provenance-busy');
    applyMessage.removeClass('provenance-error');
    // The run's state is published on the element rather than inferred from the
    // message text, which is translated and cannot be matched reliably.
    applyProgressBox.removeClass('provenance-done').css('display', 'inline-flex');
    applyProgress(0, total);

    // Serialized deliberately: chunk N+1 starts only once N has answered, so a
    // failure stops the run instead of being buried under later requests.
    function next(index) {
      if (index >= chunks.length) {
        applyButton.removeClass('provenance-busy');
        applyMessage.text(sprintf(provenance_str_applied, done));
        applyProgressBox.addClass('provenance-done');
        return;
      }

      $.ajax({
        url: 'ws.php?format=json&method=pwg.provenance.applyToPhotos',
        type: 'POST',
        dataType: 'json',
        data: {
          cat_id: provenance_album_id,
          image_ids: chunks[index].join(','),
          pwg_token: provenance_pwg_token
        },
        // PwgError answers HTTP 200 with stat:"fail", so a refused chunk arrives
        // here and not in error().
        success: function (data) {
          if (data.stat !== 'ok') {
            fail(data.message);
            return;
          }
          done += chunks[index].length;
          applyProgress(done, total);
          next(index + 1);
        },
        error: function () {
          fail('');
        }
      });
    }

    function fail(detail) {
      applyButton.removeClass('provenance-busy');
      applyMessage
        .addClass('provenance-error')
        .text((provenance_str_apply_error + ' ' + (detail || '')).trim() + ' (' + done + ' / ' + total + ')');
    }

    next(0);
  });
});
