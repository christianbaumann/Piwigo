{combine_script id='provenance_photo' load='footer' require='jquery' path=$PROVENANCE_PATH|cat:'template/photo_provenance.js'}
{combine_css path=$PROVENANCE_PATH|cat:'template/album_provenance.css'}

{footer_script}
const provenance_image_id = {$PROVENANCE_PHOTO.IMAGE_ID};
const provenance_pwg_token = '{$PWG_TOKEN}';
const provenance_str_saved = '{'Provenance saved'|@translate|escape:javascript}';
const provenance_str_error = '{'Provenance could not be saved'|@translate|escape:javascript}';
{/footer_script}

    <p id="provenance-photo">
      <strong>{'Provenance'|@translate}</strong>
      <br>
      <span class="provenance-inherited">
        <span>{'Physical album'|@translate}: {$PROVENANCE_PHOTO.PHYSICAL_ALBUM|escape}</span>
        <span>{'Owner'|@translate}: {$PROVENANCE_PHOTO.OWNER|escape}</span>
        <span>{'Scanned on'|@translate}: {$PROVENANCE_PHOTO.SCANNED_ON|escape}</span>
        <span>{'Album note'|@translate}: {$PROVENANCE_PHOTO.ALBUM_NOTE|escape}</span>
      </span>
      <label for="provenance-photo-note">{'Note'|@translate}</label>
      <textarea rows="3" id="provenance-photo-note" class="description">{$PROVENANCE_PHOTO.NOTE|escape}</textarea>
      <span class="buttonLike" id="provenance-photo-save"><i class="icon-floppy"></i> {'Save Settings'|@translate}</span>
      <span id="provenance-photo-message"></span>
    </p>

