{combine_css path=$PHOTOEDIT_PATH|cat:'template/editor.css'}
{combine_css path='themes/default/js/plugins/jquery.Jcrop.css'}
{combine_script id='jquery.jcrop' load='footer' require='jquery' path='themes/default/js/plugins/jquery.Jcrop.min.js'}
{combine_script id='photoedit_editor' load='footer' require='jquery.jcrop' path=$PHOTOEDIT_PATH|cat:'template/editor.js'}
{strip}
<a id="photoedit-toggle" class="pwg-state-default pwg-button photoedit-button{if $PHOTOEDIT_UNAVAILABLE} photoedit-disabled{/if}" href="#" title="{if $PHOTOEDIT_UNAVAILABLE}{$PHOTOEDIT_UNAVAILABLE|escape}{else}{'Turn or crop this photo'|@translate|escape}{/if}" rel="nofollow" data-image-id="{$PHOTOEDIT_IMAGE_ID}" data-token="{$PHOTOEDIT_TOKEN|escape}" data-ws-url="{$PHOTOEDIT_WS_URL|escape}" data-unavailable="{$PHOTOEDIT_UNAVAILABLE|escape}" data-confirm-lost="{'These person markings will be removed:'|@translate|escape}"><span class="pwg-icon pwg-button-text photoedit-label">{'Turn/crop'|@translate}</span></a>
<span id="photoedit-controls">
<a id="photoedit-turn-left" class="pwg-state-default pwg-button photoedit-button" href="#" title="{'Turn left'|@translate|escape}" rel="nofollow"><span class="pwg-icon pwg-button-text photoedit-label">&#8634;</span></a>
<a id="photoedit-turn-right" class="pwg-state-default pwg-button photoedit-button" href="#" title="{'Turn right'|@translate|escape}" rel="nofollow"><span class="pwg-icon pwg-button-text photoedit-label">&#8635;</span></a>
<a id="photoedit-save" class="pwg-state-default pwg-button photoedit-button" href="#" rel="nofollow"><span class="pwg-icon pwg-button-text photoedit-label">{'Save'|@translate}</span></a>
<a id="photoedit-cancel" class="pwg-state-default pwg-button photoedit-button" href="#" rel="nofollow"><span class="pwg-icon pwg-button-text photoedit-label">{'Cancel'|@translate}</span></a>
</span>
{/strip}
