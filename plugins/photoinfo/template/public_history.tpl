{if isset($PHOTOINFO.HISTORY)}
{combine_css path=$PHOTOINFO.PATH|cat:'template/info.css'}
{combine_script id='photoinfo_history' load='footer' path=$PHOTOINFO.PATH|cat:'template/history.js'}
<div id="PhotoHistory" class="imageInfo">
	<dt>{'Recent changes'|@translate}</dt>
	<dd>
		<details id="photoinfo-history" data-image-id="{$PHOTOINFO.HISTORY.IMAGE_ID}" data-ws-url="{$PHOTOINFO.WS_URL}" data-per-page="{$PHOTOINFO.HISTORY.PER_PAGE}" data-fields="{$PHOTOINFO.HISTORY.FIELDS}">
			<summary>{'Recent changes to this photo'|@translate} (<span id="photoinfo-history-count">{$PHOTOINFO.HISTORY.TOTAL}</span>)</summary>
			<ul id="photoinfo-history-list">
{foreach from=$PHOTOINFO.HISTORY.ROWS item=entry}
				<li{if $entry.undone} class="photoinfo-history-undone"{/if}><span class="photoinfo-history-text">{$entry.text|escape}</span> &mdash; <span class="photoinfo-history-meta">{if $entry.by !== ''}{$entry.by|escape} &middot; {/if}{$entry.when|escape}</span><span class="photoinfo-history-action"></span></li>
{/foreach}
			</ul>
			<p id="photoinfo-history-empty"{if $PHOTOINFO.HISTORY.TOTAL > 0} hidden{/if}>{'No changes recorded'|@translate}</p>
			<p id="photoinfo-history-all"{if $PHOTOINFO.HISTORY.TOTAL <= $PHOTOINFO.HISTORY.PER_PAGE} hidden{/if}><a href="{$PHOTOINFO.HISTORY.ALL_URL}">{'Show all'|@translate}</a></p>
		</details>
	</dd>
</div>
{/if}
