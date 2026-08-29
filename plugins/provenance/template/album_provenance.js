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

  // ── chunked runs: the copy-down and the file write-back ──────────────────
  //
  // The album is cut into chunks here rather than on the server: one request
  // per chunk, one in flight at a time, so a large album never runs into the
  // production 60 s request ceiling. Halving the album keeps small albums to a
  // single request; the ceiling comes from the server, which refuses anything
  // larger. The two operations differ only in their ceiling, their method and
  // what they say afterwards, so they share one runner.
  function provenanceChunks(ids, maxChunk) {
    const size = Math.max(1, Math.min(Math.round(ids.length / 2), maxChunk));
    const chunks = [];

    for (let i = 0; i < ids.length; i += size) {
      chunks.push(ids.slice(i, i + size));
    }

    return chunks;
  }

  function sprintf(template, value) {
    return template.replace('%d', value);
  }

  function provenanceRunner(options) {
    const button = $(options.button);
    const message = $(options.message);
    const fill = $(options.fill);
    const box = $(options.progress);

    // The counters are published on the element as well as painted, so what the
    // run has really covered is readable without measuring a bar mid-transition.
    function progress(done, total) {
      fill.css('width', (total === 0 ? 0 : Math.round((done / total) * 100)) + '%');
      box.attr('data-done', done).attr('data-total', total);
      message.text(sprintf(options.runningText, total) + '  ' + done + ' / ' + total);
    }

    button.on('click', function () {
      if (button.hasClass('provenance-busy')) {
        return;
      }

      const total = provenance_photo_ids.length;
      const chunks = provenanceChunks(provenance_photo_ids, options.maxChunk);
      const state = {};
      let done = 0;

      button.addClass('provenance-busy');
      message.removeClass('provenance-error');
      // The run's state is published on the element rather than inferred from
      // the message text, which is translated and cannot be matched reliably.
      box.removeClass('provenance-done').css('display', 'inline-flex');
      progress(0, total);

      // Serialized deliberately: chunk N+1 starts only once N has answered, so a
      // failure stops the run instead of being buried under later requests.
      function next(index) {
        if (index >= chunks.length) {
          button.removeClass('provenance-busy');
          message.text(options.doneText(done, state));
          box.addClass('provenance-done');
          return;
        }

        $.ajax({
          url: 'ws.php?format=json&method=' + options.method,
          type: 'POST',
          dataType: 'json',
          data: $.extend({ pwg_token: provenance_pwg_token }, options.data(chunks[index])),
          // PwgError answers HTTP 200 with stat:"fail", so a refused chunk
          // arrives here and not in error().
          success: function (data) {
            if (data.stat !== 'ok') {
              fail(data.message);
              return;
            }
            if (options.collect) {
              options.collect(state, data.result);
            }
            done += chunks[index].length;
            progress(done, total);
            next(index + 1);
          },
          error: function () {
            fail('');
          }
        });
      }

      function fail(detail) {
        button.removeClass('provenance-busy');
        message
          .addClass('provenance-error')
          .text((options.errorText + ' ' + (detail || '')).trim() + ' (' + done + ' / ' + total + ')');
      }

      next(0);
    });
  }

  provenanceRunner({
    button: '#provenance-apply',
    message: '#provenance-apply-message',
    fill: '#provenance-apply-bar-fill',
    progress: '#provenance-apply-progress',
    method: 'pwg.provenance.applyToPhotos',
    maxChunk: provenance_apply_max_chunk,
    data: function (chunk) {
      return { cat_id: provenance_album_id, image_ids: chunk.join(',') };
    },
    runningText: provenance_str_applying,
    doneText: function (done) {
      return sprintf(provenance_str_applied, done);
    },
    errorText: provenance_str_apply_error
  });

  provenanceRunner({
    button: '#provenance-write',
    message: '#provenance-write-message',
    fill: '#provenance-write-bar-fill',
    progress: '#provenance-write-progress',
    method: 'pwg.provenance.writeBack',
    maxChunk: provenance_writeback_max_chunk,
    data: function (chunk) {
      return { image_ids: chunk.join(',') };
    },
    // A photo whose file could not be written does not stop the run, so the
    // failures are carried along and named at the end rather than lost.
    collect: function (state, result) {
      state.written = (state.written || 0) + result.written;
      state.failed = (state.failed || 0) + Object.keys(result.failed || {}).length;
    },
    runningText: provenance_str_writing,
    doneText: function (done, state) {
      const written = sprintf(provenance_str_written, state.written || 0);
      return state.failed ? written + '  ' + sprintf(provenance_str_write_failed, state.failed) : written;
    },
    errorText: provenance_str_write_error
  });
});
