<style>
  [data-si-preset="mpc_export_lexicons"] {
    display: flex;
    gap: 12px;
    align-items: stretch;
  }

  [data-si-preset="mpc_export_lexicons"] button {
    margin: 0 !important;
  }
  [data-si-preset="mpc_export_lexicons"] select {
    flex-grow: 1;
  }
</style>
<form action="" data-si-form data-si-preset="mpc_export_lexicons">
    <select name="filename" data-si-preset="mpc_load_sections" data-si-event="change">
        <option value="">{'mpc_widget_export_choose_file' | lexicon}</option>
        {foreach $filelist as $filename}
            <option value="{$filename}">{$filename}</option>
        {/foreach}
    </select>
    <select name="section">
        <option value="">{'mpc_widget_all_sections' | lexicon}</option>
    </select>
    <button type="submit" class="x-btn x-btn-small x-btn-icon-small-left primary-button x-btn-noicon">
        {'mpc_widget_export_btn' | lexicon}
    </button>
    <a href="" download=""></a>
</form>
