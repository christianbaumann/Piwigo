{combine_script id='provenance_album' load='footer' require='jquery' path=$PROVENANCE_PATH|cat:'template/album_provenance.js'}
{combine_css path=$PROVENANCE_PATH|cat:'template/album_provenance.css'}

{footer_script}
const provenance_album_id = {$PROVENANCE_ALBUM.CAT_ID};
const provenance_pwg_token = '{$PWG_TOKEN}';
const provenance_str_saved = '{'Provenance saved'|@translate|escape:javascript}';
const provenance_str_error = '{'Provenance could not be saved'|@translate|escape:javascript}';
{/footer_script}

<span class="buttonLike" id="provenance-open"><i class="icon-book"></i> {'Provenance'|@translate}</span>

<div class="desc-modal" id="provenance-modal">
  <div class="desc-modal-content">
    <div class="desc-modal-header">
      <p>{'Provenance'|@translate}</p>
    </div>
    <div class="desc-modal-body">
      <div class="cat-modify-input-container">
        <label for="provenance-physical-album">{'Physical album'|@translate}</label>
        <input type="text" id="provenance-physical-album" value="{$PROVENANCE_ALBUM.PHYSICAL_ALBUM|escape}" maxlength="{$PROVENANCE_SHORT_TEXT_MAX}">
      </div>

      <div class="cat-modify-input-container">
        <label for="provenance-owner">{'Owner'|@translate}</label>
        <input type="text" id="provenance-owner" value="{$PROVENANCE_ALBUM.OWNER|escape}" maxlength="{$PROVENANCE_SHORT_TEXT_MAX}">
      </div>

      <div class="cat-modify-input-container">
        <label for="provenance-scanned-on">{'Scanned on'|@translate}</label>
        <input type="date" id="provenance-scanned-on" value="{$PROVENANCE_ALBUM.SCANNED_ON|escape}">
      </div>

      <div class="cat-modify-input-container">
        <label for="provenance-note">{'Note'|@translate}</label>
        <textarea rows="6" id="provenance-note">{$PROVENANCE_ALBUM.NOTE|escape}</textarea>
      </div>
    </div>
    <div class="desc-modal-footer">
      <span id="provenance-message"></span>
      <span class="buttonLike" id="provenance-save"><i class="icon-floppy"></i> {'Save Settings'|@translate}</span>
      <p id="provenance-modal-close" class="cat-modify-footer-see-out"><span class="icon-resize-small"></span>{'Shrink'|translate}</p>
    </div>
  </div>
</div>
