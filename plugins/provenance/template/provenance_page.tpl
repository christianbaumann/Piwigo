{combine_css path=$PROVENANCE_PATH|cat:'template/provenance_page.css'}
<div id="provenancePage" data-object="{$PROVENANCE_PAGE.OBJECT}">
  <p class="provenance-page-back"><a id="provenancePageBack" href="{$PROVENANCE_PAGE.BACK_URL}">{$PROVENANCE_PAGE.BACK_NAME}</a></p>

  <dl id="provenancePageValues">
  {foreach from=$PROVENANCE_PAGE.VALUES item=value}
    <dt>{$value.LABEL}</dt>
    <dd data-field="{$value.FIELD}">{if $value.VALUE !== ''}{$value.VALUE|escape}{else}<span class="provenance-page-empty">{'Not set'|@translate}</span>{/if}</dd>
  {/foreach}
  </dl>

  <h3>{'Change history'|@translate}</h3>
  {if empty($PROVENANCE_PAGE.HISTORY)}
  <p id="provenancePageNoHistory">{'No changes recorded'|@translate}</p>
  {else}
  {if $PROVENANCE_PAGE.HISTORY_TOTAL > $PROVENANCE_PAGE.HISTORY_SHOWN}
  <p>{'The newest %d of %d changes'|@translate|@sprintf:$PROVENANCE_PAGE.HISTORY_SHOWN:$PROVENANCE_PAGE.HISTORY_TOTAL}</p>
  {/if}
  <table id="provenancePageHistory">
    <thead>
      <tr>
        <th>{'Date'|@translate}</th>
        <th>{'Field'|@translate}</th>
        <th>{'Before'|@translate}</th>
        <th>{'After'|@translate}</th>
        <th>{'Cause'|@translate}</th>
        <th>{'By'|@translate}</th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$PROVENANCE_PAGE.HISTORY item=row}
      <tr>
        <td>{$row.DATE}</td>
        <td>{$row.FIELD|escape}</td>
        <td class="provenance-page-text">{$row.OLD|escape}</td>
        <td class="provenance-page-text">{$row.NEW|escape}</td>
        <td>{$row.SOURCE|escape}</td>
        <td>{$row.BY|escape}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  {/if}
</div><!-- /provenancePage -->
