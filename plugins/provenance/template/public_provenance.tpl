{if !empty($display_info.provenance) and !empty($PROVENANCE_TEXT)}
<div id="Provenance" class="imageInfo">
	<dt>{'Provenance'|@translate}</dt>
	<dd>{$PROVENANCE_TEXT|escape} <a id="provenancePageLink" href="{$PROVENANCE_PAGE_URL}">{'Details and history'|@translate}</a></dd>
</div>
{/if}
