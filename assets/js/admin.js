(() => {
  'use strict';
  const startButton = document.getElementById('sla-rescan');
  if (!startButton || !window.SLAConfig) return;

  const cancelButton = document.getElementById('sla-cancel');
  const status = document.getElementById('sla-status');
  const progressWrap = document.getElementById('sla-progress-wrap');
  const progress = document.getElementById('sla-progress');
  const progressLabel = document.getElementById('sla-progress-label');
  const errorBox = document.getElementById('sla-error');
  let activeScan = null;
  let busy = false;
  let cancelRequested = false;

  async function request(action, payload = {}) {
    const body = new URLSearchParams({ action, nonce: SLAConfig.nonce, ...payload });
    const response = await fetch(SLAConfig.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    });
    const json = await response.json();
    if (!response.ok || !json.success) {
      throw new Error(json?.data?.message || SLAConfig.strings.failed);
    }
    return json.data;
  }

  function setProgress(processed, total, percent) {
    progress.value = percent;
    progressLabel.textContent = percent + '% (' + processed + ' از ' + total + ')';
  }

  function showError(message) {
    errorBox.hidden = false;
    errorBox.querySelector('p').textContent = message;
  }

  function finish(message) {
    busy = false;
    activeScan = null;
    cancelRequested = false;
    cancelButton.hidden = true;
    cancelButton.disabled = false;
    startButton.disabled = false;
    status.textContent = message;
  }

  async function runBatches() {
    try {
      while (activeScan && busy) {
        if (cancelRequested) {
          await request('sla_cancel_scan', {
            scanId: String(activeScan.scanId),
            token: activeScan.token
          });
          finish(SLAConfig.strings.cancelled);
          return;
        }

        // Each bounded request completes before the next request is sent.
        const result = await request('sla_scan_batch', {
          scanId: String(activeScan.scanId),
          token: activeScan.token
        });
        setProgress(result.processed, result.total, result.percent);

        if (result.done) {
          finish(SLAConfig.strings.done);
          window.location.reload();
          return;
        }
      }
    } catch (error) {
      finish(cancelRequested ? SLAConfig.strings.cancelled : SLAConfig.strings.failed);
      if (!cancelRequested) showError(error.message || SLAConfig.strings.failed);
    }
  }

  startButton.addEventListener('click', async () => {
    if (busy || !window.confirm(SLAConfig.strings.confirm)) return;
    errorBox.hidden = true;
    startButton.disabled = true;
    status.textContent = SLAConfig.strings.starting;
    progressWrap.hidden = false;
    progress.value = 0;
    progressLabel.textContent = '0%';
    try {
      const result = await request('sla_start_scan');
      activeScan = { scanId: result.scanId, token: result.token };
      busy = true;
      cancelRequested = false;
      cancelButton.hidden = false;
      status.textContent = SLAConfig.strings.working;
      setProgress(result.processed, result.total, 0);
      await runBatches();
    } catch (error) {
      finish(SLAConfig.strings.failed);
      showError(error.message || SLAConfig.strings.failed);
    }
  });

  cancelButton.addEventListener('click', () => {
    if (!activeScan || !busy) return;
    cancelRequested = true;
    cancelButton.disabled = true;
    status.textContent = 'در حال لغو بررسی پس از پایان درخواست جاری…';
  });
})();