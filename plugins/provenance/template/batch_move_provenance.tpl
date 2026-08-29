{* Injected into the Batch Manager's move panel by provenance_batch_prefilter().
   The radios post PROVENANCE_MOVE_MODE_PARAM alongside the move itself, which is
   how the choice reaches provenance_inherit_into() - core fires one trigger for
   every virtual link and cannot say there whether it was a move. *}
<div class="provenance-move-mode">
  <label class="provenance-move-mode-title">{'Provenance of the moved photos'|@translate}</label>
  <label><input type="radio" name="provenance_move_mode" value="keep" checked="checked"> {'Keep what each photo already has'|@translate}</label>
  <label><input type="radio" name="provenance_move_mode" value="clear"> {'Clear it'|@translate}</label>
  <label><input type="radio" name="provenance_move_mode" value="replace"> {'Replace it with the destination album\'s'|@translate}</label>
</div>
