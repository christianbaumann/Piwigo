{combine_css path=$PERSONS_PATH|cat:'template/overlay.css'}
{combine_script id='persons_overlay' load='footer' path=$PERSONS_PATH|cat:'template/overlay.js'}
{strip}
<div id="persons-overlay">
{foreach from=$PERSONS_BOXES item=box}
	<div class="person-box{if $box.STALE} person-box-stale{/if}" data-person-region="{$box.ID}" style="left:{$box.LEFT};top:{$box.TOP};width:{$box.W};height:{$box.H}"{if $box.STALE} title="{$PERSONS_STALE_TITLE|escape}"{/if}>
		{if $box.URL}<a class="person-box-label" href="{$box.URL}">{$box.NAME|escape}</a>{else}<span class="person-box-label">{$box.NAME|escape}</span>{/if}
	</div>
{/foreach}
</div>
{/strip}
