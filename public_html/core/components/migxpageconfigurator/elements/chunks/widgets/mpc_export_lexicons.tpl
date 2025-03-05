<style>
  [data-si-form] {
    display: flex;
    gap: 12px;
    align-items: stretch;
  }

  [data-si-form] button {
    margin: 0 !important;
  }
</style>
<form action="" data-si-form data-si-preset="mpc_export_lexicons">
    <select name="filename">
        <option value="">{'mpc_widget_export_choose_file' | lexicon}</option>
        {foreach $filelist as $filename}
            <option value="{$filename}">{$filename}</option>
        {/foreach}
    </select>
    <button type="submit" class="x-btn x-btn-small x-btn-icon-small-left primary-button x-btn-noicon">
        {'mpc_widget_export_btn' | lexicon}
    </button>
    <a href="" download=""></a>
</form>
