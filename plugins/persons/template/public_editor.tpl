{strip}
<div id="persons-editor"
	data-persons-image="{$PERSONS_IMAGE_ID}"
	data-persons-token="{$PERSONS_TOKEN}"
	data-persons-rotation="{$PERSONS_ROTATION}"
	data-persons-min-fraction="{$PERSONS_MIN_FRACTION}"
	data-persons-str-who="{'Who is this?'|@translate|escape}"
	data-persons-str-create="{'Create'|@translate|escape}"
	data-persons-str-hint="{'Enter commits - Esc cancels'|@translate|escape}"
	data-persons-str-draw="{'Drag a box around a face - Esc cancels'|@translate|escape}"
	data-persons-str-too-small="{'That box is too small - drag a larger one'|@translate|escape}"
	data-persons-str-gone="{$PERSONS_MARK_GONE|escape}"
	data-persons-str-failed="{'The photo could not be saved'|@translate|escape}"
	data-persons-str-no-exiftool="{'This server cannot write metadata into image files'|@translate|escape}"
	data-persons-str-saving="{'Saving …'|@translate|escape}"
	data-persons-str-saved="{'✓ Saved'|@translate|escape}"
	data-persons-str-not-saved="{'Not saved: %s'|@translate|escape}"
	data-persons-str-write="{'Saved, but not written into the image file: %s'|@translate|escape}"
	data-persons-str-remove="{'Remove this person from the photo'|@translate|escape}">
{* Without the Personen row (switched off, or the admin screen) the names and the button sit here. *}
{if empty($display_info.persons) or !empty($persons_force_controls)}
	<div class="persons-editor-row">{include file=$PERSONS_NAMES_TPL} {include file=$PERSONS_CONTROLS_TPL}</div>
{/if}
</div>
{/strip}
