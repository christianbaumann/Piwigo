{if !empty($display_info.provenance) and !empty($PROVENANCE_TEXT)}
<div id="Provenance" class="imageInfo">
	<dt>{'Provenance'|@translate}</dt>
	<dd>{$PROVENANCE_TEXT|escape}</dd>
</div>
{/if}
