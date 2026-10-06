{combine_css path=$PERSONS_PATH|cat:'template/overlay.css'}
{combine_script id='persons_overlay' load='footer' path=$PERSONS_PATH|cat:'template/overlay.js'}
{combine_css path=$PERSONS_PATH|cat:'template/editor.css'}
{combine_script id='persons_editor' load='footer' require='persons_overlay' path=$PERSONS_PATH|cat:'template/editor.js'}
{strip}
<div id="persons-overlay">
	{* On the photo rather than beside the toggle: in the narrow layout the
	   information panel is below the photo, out of sight while a box is drawn. *}
	<span id="persons-editor-message" role="status"></span>
{foreach from=$PERSONS_BOXES item=box}
	<div class="person-box{if $box.STALE} person-box-stale{/if}" data-person-region="{$box.ID}" style="left:{$box.LEFT};top:{$box.TOP};width:{$box.W};height:{$box.H}"{if $box.STALE} title="{$PERSONS_STALE_TITLE|escape}"{/if}>
		{if $box.URL}<a class="person-box-label" href="{$box.URL}">{$box.NAME|escape}</a>{else}<span class="person-box-label">{$box.NAME|escape}</span>{/if}
		<button type="button" class="person-box-delete" title="{'Remove this person from the photo'|@translate|escape}">&times;</button>
	</div>
{/foreach}
</div>
{/strip}
