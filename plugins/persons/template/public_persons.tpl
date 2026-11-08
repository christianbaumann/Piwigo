{if !empty($display_info.persons)}
<div id="Persons" class="imageInfo">
	<dt>{'Persons'|@translate}</dt>
	<dd>{include file=$PERSONS_NAMES_TPL} {include file=$PERSONS_CONTROLS_TPL}</dd>
</div>
{/if}
