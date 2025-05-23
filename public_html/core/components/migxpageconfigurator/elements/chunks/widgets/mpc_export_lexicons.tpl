<style>
  [data-si-preset="mpc_export_lexicons"] {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: stretch;
  }

  [data-si-preset="mpc_export_lexicons"] button {
    margin: 0 !important;
  }

  [data-si-preset="mpc_export_lexicons"] select {
    flex-grow: 1;
  }

  .languages {
    display: flex;
    align-items: center;
    justify-content: space-around;
    width: 100%;
  }

  .languages label {
    display: flex;
    align-items: center;
    gap: 10px;
  }
</style>
<form action="" data-si-form data-si-preset="mpc_export_lexicons">
    <div class="languages">
        {foreach $languages as $language index=$i}
            {if $language != $defaultLanguageKey}
                <label for="lang-{$i}">
                    <input type="checkbox" id="lang-{$i}" name="languages[]" value="{$language}">
                    {$language}
                </label>
            {/if}
        {/foreach}
    </div>
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
