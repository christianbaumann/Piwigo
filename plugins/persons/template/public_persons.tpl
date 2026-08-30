{if !empty($display_info.persons) and !empty($PERSONS_NAMES)}
<div id="Persons" class="imageInfo">
	<dt>{'Persons'|@translate}</dt>
	<dd>{foreach from=$PERSONS_NAMES item=person name=persons_row}{if !$smarty.foreach.persons_row.first}, {/if}{if $person.URL}<a href="{$person.URL}">{$person.NAME|escape}</a>{else}{$person.NAME|escape}{/if}{/foreach}</dd>
</div>
{/if}
