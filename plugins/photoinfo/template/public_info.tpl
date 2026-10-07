<div id="PhotoInfo" class="imageInfo">
	<dt>{'Info'|@translate}</dt>
	<dd>
{if $PHOTOINFO.EDITABLE}
{combine_css path=$PHOTOINFO.PATH|cat:'template/info.css'}
{combine_script id='photoinfo_info' load='footer' path=$PHOTOINFO.PATH|cat:'template/info.js'}
		<div id="photoinfo-info-view" role="button" tabindex="0" title="{'Click to edit'|@translate}">{if isset($COMMENT_IMG)}{$COMMENT_IMG}{else}<span class="photoinfo-placeholder">{'Click to add a description'|@translate}</span>{/if}</div>
		<form id="photoinfo-info-form" hidden data-image-id="{$PHOTOINFO.IMAGE_ID}" data-ws-url="{$PHOTOINFO.WS_URL}" data-token="{$PHOTOINFO.TOKEN}" data-error-save="{'The description could not be saved'|@translate|escape}" data-error-write="{'Saved, but not written into the image file: %s'|@translate|escape}">
			<textarea name="info" rows="5">{$PHOTOINFO.RAW|escape}</textarea>
			<div class="photoinfo-actions">
				<input type="submit" value="{'Save'|@translate}">
				<input type="button" class="photoinfo-cancel" value="{'Cancel'|@translate}">
			</div>
			<p class="photoinfo-message" role="status"></p>
		</form>
{else}
		{$COMMENT_IMG}
{/if}
	</dd>
</div>
