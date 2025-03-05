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
