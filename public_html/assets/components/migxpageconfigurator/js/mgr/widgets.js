function toggleButtonDisabled(root, disabled = true){
  const button = root.querySelector('button[type="submit"]');
  button && (button.disabled = disabled);
}
document.addEventListener('fu:uploading:start', e => {
  const { root } = e.detail;
  if(root.dataset.siPreset === 'mpc_upload_lexicon_file'){
    toggleButtonDisabled(root);
  }
})
document.addEventListener('fu:uploading:end', e => {
  const { root } = e.detail;
  if(root.dataset.siPreset === 'mpc_upload_lexicon_file'){
    toggleButtonDisabled(root, false);
  }
})
document.addEventListener('si:send:before', e => {
  const { target, headers } = e.detail;
  if(['mpc_export_lexicons','mpc_import_lexicons'].includes(headers['X-SIPRESET'])){
    toggleButtonDisabled(target);
  }
})
document.addEventListener('si:send:finish', e => {
  const { target, headers } = e.detail;
  if(['mpc_export_lexicons','mpc_import_lexicons'].includes(headers['X-SIPRESET'])){
    toggleButtonDisabled(target, false);
  }
})
document.addEventListener('si:send:success', e => {
  const { result, headers } = e.detail;
  if (headers['X-SIPRESET'] === 'mpc_export_lexicons') {
    const form = document.querySelector('[data-si-preset="mpc_export_lexicons"]');
    const linkDownload = form.querySelector('a[download]');
    linkDownload && (linkDownload.href = result.data.filePath);
    linkDownload && (linkDownload.download = result.data.fileName);
    linkDownload && linkDownload.click();
  }
})
