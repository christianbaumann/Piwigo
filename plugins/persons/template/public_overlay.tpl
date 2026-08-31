{combine_css path=$PERSONS_PATH|cat:'template/overlay.css'}
{combine_script id='persons_overlay' load='footer' path=$PERSONS_PATH|cat:'template/overlay.js'}
{combine_css path=$PERSONS_PATH|cat:'template/editor.css'}
{combine_script id='persons_editor' load='footer' require='persons_overlay' path=$PERSONS_PATH|cat:'template/editor.js'}
{strip}
<div id="persons-overlay">
{foreach from=$PERSONS_BOXES item=box}
	<div class="person-box{if $box.STALE} person-box-stale{/if}" data-person-region="{$box.ID}" style="left:{$box.LEFT};top:{$box.TOP};width:{$box.W};height:{$box.H}"{if $box.STALE} title="{$PERSONS_STALE_TITLE|escape}"{/if}>
		{if $box.URL}<a class="person-box-label" href="{$box.URL}">{$box.NAME|escape}</a>{else}<span class="person-box-label">{$box.NAME|escape}</span>{/if}
		<button type="button" class="person-box-delete" title="{'Remove this person from the photo'|@translate|escape}">&times;</button>
	</div>
{/foreach}
</div>
<div id="persons-editor"
	data-persons-image="{$PERSONS_IMAGE_ID}"
	data-persons-token="{$PERSONS_TOKEN}"
	data-persons-rotation="{$PERSONS_ROTATION}"
	data-persons-min-fraction="{$PERSONS_MIN_FRACTION}"
	data-persons-str-who="{'Who is this?'|@translate|escape}"
	data-persons-str-create="{'Create'|@translate|escape}"
	data-persons-str-hint="{'Enter commits - Esc cancels'|@translate|escape}"
	data-persons-str-too-small="{'That box is too small - drag a larger one'|@translate|escape}"
	data-persons-str-failed="{'The photo could not be saved'|@translate|escape}"
	data-persons-str-no-exiftool="{'This server cannot write metadata into image files'|@translate|escape}"
	data-persons-str-tag="{'Tag people'|@translate|escape}"
	data-persons-str-done="{'Done tagging'|@translate|escape}">
	<button type="button" id="persons-tag-toggle"{if !$PERSONS_EXIFTOOL} disabled title="{'This server cannot write metadata into image files'|@translate|escape}"{/if}>{'Tag people'|@translate}</button>
	<span id="persons-editor-message" role="status"></span>
</div>
{/strip}
