{strip}
<div id="persons-editor"
	data-persons-image="{$PERSONS_IMAGE_ID}"
	data-persons-token="{$PERSONS_TOKEN}"
	data-persons-rotation="{$PERSONS_ROTATION}"
	data-persons-min-fraction="{$PERSONS_MIN_FRACTION}"
	{* {strip} joins these lines with no whitespace, so the space is written out. *}
	{if !empty($PERSONS_RELOAD_ON_EXIT)} data-persons-reload-on-exit="1"{/if}
	data-persons-str-who="{'Who is this?'|@translate|escape}"
	data-persons-str-create="{'Create'|@translate|escape}"
	data-persons-str-hint="{'Enter commits - Esc cancels'|@translate|escape}"
	data-persons-str-too-small="{'That box is too small - drag a larger one'|@translate|escape}"
	data-persons-str-failed="{'The photo could not be saved'|@translate|escape}"
	data-persons-str-no-exiftool="{'This server cannot write metadata into image files'|@translate|escape}"
	data-persons-str-tag="{'Tag people'|@translate|escape}"
	data-persons-str-done="{'Done tagging'|@translate|escape}">
	<input type="button" id="persons-tag-toggle"{if !$PERSONS_EXIFTOOL} disabled title="{'This server cannot write metadata into image files'|@translate|escape}"{/if} value="{'Tag people'|@translate|escape}">
</div>
{/strip}
