jQuery(function ($) {
  const table = $('#persons-table');

  function sprintf(template, value) {
    return template.replace('%d', value).replace('%s', value);
  }

  function fail(detail) {
    window.alert((persons_str_failed + ' ' + (detail || '')).trim());
  }

  // PwgError answers HTTP 200 with stat:"fail", so a refused call arrives in
  // success() and not in error() - which only fires for a transport failure.
  function call(method, data, onOk) {
    $.ajax({
      url: 'ws.php?format=json&method=' + method,
      type: 'POST',
      dataType: 'json',
      data: $.extend({ pwg_token: persons_pwg_token }, data),
      success: function (answer) {
        if (answer.stat !== 'ok') {
          fail(answer.message);
          return;
        }
        onOk(answer.result);
      },
      error: function () {
        fail('');
      }
    });
  }

  table.on('click', '.persons-rename', function (event) {
    event.preventDefault();

    const row = $(this).closest('tr');
    const current = row.attr('data-person-name');
    const wanted = window.prompt(sprintf(persons_str_rename, current), current);

    if (wanted === null || wanted === current) {
      return;
    }

    call('pwg.persons.rename', { person_id: row.attr('data-person'), name: wanted }, function () {
      window.location.reload();
    });
  });

  table.on('click', '.persons-delete', function (event) {
    event.preventDefault();

    const row = $(this).closest('tr');

    if (!window.confirm(sprintf(persons_str_delete, row.attr('data-person-name')))) {
      return;
    }

    call('pwg.persons.delete', { person_id: row.attr('data-person') }, function () {
      window.location.reload();
    });
  });

  // ── the chunked rescan ──────────────────────────────────────────────────
  //
  // The gallery is cut into chunks here rather than on the server: one request
  // per chunk, one in flight at a time, so a large gallery never runs into the
  // production request ceiling. The server refuses anything larger than
  // persons_max_chunk, so that is the ceiling rather than a preference.
  const button = $('#persons-rescan');
  const box = $('#persons-rescan-progress');
  const fill = $('#persons-rescan-bar-fill');
  const message = $('#persons-rescan-message');

  function chunks(ids) {
    const out = [];
    for (let i = 0; i < ids.length; i += persons_max_chunk) {
      out.push(ids.slice(i, i + persons_max_chunk));
    }
    return out;
  }

  button.on('click', function () {
    if (button.hasClass('persons-busy')) {
      return;
    }

    const total = persons_photo_ids.length;
    const batches = chunks(persons_photo_ids);
    let done = 0;
    let failed = 0;

    button.addClass('persons-busy');
    box.removeClass('persons-done');

    // The counters are published on the element as well as painted, so what the
    // run has really covered is readable without measuring a bar mid-transition.
    function progress() {
      fill.css('width', (total === 0 ? 0 : Math.round((done / total) * 100)) + '%');
      box.attr('data-done', done).attr('data-total', total);
      message.text(sprintf(persons_str_scanning, total) + '  ' + done + ' / ' + total);
    }

    progress();

    // Serialized deliberately: chunk N+1 starts only once N has answered, so a
    // failure stops the run instead of being buried under later requests.
    function next(index) {
      if (index >= batches.length) {
        button.removeClass('persons-busy');
        message.text(
          sprintf(persons_str_scanned, done) +
          (failed ? '  ' + sprintf(persons_str_scan_failed, failed) : '')
        );
        box.addClass('persons-done');
        return;
      }

      $.ajax({
        url: 'ws.php?format=json&method=pwg.persons.rescan',
        type: 'POST',
        dataType: 'json',
        data: { pwg_token: persons_pwg_token, image_ids: batches[index].join(',') },
        success: function (answer) {
          if (answer.stat !== 'ok') {
            stop(answer.message);
            return;
          }
          // A photo whose file could not be read does not stop the run; the
          // failures are counted and named at the end rather than lost.
          failed += Object.keys(answer.result.failed || {}).length;
          done += batches[index].length;
          progress();
          next(index + 1);
        },
        error: function () {
          stop('');
        }
      });
    }

    function stop(detail) {
      button.removeClass('persons-busy');
      message.text((persons_str_failed + ' ' + (detail || '')).trim() + ' (' + done + ' / ' + total + ')');
    }

    next(0);
  });
});
