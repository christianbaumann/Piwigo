{if $PHOTOINFO.INFO}
<div id="PhotoInfo" class="imageInfo">
	<dt>{'Info'|@translate}</dt>
	<dd>
{if $PHOTOINFO.EDITABLE}
{combine_css path=$PHOTOINFO.PATH|cat:'template/info.css'}
{combine_script id='photoinfo_info' load='footer' path=$PHOTOINFO.PATH|cat:'template/info.js'}
		<div id="photoinfo-info-view" role="button" tabindex="0" title="{'Click to edit'|@translate}" data-placeholder="{'Click to add a description'|@translate|escape}">{if isset($COMMENT_IMG)}{$COMMENT_IMG}{else}<span class="photoinfo-placeholder">{'Click to add a description'|@translate}</span>{/if}</div>
		<button type="button" class="photoinfo-edit" id="photoinfo-info-edit">{'✎ edit'|@translate}</button>
		<form id="photoinfo-info-form" hidden data-image-id="{$PHOTOINFO.IMAGE_ID}" data-ws-url="{$PHOTOINFO.WS_URL}" data-token="{$PHOTOINFO.TOKEN}" data-text-saving="{'Saving …'|@translate|escape}" data-text-saved="{'✓ Saved'|@translate|escape}" data-text-dirty="{'not saved yet'|@translate|escape}" data-text-not-saved="{'Not saved: %s'|@translate|escape}" data-error-save="{'The description could not be saved'|@translate|escape}" data-error-write="{'Saved, but not written into the image file: %s'|@translate|escape}" data-undo-label="{'Undo'|@translate|escape}" data-text-undone="{'↶ Undone'|@translate|escape}">
			<textarea name="info" rows="5">{$PHOTOINFO.RAW|escape}</textarea>
			<div class="photoinfo-actions">
				<input type="submit" value="{'Done'|@translate}">
			</div>
		</form>
		<p class="pwg-row-status" role="status"></p>
{else}
		{$COMMENT_IMG}
{/if}
	</dd>
</div>
{/if}
