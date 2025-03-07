<style>
  [data-si-preset="mpc_import_lexicons"] {
    display: grid;
    gap: 12px;
    grid-template-areas:
      "p p p"
      "f f b";
  }

  [data-si-preset="mpc_import_lexicons"] button {
    margin: 0 !important;
    grid-area: b;
  }
  .v_hidden{
    position: absolute;
    width: 0;
    z-index: -1;
  }
  [data-fu-dropzone]{
    border: 1px dashed #000;
    height: auto;
    grid-area: f;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
  }
  [data-fu-wrap]{
    display: contents;
  }
  [data-fu-progress]{
    grid-area: p;
  }
</style>
<form action="" data-si-form data-si-preset="mpc_import_lexicons">
    <div data-fu-wrap data-si-preset="mpc_upload_lexicon_file" data-si-nosave>
        <div data-fu-progress=""></div>
        <input type="hidden" name="filelist" data-fu-list>
        <label data-fu-dropzone>
            <input type="file" name="files" data-fu-field multiple class="v_hidden">
            <span data-fu-hide>Перетащите сюда файл</span>
        </label>
        <template data-fu-tpl>
            <button type="button" class="x-btn x-btn-small x-btn-icon-small-left primary-button x-btn-noicon" data-fu-path="$path">$filename&nbsp;x</button>
        </template>
    </div>
    <button type="submit" class="x-btn x-btn-small x-btn-icon-small-left primary-button x-btn-noicon">
        {'mpc_widget_import_btn' | lexicon}
    </button>
</form>
