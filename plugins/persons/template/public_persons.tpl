{if !empty($display_info.persons)}
<div id="Persons" class="imageInfo"{if empty($PERSONS_NAMES)} hidden{/if}>
	<dt>{'Persons'|@translate}</dt>
	<dd>{foreach from=$PERSONS_NAMES item=person name=persons_row}{if !$smarty.foreach.persons_row.first}, {/if}{if $person.URL}<a href="{$person.URL}">{$person.NAME|escape}</a>{else}{$person.NAME|escape}{/if}{/foreach}</dd>
</div>
{/if}
