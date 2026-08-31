{combine_css path=$PERSONS_PATH|cat:'template/admin_persons.css'}
{combine_script id='persons_admin_list' load='footer' require='jquery' path=$PERSONS_PATH|cat:'template/admin_persons.js'}

{footer_script}
const persons_pwg_token = '{$PERSONS_TOKEN}';
const persons_photo_ids = '{$PERSONS_PHOTO_IDS}'.split(',').filter(Boolean).map(Number);
const persons_max_chunk = {$PERSONS_MAX_CHUNK};
const persons_str_rename = '{'New name for %s'|@translate|escape:javascript}';
const persons_str_delete = '{'Remove %s from every photo? The regions leave the image files too.'|@translate|escape:javascript}';
const persons_str_failed = '{'That did not work'|@translate|escape:javascript}';
const persons_str_scanning = '{'Rescanning %d photos...'|@translate|escape:javascript}';
const persons_str_scanned = '{'Rescanned %d photos'|@translate|escape:javascript}';
const persons_str_scan_failed = '{'%d could not be read'|@translate|escape:javascript}';
{/footer_script}

<div class="titrePage">
	<h2>{'Persons'|@translate}</h2>
</div>

<div id="persons-admin">
	<p id="persons-admin-actions">
		{if !$PERSONS_EXIFTOOL}
		<span id="persons-rescan-unavailable">{'This server cannot write metadata into image files'|@translate}</span>
		{elseif $PERSONS_PHOTO_COUNT > 0}
		<span class="buttonLike" id="persons-rescan"><i class="icon-arrows-cw"></i> {'Rescan all files'|@translate}</span>
		<span id="persons-rescan-progress" data-done="0" data-total="0">
			<span id="persons-rescan-bar"><span id="persons-rescan-bar-fill"></span></span>
			<span id="persons-rescan-message" role="status"></span>
		</span>
		{/if}
	</p>

	<form id="persons-search" method="get" action="admin.php">
		<input type="hidden" name="page" value="plugin-persons">
		<label for="persons-search-q">{'Search'|@translate}</label>
		<input type="search" id="persons-search-q" name="q" value="{$PERSONS_QUERY|escape}">
		<input type="submit" value="{'Search'|@translate}">
	</form>

	{if $PERSONS_LIST}
	<table id="persons-table" class="table2">
		<thead>
			<tr>
				<th>{'Name'|@translate}</th>
				<th class="persons-number">{'Photos'|@translate}</th>
				<th class="persons-number">{'Regions'|@translate}</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		{foreach from=$PERSONS_LIST item=person}
			<tr data-person="{$person.ID}" data-person-name="{$person.NAME|escape}" data-person-photos="{$person.PHOTOS}" data-person-regions="{$person.REGIONS}">
				<td class="persons-name">{if $person.URL}<a href="{$person.URL}">{$person.NAME|escape}</a>{else}{$person.NAME|escape}{/if}</td>
				<td class="persons-number">{$person.PHOTOS}</td>
				<td class="persons-number">{$person.REGIONS}</td>
				<td class="persons-row-actions">
					<a href="#" class="persons-rename">{'Rename'|@translate}</a>
					<a href="#" class="persons-delete">{'Delete'|@translate}</a>
				</td>
			</tr>
		{/foreach}
		</tbody>
	</table>

	<p id="persons-admin-summary">
		{'%d persons, %d regions'|@translate|@sprintf:$PERSONS_TOTAL_PERSONS:$PERSONS_TOTAL_REGIONS}{if $PERSONS_LAST_RESCAN} &mdash; {'Index last rebuilt %s'|@translate|@sprintf:$PERSONS_LAST_RESCAN}{/if}
	</p>
	{else}
	<p id="persons-admin-summary">{if $PERSONS_QUERY}{'No person matches that search'|@translate}{else}{'Nobody is tagged yet'|@translate}{/if}</p>
	{/if}
</div>
