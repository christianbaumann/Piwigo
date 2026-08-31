{combine_css path=$PERSONS_PATH|cat:'template/admin_photo.css'}

<div class="titrePage">
	<h2>{'Tag people'|@translate} - {$PERSONS_ADMIN_PHOTO.TITLE}</h2>
</div>

<p id="persons-admin-return"><a href="{$PERSONS_ADMIN_PHOTO.U_RETURN}">{'Back to the photo'|@translate}</a></p>

{* The same ids the public page uses: overlay.js and editor.js measure
   #theMainImage inside #persons-stage, and neither knows which page it is on. *}
<div id="persons-stage">
	<img id="theMainImage" src="{$PERSONS_ADMIN_PHOTO.U_IMG}" alt="{$PERSONS_ADMIN_PHOTO.ALT|escape}">
	{include file=$PERSONS_OVERLAY_TPL}
</div>
