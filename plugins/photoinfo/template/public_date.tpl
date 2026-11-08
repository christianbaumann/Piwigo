{if $PHOTOINFO.DATE}
<div id="PhotoDate" class="imageInfo">
	<dt>{'Date'|@translate}</dt>
	<dd>
{if $PHOTOINFO.EDITABLE}
{combine_css path=$PHOTOINFO.PATH|cat:'template/info.css'}
{combine_script id='photoinfo_date' load='footer' path=$PHOTOINFO.PATH|cat:'template/date.js'}
		<div id="photoinfo-date-view">{if isset($PHOTOINFO.DATE_TEXT)}{$PHOTOINFO.DATE_TEXT}{else}<span class="photoinfo-placeholder">{'No date yet'|@translate}</span>{/if}</div>
		<button type="button" id="photoinfo-date-edit" class="photoinfo-edit">{'✎ edit'|@translate}</button>
		<form id="photoinfo-date-form" hidden data-image-id="{$PHOTOINFO.IMAGE_ID}" data-ws-url="{$PHOTOINFO.WS_URL}" data-token="{$PHOTOINFO.TOKEN}" data-min-year="{$PHOTOINFO.MIN_YEAR}" data-max-year="{$PHOTOINFO.MAX_YEAR}" data-placeholder="{'No date yet'|@translate|escape}" data-error-year="{'The year must lie between %d and %d'|@translate|escape}" data-error-end="{'The end must not lie before the start'|@translate|escape}" data-error-end-missing="{'A range needs an end year'|@translate|escape}" data-error-save="{'The date could not be saved'|@translate|escape}" data-status-saving="{'Saving …'|@translate|escape}" data-status-saved="{'✓ Saved'|@translate|escape}" data-status-dirty="{'not saved yet'|@translate|escape}" data-status-failed="{'Not saved: %s'|@translate|escape}">
			<select name="qualifier" aria-label="{'Qualifier'|@translate}">
				<option value="">—</option>
{foreach from=$PHOTOINFO.QUALIFIERS key=value item=label}
				<option value="{$value}"{if $value == $PHOTOINFO.QUALIFIER} selected{/if}>{$label}</option>
{/foreach}
			</select>
			<input type="text" name="year" inputmode="numeric" pattern="[0-9]{ldelim}4{rdelim}" maxlength="4" size="4" placeholder="{'Year'|@translate}" aria-label="{'Year'|@translate}" value="{$PHOTOINFO.START.year}">
			<select name="month" aria-label="{'Month'|@translate}">
				<option value="">—</option>
{foreach from=$PHOTOINFO.MONTHS key=number item=name}
				<option value="{$number}"{if $number == $PHOTOINFO.START.month} selected{/if}>{$name}</option>
{/foreach}
			</select>
			<select name="day" aria-label="{'Day'|@translate}" data-day="{$PHOTOINFO.START.day}">
				<option value="">—</option>
			</select>
			<div class="photoinfo-date-end" hidden>
				{'until'|@translate}
				<input type="text" name="end_year" inputmode="numeric" pattern="[0-9]{ldelim}4{rdelim}" maxlength="4" size="4" placeholder="{'Year'|@translate}" aria-label="{'End year'|@translate}" value="{$PHOTOINFO.END.year}">
				<select name="end_month" aria-label="{'End month'|@translate}">
					<option value="">—</option>
{foreach from=$PHOTOINFO.MONTHS key=number item=name}
					<option value="{$number}"{if $number == $PHOTOINFO.END.month} selected{/if}>{$name}</option>
{/foreach}
				</select>
				<select name="end_day" aria-label="{'End day'|@translate}" data-day="{$PHOTOINFO.END.day}">
					<option value="">—</option>
				</select>
			</div>
			<div class="photoinfo-actions">
				<input type="submit" class="photoinfo-done" value="{'Done'|@translate}">
			</div>
			<p class="photoinfo-message" role="status"></p>
		</form>
		<p id="photoinfo-date-status" class="pwg-row-status" role="status"></p>
{else}
		<a href="{$PHOTOINFO.DATE_URL}" rel="nofollow">{$PHOTOINFO.DATE_TEXT}</a>
{/if}
	</dd>
</div>
{/if}
